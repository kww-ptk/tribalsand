<?php
declare(strict_types=1);
/**
 * Inventory & Assets — ONE inventory database under the POS, the properties, the
 * staff and central stock. Spec: docs/superpowers/specs/2026-09-27-inventory-assets-design.md
 * Migration: db/migrations/add_inventory.sql. Test: php tests/inventory_logic.php
 *
 * Load-bearing rules:
 *   • inv_move() is the ONLY writer of inv_moves and inv_balances. It locks
 *     balance rows in the global (item_id, location_id) order — compound actions
 *     pre-lock every pair with inv_lock_balances() — then inv_assets rows, refuses
 *     going below zero (except a POS listing flagged allow_negative), snapshots
 *     value, and updates both balances in one transaction. Compound actions
 *     (replace, restock, count resolution) are several inv_move() calls inside
 *     ONE inv_tx().
 *   • Counting never moves stock. A manager's resolution does, and only while the
 *     live balance still equals what the counter was shown.
 *   • Money is never summed across currencies (inv_sum_by_currency()).
 *   • Every surface checks inv_supported() first (catalog lookup — safe in a tx).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-support.php';   // inv_supported(), InvRefusal, inv_tx()

const INV_TYPES = [
    'sellable'    => 'Sellable product',
    'operational' => 'Property asset',
    'employee'    => 'Employee asset',
    'consignment' => 'Consignment product',
    'spare'       => 'Stock / spare',
];
const INV_LOCATION_KINDS = ['store' => 'Main stock', 'property' => 'Property', 'area' => 'Area', 'outlet' => 'Outlet', 'person' => 'Team member'];
const INV_REASONS_IN     = ['receive', 'found', 'opening', 'void'];                   // from = NULL
const INV_REASONS_OUT    = ['sale', 'broken', 'missing', 'stolen', 'written_off'];    // to   = NULL
const INV_REASONS_MOVE   = ['transfer', 'assign', 'return', 'replaced'];              // both ends
const INV_LOSS_REASONS   = ['broken', 'missing', 'stolen', 'written_off'];
const INV_RESOLUTIONS    = ['missing', 'broken', 'stolen', 'found', 'recount', 'accepted'];
const INV_CONDITIONS     = ['new', 'good', 'fair', 'poor'];
const INV_MAX_QTY        = 100000;
const INV_DEFAULT_CURRENCY = 'KES';

// ── Pure rules ──────────────────────────────────────────────────────────────

/** Postgres/PDO boolean → PHP bool ('t', true, 1, '1', 'true'). */
function inv_bool(mixed $v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/** A move request with ids as int|null (0/'' → null), qty as int (bad → 0), value rounded. */
function inv_normalize_move(array $m): array {
    $id  = fn($v) => ($v === null || $v === '' || !is_numeric($v) || (int)$v <= 0) ? null : (int)$v;
    $qty = $m['qty'] ?? 0;
    $qty = is_int($qty) ? $qty : (ctype_digit((string)$qty) ? (int)$qty : 0);
    $uv  = $m['unit_value'] ?? null;
    return [
        'item_id'        => (int)($id($m['item_id'] ?? null) ?? 0),
        'qty'            => $qty,
        'from'           => $id($m['from'] ?? null),
        'to'             => $id($m['to'] ?? null),
        'reason'         => (string)($m['reason'] ?? ''),
        'user_id'        => $id($m['user_id'] ?? null),
        'note'           => mb_substr(trim((string)($m['note'] ?? '')), 0, 500),
        'asset_id'       => $id($m['asset_id'] ?? null),
        'pos_sale_id'    => $id($m['pos_sale_id'] ?? null),
        'count_line_id'  => $id($m['count_line_id'] ?? null),
        'unit_value'     => ($uv !== null && $uv !== '' && is_numeric($uv)) ? round((float)$uv, 2) : null,
        'terms'          => (array)($m['terms'] ?? []),
        'allow_negative' => inv_bool($m['allow_negative'] ?? false),
    ];
}

/** Why a (normalized) move is malformed, or null — PURE. Stock levels are checked later, under lock. */
function inv_move_error(array $m): ?string {
    if ((int)($m['item_id'] ?? 0) <= 0) return 'Pick an item.';
    $qty = $m['qty'] ?? 0;
    if (!is_int($qty) || $qty < 1) return 'Enter a quantity of at least 1.';
    if ($qty > INV_MAX_QTY) return 'That quantity is too large.';
    $from = $m['from'] ?? null;
    $to   = $m['to'] ?? null;
    $r    = (string)($m['reason'] ?? '');
    if (in_array($r, INV_REASONS_IN, true)) {
        if ($from !== null || $to === null) return 'Stock coming in needs a destination and no source.';
    } elseif (in_array($r, INV_REASONS_OUT, true)) {
        if ($from === null || $to !== null) return 'Stock leaving needs a source and no destination.';
    } elseif (in_array($r, INV_REASONS_MOVE, true)) {
        if ($from === null || $to === null) return 'Pick where it comes from and where it goes.';
        if ($from === $to) return 'Pick two different locations.';
    } else {
        return 'Unknown movement type.';
    }
    if (($m['asset_id'] ?? null) !== null && $qty !== 1) return 'A serial-tracked unit moves one at a time.';
    if (($m['unit_value'] ?? null) !== null && $m['unit_value'] < 0) return 'Value cannot be negative.';
    if (($m['unit_value'] ?? null) !== null && $m['unit_value'] > 9999999999.99) return 'That value is too large.';
    return null;
}

/** The reason a location-to-location move is recorded under — PURE. */
function inv_transfer_reason(string $fromKind, string $toKind): string {
    if ($toKind === 'person') return 'assign';
    if ($fromKind === 'person') return 'return';
    return 'transfer';
}

/** The refusal shown when a location does not hold enough — PURE. */
function inv_shortfall_message(string $name, int $have, string $where): string {
    return $have <= 0 ? "No {$name} left at {$where}." : "Only {$have} × {$name} at {$where}.";
}

/**
 * May an account with $venueIds (null = owner, all) make a move between these
 * two location rows (either may be null for in/out moves)? — PURE.
 * Shared locations (venue_id NULL: Main stock, venue-less outlets — never a
 * person) are open to a manager only as the OTHER end of a move into/out of one
 * of their own properties; every non-shared end must be theirs.
 */
function inv_move_in_scope(?array $from, ?array $to, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $owned = false;
    foreach ([$from, $to] as $loc) {
        if ($loc === null) continue;
        $v = isset($loc['venue_id']) && $loc['venue_id'] !== null ? (int)$loc['venue_id'] : null;
        if ($v === null) {
            if (($loc['kind'] ?? '') === 'person') return false;   // a venue-less team member's items are owner business
            continue;                                              // Main stock / a shared outlet
        }
        if (!in_array($v, array_map('intval', $venueIds), true)) return false;
        $owned = true;
    }
    return $owned;
}

/**
 * The move a count resolution implies — PURE.
 * Returns ['reason','qty','dir'=>'in'|'out'], null (no move), or an error string.
 */
function inv_resolution_move(string $resolution, int $expected, int $counted): array|string|null {
    if (!in_array($resolution, INV_RESOLUTIONS, true)) return 'Pick what happened.';
    $gap = $counted - $expected;
    if ($resolution === 'recount') return null;
    if ($resolution === 'accepted') return $gap === 0 ? null : 'A gap cannot be accepted as it is — pick what happened, or recount.';
    if ($resolution === 'found') return $gap > 0 ? ['reason' => 'found', 'qty' => $gap, 'dir' => 'in'] : '"Found" only applies when more were counted than expected.';
    return $gap < 0 ? ['reason' => $resolution, 'qty' => -$gap, 'dir' => 'out'] : 'Nothing is short on this line.';
}

/** Nairobi-local date the next count is due, or null (manual only / never counted) — PURE. */
function inv_count_due_ymd(?string $lastCountedAt, ?int $everyDays): ?string {
    if (!$everyDays || $everyDays < 1 || !$lastCountedAt) return null;
    $d = DateTime::createFromFormat('!Y-m-d', substr($lastCountedAt, 0, 10));
    return $d ? $d->modify("+{$everyDays} days")->format('Y-m-d') : null;
}

/** 'manual' | 'ok' | 'due' | 'overdue' for a location's count schedule — PURE. */
function inv_count_status(?string $lastCountedAt, ?int $everyDays, string $todayYmd): string {
    if (!$everyDays) return 'manual';
    if (!$lastCountedAt) return 'due';
    $due = inv_count_due_ymd($lastCountedAt, $everyDays);
    if ($due === null) return 'due';
    return $todayYmd > $due ? 'overdue' : ($todayYmd === $due ? 'due' : 'ok');
}

/** Sum a value column per currency (never across) — PURE. ['KES' => 150.0, 'USD' => 20.0] */
function inv_sum_by_currency(array $rows, string $valueKey = 'value', string $currencyKey = 'currency'): array {
    $out = [];
    foreach ($rows as $r) {
        $v = $r[$valueKey] ?? null;
        if ($v === null || $v === '') continue;
        $c = strtoupper((string)(($r[$currencyKey] ?? '') ?: INV_DEFAULT_CURRENCY));
        $out[$c] = round(($out[$c] ?? 0) + (float)$v, 2);
    }
    ksort($out);
    return $out;
}

/** [item_id => qty needed to reach par] for balance rows (item_id, qty, par_qty) — PURE. */
function inv_restock_plan(array $rows): array {
    $plan = [];
    foreach ($rows as $r) {
        if (!isset($r['par_qty']) || $r['par_qty'] === null || $r['par_qty'] === '') continue;
        $need = (int)$r['par_qty'] - max(0, (int)$r['qty']);
        if ($need > 0) $plan[(int)$r['item_id']] = $need;
    }
    ksort($plan);
    return $plan;
}

// ── Reads ───────────────────────────────────────────────────────────────────

function inv_fetch_item(int $id): array|false {
    if (!inv_supported() || $id <= 0) return false;
    return db_query('SELECT * FROM inv_items WHERE id = :id', [':id' => $id])->fetch();
}

function inv_fetch_location(int $id): array|false {
    if (!inv_supported() || $id <= 0) return false;
    return db_query('SELECT * FROM inv_locations WHERE id = :id', [':id' => $id])->fetch();
}

/** Current quantity of an item at a location (0 when there is no balance row). */
function inv_balance(int $itemId, int $locationId): int {
    if (!inv_supported()) return 0;
    $q = db_query('SELECT qty FROM inv_balances WHERE item_id = :i AND location_id = :l', [':i' => $itemId, ':l' => $locationId])->fetchColumn();
    return $q === false ? 0 : (int)$q;
}

/** Lock (creating if needed) an item's balance row at a location; returns its qty. Call inside inv_tx(). */
function inv_balance_lock(int $itemId, int $locationId): int {
    db_query('INSERT INTO inv_balances (item_id, location_id, qty) VALUES (:i, :l, 0) ON CONFLICT (item_id, location_id) DO NOTHING',
        [':i' => $itemId, ':l' => $locationId]);
    return (int) db_query('SELECT qty FROM inv_balances WHERE item_id = :i AND location_id = :l FOR UPDATE',
        [':i' => $itemId, ':l' => $locationId])->fetchColumn();
}

/**
 * Lock several balance rows in ONE global order — (item_id, location_id) — before a
 * compound action (replace, restock, a POS sale) makes several moves. inv_move()
 * locks its own pair in that same order, and a row lock already held is re-entrant,
 * so every multi-move transaction acquires locks consistently: no deadlock cycles.
 * $pairs = [[item_id, location_id], …] (duplicates and empty ids ignored). Call inside inv_tx().
 */
function inv_lock_balances(array $pairs): void {
    $keys = [];
    foreach ($pairs as $p) {
        $i = (int)($p[0] ?? 0); $l = (int)($p[1] ?? 0);
        if ($i > 0 && $l > 0) $keys["{$i}:{$l}"] = [$i, $l];
    }
    $list = array_values($keys);
    usort($list, fn(array $a, array $b): int => $a <=> $b);
    foreach ($list as [$i, $l]) inv_balance_lock($i, $l);
}

// ── Locations (created on demand, once) ─────────────────────────────────────

/**
 * Bring a location's name/owning venue up to date — writes (and so locks) the row
 * ONLY when something changed. The ensure-functions below run inside the POS sale
 * transaction, where an unconditional upsert would serialise every sale at an outlet.
 */
function inv_location_touch(array $row, string $name, ?int $venueId): void {
    $name = mb_substr($name, 0, 120);
    $cur  = $row['venue_id'] !== null ? (int)$row['venue_id'] : null;
    if ((string)$row['name'] === $name && $cur === $venueId) return;
    db_query('UPDATE inv_locations SET name = :n, venue_id = :v WHERE id = :id', [':n' => $name, ':v' => $venueId, ':id' => (int)$row['id']]);
}

/** The one Main stock location. */
function inv_store_location_id(): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    db_query("INSERT INTO inv_locations (kind, name) VALUES ('store', 'Main stock') ON CONFLICT (kind) WHERE kind = 'store' DO NOTHING");
    return (int) db_query("SELECT id FROM inv_locations WHERE kind = 'store'")->fetchColumn();
}

