<?php
declare(strict_types=1);
/**
 * Inventory — Shipments: a supplier list turned into inventory.
 * Spec: docs/superpowers/specs/2026-09-28-inventory-shipments-design.md
 * Migration: db/migrations/add_inventory_shipments.sql. Test: php tests/inventory_shipments_logic.php
 *
 * Load-bearing rules:
 *   • Importing NEVER moves stock — it creates items (matched by name) and lines.
 *   • Receiving posts each line's NEW TOTALS; the server writes only the difference
 *     (receive moves into the shipment's store, or a written-off "Receiving
 *     correction"), so a retry or a double tap never counts twice. Damaged units
 *     are stored on the line only — they never enter stock.
 *   • Lock order: inv_shipments → inv_shipment_lines (id order) → balances
 *     (inv_lock_balances) → inv_assets.
 *   • Nothing here scopes by venue — pages check inv_ship_store_allowed().
 */

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/inventory-shipment-import.php';

const INV_SHIP_STATUS = [
    'expected'  => ['Expected', 'badge--grey'],
    'receiving' => ['Receiving', 'badge--orange'],
    'received'  => ['Received', 'badge--green'],
    'cancelled' => ['Cancelled', 'badge--grey'],
];

// ── Pure rules ──────────────────────────────────────────────────────────────

/** Pieces not accounted for: expected − good − damaged, never below 0 — PURE. */
function inv_ship_short(array $l): int {
    return max(0, (int)$l['qty_expected'] - (int)$l['qty_good'] - (int)$l['qty_damaged']);
}

/**
 * Check one posted card against its line — PURE. $p: good, damaged (the NEW TOTALS,
 * '' = keep), note (absent = keep), over ('1' = "more than ordered" confirmed),
 * base_good / base_damaged (OPTIONAL — the totals the card was drawn from). Pages
 * MUST post base_good/base_damaged with every save: when either is present and
 * does not exactly match the line's CURRENT qty_good/qty_damaged, the line was
 * changed by someone else since the card was opened, and the save is refused
 * rather than silently overwriting (or writing off) their update.
 * Returns ['good','damaged','delta','note','changed'] or the refusal text.
 */
function inv_ship_receive_plan(array $line, array $p): array|string {
    $label = trim(((string)($line['code'] ?? '')) . ' ' . mb_strimwidth((string)$line['description'], 0, 60, '…'));
    foreach (['good' => 'qty_good', 'damaged' => 'qty_damaged'] as $k => $col) {
        $bk = "base_{$k}";
        if (!array_key_exists($bk, $p)) continue;
        $bv = trim((string)$p[$bk]);
        if ($bv === '' || !ctype_digit($bv) || (int)$bv !== (int)$line[$col]) {
            return "{$label}: someone else saved this line since you opened it — reload and check.";
        }
    }
    $num = function (string $k, int $cur) use ($p): ?int {
        $v = trim((string)($p[$k] ?? ''));
        if ($v === '') return $cur;
        return (ctype_digit($v) && strlen($v) <= 6) ? (int)$v : null;
    };
    $good = $num('good', (int)$line['qty_good']);
    $dmg  = $num('damaged', (int)$line['qty_damaged']);
    if ($good === null || $dmg === null) return "{$label}: enter whole numbers.";
    if ($good + $dmg > INV_SHIP_MAX_QTY) return "{$label}: that quantity is too large.";
    if ($good + $dmg > (int)$line['qty_expected'] && ($p['over'] ?? '') !== '1') {
        return "{$label}: that is more than the {$line['qty_expected']} ordered — tick “More than ordered” to confirm.";
    }
    $oldNote = trim((string)($line['note'] ?? ''));
    $note    = array_key_exists('note', $p) ? mb_substr(trim((string)$p['note']), 0, 1000) : $oldNote;
    $delta   = $good - (int)$line['qty_good'];
    return ['good' => $good, 'damaged' => $dmg, 'delta' => $delta, 'note' => $note,
            'changed' => $delta !== 0 || $dmg !== (int)$line['qty_damaged'] || $note !== $oldNote];
}

