<?php
declare(strict_types=1);
// Phase A — restaurant sync models (tables, hours, customers) + reservation
// status machine. Run: php tests/restaurant_sync_models.php
// Pure logic runs anywhere; DB CRUD runs in ONE rolled-back transaction when a
// migrated DB is reachable, else SKIPs. Requires add_restaurant_sync_models.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reservations.php';       // pulls sync.php (state machine)
require_once __DIR__ . '/../includes/restaurant-tables.php';
require_once __DIR__ . '/../includes/opening-hours.php';
require_once __DIR__ . '/../includes/customers.php';
require_once __DIR__ . '/../includes/sync-mappers.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Reservation status badges (new states) ───────────────────────────────────
check('badge pending → orange',   reservation_status_badge('pending')   === 'badge--orange');
check('badge confirmed → green',  reservation_status_badge('confirmed') === 'badge--green');
check('badge seated → blue',      reservation_status_badge('seated')    === 'badge--blue');
check('badge completed → grey',   reservation_status_badge('completed') === 'badge--grey');
check('badge cancelled → red',    reservation_status_badge('cancelled') === 'badge--red');
check('badge no_show → purple',   reservation_status_badge('no_show')   === 'badge--purple');

// ── State machine surfaces through reservations layer ────────────────────────
check('6 states known',           count(sync_reservation_states()) === 6);
check('confirmed→seated allowed', sync_reservation_transition_allowed('confirmed', 'seated'));
check('cancelled never revives',  sync_reservation_transition_allowed('cancelled', 'confirmed') === false);

// ── Mappers (Zuri contract, handover §6) ─────────────────────────────────────────────
$t = sync_map_restaurant_table(['venue_slug'=>'zuri','label'=>'T1','seats'=>4,'section'=>'Terrace','sort_order'=>2,'is_active'=>true]);
check('table: label → number',     $t['number'] === 'T1');
check('table: seats → capacity',   $t['capacity'] === 4);
check('table: section → zone',     $t['zone'] === 'Terrace');
check('table capacity clamped',    sync_map_restaurant_table(['label'=>'T2','seats'=>999])['capacity'] === 255);

$hOpen = sync_map_opening_hours(['venue_slug'=>'zuri','day_of_week'=>1,'open_time'=>'12:00:00','close_time'=>'22:30:00','is_closed'=>false]);
check('hours trims to HH:MM',      $hOpen['open_time'] === '12:00' && $hOpen['close_time'] === '22:30');
$hClosed = sync_map_opening_hours(['venue_slug'=>'zuri','day_of_week'=>2,'open_time'=>'12:00','close_time'=>'22:00','is_closed'=>true]);
check('closed day nulls times',    $hClosed['open_time'] === null && $hClosed['close_time'] === null && $hClosed['is_closed'] === true);

// ── Customer phone normalisation (backfill match key) ────────────────────────
check('phone strips symbols',      customer_normalize_phone('+254 700 123 456') === '254700123456');
check('phone empty stays empty',   customer_normalize_phone('') === '');

// ── DB CRUD (rolled back) ────────────────────────────────────────────────────
if (!rtables_supported() || !reservations_supported()) {
    echo "\nSKIP  DB assertions (add_restaurant_sync_models.sql not applied / DB unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

$venueId = (int) db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
if (!$venueId) { echo "\nSKIP  no venues seeded\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    // restaurant_tables CRUD
    $row = create_restaurant_table(['venue_id'=>$venueId,'label'=>'TST-1','seats'=>4,'section'=>'Test','is_active'=>true]);
    $tid = (int)($row['id'] ?? 0);
    check('table create returns row',   $tid > 0);
    check('table create sync_version=1',(int)($row['sync_version'] ?? 0) === 1);
    check('table create has sync_uuid', !empty($row['sync_uuid']));
    check('table update bumps version', update_restaurant_table($tid, ['label'=>'TST-1b','seats'=>6,'section'=>'Test','sort_order'=>1,'is_active'=>true]));
    $after = fetch_restaurant_table($tid);
    check('table version bumped to 2',  (int)($after['sync_version'] ?? 0) === 2);
    check('table soft-delete works',    delete_restaurant_table($tid));
    check('soft-deleted hidden from list', !array_filter(fetch_restaurant_tables([$venueId]), fn($r)=>(int)$r['id']===$tid));
    check('soft-deleted row still exists', (bool) fetch_restaurant_table($tid));   // not hard-deleted

    // opening_hours CRUD
    $h = create_opening_hour(['venue_id'=>$venueId,'day_of_week'=>1,'open_time'=>'12:00','close_time'=>'22:00','is_closed'=>false]);
    $hid = (int)($h['id'] ?? 0);
    check('hours create returns row',   $hid > 0);
    check('hours update bumps version', update_opening_hour($hid, ['day_of_week'=>1,'is_closed'=>true]));
    check('hours soft-delete works',    delete_opening_hour($hid));

    // customers upsert (Zuri-owned inbound)
    if (customers_supported()) {
        $cu = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $c1 = upsert_customer_from_sync($cu, ['name'=>'Test Guest','phone'=>'+254700111222','email'=>'g@example.com'], 3);
        check('customer upsert inserts',  (int)($c1['id'] ?? 0) > 0 && (string)$c1['sync_source'] === 'zuri');
        $c2 = upsert_customer_from_sync($cu, ['name'=>'Test Guest 2','phone'=>'+254700111222','email'=>'g@example.com'], 4);
        check('customer upsert updates same row', (int)$c2['id'] === (int)$c1['id'] && (int)$c2['sync_version'] === 4);
        $m = match_customer('0700 111 222', '');
        check('customer phone match (normalised)', $m && (int)$m['id'] === (int)$c1['id']);
    }

    // reservation state machine through set_reservation_status
    $res = create_reservation([
        'venue_id'=>$venueId,'reservation_date'=>'2099-06-15','reservation_time'=>'19:30',
        'party_size'=>2,'guest_name'=>'SM Test','guest_phone'=>'+254700000000',
    ]);
    $rid = (int)($res['id'] ?? 0);
    check('reservation created',            $rid > 0);
    check('pending→confirmed allowed',      set_reservation_status($rid, 'confirmed'));
    check('confirmed→seated allowed',       set_reservation_status($rid, 'seated'));
    check('seated→cancelled illegal',       set_reservation_status($rid, 'cancelled') === false);
    check('seated→completed allowed',       set_reservation_status($rid, 'completed'));
    check('completed→confirmed illegal (terminal)', set_reservation_status($rid, 'confirmed') === false);

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