/** A property's location (named after the venue). */
function inv_property_location_id(int $venueId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $name = db_query('SELECT name FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn();
    if ($name === false) throw new InvRefusal('That property does not exist.');
    $row = db_query("SELECT id, name, venue_id FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $venueId])->fetch();
    if ($row) { inv_location_touch($row, (string)$name, $venueId); return (int)$row['id']; }
    db_query("INSERT INTO inv_locations (kind, name, venue_id) VALUES ('property', :n, :v)
              ON CONFLICT (venue_id) WHERE kind = 'property' DO NOTHING",
        [':n' => mb_substr((string)$name, 0, 120), ':v' => $venueId]);
    return (int) db_query("SELECT id FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $venueId])->fetchColumn();
}

/** A POS outlet's shelf. Owning venue = the outlet's venue (NULL = shared). */
function inv_outlet_location_id(int $outletId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $o = db_query('SELECT name, venue_id FROM pos_outlets WHERE id = :o', [':o' => $outletId])->fetch();
    if (!$o) throw new InvRefusal('That outlet does not exist.');
    $venue = $o['venue_id'] !== null ? (int)$o['venue_id'] : null;
    $row = db_query('SELECT id, name, venue_id FROM inv_locations WHERE pos_outlet_id = :o', [':o' => $outletId])->fetch();
    // The location follows its outlet's name and venue (the owning venue must never go stale).
    if ($row) { inv_location_touch($row, (string)$o['name'], $venue); return (int)$row['id']; }
    db_query("INSERT INTO inv_locations (kind, name, pos_outlet_id, venue_id) VALUES ('outlet', :n, :o, :v)
              ON CONFLICT (pos_outlet_id) DO NOTHING", [':n' => mb_substr((string)$o['name'], 0, 120), ':o' => $outletId, ':v' => $venue]);
    return (int) db_query('SELECT id FROM inv_locations WHERE pos_outlet_id = :o', [':o' => $outletId])->fetchColumn();
}

/** A team member's location — "assigned to Jane" means "at Jane's location". Owning venue = their home venue. */
function inv_person_location_id(int $hrStaffId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $s = db_query('SELECT full_name, venue_id FROM hr_staff WHERE id = :s', [':s' => $hrStaffId])->fetch();
    if (!$s) throw new InvRefusal('That team member does not exist.');
    $venue = $s['venue_id'] !== null ? (int)$s['venue_id'] : null;
    $row = db_query('SELECT id, name, venue_id FROM inv_locations WHERE hr_staff_id = :s', [':s' => $hrStaffId])->fetch();
    if ($row) { inv_location_touch($row, (string)$s['full_name'], $venue); return (int)$row['id']; }
    db_query("INSERT INTO inv_locations (kind, name, hr_staff_id, venue_id) VALUES ('person', :n, :s, :v)
              ON CONFLICT (hr_staff_id) DO NOTHING", [':n' => mb_substr((string)$s['full_name'], 0, 120), ':s' => $hrStaffId, ':v' => $venue]);
    return (int) db_query('SELECT id FROM inv_locations WHERE hr_staff_id = :s', [':s' => $hrStaffId])->fetchColumn();
}

/**
 * Re-copy name + owning venue onto every outlet and person location whose source
 * record changed. Called after an outlet or a staff record is saved, so scope and
 * (later) accounting never follow a stale venue.
 */
function inv_refresh_location_owners(): void {
    if (!inv_supported()) return;
    db_query("UPDATE inv_locations l SET name = LEFT(o.name, 120), venue_id = o.venue_id FROM pos_outlets o
               WHERE l.pos_outlet_id = o.id AND (l.name IS DISTINCT FROM LEFT(o.name, 120) OR l.venue_id IS DISTINCT FROM o.venue_id)");
    db_query("UPDATE inv_locations l SET name = LEFT(s.full_name, 120), venue_id = s.venue_id FROM hr_staff s
               WHERE l.hr_staff_id = s.id AND (l.name IS DISTINCT FROM LEFT(s.full_name, 120) OR l.venue_id IS DISTINCT FROM s.venue_id)");
    db_query("UPDATE inv_locations l SET name = LEFT(v.name, 120) FROM venues v
               WHERE l.kind = 'property' AND l.venue_id = v.id AND l.name IS DISTINCT FROM LEFT(v.name, 120)");
}

/**
 * Units (absolute) still held at the locations linked to an outlet, a staff
 * member or a venue — a delete of that record must be refused while this is > 0,
 * or its stock would sit at an orphaned (or, for a venue, "shared") location.
 * $link: 'pos_outlet_id' | 'hr_staff_id' | 'venue_id'.
 */
function inv_linked_stock_count(string $link, int $id): int {
    if (!inv_supported()) return 0;
    if (!in_array($link, ['pos_outlet_id', 'hr_staff_id', 'venue_id'], true)) throw new InvalidArgumentException('bad link column');
    return (int) db_query("SELECT COALESCE(SUM(ABS(b.qty)), 0) FROM inv_balances b JOIN inv_locations l ON l.id = b.location_id
                            WHERE l.{$link} = :id AND b.qty <> 0", [':id' => $id])->fetchColumn();
}

// ── Items ───────────────────────────────────────────────────────────────────

/**
 * Create a catalogue item. $v: name, item_type, and optionally category, sku,
 * image_key, icon, tracking ('qty'|'serial'), unit_label, replacement_value,
 * currency, consignor_id, low_stock_at. Returns the new id.
 */
function inv_create_item(array $v): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $name = trim((string)($v['name'] ?? ''));
    if ($name === '') throw new InvRefusal('Give the item a name.');
    $type = (string)($v['item_type'] ?? 'operational');
    if (!isset(INV_TYPES[$type])) throw new InvRefusal('Pick an item type.');
    $val = $v['replacement_value'] ?? null;
    if ($val !== null && $val !== '' && (!is_numeric($val) || (float)$val < 0)) throw new InvRefusal('Replacement value must be zero or more.');
    if ($val !== null && $val !== '' && (float)$val > 9999999999.99) throw new InvRefusal('That replacement value is too large.');
    $cur = strtoupper(trim((string)($v['currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = INV_DEFAULT_CURRENCY;
    $opt = fn(string $k, int $max) => ($s = trim((string)($v[$k] ?? ''))) !== '' ? mb_substr($s, 0, $max) : null;
    $low = $v['low_stock_at'] ?? null;
    $consignorId = !empty($v['consignor_id']) ? (int)$v['consignor_id'] : null;
    if ($consignorId !== null && !db_query('SELECT 1 FROM pos_consignors WHERE id = :c', [':c' => $consignorId])->fetchColumn()) {
        throw new InvRefusal('That supplier does not exist.');
    }
    db_query('INSERT INTO inv_items (name, item_type, category, sku, image_key, icon, tracking, unit_label,
                                     replacement_value, currency, consignor_id, low_stock_at)
              VALUES (:n, :t, :c, :s, :img, :ic, :tr, :u, :v, :cur, :cs, :low)', [
        ':n'   => mb_substr($name, 0, 160),
        ':t'   => $type,
        ':c'   => $opt('category', 60),
        ':s'   => $opt('sku', 60),
        ':img' => !empty($v['image_key']) ? (string)$v['image_key'] : null,
        ':ic'  => $opt('icon', 40),
        ':tr'  => ($v['tracking'] ?? 'qty') === 'serial' ? 'serial' : 'qty',
        ':u'   => $opt('unit_label', 20) ?? 'pcs',
        ':v'   => ($val === null || $val === '') ? null : round((float)$val, 2),
        ':cur' => $cur,
        ':cs'  => $consignorId,
        ':low' => ($low === null || $low === '') ? null : min(INV_MAX_QTY, max(0, (int)$low)),
    ]);
    return (int) db()->lastInsertId();
}

// ── The ONE write path ──────────────────────────────────────────────────────

/**
 * Record one movement and update the balances. The only writer of inv_moves and
 * inv_balances. $m keys:
 *   item_id, qty, reason, from (location id|null), to (location id|null),
 *   user_id?, note?, asset_id? (serial unit), pos_sale_id?, count_line_id?,
 *   unit_value? (defaults to the item's replacement_value),
 *   terms? ['consignor_id','consign_pct','consignor_cost'] (a consignment delivery),
 *   allow_negative? (only a POS listing flagged allow_negative passes true).
 * Returns the new move id. Throws InvRefusal to refuse.
 * Does NO venue scoping — the caller must check inv_move_in_scope() (spec §3.8).
 */
function inv_move(array $m): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $n = inv_normalize_move($m);
    if ($err = inv_move_error($n)) throw new InvRefusal($err);
    return inv_tx(fn(): int => inv_move_tx($n));
}

/**
 * Body of inv_move(), inside the transaction.
 * @internal Call inv_move(), which normalizes, validates and opens the transaction.
 */
function inv_move_tx(array $n): int {
    $item = db_query('SELECT id, name, tracking, replacement_value, currency FROM inv_items WHERE id = :i', [':i' => $n['item_id']])->fetch();
    if (!$item) throw new InvRefusal('That item no longer exists.');
    $from = $n['from'];
    $to   = $n['to'];
    $qty  = $n['qty'];

    // Lock both balance rows in location-id order, so two moves never deadlock.
    $locs = array_values(array_filter([$from, $to], fn($l) => $l !== null));
    sort($locs);
    $names = [];
    $have  = [];
    foreach ($locs as $l) {
        $loc = db_query('SELECT id, name, is_active FROM inv_locations WHERE id = :l', [':l' => $l])->fetch();
        if (!$loc) throw new InvRefusal('That location no longer exists.');
        if ($l === $to && !inv_bool($loc['is_active'])) throw new InvRefusal("{$loc['name']} is closed — reopen it before moving stock in.");
        $names[$l] = (string)$loc['name'];
        $have[$l]  = inv_balance_lock($n['item_id'], $l);
    }
    if ($from !== null && !$n['allow_negative'] && $have[$from] < $qty) {
        throw new InvRefusal(inv_shortfall_message((string)$item['name'], $have[$from], $names[$from]));
    }

    // Serial units: name the unit, move it from where it actually is.
    $isSerial = $item['tracking'] === 'serial';
    if ($isSerial && $n['asset_id'] === null) throw new InvRefusal("Pick which {$item['name']} — it is tracked by serial number.");
    if (!$isSerial && $n['asset_id'] !== null) throw new InvRefusal("{$item['name']} is not tracked by serial number.");
    $assetMoved = false;
    if ($isSerial) {
        $a = db_query('SELECT id, status, location_id FROM inv_assets WHERE id = :a AND item_id = :i FOR UPDATE',
            [':a' => $n['asset_id'], ':i' => $n['item_id']])->fetch();
        if (!$a) throw new InvRefusal('That unit does not belong to this item.');
        $aLoc = $a['location_id'] !== null ? (int)$a['location_id'] : null;
        if ($from !== null) {
            if ($a['status'] !== 'active' || $aLoc !== $from) throw new InvRefusal("That unit is not at {$names[$from]}.");
        } else {
            $assetMoved = (bool) db_query('SELECT 1 FROM inv_moves WHERE asset_id = :a LIMIT 1', [':a' => $n['asset_id']])->fetchColumn();
            $ok = match ($n['reason']) {
                'found'              => $a['status'] === 'written_off',
                'void'               => $a['status'] === 'sold',
                'receive', 'opening' => !$assetMoved,
                default              => false,
            };
            if (!$ok) throw new InvRefusal(match ($n['reason']) {
                'found'  => 'Only a unit that was written off can be found again.',
                'void'   => 'Only a sold unit can come back through a void.',
                default  => 'That unit is already registered — move it, or report it found.',
            });
        }
    }

    $unit = $n['unit_value'];
    if ($unit === null && $isSerial && $from === null && $assetMoved) {
        $last = db_query('SELECT unit_value FROM inv_moves WHERE asset_id = :a ORDER BY id DESC LIMIT 1', [':a' => $n['asset_id']])->fetchColumn();
        $unit = ($last !== false && $last !== null) ? (float)$last : null;
    }
    $unit  = $unit ?? ($item['replacement_value'] !== null ? (float)$item['replacement_value'] : null);
    $terms = $n['terms'];
    if (!empty($terms['consignor_id'])) {
        if ($n['reason'] !== 'receive') throw new InvRefusal('Consignment terms only apply to a delivery.');
        if (!db_query('SELECT 1 FROM pos_consignors WHERE id = :c', [':c' => (int)$terms['consignor_id']])->fetchColumn()) {
            throw new InvRefusal('That supplier does not exist.');
        }
    }
    db_query(
        'INSERT INTO inv_moves (item_id, qty, from_location_id, to_location_id, reason, unit_value, value, currency,
                                asset_id, pos_sale_id, count_line_id, consignor_id, consign_pct, consignor_cost, note, admin_user_id)
         VALUES (:i, :q, :f, :t, :r, :uv, :v, :cur, :a, :s, :cl, :ci, :cp, :cc, :n, :u)',
        [':i' => $n['item_id'], ':q' => $qty, ':f' => $from, ':t' => $to, ':r' => $n['reason'],
         ':uv' => $unit, ':v' => $unit === null ? null : round($unit * $qty, 2), ':cur' => (string)$item['currency'],
         ':a' => $n['asset_id'], ':s' => $n['pos_sale_id'], ':cl' => $n['count_line_id'],
         ':ci' => !empty($terms['consignor_id']) ? (int)$terms['consignor_id'] : null,
         ':cp' => isset($terms['consign_pct']) && $terms['consign_pct'] !== null ? (float)$terms['consign_pct'] : null,
         ':cc' => isset($terms['consignor_cost']) && $terms['consignor_cost'] !== null ? (float)$terms['consignor_cost'] : null,
         ':n' => $n['note'] !== '' ? $n['note'] : null, ':u' => $n['user_id']]
    );
    $moveId = (int) db()->lastInsertId();

    if ($from !== null) db_query('UPDATE inv_balances SET qty = qty - :q WHERE item_id = :i AND location_id = :l', [':q' => $qty, ':i' => $n['item_id'], ':l' => $from]);
    if ($to !== null)   db_query('UPDATE inv_balances SET qty = qty + :q WHERE item_id = :i AND location_id = :l', [':q' => $qty, ':i' => $n['item_id'], ':l' => $to]);
    if ($isSerial) {
        if ($to !== null) {
            db_query("UPDATE inv_assets SET status = 'active', location_id = :l WHERE id = :a", [':l' => $to, ':a' => $n['asset_id']]);
        } else {
            db_query('UPDATE inv_assets SET status = :s, location_id = NULL WHERE id = :a',
                [':s' => $n['reason'] === 'sale' ? 'sold' : 'written_off', ':a' => $n['asset_id']]);
        }
    }
    return $moveId;
}

// ── Everyday actions (each is one or more inv_move() calls) ─────────────────

/**
 * Move stock between two locations; recorded as transfer / assign / return by the location kinds.
 * Does NO venue scoping — the caller must check inv_move_in_scope() (spec §3.8).
 */
function inv_transfer(int $itemId, int $qty, int $fromId, int $toId, ?int $userId, string $note = '', ?int $assetId = null): int {
    $from = inv_fetch_location($fromId);
    $to   = inv_fetch_location($toId);
    if (!$from || !$to) throw new InvRefusal('Pick where it comes from and where it goes.');
    return inv_move(['item_id' => $itemId, 'qty' => $qty, 'from' => $fromId, 'to' => $toId,
                     'reason' => inv_transfer_reason((string)$from['kind'], (string)$to['kind']),
                     'user_id' => $userId, 'note' => $note, 'asset_id' => $assetId]);
}

/**
 * Record stock lost from a location: broken, missing, stolen or written off (value snapshotted).
 * Does NO venue scoping — the caller must check inv_move_in_scope() (spec §3.8).
 */
function inv_report_loss(int $itemId, int $qty, int $fromId, string $reason, ?int $userId, string $note = '', ?int $assetId = null): int {
    if (!in_array($reason, INV_LOSS_REASONS, true)) throw new InvRefusal('Pick what happened: broken, missing, stolen or written off.');
    return inv_move(['item_id' => $itemId, 'qty' => $qty, 'from' => $fromId, 'reason' => $reason,
                     'user_id' => $userId, 'note' => $note, 'asset_id' => $assetId]);
}

/**
 * Register a serial-tracked unit and receive it into a location. $f: serial, tag,
 * condition (new|good|fair|poor), purchase_date (Y-m-d), purchase_value, notes.
 * Returns the unit (inv_assets) id.
 * Does NO venue scoping — the caller must check inv_move_in_scope() (spec §3.8).
 */
function inv_asset_create(int $itemId, int $toLocationId, array $f, ?int $userId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $item = inv_fetch_item($itemId);
    if (!$item || $item['tracking'] !== 'serial') throw new InvRefusal('Units can only be added to a serial-tracked item.');
    $serial = trim((string)($f['serial'] ?? ''));
    $cond   = in_array($f['condition'] ?? '', INV_CONDITIONS, true) ? (string)$f['condition'] : 'good';
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['purchase_date'] ?? '')) ? (string)$f['purchase_date'] : null;
    $pv     = $f['purchase_value'] ?? null;
    if ($pv !== null && $pv !== '' && (!is_numeric($pv) || (float)$pv < 0)) throw new InvRefusal('Purchase value must be zero or more.');
    if ($pv !== null && $pv !== '' && (float)$pv > 9999999999.99) throw new InvRefusal('That purchase value is too large.');
    $pv = ($pv === null || $pv === '') ? null : round((float)$pv, 2);
    $loc = inv_fetch_location($toLocationId);
    if (!$loc || !inv_bool($loc['is_active'])) throw new InvRefusal('Pick an open location for this unit.');
    try {
        return inv_tx(function () use ($itemId, $toLocationId, $serial, $cond, $date, $pv, $f, $userId): int {
            db_query("INSERT INTO inv_assets (item_id, serial, tag, condition, status, location_id, purchase_date, purchase_value, notes)
                      VALUES (:i, :s, :t, :c, 'active', :l, :d, :pv, :n)", [
                ':i' => $itemId, ':s' => $serial !== '' ? mb_substr($serial, 0, 80) : null,
                ':t' => ($t = trim((string)($f['tag'] ?? ''))) !== '' ? mb_substr($t, 0, 40) : null,
                ':c' => $cond, ':l' => $toLocationId, ':d' => $date, ':pv' => $pv,
                ':n' => ($nt = trim((string)($f['notes'] ?? ''))) !== '' ? $nt : null,
            ]);
            $assetId = (int) db()->lastInsertId();
            inv_move(['item_id' => $itemId, 'qty' => 1, 'to' => $toLocationId, 'reason' => 'receive', 'asset_id' => $assetId,
                      'unit_value' => $pv, 'user_id' => $userId, 'note' => $serial !== '' ? "Serial {$serial}" : '']);
            return $assetId;
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') throw new InvRefusal("Serial {$serial} is already registered for this item.");
        throw $e;
    }
}
