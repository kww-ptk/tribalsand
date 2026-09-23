<?php
declare(strict_types=1);
// Opening hours (ONE record) + restaurant tables → outbox (Zuri sync tasks 1–2).
// Run: php tests/restaurant_setup_sync.php
// Pure checks run anywhere; the DB round-trip runs in ONE rolled-back transaction
// when add_restaurant_sync(_models) + add_restaurant_hours are applied, else SKIPs.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant-hours.php';
require_once __DIR__ . '/../includes/restaurant-tables.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure: hours ─────────────────────────────────────────────────────────────
$m = menu_sync_map('opening_hours', ['lunch' => 'Noon – 3', 'dinner' => null, 'first_slot' => '12:00:00',
    'last_slot' => '21:30:00', 'slot_minutes' => 30, 'duration_minutes' => 90]);
check('hours mapper: exact contract keys', array_keys($m) === ['lunch', 'dinner', 'first_slot', 'last_slot', 'slot_minutes', 'duration_minutes']);
check('hours mapper: HH:MM',               $m['first_slot'] === '12:00' && $m['last_slot'] === '21:30');

$ok = rhours_validate(['lunch' => ' 12–3 ', 'dinner' => '', 'first_slot' => '9:00', 'last_slot' => '22:00',
    'slot_minutes' => '30', 'duration_minutes' => '90']);
check('hours validate: accepts a normal day', $ok['errors'] === []);
check('hours validate: normalises',           $ok['data']['first_slot'] === '09:00' && $ok['data']['lunch'] === '12–3' && $ok['data']['dinner'] === null);
$bad = rhours_validate(['first_slot' => '22:00', 'last_slot' => '12:00', 'slot_minutes' => 10, 'duration_minutes' => 5]);
check('hours validate: last before first',    isset($bad['errors']['last_slot']));
check('hours validate: slot < 15 refused',    isset($bad['errors']['slot_minutes']));
check('hours validate: duration < 15 refused', isset($bad['errors']['duration_minutes']));
check('hours validate: "+1" refused',         isset(rhours_validate(['first_slot' => '23:00+1'])['errors']['first_slot']));
check('hours validate: 24:00 refused',        rhours_hm('24:00') === null);
check('hours comparable ignores seconds',     rhours_comparable(['first_slot' => '12:00:00']) ['first_slot'] === '12:00');

// ── Pure: tables ────────────────────────────────────────────────────────────
check('table validate: ok',                   rtable_validate(['label' => 'T1', 'seats' => '4'])['errors'] === []);
check('table validate: number required',      isset(rtable_validate(['label' => ' ', 'seats' => 4])['errors']['label']));
check('table validate: number ≤10',           isset(rtable_validate(['label' => 'Terrace-101', 'seats' => 4])['errors']['label']));
check('table validate: seats 1–255',          isset(rtable_validate(['label' => 'T1', 'seats' => 0])['errors']['seats'])
                                              && isset(rtable_validate(['label' => 'T1', 'seats' => 256])['errors']['seats']));
check('table validate: name ≤80',           isset(rtable_validate(['label' => '10', 'seats' => 12, 'name' => str_repeat('x', 81)])['errors']['name'])
                                              && rtable_validate(['label' => '10', 'seats' => 12, 'name' => 'Private Dining'])['errors'] === []);