/** May this account work on shipments landing in this store? Owner: yes; others: the store's venue set meets theirs — PURE. */
function inv_ship_store_allowed(array $store, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    return ($store['kind'] ?? '') === 'store'
        && (bool) array_intersect(inv_location_venue_set($store), array_map('intval', $venueIds));
}

/** The store of a shipment row (from inv_shipment_fetch()/list) as a location row — PURE. */
function inv_shipment_store_row(array $s): array {
    return ['id' => (int)$s['to_location_id'], 'kind' => $s['store_kind'], 'name' => $s['store_name'],
            'venue_id' => $s['store_venue_id'], 'share_venue_ids' => $s['store_share_venue_ids'], 'is_active' => $s['store_active']];
}

// ── Reads ───────────────────────────────────────────────────────────────────

function inv_shipment_select_sql(): string {
    return "SELECT s.*, l.name AS store_name, l.kind AS store_kind, l.venue_id AS store_venue_id,
                   l.share_venue_ids AS store_share_venue_ids, l.is_active AS store_active, a.name AS created_by_name,
                   COALESCE(t.line_count, 0) AS line_count, COALESCE(t.pieces_expected, 0) AS pieces_expected,
                   COALESCE(t.pieces_good, 0) AS pieces_good, COALESCE(t.pieces_damaged, 0) AS pieces_damaged
              FROM inv_shipments s
              JOIN inv_locations l      ON l.id = s.to_location_id
              LEFT JOIN admin_users a   ON a.id = s.created_by
              LEFT JOIN (SELECT shipment_id, COUNT(*) AS line_count, SUM(qty_expected) AS pieces_expected,
                                SUM(qty_good) AS pieces_good, SUM(qty_damaged) AS pieces_damaged
                           FROM inv_shipment_lines GROUP BY shipment_id) t ON t.shipment_id = s.id";
}

function inv_shipment_fetch(int $id): array|false {
    if (!inv_shipments_supported() || $id <= 0) return false;
    return db_query(inv_shipment_select_sql() . ' WHERE s.id = :id', [':id' => $id])->fetch();
}

/**
 * Shipments this account may see, newest first (at most 200) — scoped and filtered
 * in SQL BEFORE the LIMIT. $venueIds: null = owner (no scope); an array = a
 * manager's venues, scoped to stores they own or share (an EMPTY list returns []
 * without querying). $statuses (optional, validated against INV_SHIP_STATUS keys)
 * narrows to those statuses. inv_ship_store_allowed() remains the pure rule for
 * checking a single already-fetched row.
 */
