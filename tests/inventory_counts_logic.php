<?php
declare(strict_types=1);
// Inventory counts + people helpers. Run: php tests/inventory_counts_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-count-views.php';
require_once __DIR__ . '/../includes/inventory-people.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Who may count / resolve ─────────────────────────────────────────────────
$amani = ['kind' => 'property', 'venue_id' => 1, 'count_assignee_id' => null];
$store = ['kind' => 'store', 'venue_id' => null, 'count_assignee_id' => null];
$jane  = ['kind' => 'person', 'venue_id' => 1, 'count_assignee_id' => null];
check('count: owner counts anything', inv_can_count($store, 9, 'owner', null));
check('count: staff at the property count it', inv_can_count($amani, 5, 'staff', [1]));
check('count: staff elsewhere do not', !inv_can_count($amani, 5, 'staff', [3]));
check('count: staff do not count shared Main stock…', !inv_can_count($store, 5, 'staff', [1]));
check('count: …unless they are responsible for it', inv_can_count(['count_assignee_id' => 5] + $store, 5, 'staff', [1]));
check('count: managers count shared Main stock', inv_can_count($store, 7, 'manager', [1]));
check('count: a team member’s own location is never a count', !inv_can_count($jane, 9, 'owner', null));
check('resolve: owner resolves anywhere', inv_can_resolve($store, 'owner', null));
check('resolve: a manager resolves their own property', inv_can_resolve($amani, 'manager', [1]));
check('resolve: a manager does not resolve shared Main stock', !inv_can_resolve($store, 'manager', [1]));
check('resolve: staff never resolve', !inv_can_resolve($amani, 'staff', [1]));
check('resolve: a manager never resolves another property', inv_can_resolve(['kind' => 'property', 'venue_id' => 2], 'manager', [1]) === false);
check('count: a stale assignee does not bypass a venue-bound place', !inv_can_count(['kind' => 'property', 'venue_id' => 1, 'count_assignee_id' => 5], 5, 'staff', [3]));

// ── Sorting and gaps ────────────────────────────────────────────────────────
$sorted = inv_count_sort([
    ['name' => 'B', 'count_status' => 'ok'], ['name' => 'A', 'count_status' => 'manual'],
    ['name' => 'C', 'count_status' => 'overdue'], ['name' => 'D', 'count_status' => 'due'],
]);
check('sort: overdue, due, up to date, manual', array_column($sorted, 'name') === ['C', 'D', 'B', 'A']);
check('gap: short line valued at replacement', inv_count_line_gap(['expected' => 12, 'counted' => 11, 'replacement_value' => '400']) === ['gap' => -1, 'value' => 400.0]);
check('gap: extra line, no value known', inv_count_line_gap(['expected' => 5, 'counted' => 7, 'replacement_value' => null]) === ['gap' => 2, 'value' => null]);
check('due card: lists due places with a Count button', str_contains($card = inv_counts_due_card([['id' => 4, 'label' => 'My Amani › Pantry', 'count_status' => 'overdue', 'open_count_id' => null]], 0), 'Pantry') && str_contains($card, 'Count now'));
check('due card: skips places that are not due, and is empty when nothing is', inv_counts_due_card([['id' => 4, 'label' => 'X', 'count_status' => 'ok', 'open_count_id' => null]], 0) === '');
check('due card: shows the review count for managers', str_contains(inv_counts_due_card([], 3), '3 to review'));
check('due card: escapes the label', str_contains(inv_counts_due_card([['id' => 1, 'label' => '<script>x</script>', 'count_status' => 'due', 'open_count_id' => null, 'line_count' => 1]], 0), '&lt;script&gt;'));
check('due card: an open count links straight to it', str_contains(inv_counts_due_card([['id' => 4, 'label' => 'P', 'count_status' => 'due', 'open_count_id' => 77, 'line_count' => 2]], 0), 'inventory-count.php?count=77'));
check('due card: a due row with nothing expected is skipped', inv_counts_due_card([['id' => 1, 'label' => 'X', 'count_status' => 'due', 'open_count_id' => null, 'line_count' => 0]], 0) === '');

