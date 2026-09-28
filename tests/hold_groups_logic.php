<?php
declare(strict_types=1);
// Multi-room bookings — hold every room of a combination or none; confirm / cancel as one.
// Run: php tests/hold_groups_logic.php
// Pure rules always run. The DB block runs inside ONE rolled-back transaction and
// SKIPs with no DB, without add_hold_groups.sql, or without a venue with 2+ units.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hold-groups.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Request cleaning ────────────────────────────────────────────────────────
[$r, $e] = hold_group_clean_rooms([['slug' => 'garden', 'units' => 1], ['slug' => 'ocean', 'units' => 2], ['slug' => 'garden', 'units' => 1]]);
check('clean: repeats merge', $e === null && $r === [['slug' => 'garden', 'units' => 2], ['slug' => 'ocean', 'units' => 2]]);
check('clean: a single room is not a combination', hold_group_clean_rooms([['slug' => 'garden', 'units' => 1]])[1] !== null);
check('clean: nothing posted', hold_group_clean_rooms(null)[1] !== null && hold_group_clean_rooms([])[1] !== null);
check('clean: a bad slug is refused', hold_group_clean_rooms([['slug' => '../x', 'units' => 1], ['slug' => 'a', 'units' => 1]])[1] !== null);
check('clean: zero / huge counts refused', hold_group_clean_rooms([['slug' => 'a', 'units' => 0], ['slug' => 'b', 'units' => 1]])[1] !== null
    && hold_group_clean_rooms([['slug' => 'a', 'units' => 13], ['slug' => 'b', 'units' => 1]])[1] !== null);
check('clean: too many rooms in total refused', hold_group_clean_rooms([['slug' => 'a', 'units' => 7], ['slug' => 'b', 'units' => 6]])[1] !== null);
check('clean: too many room types refused', hold_group_clean_rooms(array_map(fn($i) => ['slug' => "r{$i}", 'units' => 1], range(1, 7)))[1] !== null);
check('ref: shape', (bool) preg_match('/^G-\d{6}-[0-9A-F]{6}$/', hold_group_new_ref()));

$rows = [['id' => 1, 'room_name' => 'Garden Room', 'unit_name' => 'G1', 'access_code' => 'A'], ['id' => 2, 'room_name' => 'Garden Room', 'unit_name' => 'G2'], ['id' => 3, 'room_name' => 'Ocean Suite', 'unit_name' => 'O1']];
check('label: counts repeats', hold_group_label($rows) === 'Garden Room ×2 + Ocean Suite');
$m = hold_group_mail_row($rows);
check('mail row: the lead hold, labelled with every room', $m['id'] === 1 && $m['room_name'] === 'Garden Room ×2 + Ocean Suite' && $m['unit_name'] === '3 rooms' && $m['access_code'] === 'A');
check('mail row: a group of one is unchanged', hold_group_mail_row([$rows[0]]) === $rows[0]);

