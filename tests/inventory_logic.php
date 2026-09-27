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
check('move: an absurd value refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'unit_value' => 1e11] + $base) !== null);

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

    // ── The one write path ──
    $m1 = inv_move(['item_id' => $plates, 'qty' => 100, 'to' => $store, 'reason' => 'receive', 'unit_value' => 800]);
    check('move: receive 100 plates into Main stock', inv_balance($plates, $store) === 100);
    $mv = db_query('SELECT * FROM inv_moves WHERE id = :id', [':id' => $m1])->fetch();
    check('move: a receive keeps its unit value, total and currency',
        (float)$mv['unit_value'] === 800.0 && (float)$mv['value'] === 80000.0 && $mv['currency'] === 'KES');
    inv_move(['item_id' => $plates, 'qty' => 20, 'from' => $store, 'to' => $locA, 'reason' => 'transfer']);
    inv_move(['item_id' => $plates, 'qty' => 20, 'from' => $store, 'to' => $locB, 'reason' => 'transfer']);
    check('move: 60 left in Main stock, 20 at each property',
        inv_balance($plates, $store) === 60 && inv_balance($plates, $locA) === 20 && inv_balance($plates, $locB) === 20);
    check('guard: stock held under a venue is seen; an empty person holds none',
        inv_linked_stock_count('venue_id', $vA) === 20 && inv_linked_stock_count('hr_staff_id', $staff) === 0);
    $uv = db_query("SELECT unit_value FROM inv_moves WHERE item_id = :i AND reason = 'transfer' ORDER BY id DESC LIMIT 1", [':i' => $plates])->fetchColumn();
    check('move: without a value it snapshots the replacement value', (float)$uv === 850.0);
    $msg = ''; try { inv_move(['item_id' => $plates, 'qty' => 21, 'from' => $locA, 'reason' => 'broken']); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('move: cannot take more than is there', str_contains($msg, 'Only 20') && inv_balance($plates, $locA) === 20);
    check('move: the outer transaction survives a refusal', $count('SELECT 1') === 1);
    inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $locA, 'reason' => 'broken', 'note' => 'dropped']);
    check('move: a breakage leaves the property', inv_balance($plates, $locA) === 19);
    check('ledger: every balance equals the sum of its moves',
        $ledger($plates, $store) === 60 && $ledger($plates, $locA) === 19 && $ledger($plates, $locB) === 20);
    db_query('UPDATE inv_locations SET is_active = FALSE WHERE id = :l', [':l' => $locB]);
    $threw = false; try { inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $store, 'to' => $locB, 'reason' => 'transfer']); } catch (InvRefusal $e) { $threw = true; }
    check('move: nothing moves into a closed location', $threw && inv_balance($plates, $store) === 60);
    db_query('UPDATE inv_locations SET is_active = TRUE WHERE id = :l', [':l' => $locB]);
    $cap = inv_create_item(['name' => 'ZZ Cap', 'item_type' => 'sellable', 'replacement_value' => 1200]);
    inv_move(['item_id' => $cap, 'qty' => 2, 'from' => $locShop, 'reason' => 'sale', 'allow_negative' => true]);
    check('move: allow_negative (POS only) may go below zero, ledger still agrees',
        inv_balance($cap, $locShop) === -2 && $ledger($cap, $locShop) === -2);
    $threw = false; try { inv_move(['item_id' => $cap, 'qty' => 1, 'from' => $locShop, 'reason' => 'sale']); } catch (InvRefusal $e) { $threw = true; }
    check('move: without allow_negative a negative shelf sells nothing', $threw);
    inv_tx(function () use ($plates, $store, $locA): void { inv_lock_balances([[$plates, $locA], [$plates, $store], [$plates, $locA], [0, 5]]); });
    check('lock: pre-locking a set of balances is harmless and re-entrant', inv_balance($plates, $store) === 60);

    // ── Serial units, transfers, losses ──
    $a1 = inv_asset_create($laptop, $store, ['serial' => 'ZZ-SN-001', 'condition' => 'new', 'purchase_value' => 92000], null);
    $a2 = inv_asset_create($laptop, $store, ['serial' => 'ZZ-SN-002'], null);
    $status = fn(int $a) => db_query('SELECT status FROM inv_assets WHERE id = :a', [':a' => $a])->fetchColumn();
    check('serial: two units received into Main stock', inv_balance($laptop, $store) === 2);
    $threw = false; try { inv_asset_create($laptop, $store, ['serial' => 'ZZ-SN-001'], null); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a duplicate serial is refused, nothing added', $threw && inv_balance($laptop, $store) === 2);
    $threw = false; try { inv_move(['item_id' => $laptop, 'qty' => 1, 'from' => $store, 'to' => $locJane, 'reason' => 'assign']); } catch (InvRefusal $e) { $threw = true; }
    check('serial: moving without naming the unit is refused', $threw);
    inv_transfer($laptop, 1, $store, $locJane, null, 'Laptop for Jane', $a1);
    check('serial: assigned to Jane',
        inv_balance($laptop, $locJane) === 1 && (int)db_query('SELECT location_id FROM inv_assets WHERE id = :a', [':a' => $a1])->fetchColumn() === $locJane);
    check('serial: recorded as an assignment',
        db_query('SELECT reason FROM inv_moves WHERE asset_id = :a ORDER BY id DESC LIMIT 1', [':a' => $a1])->fetchColumn() === 'assign');
    $threw = false; try { inv_transfer($laptop, 1, $store, $locA, null, '', $a1); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a unit only leaves from where it is', $threw);
    inv_report_loss($laptop, 1, $locJane, 'stolen', null, 'taken from car', $a1);
    check('serial: a stolen unit is written off and leaves Jane', inv_balance($laptop, $locJane) === 0 && $status($a1) === 'written_off');
    $active = fn(int $loc) => $count("SELECT COUNT(*) FROM inv_assets WHERE item_id = :i AND location_id = :l AND status = 'active'", [':i' => $laptop, ':l' => $loc]);
    check('serial: balance = active units at every location',
        inv_balance($laptop, $store) === $active($store) && inv_balance($laptop, $locJane) === $active($locJane));
    db_query('UPDATE inv_items SET replacement_value = 99000 WHERE id = :i', [':i' => $laptop]);
    inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'found', 'asset_id' => $a1]);
    db_query('UPDATE inv_items SET replacement_value = 95000 WHERE id = :i', [':i' => $laptop]);
    check('serial: a found unit comes back into stock', inv_balance($laptop, $store) === 2 && $status($a1) === 'active');
    $threw = false; try { inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'found', 'asset_id' => $a2]); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a unit already in stock cannot come in twice', $threw && inv_balance($laptop, $store) === 2);
    $threw = false; try { inv_report_loss($plates, 1, $locA, 'vanished', null); } catch (InvRefusal $e) { $threw = true; }
    check('loss: only broken / missing / stolen / written off', $threw);
    $bigItem = inv_create_item(['name' => 'ZZ Big Value', 'item_type' => 'spare', 'replacement_value' => 50000000]);
    $threw = false; try { inv_move(['item_id' => $bigItem, 'qty' => 100000, 'to' => $store, 'reason' => 'receive']); } catch (InvRefusal $e) { $threw = true; }
    check('move: a total value beyond NUMERIC(14,2) is refused, nothing written', $threw && inv_balance($bigItem, $store) === 0);
    check('transfer: person → store is a return', inv_transfer_reason('person', 'store') === 'return');

    // Serial revival rules.
    $threw = false; try { inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'void', 'asset_id' => $a1]); } catch (InvRefusal $e) { $threw = true; }
    check('serial: an unsold unit cannot come back through a void', $threw);
    inv_move(['item_id' => $laptop, 'qty' => 1, 'from' => $store, 'reason' => 'sale', 'asset_id' => $a2]);
    check('serial: a sale marks the unit sold', $status($a2) === 'sold' && inv_balance($laptop, $store) === 1);
    $threw = false; try { inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'found', 'asset_id' => $a2]); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a sold unit cannot be "found"', $threw);
    inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'void', 'asset_id' => $a2]);
    check('serial: a void brings a sold unit back', $status($a2) === 'active' && inv_balance($laptop, $store) === 2);
    $uv = db_query("SELECT unit_value FROM inv_moves WHERE asset_id = :a AND reason = 'receive'", [':a' => $a1])->fetchColumn();
    check('serial: the receive snapshots the purchase value', (float)$uv === 92000.0);
    $fv = db_query("SELECT unit_value FROM inv_moves WHERE asset_id = :a AND reason = 'found'", [':a' => $a1])->fetchColumn();
    $sv = db_query("SELECT unit_value FROM inv_moves WHERE asset_id = :a AND reason = 'stolen'", [':a' => $a1])->fetchColumn();
    check('serial: a found unit comes back at the value it was lost at', (float)$fv === (float)$sv);
    $otherSerial = inv_create_item(['name' => 'ZZ Phone', 'item_type' => 'employee', 'tracking' => 'serial']);
    inv_asset_create($otherSerial, $store, ['serial' => 'ZZ-PH-001'], null);
    $msg = ''; try { inv_move(['item_id' => $otherSerial, 'qty' => 1, 'from' => $store, 'to' => $locJane, 'reason' => 'assign', 'asset_id' => $a1]); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('serial: a unit of another item is refused', str_contains($msg, 'does not belong'));
    db_query('UPDATE inv_locations SET is_active = FALSE WHERE id = :l', [':l' => $locB]);
    $threw = false; try { inv_asset_create($laptop, $locB, ['serial' => 'ZZ-SN-009'], null); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a unit cannot be registered into a closed location', $threw);
    db_query('UPDATE inv_locations SET is_active = TRUE WHERE id = :l', [':l' => $locB]);
    check('ledger: the laptop balance equals its moves', $ledger($laptop, $store) === inv_balance($laptop, $store) && $ledger($laptop, $locJane) === 0);
    // Consignment terms on the move.
    $cons = $ins("INSERT INTO pos_consignors (name, commission_pct) VALUES ('ZZ Inv Weavers', 20)");
    $mt = inv_move(['item_id' => $plates, 'qty' => 1, 'to' => $locB, 'reason' => 'receive', 'terms' => ['consignor_id' => $cons, 'consign_pct' => 15]]);
    check('terms: a delivery records its consignment terms',
        (int)db_query('SELECT consignor_id FROM inv_moves WHERE id = :m', [':m' => $mt])->fetchColumn() === $cons);
    $threw = false; try { inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $store, 'to' => $locA, 'reason' => 'transfer', 'terms' => ['consignor_id' => $cons]]); } catch (InvRefusal $e) { $threw = true; }
    check('terms: only a delivery carries consignment terms', $threw);

    // ── Replace, par, restock ──
    $r = inv_replace($plates, 2, $locA, 'broken', null, 'dinner party');
    check('replace: breakage + refill in one step', inv_balance($plates, $locA) === 19 && inv_balance($plates, $store) === 58);
    $loss = db_query('SELECT reason, value FROM inv_moves WHERE id = :id', [':id' => $r['loss_move_id']])->fetch();
    check('replace: the loss carries its value (2 × 850)', $loss['reason'] === 'broken' && (float)$loss['value'] === 1700.0);
    check('replace: the refill is a "replaced" move from Main stock',
        db_query('SELECT reason FROM inv_moves WHERE id = :id', [':id' => $r['replace_move_id']])->fetchColumn() === 'replaced');
    $threw = false; try { inv_replace($laptop, 1, $store, 'broken', null); } catch (InvRefusal $e) { $threw = true; }
    check('replace: serial items are replaced unit by unit, not here', $threw);
    inv_set_par($plates, $locA, 24);
    $glasses = inv_create_item(['name' => 'ZZ Wine glass', 'item_type' => 'operational', 'replacement_value' => 400]);
    inv_move(['item_id' => $glasses, 'qty' => 3, 'to' => $store, 'reason' => 'receive']);
    inv_set_par($glasses, $locA, 12);
    $res = inv_restock_to_par($locA, null);
    check('restock: plates topped up to par 24', inv_balance($plates, $locA) === 24 && ($res['moved'][$plates] ?? 0) === 5);
    check('restock: glasses limited by Main stock, shortfall reported',
        inv_balance($glasses, $locA) === 3 && ($res['short'][$glasses] ?? 0) === 9 && inv_balance($glasses, $store) === 0);
    $threw = false; try { inv_set_par($plates, $locA, -1); } catch (InvRefusal $e) { $threw = true; }
    check('par: cannot be negative', $threw);
    $losses = inv_sum_by_currency(db_query(
        "SELECT value, currency FROM inv_moves WHERE item_id IN (:a, :b) AND reason IN ('broken','missing','stolen','written_off')",
        [':a' => $plates, ':b' => $laptop])->fetchAll());
    check('money: losses per currency (3 plates × 850 + 1 laptop × 95,000)', $losses === ['KES' => 97550.0]);

    // ── Counts flag; a manager's resolution moves stock ──
    $counter = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'staff', 'ZZ Counter', TRUE)",
        [':e' => "zz-inv-counter-{$sfx}@example.com"]);
    $cid = inv_count_start($locA, $counter);
    check('count: starting twice reuses the open count', inv_count_start($locA, $counter) === $cid);
    $exp = db_query('SELECT item_id, expected FROM inv_count_lines WHERE count_id = :c', [':c' => $cid])->fetchAll(PDO::FETCH_KEY_PAIR);
    check('count: expected is snapshotted from the system', (int)($exp[$plates] ?? -1) === 24 && (int)($exp[$glasses] ?? -1) === 3);
    $threw = false; try { inv_count_submit($cid, [$plates => 23], $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: every line needs a number', $threw);
    $res = inv_count_submit($cid, [$plates => 23, $glasses => 3], $counter);
    check('count: submitting never moves stock', $res['gaps'] === 1 && inv_balance($plates, $locA) === 24);
    check('count: a matching line is accepted automatically',
        db_query('SELECT resolution FROM inv_count_lines WHERE count_id = :c AND item_id = :i', [':c' => $cid, ':i' => $glasses])->fetchColumn() === 'accepted');
    check('count: the location records when it was counted', inv_fetch_location($locA)['last_counted_at'] !== null);
    $line = (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c AND item_id = :i', [':c' => $cid, ':i' => $plates])->fetchColumn();
    $threw = false; try { inv_count_resolve_line($line, 'found', $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: "found" on a short line is refused', $threw);
    inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $store, 'to' => $locA, 'reason' => 'transfer']);
    $msg = ''; try { inv_count_resolve_line($line, 'missing', $counter); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('count: refused when stock changed since the count', str_contains($msg, 'changed since'));
    inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $locA, 'to' => $store, 'reason' => 'transfer']);
    $mid = inv_count_resolve_line($line, 'missing', $counter);
    check('count: resolving "missing" writes the loss, linked to the line',
        $mid !== null && inv_balance($plates, $locA) === 23
        && (int)db_query('SELECT count_line_id FROM inv_moves WHERE id = :m', [':m' => $mid])->fetchColumn() === $line);
    check('count: the count is resolved once every line is',
        db_query('SELECT status FROM inv_counts WHERE id = :c', [':c' => $cid])->fetchColumn() === 'resolved');
    $threw = false; try { inv_count_resolve_line($line, 'missing', $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: a line resolves only once', $threw && inv_balance($plates, $locA) === 23);
    check('ledger: still equals every balance after all of it',
        $ledger($plates, $locA) === 23 && $ledger($plates, $store) === inv_balance($plates, $store) && $ledger($glasses, $locA) === 3);

    // ── Count lifecycle, atomicity, history ──
    $cstatus = fn(int $c) => db_query('SELECT status FROM inv_counts WHERE id = :c', [':c' => $c])->fetchColumn();
    $threw = false; try { inv_replace($glasses, 1, $locA, 'broken', null); } catch (InvRefusal $e) { $threw = true; }
    check('replace: all-or-nothing — no refill stock means no loss is recorded either',
        $threw && inv_balance($glasses, $locA) === 3 && $count("SELECT COUNT(*) FROM inv_moves WHERE item_id = :i AND reason = 'broken'", [':i' => $glasses]) === 0);
    $towels = inv_create_item(['name' => 'ZZ Towel', 'item_type' => 'operational', 'replacement_value' => 1500]);
    inv_move(['item_id' => $towels, 'qty' => 5, 'to' => $locB, 'reason' => 'receive']);
    inv_transfer($laptop, 1, $store, $locB, null, 'Villa laptop', $a2);
    $c2 = inv_count_start($locB, $counter);
    db_query("UPDATE inv_counts SET started_at = now() - INTERVAL '2 days' WHERE id = :c", [':c' => $c2]);
    $c3 = inv_count_start($locB, $counter);
    check('count: an open count from an earlier day is cancelled, not reused', $c3 !== $c2 && $cstatus($c2) === 'cancelled' && $cstatus($c3) === 'open');
    $staleLine = (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c ORDER BY id LIMIT 1', [':c' => $c2])->fetchColumn();
    $platesB = inv_balance($plates, $locB);
    $msg = ''; try { inv_count_resolve_line($staleLine, 'missing', $counter); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('count: a line of a cancelled count can never be resolved (no false loss)', str_contains($msg, 'closed') && inv_balance($plates, $locB) === $platesB);
    $msg = ''; try { inv_count_submit($c2, [], $counter); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('count: a cancelled count cannot be submitted', str_contains($msg, 'closed'));
    $threw = false; try { inv_tx(fn() => db_query('INSERT INTO inv_counts (location_id) VALUES (:l)', [':l' => $locB])); } catch (PDOException $e) { $threw = true; }
    check('count: the DB allows one open count per location', $threw);
    $line = fn(int $item) => (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c AND item_id = :i', [':c' => $c3, ':i' => $item])->fetchColumn();
    $msg = ''; try { inv_count_resolve_line($line($plates), 'found', $counter); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('count: nothing resolves before the count is submitted', str_contains($msg, 'not been submitted'));
    $res = inv_count_submit($c3, [$plates => 23, $towels => 4, $laptop => 0], $counter);
    check('count: three gaps flagged, stock untouched', $res['gaps'] === 3 && inv_balance($plates, $locB) === 21 && inv_balance($towels, $locB) === 5);
    $threw = false; try { inv_count_submit($c3, [$plates => 23, $towels => 4, $laptop => 0], $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: a count is submitted once', $threw);
    inv_count_resolve_line($line($plates), 'found', $counter);
    check('count: "found" brings the extra in', inv_balance($plates, $locB) === 23);
    check('count: "recount" closes a line without moving stock', inv_count_resolve_line($line($towels), 'recount', $counter) === null && inv_balance($towels, $locB) === 5);
    $msg = ''; try { inv_count_resolve_line($line($laptop), 'missing', $counter); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('count: a serial line points to reporting the unit itself', str_contains($msg, 'serial number') && inv_balance($laptop, $locB) === 1);
    inv_count_resolve_line($line($laptop), 'recount', $counter);
    check('count: resolved once the last line is', $cstatus($c3) === 'resolved');
    $hist = inv_item_moves($plates, $locB, 5);
    check('history: filtered to a location, newest first', ($hist[0]['reason'] ?? '') === 'found' && (int)$hist[0]['to_location_id'] === $locB);
    $threw = false; try { inv_set_par($plates, $locA, INV_MAX_QTY + 1); } catch (InvRefusal $e) { $threw = true; }
    check('par: capped', $threw);
    $threw = false; try { inv_set_par($plates, 999999999, 5); } catch (InvRefusal $e) { $threw = true; }
    check('par: an unknown location is a refusal, not a DB error', $threw);

    // ── DB checks (tasks 4–8 insert their blocks above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
