<?php
declare(strict_types=1);
// Inventory core. Run: php tests/inventory_logic.php
// Pure rules always run. The DB block runs inside ONE transaction that is rolled
// back, and SKIPs when no database is reachable or add_inventory.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Move shape ──────────────────────────────────────────────────────────────
$base = ['item_id' => 1, 'qty' => 2, 'from' => null, 'to' => null, 'asset_id' => null, 'unit_value' => null];
check('move: receive into a location is valid', inv_move_error(['reason' => 'receive', 'to' => 5] + $base) === null);
check('move: receive with a source is refused', inv_move_error(['reason' => 'receive', 'from' => 4, 'to' => 5] + $base) !== null);
check('move: sale leaves (source, no destination)', inv_move_error(['reason' => 'sale', 'from' => 4] + $base) === null
    && inv_move_error(['reason' => 'sale', 'from' => 4, 'to' => 5] + $base) !== null);
check('move: every loss reason leaves a location',
    count(array_filter(INV_LOSS_REASONS, fn($r) => inv_move_error(['reason' => $r, 'from' => 4] + $base) === null)) === count(INV_LOSS_REASONS));
check('move: transfer needs both ends', inv_move_error(['reason' => 'transfer', 'from' => 4] + $base) !== null);
check('move: transfer to the same place refused', inv_move_error(['reason' => 'transfer', 'from' => 4, 'to' => 4] + $base) !== null);
check('move: transfer between two places is valid', inv_move_error(['reason' => 'transfer', 'from' => 4, 'to' => 5] + $base) === null);
check('move: unknown reason refused', inv_move_error(['reason' => 'teleport', 'to' => 5] + $base) !== null);
check('move: qty 0 refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'qty' => 0] + $base) !== null);
check('move: qty over the max refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'qty' => INV_MAX_QTY + 1] + $base) !== null);
check('move: no item refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'item_id' => 0] + $base) !== null);
check('move: a serial unit moves one at a time', inv_move_error(['reason' => 'receive', 'to' => 5, 'asset_id' => 9] + $base) !== null
    && inv_move_error(['reason' => 'receive', 'to' => 5, 'asset_id' => 9, 'qty' => 1] + $base) === null);
check('move: negative value refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'unit_value' => -1.0] + $base) !== null);

check('bool: Postgres t/f', inv_bool('t') && inv_bool(true) && !inv_bool('f') && !inv_bool(false) && !inv_bool(null));

$n = inv_normalize_move(['item_id' => '7', 'qty' => '3', 'from' => '0', 'to' => '12', 'reason' => 'receive', 'unit_value' => '4.556']);
check('normalize: strings → ints, 0 → null, value rounded to the cent',
    $n['item_id'] === 7 && $n['qty'] === 3 && $n['from'] === null && $n['to'] === 12 && $n['unit_value'] === 4.56);
check('normalize: a negative qty becomes 0 (and is refused)', inv_normalize_move(['qty' => '-2'])['qty'] === 0);
check('normalize: a Postgres "f" never allows negative stock',
    inv_normalize_move(['allow_negative' => 'f'])['allow_negative'] === false && inv_normalize_move(['allow_negative' => true])['allow_negative'] === true);

check('reason: store → property is a transfer', inv_transfer_reason('store', 'property') === 'transfer');
check('reason: anything → person is an assignment', inv_transfer_reason('store', 'person') === 'assign');
check('reason: person → store is a return', inv_transfer_reason('person', 'store') === 'return');

check('shortfall: none left', inv_shortfall_message('Plates', 0, 'My Amani') === 'No Plates left at My Amani.');
check('shortfall: some left', inv_shortfall_message('Plates', 3, 'My Amani') === 'Only 3 × Plates at My Amani.');

// ── Scope (who may move what) ───────────────────────────────────────────────
$main = ['venue_id' => null]; $amani = ['venue_id' => 1]; $zuri = ['venue_id' => 3];
check('scope: owner moves anything', inv_move_in_scope($amani, $zuri, null));
check('scope: manager restocks own property from Main stock', inv_move_in_scope($main, $amani, [1]));
check('scope: manager returns to Main stock', inv_move_in_scope($amani, $main, [1]));
check('scope: manager cannot move into another property', !inv_move_in_scope($amani, $zuri, [1]));
check('scope: manager cannot move out of another property', !inv_move_in_scope($zuri, $main, [1]));
check('scope: manager cannot shuffle shared stock only', !inv_move_in_scope($main, ['venue_id' => null], [1]));
check('scope: manager writes off at own property', inv_move_in_scope($amani, null, [1]));
check('scope: an account with no properties does nothing', !inv_move_in_scope($main, $amani, []));
check('scope: a venue-less team member is owner-only',
    !inv_move_in_scope(['venue_id' => null, 'kind' => 'person'], $amani, [1]) && inv_move_in_scope(['venue_id' => null, 'kind' => 'person'], $amani, null));
check('scope: a venue id read from the DB as a string still matches', inv_move_in_scope(['venue_id' => '1'], null, [1]));

// ── Count resolutions ───────────────────────────────────────────────────────
check('count: short line → missing move of the gap', inv_resolution_move('missing', 12, 11) === ['reason' => 'missing', 'qty' => 1, 'dir' => 'out']);
check('count: broken also leaves', inv_resolution_move('broken', 20, 17) === ['reason' => 'broken', 'qty' => 3, 'dir' => 'out']);
check('count: extra line → found', inv_resolution_move('found', 5, 7) === ['reason' => 'found', 'qty' => 2, 'dir' => 'in']);
check('count: found on a short line refused', is_string(inv_resolution_move('found', 12, 11)));
check('count: missing on an extra line refused', is_string(inv_resolution_move('missing', 5, 7)));
check('count: recount never moves stock', inv_resolution_move('recount', 12, 11) === null);
check('count: accepted only when it matches', inv_resolution_move('accepted', 4, 4) === null && is_string(inv_resolution_move('accepted', 4, 3)));
check('count: unknown resolution refused', is_string(inv_resolution_move('lost-at-sea', 4, 3)));

// ── Count schedule (Nairobi-local dates) ────────────────────────────────────
check('due: manual-only location', inv_count_status(null, null, '2026-09-27') === 'manual');
check('due: scheduled but never counted is due', inv_count_status(null, 7, '2026-09-27') === 'due');
check('due: weekly, counted 20 Sep → due 27 Sep',
    inv_count_due_ymd('2026-09-20 18:30:00+03', 7) === '2026-09-27' && inv_count_status('2026-09-20 18:30:00+03', 7, '2026-09-27') === 'due');
check('due: before the due day is ok', inv_count_status('2026-09-20 09:00:00+03', 7, '2026-09-26') === 'ok');
check('due: after the due day is overdue', inv_count_status('2026-09-20 09:00:00+03', 7, '2026-09-28') === 'overdue');
check('due: daily', inv_count_due_ymd('2026-09-27 07:00:00+03', 1) === '2026-09-28');

// ── Money never crosses currencies ──────────────────────────────────────────
$tot = inv_sum_by_currency([
    ['value' => '100.50', 'currency' => 'KES'], ['value' => 20, 'currency' => 'usd'],
    ['value' => null, 'currency' => 'KES'],     ['value' => '49.50', 'currency' => 'KES'],
]);
check('money: summed per currency, never across', $tot === ['KES' => 150.0, 'USD' => 20.0]);
check('money: a blank currency counts as the default', inv_sum_by_currency([['value' => 5, 'currency' => '']]) === ['KES' => 5.0]);

// ── Restock plan ────────────────────────────────────────────────────────────
$plan = inv_restock_plan([
    ['item_id' => 3, 'qty' => 17, 'par_qty' => 20], ['item_id' => 1, 'qty' => 25, 'par_qty' => 20],
    ['item_id' => 2, 'qty' => -2, 'par_qty' => 6],  ['item_id' => 4, 'qty' => 0,  'par_qty' => null],
]);
check('restock: tops up to par; ignores over-par and no-par rows; negative counts as 0', $plan === [2 => 6, 3 => 3]);

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_supported()) {
    echo "\nSKIP  DB block (add_inventory.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx    = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins    = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count  = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    // Σ moves into a location − Σ moves out of it: must always equal the cached balance.
    $ledger = fn(int $item, int $loc) => (int) db_query(
        'SELECT COALESCE(SUM(CASE WHEN to_location_id = :a THEN qty ELSE -qty END), 0)
           FROM inv_moves WHERE item_id = :i AND (to_location_id = :b OR from_location_id = :c)',
        [':a' => $loc, ':i' => $item, ':b' => $loc, ':c' => $loc])->fetchColumn();

    // ── Locations + items ──
    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Inv A')", [':s' => "zz-inv-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Inv B')", [':s' => "zz-inv-b-{$sfx}"]);
    $store = inv_store_location_id();
    check('locations: Main stock exists exactly once',
        $store > 0 && inv_store_location_id() === $store && $count("SELECT COUNT(*) FROM inv_locations WHERE kind = 'store'") === 1);
    $locA = inv_property_location_id($vA);
    check('locations: one property location per venue', $locA > 0 && inv_property_location_id($vA) === $locA);
    $la = inv_fetch_location($locA);
    check('locations: a property carries its venue', $la && (int)$la['venue_id'] === $vA && $la['kind'] === 'property');
    $locB = inv_property_location_id($vB);
    $outlet = $ins("INSERT INTO pos_outlets (name, slug, kind, venue_id, currency) VALUES ('ZZ Inv Shop', :s, 'shop', :v, 'KES')",
        [':s' => "zz-inv-shop-{$sfx}", ':v' => $vA]);
    $locShop = inv_outlet_location_id($outlet);
    check('locations: an outlet location is tied to the outlet and its venue',
        $locShop === inv_outlet_location_id($outlet) && (int)inv_fetch_location($locShop)['venue_id'] === $vA);
    $staff = $ins("INSERT INTO hr_staff (full_name, venue_id) VALUES ('ZZ Jane Wanjiru', :v)", [':v' => $vA]);
    $locJane = inv_person_location_id($staff);
    check('locations: a person location is created on demand, once',
        $locJane === inv_person_location_id($staff) && inv_fetch_location($locJane)['kind'] === 'person');
    db_query("UPDATE pos_outlets SET venue_id = :v, name = 'ZZ Inv Shop 2' WHERE id = :o", [':v' => $vB, ':o' => $outlet]);
    inv_refresh_location_owners();
    $ls = inv_fetch_location($locShop);
    check('locations: an outlet location follows its outlet (venue + name)', (int)$ls['venue_id'] === $vB && $ls['name'] === 'ZZ Inv Shop 2');
    db_query("UPDATE pos_outlets SET venue_id = :v, name = 'ZZ Inv Shop' WHERE id = :o", [':v' => $vA, ':o' => $outlet]);
    check('locations: the ensure call refreshes the owner too',
        inv_outlet_location_id($outlet) === $locShop && (int)inv_fetch_location($locShop)['venue_id'] === $vA);
    db_query("UPDATE hr_staff SET full_name = 'ZZ Jane W.', venue_id = :v WHERE id = :s", [':v' => $vB, ':s' => $staff]);
    inv_refresh_location_owners();
    $lj = inv_fetch_location($locJane);
    check('locations: a person location follows the staff record', $lj['name'] === 'ZZ Jane W.' && (int)$lj['venue_id'] === $vB);
    db_query("UPDATE hr_staff SET full_name = 'ZZ Jane Wanjiru', venue_id = :v WHERE id = :s", [':v' => $vA, ':s' => $staff]);
    check('locations: the person ensure call refreshes the owner too', inv_person_location_id($staff) === $locJane && (int)inv_fetch_location($locJane)['venue_id'] === $vA);
    $threw = false; try { inv_linked_stock_count('id; DROP TABLE x', 1); } catch (InvalidArgumentException $e) { $threw = true; }
    check('guard: the link column is whitelisted', $threw);
    $plates = inv_create_item(['name' => 'ZZ Dinner plate', 'item_type' => 'operational', 'category' => 'Kitchen', 'replacement_value' => '850', 'currency' => 'KES']);
    $laptop = inv_create_item(['name' => 'ZZ Laptop', 'item_type' => 'employee', 'tracking' => 'serial', 'replacement_value' => 95000]);
    $p = inv_fetch_item($plates);
    check('items: created with type, value and qty tracking',
        $p && $p['item_type'] === 'operational' && (float)$p['replacement_value'] === 850.0 && $p['tracking'] === 'qty' && $p['currency'] === 'KES');
    check('items: a serial item', inv_fetch_item($laptop)['tracking'] === 'serial');
    $threw = false; try { inv_create_item(['name' => '  ', 'item_type' => 'operational']); } catch (InvRefusal $e) { $threw = true; }
    check('items: a name is required', $threw);
    $threw = false; try { inv_create_item(['name' => 'X', 'item_type' => 'gadget']); } catch (InvRefusal $e) { $threw = true; }
    check('items: an unknown type is refused', $threw);
    $threw = false; try { inv_create_item(['name' => 'X', 'item_type' => 'spare', 'replacement_value' => '-3']); } catch (InvRefusal $e) { $threw = true; }
    check('items: a negative value is refused', $threw);
    $threw = false; try { inv_create_item(['name' => 'X', 'item_type' => 'spare', 'replacement_value' => '99999999999']); } catch (InvRefusal $e) { $threw = true; }
    check('items: an absurd value is refused, not a DB error', $threw);
    $threw = false; try { inv_create_item(['name' => 'X', 'item_type' => 'consignment', 'consignor_id' => 999999999]); } catch (InvRefusal $e) { $threw = true; }
    check('items: an unknown supplier is refused, not a DB error', $threw);

    // ── DB checks (tasks 4–8 insert their blocks above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