// ── DB-backed ───────────────────────────────────────────────────────────────
try { db()->query('SELECT 1'); } catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
if (!inv_supported()) { echo "\nSKIP  DB block (add_inventory.sql not applied)\n"; echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    $sfx = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $today = frontdesk_today_ymd();

    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Count A')", [':s' => "zz-cnt-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Count B')", [':s' => "zz-cnt-b-{$sfx}"]);
    $store = inv_store_location_id();
    $locA  = inv_property_location_id($vA);
    $locB  = inv_property_location_id($vB);
    $area  = inv_create_area($locA, 'ZZ Pantry');
    $mkUser = fn(string $role, string $tag) => $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, :r, :n, TRUE)",
        [':e' => "zz-cnt-{$tag}-{$sfx}@example.com", ':r' => $role, ':n' => "ZZ {$tag}"]);
    $owner = $mkUser('owner', 'owner'); $mgrA = $mkUser('manager', 'mgra'); $mgrB = $mkUser('manager', 'mgrb'); $maid = $mkUser('staff', 'maid');
    foreach ([[$mgrA, $vA], [$mgrB, $vB], [$maid, $vA]] as [$u, $v]) db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:u, :v)', [':u' => $u, ':v' => $v]);
    $glass = inv_create_item(['name' => 'ZZ Count glass', 'item_type' => 'operational', 'replacement_value' => 400]);
    inv_move(['item_id' => $glass, 'qty' => 20, 'to' => $store, 'reason' => 'receive']);
    inv_transfer($glass, 12, $store, $area, $owner);

    // Places to count.
    $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);
    $maidPlaces = $ids(inv_countable_locations($maid, 'staff', [$vA], $today));
    check('places: staff get their property and its area', in_array($locA, $maidPlaces, true) && in_array($area, $maidPlaces, true));
    check('places: staff do not get Main stock or another property', !in_array($store, $maidPlaces, true) && !in_array($locB, $maidPlaces, true));
    inv_update_location($store, ['count_every_days' => '', 'count_assignee_id' => $maid]);
    check('places: …Main stock appears once they are responsible for it', in_array($store, $ids(inv_countable_locations($maid, 'staff', [$vA], $today)), true));
    inv_update_location($area, ['count_every_days' => '7', 'count_assignee_id' => 0]);
    $due = inv_counts_due($maid, 'staff', [$vA], $today);
    check('due: a weekly place never counted is due', in_array($area, $ids($due), true) && !in_array($locA, $ids($due), true));
    $mgrAPlaces = $ids(inv_countable_locations($mgrA, 'manager', [$vA], $today));
    check('places: a manager gets Main stock + their property, not another', in_array($store, $mgrAPlaces, true) && in_array($locA, $mgrAPlaces, true) && !in_array($locB, $mgrAPlaces, true));
    inv_update_location($locB, ['count_every_days' => '7', 'count_assignee_id' => 0]);
    check('due: a scheduled place with nothing expected never nags', !in_array($locB, $ids(inv_counts_due($owner, 'owner', null, $today)), true));

    // Count → review queue.
    check('state: an empty place has nothing to count', inv_count_location_state($locB) === ['line_count' => 0, 'open_count_id' => null]);
    check('state: no open count before starting', inv_count_location_state($area) === ['line_count' => 1, 'open_count_id' => null]);
    $cid = inv_count_start($area, $maid);
    check('state: today’s open count is found', inv_count_location_state($area)['open_count_id'] === $cid);
    $sheet = inv_count_sheet($cid);
    check('sheet: lines carry item details and expected', $sheet && count($sheet['lines']) === 1 && (int)$sheet['lines'][0]['expected'] === 12 && $sheet['lines'][0]['name'] === 'ZZ Count glass');
    check('sheet: a non-existent count is null', inv_count_sheet(0) === null && inv_count_sheet(999999999) === null);
    check('line location: a non-existent line is null', inv_count_line_location(999999999) === null);
    inv_count_submit($cid, [$glass => 11], $maid);
    $qA = array_map(fn($c) => (int)$c['id'], inv_count_queue([$vA]));
    check('queue: the manager of the property sees the gap', in_array($cid, $qA, true));
    check('queue: another property’s manager does not', !in_array($cid, array_map(fn($c) => (int)$c['id'], inv_count_queue([$vB])), true));
    check('queue: size helper agrees', inv_count_queue_size([$vA]) === count(inv_count_queue([$vA])));
    $line = (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c', [':c' => $cid])->fetchColumn();
    $ll = inv_count_line_location($line);
    check('line location: resolves to the counted place', $ll && (int)$ll['id'] === $area && inv_can_resolve($ll, 'manager', [$vA]));
    check('line location: carries its count and a full label', (int)$ll['count_id'] === $cid && inv_location_label($ll) === 'ZZ Count A › ZZ Pantry');
    check('due: after counting, the place is no longer due', !in_array($area, $ids(inv_counts_due($maid, 'staff', [$vA], $today)), true));

    // Resolvable-only queue: a Main-stock gap is visible to a property manager, but Main stock is owner-resolved.
    $sizeAllBefore      = inv_count_queue_size([$vA]);
    $sizeResBefore      = inv_count_queue_size([$vA], true);
    $sizeOwnerResBefore = inv_count_queue_size(null, true);
    // Never reuse a same-day Main-stock count left open outside this test.
    db_query("UPDATE inv_counts SET status = 'cancelled' WHERE location_id = :l AND status = 'open'", [':l' => $store]);
    $cid2   = inv_count_start($store, $owner);
    $sheet2 = inv_count_sheet($cid2);
    $counts2 = [];
    foreach ($sheet2['lines'] as $ln) { $counts2[(int)$ln['item_id']] = (int)$ln['expected']; }
    $counts2[$glass] = max(0, $counts2[$glass] - 1);   // force a gap on our test item, whatever else is on the sheet
    inv_count_submit($cid2, $counts2, $owner);
    check('queue: Main stock’s gap is visible to a property manager', in_array($cid2, array_map(fn($c) => (int)$c['id'], inv_count_queue([$vA])), true));
    check('queue: …but not resolvable-only, since Main stock is owner-resolved', !in_array($cid2, array_map(fn($c) => (int)$c['id'], inv_count_queue([$vA], true)), true));
    check('queue size: the visible count rose by one', inv_count_queue_size([$vA]) === $sizeAllBefore + 1);
    check('queue size: the manager’s resolvable-only count did not', inv_count_queue_size([$vA], true) === $sizeResBefore);
    check('queue size: the owner’s resolvable-only view is unrestricted and rose by one', inv_count_queue_size(null, true) === $sizeOwnerResBefore + 1);

    // People.
    $jane = $ins("INSERT INTO hr_staff (full_name, venue_id) VALUES ('ZZ Count Jane', :v)", [':v' => $vA]);
    check('person: nothing held yet, no location created', inv_person_location_find($jane) === null && inv_person_assets($jane)['rows'] === []);
    $stock = inv_assignable_stock([$vA]);
    check('assignable: stock at the manager’s places and shared Main stock', (bool) array_filter($stock, fn($s) => (int)$s['item_id'] === $glass && (int)$s['location_id'] === $store));
    inv_apply_item_action(['action' => 'transfer', 'qty' => '2', 'from_id' => (string)$store, 'to' => 'staff:' . $jane], inv_fetch_item($glass), [$vA], $mgrA);
    $held = inv_person_assets($jane);
    check('person: holds 2 glasses, with value', count($held['rows']) === 1 && (int)$held['rows'][0]['qty'] === 2 && (float)$held['rows'][0]['value'] === 800.0 && $held['location'] !== null);
    $phone = inv_create_item(['name' => 'ZZ Count phone', 'item_type' => 'employee', 'tracking' => 'serial', 'replacement_value' => 30000]);
    $unit = inv_asset_create($phone, $store, ['serial' => "ZZ-CP-{$sfx}"], $owner);
    check('assignable: a serial unit in Main stock', (bool) array_filter(inv_assignable_units(null), fn($u) => (int)$u['id'] === $unit));
    inv_apply_item_action(['action' => 'transfer', 'asset_id' => (string)$unit, 'to' => 'staff:' . $jane], inv_fetch_item($phone), null, $owner);
    $held = inv_person_assets($jane);
    $ph = array_values(array_filter($held['rows'], fn($r) => (int)$r['item_id'] === $phone))[0] ?? null;
    check('person: the phone is listed with its serial', $ph && count($ph['units']) === 1 && $ph['units'][0]['serial'] === "ZZ-CP-{$sfx}");
    // ── DB checks (later tasks insert above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
