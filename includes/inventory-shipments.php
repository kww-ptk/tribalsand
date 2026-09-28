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
 * '' = keep), note (absent = keep), over ('1' = "more than ordered" confirmed).
 * Returns ['good','damaged','delta','note','changed'] or the refusal text.
 */
function inv_ship_receive_plan(array $line, array $p): array|string {
    $label = trim(((string)($line['code'] ?? '')) . ' ' . mb_strimwidth((string)$line['description'], 0, 60, '…'));
    $num = function (string $k, int $cur) use ($p): ?int {
        $v = trim((string)($p[$k] ?? ''));
        if ($v === '') return $cur;
        return (ctype_digit($v) && strlen($v) <= 6) ? (int)$v : null;
    };
    $good = $num('good', (int)$line['qty_good']);
    $dmg  = $num('damaged', (int)$line['qty_damaged']);
    if ($good === null || $dmg === null) return "{$label}: enter whole numbers.";
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

/** Shipments this account may see, newest first (at most 200). */
function inv_shipments_list(?array $venueIds): array {
    if (!inv_shipments_supported()) return [];
    $rows = db_query(inv_shipment_select_sql() . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 200')->fetchAll();
    return array_values(array_filter($rows, fn($s) => inv_ship_store_allowed(inv_shipment_store_row($s), $venueIds)));
}

/** Shipments still to receive (expected / receiving) for this account. */
function inv_shipments_open(?array $venueIds): array {
    return array_values(array_filter(inv_shipments_list($venueIds), fn($s) => in_array($s['status'], ['expected', 'receiving'], true)));
}

/** A shipment's lines in list order (optionally one section), with their item. */
function inv_shipment_lines(int $shipmentId, string $section = ''): array {
    if (!inv_shipments_supported()) return [];
    $p = [':s' => $shipmentId];
    $w = '';
    if ($section !== '') { $w = ' AND sl.section = :sec'; $p[':sec'] = $section; }
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
 * Create a shipment from a parsed list — NO stock moves. Items are matched by name
 * (an active item, case-insensitive) or created with the group's category, kind
 * (operational | spare | serial) and unit. $head: name, supplier, reference,
 * containers, expected_on (Y-m-d or ''), to_location_id (a store),
 * source_filename. $lines: parsed lines; $groups: inv_ship_group()-shaped, with the
 * preview's choices applied. Does NO scoping. Returns the shipment id.
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
    if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) throw new InvRefusal('Pick a valid expected date.');
    $opt = fn(string $k, int $max): ?string => ($s = trim((string)($head[$k] ?? ''))) !== '' ? mb_substr($s, 0, $max) : null;

    return inv_tx(function () use ($lines, $groups, $userId, $name, $store, $exp, $opt): int {
        $itemFor = [];
        foreach ($groups as $g) {
            $gname = mb_substr(inv_ship_text((string)($g['name'] ?? '')), 0, 160);
            if ($gname === '') throw new InvRefusal('Every item needs a name.');
            $kind = isset(INV_SHIP_KINDS[$g['kind'] ?? '']) ? (string)$g['kind'] : 'operational';
            $id = db_query('SELECT id FROM inv_items WHERE is_active = TRUE AND lower(name) = lower(:n) ORDER BY id LIMIT 1', [':n' => $gname])->fetchColumn();
            if ($id === false) {
                $id = inv_create_item(['name' => $gname, 'item_type' => $kind === 'spare' ? 'spare' : 'operational',
                    'tracking' => $kind === 'serial' ? 'serial' : 'qty', 'category' => (string)($g['category'] ?? ''),
                    'unit_label' => (string)($g['unit'] ?? 'pcs')]);
            }
            foreach ((array)$g['lines'] as $i) $itemFor[$i] = (int)$id;
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
                ':d'   => (string)$l['description'], ':i' => $itemFor[$i], ':q' => $qty]);
        }
        return $sid;
    });
}

/**
 * Save a receiving round. $posted: [line id => ['good','damaged','note','over','serials' => […]]]
 * where good/damaged are the line's NEW TOTALS. Writes receive moves for what
 * arrived since the last save (a serial item: one unit per piece, with the serials
 * given), a written-off "Receiving correction" for a lowered good count, nothing
 * for damaged units. Does NO scoping. Returns ['lines','received','corrected'].
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
            $l = db_query('SELECT sl.*, i.tracking, i.name AS item_name FROM inv_shipment_lines sl JOIN inv_items i ON i.id = sl.item_id
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
            $plans[$lid] = $p;
        }
        $pairs = [];
        foreach ($plans as $lid => $p) if ($p['delta'] !== 0) $pairs[] = [(int)$lines[$lid]['item_id'], $store];
        inv_lock_balances($pairs);

        $received = 0; $corrected = 0;
        foreach ($plans as $lid => $p) {
            $l    = $lines[$lid];
            $item = (int)$l['item_id'];
            $unit = $l['unit_cost'] !== null ? (float)$l['unit_cost'] : null;
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
            $received  += max(0, $p['delta']);
            $corrected += max(0, -$p['delta']);
            db_query('UPDATE inv_shipment_lines SET qty_good = :g, qty_damaged = :d, note = :n, updated_by = :u, updated_at = now() WHERE id = :id',
                [':g' => $p['good'], ':d' => $p['damaged'], ':n' => $p['note'] !== '' ? $p['note'] : null, ':u' => $userId, ':id' => $lid]);
        }
        if ($plans) {
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
    $old = db_query('SELECT photo_key FROM inv_shipment_lines WHERE id = :l AND shipment_id = :s', [':l' => $lineId, ':s' => $shipmentId])->fetchColumn();
    if ($old === false) throw new InvRefusal('A line does not belong to this shipment.');
    db_query('UPDATE inv_shipment_lines SET photo_key = :k WHERE id = :l', [':k' => $key, ':l' => $lineId]);
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