function inv_shipments_list(?array $venueIds, array $statuses = []): array {
    if (!inv_shipments_supported()) return [];
    if ($venueIds !== null && !$venueIds) return [];
    $p = [];
    $w = [];
    if ($venueIds !== null) {
        $lit = inv_pg_int_array_literal($venueIds);
        $w[] = "l.kind = 'store' AND (l.venue_id = ANY(CAST(:sv AS int[])) OR l.share_venue_ids && CAST(:ss AS int[]))";
        $p[':sv'] = $lit;
        $p[':ss'] = $lit;   // a placeholder may not be reused in one statement
    }
    $statuses = array_values(array_unique(array_intersect($statuses, array_keys(INV_SHIP_STATUS))));
    if ($statuses) {
        $w[] = 's.status = ANY(CAST(:st AS text[]))';
        $p[':st'] = '{' . implode(',', $statuses) . '}';
    }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    return db_query(inv_shipment_select_sql() . $where . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 200', $p)->fetchAll();
}

/** Shipments still to receive (expected / receiving) for this account. */
function inv_shipments_open(?array $venueIds): array {
    return inv_shipments_list($venueIds, ['expected', 'receiving']);
}

/**
 * A shipment's lines in list order, with their item. $section: null (default) = every
 * line; '' = lines with no section (section IS NULL); else that exact section.
 */
function inv_shipment_lines(int $shipmentId, ?string $section = null): array {
    if (!inv_shipments_supported()) return [];
    $p = [':s' => $shipmentId];
    $w = '';
    if ($section === '') { $w = ' AND sl.section IS NULL'; }
    elseif ($section !== null) { $w = ' AND sl.section = :sec'; $p[':sec'] = $section; }
    return db_query("SELECT sl.*, i.name AS item_name, i.tracking, i.unit_label, i.image_key, i.icon, i.category
                       FROM inv_shipment_lines sl JOIN inv_items i ON i.id = sl.item_id
                      WHERE sl.shipment_id = :s{$w} ORDER BY sl.sort_order, sl.id", $p)->fetchAll();
}

/** [['section','lines','pieces']] in list order. */
function inv_shipment_sections(int $shipmentId): array {
    if (!inv_shipments_supported()) return [];
    return db_query("SELECT COALESCE(section, '') AS section, COUNT(*) AS lines, SUM(qty_expected) AS pieces
                       FROM inv_shipment_lines WHERE shipment_id = :s GROUP BY COALESCE(section, '') ORDER BY MIN(sort_order)",
                    [':s' => $shipmentId])->fetchAll();
}

/** Active stores this account may send a shipment to (Main stock first). */
function inv_ship_target_stores(?array $venueIds): array {
    if (!inv_shipments_supported()) return [];
    $rows = db_query("SELECT * FROM inv_locations WHERE kind = 'store' AND is_active = TRUE ORDER BY is_main DESC, name")->fetchAll();
    return array_values(array_filter($rows, fn($s) => inv_ship_store_allowed($s, $venueIds)));
}

/**
 * Active items indexed by merge key (inv_ship_key(name) — case/spacing/trailing-dot
 * insensitive, the same rule inv_ship_group() groups a workbook by), first by id
 * wins on a collision. ONE query, so inv_shipment_create() matches every group
 * against a consistent snapshot instead of one lookup per group.
 */
function inv_ship_existing_items(): array {
    if (!inv_supported()) return [];
    $rows = db_query("SELECT id, name, tracking, item_type, category, unit_label FROM inv_items WHERE is_active = TRUE ORDER BY id")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $key = inv_ship_key((string)$r['name']);
        if (!isset($out[$key])) {
            $out[$key] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'tracking' => (string)$r['tracking'],
                'item_type' => (string)$r['item_type'], 'category' => (string)$r['category'], 'unit_label' => (string)$r['unit_label']];
        }
    }
    return $out;
}