// ── DB block ────────────────────────────────────────────────────────────────
try { db()->query('SELECT 1'); } catch (Throwable $e) { echo "\nSKIP  DB block\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }
if (!hold_groups_supported()) { echo "\nSKIP  DB block (add_hold_groups.sql not applied)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

// A venue with a room type that has 2+ active units, plus another bookable room.
$pick = db_query("SELECT r.id, r.venue_id FROM rooms r JOIN units u ON u.room_id = r.id AND u.is_active
                   WHERE r.is_published AND NOT COALESCE(r.is_entire_place, FALSE) AND r.venue_id IS NOT NULL
                   GROUP BY r.id, r.venue_id HAVING COUNT(*) >= 2 ORDER BY r.id LIMIT 1")->fetch();
$other = $pick ? db_query("SELECT r.id FROM rooms r JOIN units u ON u.room_id = r.id AND u.is_active
                            WHERE r.venue_id = :v AND r.id <> :r AND r.is_published AND NOT COALESCE(r.is_entire_place, FALSE)
                            GROUP BY r.id ORDER BY r.id LIMIT 1", [':v' => $pick['venue_id'], ':r' => $pick['id']])->fetchColumn() : false;
if (!$pick || !$other) { echo "\nSKIP  DB block (no venue with a 2-unit room + another room)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

require_once __DIR__ . '/../includes/bookings.php';
db()->beginTransaction();
try {
    if (function_exists('acct_supported') || is_file(__DIR__ . '/../includes/acct.php')) { require_once __DIR__ . '/../includes/acct.php'; if (acct_supported()) db_query('UPDATE companies SET accounting_starts_on = NULL'); }
    $ci = date('Y-m-d', strtotime('+400 days')); $co = date('Y-m-d', strtotime('+403 days'));
    db_query("UPDATE rooms SET form_mode = 'availability', capacity = 2 WHERE id IN (:a, :b)", [':a' => $pick['id'], ':b' => $other]);
    $venue = db_query('SELECT * FROM venues WHERE id = :v', [':v' => $pick['venue_id']])->fetch();
    $slugA = (string) db_query('SELECT slug FROM rooms WHERE id = :r', [':r' => $pick['id']])->fetchColumn();
    $slugB = (string) db_query('SELECT slug FROM rooms WHERE id = :r', [':r' => $other])->fetchColumn();
    $unitsA = (int) db_query('SELECT COUNT(*) FROM units WHERE room_id = :r AND is_active', [':r' => $pick['id']])->fetchColumn();
    db_query('INSERT INTO submissions (type, guest_name, guest_email) VALUES (\'enquiry\', \'ZZ Group\', \'zz@x.test\')');
    $sub = (int) db()->lastInsertId();

    $res = hold_group_resolve($venue, [['slug' => $slugA, 'units' => 2], ['slug' => $slugB, 'units' => 1]], 6);
    check('resolve: all rooms in availability mode → hold', $res['error'] === null && $res['mode'] === 'hold');
    check('resolve: a party too big for the rooms is refused', hold_group_resolve($venue, [['slug' => $slugA, 'units' => 2], ['slug' => $slugB, 'units' => 1]], 7)['error'] !== null);
    $foreign = db_query('SELECT slug FROM rooms WHERE venue_id <> :v AND is_published LIMIT 1', [':v' => $venue['id']])->fetchColumn();
    if ($foreign) check('resolve: a room of another property is refused', hold_group_resolve($venue, [['slug' => $slugA, 'units' => 1], ['slug' => (string)$foreign, 'units' => 1]], 2)['error'] !== null);
    db_query("UPDATE rooms SET form_mode = 'enquiry' WHERE id = :b", [':b' => $other]);
    check('resolve: one enquiry-only room makes the whole request an enquiry', hold_group_resolve($venue, [['slug' => $slugA, 'units' => 1], ['slug' => $slugB, 'units' => 1]], 2)['mode'] === 'enquiry');
    db_query("UPDATE rooms SET form_mode = 'availability' WHERE id = :b", [':b' => $other]);

    $ids = hold_group_create($res, $sub, $ci, $co, 'ZZ Group', 'zz@x.test');
    check('create: every room held', is_array($ids) && count($ids) === 3);
    $h = db_query('SELECT id, unit_id, group_ref, status FROM holds WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll();
    check('create: two units of one room type are two different units', count(array_unique(array_column($h, 'unit_id'))) === 3);
    check('create: they share one group reference', count(array_unique(array_column($h, 'group_ref'))) === 1 && $h[0]['group_ref'] !== null);
    check('create: each has its block', (int) db_query('SELECT COUNT(*) FROM availability_blocks WHERE hold_id IN (' . implode(',', $ids) . ')')->fetchColumn() === 3);
    check('group: any member finds the whole group', hold_group_ids($ids[2]) === $ids);

    // All or nothing: ask for more units of A than exist → no hold at all.
    $before = (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn();
    $tooMany = hold_group_resolve($venue, [['slug' => $slugA, 'units' => $unitsA + 1], ['slug' => $slugB, 'units' => 1]], 2);
    check('all-or-nothing: an impossible combination holds nothing', hold_group_create($tooMany, $sub, $ci, $co, 'ZZ Two', 'zz2@x.test') === false
        && (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn() === $before);
    check('all-or-nothing: …and the transaction is still usable', (int) db_query('SELECT 1')->fetchColumn() === 1);

    // Confirm as one.
    $g = hold_group_confirm($ids[1], null);
    check('confirm: confirming one room confirms all of them', $g['ids'] === $ids
        && (int) db_query("SELECT COUNT(*) FROM holds WHERE id IN (" . implode(',', $ids) . ") AND status = 'confirmed'")->fetchColumn() === 3);
    check('confirm: blocks become bookings', (int) db_query("SELECT COUNT(*) FROM availability_blocks WHERE block_type = 'booked' AND hold_id IN (" . implode(',', $ids) . ')')->fetchColumn() === 3);
    if (bookings_supported()) check('confirm: each room reaches the revenue ledger', (int) db_query('SELECT COUNT(*) FROM bookings WHERE hold_id IN (' . implode(',', $ids) . ')')->fetchColumn() === 3);
    check('confirm: ONE email row naming every room', $g['mail_row'] && str_contains($g['mail_row']['room_name'], ' + ') && $g['mail_row']['unit_name'] === '3 rooms');
    check('confirm: confirming again does nothing', hold_group_confirm($ids[0], null)['ids'] === []);

    // Cancel as one.
    $c = hold_group_cancel($ids[0], null);
    check('cancel: cancelling one room cancels all and frees the dates', $c['ids'] === $ids
        && (int) db_query('SELECT COUNT(*) FROM availability_blocks WHERE hold_id IN (' . implode(',', $ids) . ')')->fetchColumn() === 0);

    // A single hold is a group of one — unchanged behaviour.
    $unit = find_available_unit((int)$pick['id'], $ci, $co);
    $single = create_hold_with_block((int)$unit['id'], $sub, $ci, $co, 'ZZ Solo', 'solo@x.test', 'pending', 24, null, (int)$pick['id']);
    check('single: a hold with no group is a group of one', hold_group_ids($single) === [$single]);
    $s1 = hold_group_confirm($single, null);
    check('single: confirm works exactly as before', $s1['ids'] === [$single] && $s1['mail_row']['id'] == $single && !str_contains((string)$s1['mail_row']['room_name'], ' + '));
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
check('db: the test left nothing behind', (int) db_query("SELECT COUNT(*) FROM holds WHERE guest_name LIKE 'ZZ %'")->fetchColumn() === 0);

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
