<?php
declare(strict_types=1);
// Inventory pages — read models, scope and settings helpers. Run: php tests/inventory_views_logic.php
// Pure rules always run. The DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Visibility (mirrors inv_move_in_scope) ──────────────────────────────────
$store = ['kind' => 'store', 'venue_id' => null]; $amani = ['kind' => 'property', 'venue_id' => 1];
$zuri = ['kind' => 'property', 'venue_id' => 3];  $office = ['kind' => 'person', 'venue_id' => null];
check('visible: owner sees everything', inv_location_visible($zuri, null) && inv_location_visible($office, null));
check('visible: manager sees their own property', inv_location_visible($amani, [1]));
check('visible: manager sees shared Main stock', inv_location_visible($store, [1]));
check('visible: manager does not see another property', !inv_location_visible($zuri, [1]));
check('visible: a venue-less team member is owner-only', !inv_location_visible($office, [1]));
check('visible: venue id read back as a string still matches', inv_location_visible(['kind' => 'area', 'venue_id' => '1'], [1]));
check('editable: owner edits anything', inv_location_editable($store, null));
check('editable: a manager cannot edit shared Main stock', !inv_location_editable($store, [1]));
check('editable: a manager edits their own property', inv_location_editable($amani, [1]) && !inv_location_editable($zuri, [1]));

// ── Labels, sorting, money, dates ───────────────────────────────────────────
check('label: area under its property', inv_location_label(['kind' => 'area', 'name' => 'Kitchen', 'parent_name' => 'My Amani']) === 'My Amani › Kitchen');
check('label: outlet and person are marked', str_contains(inv_location_label(['kind' => 'outlet', 'name' => 'Shop']), 'outlet')
    && str_contains(inv_location_label(['kind' => 'person', 'name' => 'Jane']), 'team'));
$sorted = inv_sort_locations([
    ['kind' => 'person', 'name' => 'Jane', 'sort_order' => 0],
    ['kind' => 'area', 'name' => 'Kitchen', 'parent_name' => 'Zuri', 'sort_order' => 1],
    ['kind' => 'outlet', 'name' => 'Shop', 'sort_order' => 0],
    ['kind' => 'property', 'name' => 'Zuri', 'sort_order' => 0],
    ['kind' => 'property', 'name' => 'My Amani', 'sort_order' => 0],
    ['kind' => 'store', 'name' => 'Main stock', 'sort_order' => 0],
]);
check('sort: Main stock, properties A→Z with their areas, outlets, people',
    array_column($sorted, 'name') === ['Main stock', 'My Amani', 'Zuri', 'Kitchen', 'Shop', 'Jane']);
check('breakdown: joins name + qty, caps the list',
    inv_breakdown_label([['name' => 'Main', 'qty' => 30], ['name' => 'A', 'qty' => 20], ['name' => 'B', 'qty' => 5]], 2) === 'Main 30 · A 20 · +1 more');
check('money: whole amounts without decimals', inv_money(1700.0, 'KES') === 'KES 1,700');
check('money: cents shown when present, code upper-cased', inv_money(12.5, 'usd') === 'USD 12.50');
check('money: unknown value is a dash', inv_money(null, 'KES') === '—');
check('date: a valid Y-m-d passes', inv_ymd_or('2026-09-01', 'x') === '2026-09-01');
check('date: garbage falls back', inv_ymd_or('9/1/2026', '2026-01-01') === '2026-01-01' && inv_ymd_or(null, 'f') === 'f');
check('target: a location', inv_parse_target('loc:12') === ['loc', 12]);
check('target: a team member', inv_parse_target('staff:5') === ['staff', 5]);
check('target: anything else is refused', inv_parse_target('12') === null && inv_parse_target('loc:x') === null);