/** A live shipment already imported from this file with this many lines, or null. */
function inv_ship_find_duplicate(string $filename, int $lineCount): ?int {
    if (!inv_shipments_supported() || $filename === '') return null;
    $id = db_query("SELECT s.id FROM inv_shipments s
                     WHERE s.status <> 'cancelled' AND s.source_filename = :f
                       AND (SELECT COUNT(*) FROM inv_shipment_lines l WHERE l.shipment_id = s.id) = :n
                     ORDER BY s.id LIMIT 1", [':f' => $filename, ':n' => $lineCount])->fetchColumn();
    return $id === false ? null : (int)$id;
}

// ── Writes ──────────────────────────────────────────────────────────────────

/**
 * Create a shipment from a parsed list — NO stock moves. Items are matched by merge
 * key (inv_ship_key() — case/spacing/trailing-dot insensitive, via
 * inv_ship_existing_items()) or created with the group's category, kind (operational |
 * spare | serial) and unit. A group that matches an existing item whose tracking
 * disagrees with the group's kind (serial vs quantity) is refused — never silently
 * retypes an item that already exists. $head: name, supplier, reference, containers,
 * expected_on (Y-m-d or ''), to_location_id (a store), source_filename. $lines: parsed
 * lines; $groups: inv_ship_group()-shaped, with the preview's choices applied — every
 * group's 'lines' must be real indexes into $lines and no index may appear in two
 * groups (a tampered or stale preview is refused, not guessed at). Does NO scoping.
 * Returns the shipment id.
 */
function inv_shipment_create(array $head, array $lines, array $groups, ?int $userId): int {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $name = mb_substr(trim((string)($head['name'] ?? '')), 0, 160);
    if ($name === '') throw new InvRefusal('Give the shipment a name.');
    if (!$lines) throw new InvRefusal('The list has no lines.');
    if (count($lines) > INV_SHIP_MAX_LINES) throw new InvRefusal('That list is too long — split it into smaller files.');
    $store = inv_fetch_location((int)($head['to_location_id'] ?? 0));
    if (!$store || $store['kind'] !== 'store' || !inv_bool($store['is_active'])) throw new InvRefusal('Pick the store it lands in.');
    $exp = trim((string)($head['expected_on'] ?? ''));
    if ($exp !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $exp, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
            throw new InvRefusal('Pick a valid expected date.');
        }
    }
    $opt = fn(string $k, int $max): ?string => ($s = trim((string)($head[$k] ?? ''))) !== '' ? mb_substr($s, 0, $max) : null;

    return inv_tx(function () use ($lines, $groups, $userId, $name, $store, $exp, $opt): int {
        // Every group's 'lines' must be real indexes into $lines, and no index may
        // appear in two groups — a tampered/stale preview is refused, never guessed at.
        $usedLines = [];
        foreach ($groups as $g) {
            foreach ((array)($g['lines'] ?? []) as $i) {
                if (is_int($i)) { /* ok */ }
                elseif (is_string($i) && ctype_digit($i)) { $i = (int)$i; }
                else throw new InvRefusal('The item list is inconsistent — import the file again.');
                if (!array_key_exists($i, $lines) || isset($usedLines[$i])) {
                    throw new InvRefusal('The item list is inconsistent — import the file again.');
                }
                $usedLines[$i] = true;
            }
        }

        $existing = inv_ship_existing_items();
        $itemFor  = [];
        foreach ($groups as $g) {
            $gname = mb_substr(inv_ship_text((string)($g['name'] ?? '')), 0, 160);
            if ($gname === '') throw new InvRefusal('Every item needs a name.');
            $kind  = isset(INV_SHIP_KINDS[$g['kind'] ?? '']) ? (string)$g['kind'] : 'operational';
            $match = $existing[inv_ship_key($gname)] ?? null;
            if ($match) {
                $wantTracking = $kind === 'serial' ? 'serial' : 'qty';
                if ($match['tracking'] !== $wantTracking) {
                    $have = $match['tracking'] === 'serial' ? 'serial number' : 'quantity';
                    throw new InvRefusal("{$gname} already exists and is tracked by {$have} — rename it or choose the same kind.");
                }
                $id = $match['id'];
            } else {
                $id = inv_create_item(['name' => $gname, 'item_type' => $kind === 'spare' ? 'spare' : 'operational',
                    'tracking' => $kind === 'serial' ? 'serial' : 'qty', 'category' => (string)($g['category'] ?? ''),
                    'unit_label' => (string)($g['unit'] ?? 'pcs')]);
            }
            foreach ((array)$g['lines'] as $i) $itemFor[(int)$i] = (int)$id;
        }
        db_query('INSERT INTO inv_shipments (name, supplier, reference, containers, expected_on, to_location_id, source_filename, created_by)
                  VALUES (:n, :s, :r, :c, :e, :l, :f, :u)', [
            ':n' => $name, ':s' => $opt('supplier', 160), ':r' => $opt('reference', 80), ':c' => $opt('containers', 2000),
            ':e' => $exp !== '' ? $exp : null, ':l' => (int)$store['id'], ':f' => $opt('source_filename', 200), ':u' => $userId]);
        $sid = (int) db()->lastInsertId();
        $pos = 0;
        foreach ($lines as $i => $l) {
            if (!isset($itemFor[$i])) throw new InvRefusal('A line was left without an item.');
            $qty = (int)($l['qty'] ?? 0);
            if ($qty < 1 || $qty > INV_SHIP_MAX_QTY) throw new InvRefusal('A line has an impossible quantity.');
            db_query('INSERT INTO inv_shipment_lines (shipment_id, sort_order, section, code, hs_code, description, item_id, qty_expected)
                      VALUES (:s, :o, :sec, :c, :hs, :d, :i, :q)', [
                ':s' => $sid, ':o' => ++$pos,
                ':sec' => ($x = trim((string)($l['section'] ?? ''))) !== '' ? mb_substr($x, 0, 120) : null,
                ':c'   => ($x = trim((string)($l['code'] ?? ''))) !== '' ? mb_substr($x, 0, 40) : null,
                ':hs'  => ($x = trim((string)($l['hs_code'] ?? ''))) !== '' ? mb_substr($x, 0, 20) : null,
                ':d'   => mb_substr((string)$l['description'], 0, 2000), ':i' => $itemFor[$i], ':q' => $qty]);
        }
        return $sid;
    });
}