check('table validate: zone ≤60',             isset(rtable_validate(['label' => 'T1', 'seats' => 2, 'section' => str_repeat('x', 61)])['errors']['section']));

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!sync_supported() || !rtables_supported() || !rhours_supported()) {
    echo "\nSKIP  DB assertions (sync / restaurant models / restaurant_hours migrations not applied, or DB unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
$zuri  = (int) (db_query('SELECT id FROM venues WHERE slug = :s', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
$other = (int) (db_query('SELECT id FROM venues WHERE slug <> :s ORDER BY id LIMIT 1', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
if (!$zuri || !$other) {
    echo "\nSKIP  DB assertions (need the '" . sync_venue_slug() . "' venue and one other)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
function ob_ops(string $uuid): array {
    return db_query('SELECT operation FROM sync_outbox WHERE sync_uuid = :u ORDER BY id', [':u' => $uuid])->fetchAll(PDO::FETCH_COLUMN);
}

db()->beginTransaction();
try {
    // Hours: start from a clean slate for both venues inside the rollback.
    db_query('DELETE FROM restaurant_hours WHERE venue_id IN (:a, :b)', [':a' => $zuri, ':b' => $other]);
    $d = rhours_validate(['first_slot' => '12:00', 'last_slot' => '22:00', 'slot_minutes' => 30, 'duration_minutes' => 90])['data'];
    $h1 = save_restaurant_hours($zuri, $d);
    $uuid = (string) $h1['sync_uuid'];
    check('hours create queues create',         ob_ops($uuid) === ['create']);
    $h2 = save_restaurant_hours($zuri, $d);
    check('unchanged save emits nothing',       ob_ops($uuid) === ['create'] && (int) $h2['sync_version'] === 1);
    $h3 = save_restaurant_hours($zuri, ['lunch' => '12:00 – 15:00'] + $d);
    check('edit queues update + bumps version', ob_ops($uuid) === ['create', 'update'] && (int) $h3['sync_version'] === 2);
    check('uuid stable across saves',           (string) $h3['sync_uuid'] === $uuid);
    $p = json_decode((string) db_query("SELECT payload FROM sync_outbox WHERE sync_uuid = :u AND operation = 'update'", [':u' => $uuid])->fetchColumn(), true);
    check('hours payload = contract shape',     $p['entity'] === 'opening_hours' && $p['data']['lunch'] === '12:00 – 15:00');
    $ho = save_restaurant_hours($other, $d);
    check('other venue hours never emit',       ob_ops((string) $ho['sync_uuid']) === []);
    check('backfill carries the hours uuid',    in_array(['sync_uuid' => $uuid], sync_backfill_export()['opening_hours'], true));

    // Tables: create / update / soft delete on Zuri emit; another venue never does.
    $t = create_restaurant_table(['venue_id' => $zuri, 'label' => 'TST9', 'seats' => 4, 'section' => 'Terrace', 'is_active' => true]);
    $tu = (string) $t['sync_uuid'];
    check('table create queues create',         ob_ops($tu) === ['create']);
    check('table update',                       update_restaurant_table((int) $t['id'], ['label' => 'TST9', 'seats' => 6, 'is_active' => true]));
    check('table update queues update',         ob_ops($tu) === ['create', 'update']);
    check('table version bumped once',          (int) fetch_restaurant_table((int) $t['id'])['sync_version'] === 2);
    check('table label taken',                  rtable_label_taken($zuri, 'tst9') && !rtable_label_taken($zuri, 'tst9', (int) $t['id']));
    check('table soft delete',                  delete_restaurant_table((int) $t['id']));
    check('table delete queues delete',         ob_ops($tu) === ['create', 'update', 'delete']);
    check('repeat delete not re-announced',     !delete_restaurant_table((int) $t['id']) && count(ob_ops($tu)) === 3);
    check('update of a deleted table refused',  !update_restaurant_table((int) $t['id'], ['label' => 'X', 'seats' => 2]));
    $to = create_restaurant_table(['venue_id' => $other, 'label' => 'TST9', 'seats' => 2, 'is_active' => true]);
    check('other venue table never emits',      ob_ops((string) $to['sync_uuid']) === []);

    SyncContext::applying(fn() => create_restaurant_table(['venue_id' => $zuri, 'label' => 'TSTA', 'seats' => 2, 'is_active' => true]));
    $ta = (string) db_query("SELECT sync_uuid FROM restaurant_tables WHERE venue_id = :v AND label = 'TSTA'", [':v' => $zuri])->fetchColumn();
    check('no event while applying',            ob_ops($ta) === []);

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