// ── Item form ───────────────────────────────────────────────────────────────
[$v, $e] = inv_item_from_post(['name' => 'Dinner plate', 'item_type' => 'operational', 'replacement_value' => '850', 'currency' => 'kes', 'category' => 'Kitchen', 'is_active' => '1']);
check('item form: a valid plate', !$e && $v['name'] === 'Dinner plate' && $v['replacement_value'] === 850.0 && $v['currency'] === 'KES' && $v['tracking'] === 'qty' && $v['is_active'] === true);
[$v, $e] = inv_item_from_post(['name' => '', 'item_type' => 'gadget', 'replacement_value' => '-1', 'low_stock_at' => 'two']);
check('item form: name, type, value and alert all flagged', isset($e['name'], $e['item_type'], $e['replacement_value'], $e['low_stock_at']));
[$v, $e] = inv_item_from_post(['name' => 'Laptop', 'item_type' => 'employee', 'tracking' => 'serial', 'currency' => 'KES']);
check('item form: serial tracking + blank value = unknown', !$e && $v['tracking'] === 'serial' && $v['replacement_value'] === null && $v['is_active'] === false);

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
    $sfx   = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins   = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };

    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ View A')", [':s' => "zz-view-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ View B')", [':s' => "zz-view-b-{$sfx}"]);
    $store = inv_store_location_id();
    $locA  = inv_property_location_id($vA);
    $locB  = inv_property_location_id($vB);
    $owner = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'owner', 'ZZ Owner', TRUE)", [':e' => "zz-view-o-{$sfx}@example.com"]);
    $mgr   = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'manager', 'ZZ Manager', TRUE)", [':e' => "zz-view-m-{$sfx}@example.com"]);
    db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:u, :v)', [':u' => $mgr, ':v' => $vA]);
    $jane  = $ins("INSERT INTO hr_staff (full_name, venue_id) VALUES ('ZZ Jane', :v)", [':v' => $vA]);
    $plates = inv_create_item(['name' => 'ZZ View plate', 'item_type' => 'operational', 'category' => 'ZZ Kitchen', 'replacement_value' => 850]);
    $laptop = inv_create_item(['name' => 'ZZ View laptop', 'item_type' => 'employee', 'tracking' => 'serial', 'replacement_value' => 95000]);

    // ── Read models ──
    $kitchen = inv_create_area($locA, 'ZZ Kitchen');
    inv_move(['item_id' => $plates, 'qty' => 30, 'to' => $store, 'reason' => 'receive']);
    inv_transfer($plates, 10, $store, $kitchen, $owner);
    inv_transfer($plates, 5, $store, $locB, $owner);
    $pick = fn(array $rows, int $id) => array_values(array_filter($rows, fn($r) => (int)$r['id'] === $id))[0] ?? null;

    $own = inv_central_list(['q' => 'ZZ View'], null, 50, 0);
    $row = $pick($own['rows'], $plates);
    check('list: owner sees every unit (15 Main + 10 kitchen + 5 B)', $row && (int)$row['qty'] === 30 && count($row['breakdown']) === 3);
    check('list: the value is qty × replacement value', $row && (float)$row['value'] === 25500.0);
    $mine = inv_central_list(['q' => 'ZZ View'], [$vA], 50, 0);
    $row  = $pick($mine['rows'], $plates);
    check('list: a manager sees only their property + shared Main stock', $row && (int)$row['qty'] === 25
        && !in_array($locB, array_map(fn($b) => (int)$b['location_id'], $row['breakdown']), true));
    $row = $pick(inv_central_list(['q' => 'ZZ View', 'location' => $locA], null, 50, 0)['rows'], $plates);
    check('list: a property filter includes its areas', $row && (int)$row['qty'] === 10);
    check('list: an empty filter still lists items with no stock', $pick($own['rows'], $laptop) !== null);
    check('list: a location filter hides items that are not there',
        $pick(inv_central_list(['q' => 'ZZ View', 'location' => $locA], null, 50, 0)['rows'], $laptop) === null);
    inv_report_loss($plates, 2, $locB, 'broken', $owner, 'dropped');
    $gone = inv_gone_moves(['q' => 'ZZ View', 'status' => 'written_off'], null, 50, 0);
    check('gone: losses listed with their value', $gone['total'] === 1 && ($gone['totals']['KES'] ?? 0) === 1700.0);
    check('gone: a manager does not see another property’s losses', inv_gone_moves(['q' => 'ZZ View', 'status' => 'written_off'], [$vA], 50, 0)['total'] === 0);
    check('item view: where it is, scoped', count(inv_item_locations($plates, null)) === 3 && count(inv_item_locations($plates, [$vA])) === 2);
    $hist = inv_item_history($plates, [$vA]);
    check('item view: history hides moves entirely outside the manager’s places',
        !array_filter($hist, fn($m) => $m['reason'] === 'broken') && count(inv_item_history($plates, null)) > count($hist));
    inv_set_par($plates, $kitchen, 12);
    $stock = inv_location_stock($kitchen);
    check('location view: qty, par and what is short', count($stock) === 1 && (int)$stock[0]['qty'] === 10 && (int)$stock[0]['need'] === 2);
    check('location view: child areas of a property', array_column(inv_child_areas($locA), 'id') === [$kitchen]);
    $vis = inv_locations_visible([$vA]);
    $visIds = array_map(fn($l) => (int)$l['id'], $vis);
    check('locations: a manager sees Main stock, their property and its area — not B',
        in_array($store, $visIds, true) && in_array($locA, $visIds, true) && in_array($kitchen, $visIds, true) && !in_array($locB, $visIds, true));
    check('venues: a manager’s venues only', array_keys(inv_visible_venues([$vA])) === [$vA]);
    check('staff: a manager can pick their own team', isset(inv_assignable_staff([$vA])[$jane]) && !isset(inv_assignable_staff([$vB])[$jane]));

    // ── Settings + actions ──
    check('area: a duplicate name under one property is refused', str_contains($refused(fn() => inv_create_area($locA, 'zz kitchen')), 'already has'));
    check('area: only under a property', $refused(fn() => inv_create_area($store, 'Shelf')) !== '');
    inv_update_location($kitchen, ['count_every_days' => '7', 'count_assignee_id' => $mgr, 'name' => 'ZZ Main kitchen', 'is_active' => true]);
    $k = inv_fetch_location($kitchen);
    check('location: schedule, responsible person and name saved', (int)$k['count_every_days'] === 7 && (int)$k['count_assignee_id'] === $mgr && $k['name'] === 'ZZ Main kitchen');
    check('location: a silly schedule is refused', $refused(fn() => inv_update_location($kitchen, ['count_every_days' => '400'])) !== '');
    check('location: an area holding stock cannot be closed', str_contains($refused(fn() => inv_update_location($kitchen, ['is_active' => false])), 'Move its stock'));
    inv_update_location($locA, ['name' => 'Renamed', 'count_every_days' => '']);
    check('location: a property keeps its venue name', inv_fetch_location($locA)['name'] !== 'Renamed');

    [$v] = inv_item_from_post(['name' => 'ZZ View plate', 'item_type' => 'operational', 'tracking' => 'serial', 'currency' => 'KES', 'is_active' => '1']);
    check('item: tracking cannot change once it has history', str_contains($refused(fn() => inv_update_item($plates, $v)), 'Tracking'));
    [$v] = inv_item_from_post(['name' => 'ZZ View plate (large)', 'item_type' => 'operational', 'replacement_value' => '900', 'currency' => 'KES', 'is_active' => '1']);
    inv_update_item($plates, $v);
    check('item: details saved', inv_fetch_item($plates)['name'] === 'ZZ View plate (large)' && (float)inv_fetch_item($plates)['replacement_value'] === 900.0);

    $item = inv_fetch_item($plates);
    $msg = inv_apply_item_action(['action' => 'receive', 'qty' => '4', 'to_id' => (string)$kitchen, 'note' => 'delivery'], $item, [$vA], $mgr);
    check('action: a manager receives into their own area', str_contains($msg, 'Received 4') && inv_balance($plates, $kitchen) === 14);
    check('action: a manager cannot receive into another property',
        str_contains($refused(fn() => inv_apply_item_action(['action' => 'receive', 'qty' => '1', 'to_id' => (string)$locB], $item, [$vA], $mgr)), 'location you manage'));
    $msg = inv_apply_item_action(['action' => 'transfer', 'qty' => '2', 'from_id' => (string)$kitchen, 'to' => 'staff:' . $jane], $item, [$vA], $mgr);
    $janeLoc = inv_person_location_id($jane);
    check('action: assigning to a team member', str_contains($msg, 'ZZ Jane') && inv_balance($plates, $janeLoc) === 2
        && db_query("SELECT reason FROM inv_moves WHERE item_id = :i ORDER BY id DESC LIMIT 1", [':i' => $plates])->fetchColumn() === 'assign');
    check('action: restock from shared Main stock into their own area is allowed',
        str_contains(inv_apply_item_action(['action' => 'transfer', 'qty' => '1', 'from_id' => (string)$store, 'to' => 'loc:' . $kitchen], $item, [$vA], $mgr), 'Moved'));
    check('action: moving shared stock to another property is refused',
        $refused(fn() => inv_apply_item_action(['action' => 'transfer', 'qty' => '1', 'from_id' => (string)$store, 'to' => 'loc:' . $locB], $item, [$vA], $mgr)) !== '');
    $msg = inv_apply_item_action(['action' => 'loss', 'qty' => '1', 'from_id' => (string)$kitchen, 'reason' => 'broken', 'note' => 'dropped'], $item, [$vA], $mgr);
    check('action: a loss reports its value', str_contains($msg, 'KES 900') && inv_balance($plates, $kitchen) === 12);
    $mainBefore = inv_balance($plates, $store);
    $msg = inv_apply_item_action(['action' => 'replace', 'qty' => '1', 'at_id' => (string)$kitchen, 'reason' => 'broken'], $item, null, $owner);
    check('action: replace = loss here + refill from Main stock', inv_balance($plates, $kitchen) === 12 && inv_balance($plates, $store) === $mainBefore - 1);
    check('action: an unknown action is refused', $refused(fn() => inv_apply_item_action(['action' => 'teleport'], $item, null, $owner)) !== '');

    $lap = inv_fetch_item($laptop);
    check('action: counted receive is refused for a serial item',
        $refused(fn() => inv_apply_item_action(['action' => 'receive', 'qty' => '1', 'to_id' => (string)$store], $lap, null, $owner)) !== '');
    inv_apply_item_action(['action' => 'add_unit', 'to_id' => (string)$store, 'serial' => "ZZ-V-{$sfx}", 'condition' => 'new', 'purchase_value' => '90000'], $lap, null, $owner);
    $unit = (int) db_query('SELECT id FROM inv_assets WHERE item_id = :i', [':i' => $laptop])->fetchColumn();
    inv_apply_item_action(['action' => 'transfer', 'asset_id' => (string)$unit, 'to' => 'staff:' . $jane], $lap, null, $owner);
    check('action: a serial unit is assigned by unit', (int) db_query('SELECT location_id FROM inv_assets WHERE id = :a', [':a' => $unit])->fetchColumn() === $janeLoc);
    $row = array_values(array_filter(inv_central_list(['q' => 'ZZ View', 'status' => 'assigned'], null, 50, 0)['rows'], fn($r) => (int)$r['id'] === $laptop))[0] ?? null;
    check('list: "assigned" shows what people hold', $row !== null && (int)$row['qty'] === 1);
    check('list: the person filter', count(inv_central_list(['q' => 'ZZ View', 'person' => $jane], null, 50, 0)['rows']) === 2);

    // ── DB checks (tasks 2–3 insert their blocks above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