/**
 * Save a receiving round. $posted: [line id => ['good','damaged','note','over',
 * 'base_good','base_damaged','serials' => […]]] where good/damaged are the line's
 * NEW TOTALS (see inv_ship_receive_plan() for base_good/base_damaged — pages MUST
 * post them). 'serials' is the serial numbers of the PIECES ADDED IN THIS SAVE, in
 * order — one per new piece, blanks allowed, extras beyond the delta are ignored
 * (the delta decides how many are used, never count(serials)). Writes receive moves
 * for what arrived since the last save (a serial item: one unit per piece, with the
 * serials given — refused past 200 units in one save), a written-off "Receiving
 * correction" for a lowered good count, nothing for damaged units. Only a line whose
 * good/damaged actually changed reopens a 'received' shipment — a note-only or
 * photo-only save does not, though it still counts in 'lines'. Does NO scoping.
 * Returns ['lines','received','corrected'].
 */
function inv_shipment_receive(int $shipmentId, array $posted, ?int $userId): array {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $byId = [];
    foreach ($posted as $k => $v) if (is_numeric($k) && (int)$k > 0) $byId[(int)$k] = (array)$v;
    ksort($byId);

    return inv_tx(function () use ($shipmentId, $byId, $userId): array {
        $s = db_query('SELECT id, status, to_location_id FROM inv_shipments WHERE id = :id FOR UPDATE', [':id' => $shipmentId])->fetch();
        if (!$s) throw new InvRefusal('That shipment no longer exists.');
        if ($s['status'] === 'cancelled') throw new InvRefusal('That shipment was cancelled.');
        $store = (int)$s['to_location_id'];

        $lines = [];
        foreach (array_keys($byId) as $lid) {   // id order = the lock order
            $l = db_query('SELECT sl.*, i.tracking, i.name AS item_name, i.currency AS item_currency
                             FROM inv_shipment_lines sl JOIN inv_items i ON i.id = sl.item_id
                            WHERE sl.id = :l AND sl.shipment_id = :s FOR UPDATE OF sl', [':l' => $lid, ':s' => $shipmentId])->fetch();
            if (!$l) throw new InvRefusal('A line does not belong to this shipment.');
            $lines[$lid] = $l;
        }
        $plans = [];
        foreach ($lines as $lid => $l) {
            $p = inv_ship_receive_plan($l, $byId[$lid]);
            if (is_string($p)) throw new InvRefusal($p);
            if (!$p['changed']) continue;
            if ($l['tracking'] === 'serial' && $p['delta'] < 0) {
                throw new InvRefusal("{$l['item_name']}: a registered unit is taken back from its item page, not here.");
            }
            if ($l['tracking'] === 'serial' && $p['delta'] > 200) {
                throw new InvRefusal("{$l['item_name']}: register at most 200 units per save.");
            }
            $plans[$lid] = $p;
        }
        $pairs = [];
        foreach ($plans as $lid => $p) if ($p['delta'] !== 0) $pairs[] = [(int)$lines[$lid]['item_id'], $store];
        inv_lock_balances($pairs);

        $received = 0; $corrected = 0; $qtyChanged = false;
        foreach ($plans as $lid => $p) {
            $l    = $lines[$lid];
            $item = (int)$l['item_id'];
            $unit = ($l['unit_cost'] !== null && ($l['cost_currency'] === null || (string)$l['cost_currency'] === (string)$l['item_currency']))
                    ? (float)$l['unit_cost'] : null;
            if ($p['delta'] > 0 && $l['tracking'] === 'serial') {
                $serials = array_values((array)($byId[$lid]['serials'] ?? []));
                for ($k = 0; $k < $p['delta']; $k++) {
                    inv_asset_create($item, $store, ['serial' => trim((string)($serials[$k] ?? '')), 'condition' => 'new',
                        'purchase_value' => $unit, 'shipment_line_id' => $lid], $userId);
                }
            } elseif ($p['delta'] > 0) {
                inv_move(['item_id' => $item, 'qty' => $p['delta'], 'to' => $store, 'reason' => 'receive', 'unit_value' => $unit,
                          'user_id' => $userId, 'shipment_line_id' => $lid, 'note' => trim('Shipment ' . ($l['code'] ?? ''))]);
            } elseif ($p['delta'] < 0) {
                inv_move(['item_id' => $item, 'qty' => -$p['delta'], 'from' => $store, 'reason' => 'written_off', 'unit_value' => $unit,
                          'user_id' => $userId, 'shipment_line_id' => $lid, 'note' => 'Receiving correction']);
            }
            if ($p['delta'] !== 0 || $p['damaged'] !== (int)$l['qty_damaged']) $qtyChanged = true;
            $received  += max(0, $p['delta']);
            $corrected += max(0, -$p['delta']);
            db_query('UPDATE inv_shipment_lines SET qty_good = :g, qty_damaged = :d, note = :n, updated_by = :u, updated_at = now() WHERE id = :id',
                [':g' => $p['good'], ':d' => $p['damaged'], ':n' => $p['note'] !== '' ? $p['note'] : null, ':u' => $userId, ':id' => $lid]);
        }
        if ($qtyChanged) {
            db_query("UPDATE inv_shipments SET status = 'receiving', received_at = NULL WHERE id = :id AND status IN ('expected', 'received')", [':id' => $shipmentId]);
        }
        return ['lines' => count($plans), 'received' => $received, 'corrected' => $corrected];
    });
}

/** Mark a shipment received (it can still be corrected, which reopens it). */
function inv_shipment_mark_received(int $id): void {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    inv_tx(function () use ($id): void {
        $s = db_query('SELECT status FROM inv_shipments WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$s) throw new InvRefusal('That shipment no longer exists.');
        if ($s['status'] === 'cancelled') throw new InvRefusal('That shipment was cancelled.');
        db_query("UPDATE inv_shipments SET status = 'received', received_at = now() WHERE id = :id AND status <> 'received'", [':id' => $id]);
    });
}

/** Cancel a shipment — only while nothing has been received or marked damaged. */
function inv_shipment_cancel(int $id): void {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    inv_tx(function () use ($id): void {
        $s = db_query('SELECT status FROM inv_shipments WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$s) throw new InvRefusal('That shipment no longer exists.');
        $touched = (int) db_query('SELECT COALESCE(SUM(qty_good + qty_damaged), 0) FROM inv_shipment_lines WHERE shipment_id = :id', [':id' => $id])->fetchColumn();
        if ($touched > 0 || !in_array($s['status'], ['expected', 'cancelled'], true)) {
            throw new InvRefusal('Something has already been received — correct the lines instead of cancelling.');
        }
        db_query("UPDATE inv_shipments SET status = 'cancelled' WHERE id = :id", [':id' => $id]);
    });
}

/** Record a line's damage photo; returns the previous key (for the caller to delete) or null. */
function inv_shipment_set_photo(int $shipmentId, int $lineId, string $key): ?string {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $row = db_query('SELECT sl.photo_key, s.status FROM inv_shipment_lines sl JOIN inv_shipments s ON s.id = sl.shipment_id
                       WHERE sl.id = :l AND sl.shipment_id = :s', [':l' => $lineId, ':s' => $shipmentId])->fetch();
    if (!$row) throw new InvRefusal('A line does not belong to this shipment.');
    if ($row['status'] === 'cancelled') throw new InvRefusal('That shipment was cancelled.');
    db_query('UPDATE inv_shipment_lines SET photo_key = :k WHERE id = :l', [':k' => $key, ':l' => $lineId]);
    $old = $row['photo_key'];
    return $old !== null && $old !== '' ? (string)$old : null;
}

/** CSV rows (header first) for the shipment page's export — PURE. */
function inv_shipment_csv_rows(array $lines): array {
    $out = [['Section', 'Code', 'HS code', 'Description', 'Item', 'Expected', 'Good', 'Damaged', 'Short', 'Note']];
    foreach ($lines as $l) {
        $out[] = [(string)$l['section'], (string)$l['code'], (string)$l['hs_code'], (string)$l['description'], (string)$l['item_name'],
                  (int)$l['qty_expected'], (int)$l['qty_good'], (int)$l['qty_damaged'], inv_ship_short($l), (string)($l['note'] ?? '')];
    }
    return $out;
}
