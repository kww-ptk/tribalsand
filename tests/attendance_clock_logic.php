<?php
declare(strict_types=1);
// Clock in/out — slot resolution, night shifts, token and device auth.
// Run: php tests/attendance_clock_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end.
// Requires add_hr_staff.sql + add_attendance.sql + add_attendance_punches.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Guards ──────────────────────────────────────────────────────────────────
check('punches guard returns a bool', is_bool(attendance_punches_supported()));
check('devices guard returns a bool', is_bool(attendance_devices_supported()));

// ── Token shape (pure) ──────────────────────────────────────────────────────
$t1 = clock_new_token();
$t2 = clock_new_token();
check('token is 32 hex chars',  (bool)preg_match('/^[0-9a-f]{32}$/', $t1));
check('tokens differ',          $t1 !== $t2);

// ── Slot resolution (pure). $row is an attendance row, or [] for no row. ─────
$empty  = [];
$in1    = ['in1' => 420];                                   // 07:00
$closed = ['in1' => 420, 'out1' => 720];                    // 07:00–12:00
$in2    = ['in1' => 420, 'out1' => 720, 'in2' => 780];      // back at 13:00
$full   = ['in1' => 420, 'out1' => 720, 'in2' => 780, 'out2' => 1020];

check('empty + in  -> in1',     clock_next_slot($empty,  'in')  === 'in1');
check('empty + out -> null',    clock_next_slot($empty,  'out') === null);
check('in1 + in    -> null',    clock_next_slot($in1,    'in')  === null);
check('in1 + out   -> out1',    clock_next_slot($in1,    'out') === 'out1');
check('closed + in -> in2',     clock_next_slot($closed, 'in')  === 'in2');
check('closed + out-> null',    clock_next_slot($closed, 'out') === null);
check('in2 + out   -> out2',    clock_next_slot($in2,    'out') === 'out2');
check('in2 + in    -> null',    clock_next_slot($in2,    'in')  === null);
check('full + in   -> null',    clock_next_slot($full,   'in')  === null);
check('full + out  -> null',    clock_next_slot($full,   'out') === null);

// A non-worked status (leave, off) must not silently accept a punch.
check('status row + in -> null', clock_next_slot(['status' => 'LV'], 'in') === null);

// An open row is one with an in and no matching out — used for the night-shift rule.
check('open: in1 only',      clock_row_is_open($in1)    === true);
check('open: closed pair',   clock_row_is_open($closed) === false);
check('open: in2 only',      clock_row_is_open($in2)    === true);
check('open: full',          clock_row_is_open($full)   === false);
check('open: empty',         clock_row_is_open($empty)  === false);

// ── The merge rule (pure). THIS is the trap: attendance_upsert() is a whole-row
//    upsert, so a punch that passes only its own slot wipes the others. ───────
$merged = clock_merge_times(['in1' => 420], 'out1', 720);
check('merge keeps in1',        ($merged['in1']  ?? null) === '07:00');
check('merge sets out1',        ($merged['out1'] ?? null) === '12:00');
check('merge leaves in2 null',  ($merged['in2']  ?? null) === null);
check('merge leaves out2 null', ($merged['out2'] ?? null) === null);

$merged2 = clock_merge_times(['in1' => 420, 'out1' => 720, 'in2' => 780], 'out2', 1020);
check('merge keeps all three',  ($merged2['in1'] ?? null) === '07:00'
                             && ($merged2['out1'] ?? null) === '12:00'
                             && ($merged2['in2'] ?? null) === '13:00');
check('merge sets out2',        ($merged2['out2'] ?? null) === '17:00');

// Crossing midnight: 07:00 the next morning on yesterday's row is 1440 + 420.
check('past-midnight renders',  clock_merge_times(['in1' => 1140], 'out1', 1860)['out1'] === '31:00');

// Minutes helper
check('min from 07:02',   clock_minutes_from_hms('07:02:00') === 422);
check('min from 00:00',   clock_minutes_from_hms('00:00:00') === 0);
check('min from 23:59',   clock_minutes_from_hms('23:59:59') === 1439);

// ── Photo expiry labelling (pure) ───────────────────────────────────────────
// "photo expired" and "no photo" must not look the same: a purged image is
// otherwise indistinguishable from a camera that failed, and someone reviewing
// a disputed shift would draw the wrong conclusion.
check('has photo is never expired',   clock_photo_expired(['photo_key'=>'k','punched_at'=>date('Y-m-d H:i:s', strtotime('-90 days'))]) === false);
check('recent missing is not expired',clock_photo_expired(['photo_key'=>null,'punched_at'=>date('Y-m-d H:i:s')]) === false);
check('old missing is expired',       clock_photo_expired(['photo_key'=>null,'punched_at'=>date('Y-m-d H:i:s', strtotime('-60 days'))]) === true);
check('unparseable date is not expired', clock_photo_expired(['photo_key'=>null,'punched_at'=>'not a date']) === false);

// ── DB round-trip, inside a transaction we roll back ────────────────────────
$dbOk = true;
try { db(); } catch (Throwable $e) { $dbOk = false; }

if (!$dbOk || !attendance_punches_supported()) {
    echo "\nSKIP  DB assertions (no database, or add_attendance_punches.sql not applied)\n";
} else {
    db()->beginTransaction();
    try {
        $vid = (int) db_query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn();
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Clock Probe', :v, 'active')", [':v' => $vid]);
        $sid = (int) db()->lastInsertId('hr_staff_id_seq');

        $tok = clock_ensure_token($sid);
        check('token minted',            (bool)preg_match('/^[0-9a-f]{32}$/', $tok));
        check('token is stable',         clock_ensure_token($sid) === $tok);
        $found = clock_staff_by_token($tok);
        check('token resolves to person',(int)($found['id'] ?? 0) === $sid);
        check('unknown token resolves to nothing', clock_staff_by_token('deadbeef') === null);
        check('empty token resolves to nothing',   clock_staff_by_token('') === null);

        $new = clock_reissue_token($sid);
        check('reissue changes the token',  $new !== $tok);
        check('old card no longer works',   clock_staff_by_token($tok) === null);
        check('new card works',             (int)(clock_staff_by_token($new)['id'] ?? 0) === $sid);

        // ── Kiosk devices ───────────────────────────────────────────────────
        [$devId, $devTok] = clock_register_device('Probe tablet', $vid, null);
        check('device id returned',      $devId > 0);
        check('device token is 32 hex',  (bool)preg_match('/^[0-9a-f]{32}$/', $devTok));

        $dev = clock_device_by_token($devTok);
        check('device token resolves',   (int)($dev['id'] ?? 0) === $devId);
        check('wrong token refused',     clock_device_by_token(clock_new_token()) === null);
        check('empty token refused',     clock_device_by_token('') === null);

        // The plaintext token must NOT be recoverable from the row.
        $stored = (string) db_query("SELECT token_hash FROM attendance_devices WHERE id = :i", [':i' => $devId])->fetchColumn();
        check('token stored hashed',     $stored !== $devTok && $stored !== '');

        clock_revoke_device($devId);
        check('revoked device refused',  clock_device_by_token($devTok) === null);

        // ── Recording punches ───────────────────────────────────────────────
        $today = frontdesk_today_ymd();
        $yest  = date('Y-m-d', strtotime('-1 day', strtotime($today)));

        $r1 = clock_record_punch($sid, 'in', 422, $today, $devId, $vid, null);   // 07:02
        check('in accepted',        ($r1['ok'] ?? false) === true);
        check('in filled in1',      ($r1['slot'] ?? '') === 'in1');

        $dup = clock_record_punch($sid, 'in', 423, $today, $devId, $vid, null);
        check('second in refused',  ($dup['ok'] ?? true) === false);

        $r2 = clock_record_punch($sid, 'out', 720, $today, $devId, $vid, null);  // 12:00
        check('out accepted',       ($r2['ok'] ?? false) === true);
        check('out filled out1',    ($r2['slot'] ?? '') === 'out1');

        // THE TRAP: out1 must not have erased in1.
        $row = db_query("SELECT in1, out1 FROM attendance WHERE hr_staff_id = :s AND work_date = :d",
                        [':s' => $sid, ':d' => $today])->fetch();
        check('in1 survived the out punch', (int)$row['in1'] === 422);
        check('out1 stored',                (int)$row['out1'] === 720);
        check('punch rows written',
              (int) db_query("SELECT count(*) FROM attendance_punches WHERE hr_staff_id = :s", [':s'=>$sid])->fetchColumn() === 2);

        // A correction inside the window must NOT be refused: in, out, then a
        // genuine second in all within seconds targets in1, out1, in2 — three
        // different slots, so none is a duplicate.
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Fast Probe', :v, 'active')", [':v'=>$vid]);
        $fid = (int) db()->lastInsertId('hr_staff_id_seq');
        $f1 = clock_record_punch($fid, 'in',  420, $today, $devId, $vid, null);
        $f2 = clock_record_punch($fid, 'out', 421, $today, $devId, $vid, null);
        $f3 = clock_record_punch($fid, 'in',  422, $today, $devId, $vid, null);
        check('rapid in accepted',        ($f1['ok'] ?? false) === true);
        check('rapid correction out ok',  ($f2['ok'] ?? false) === true);
        check('rapid re-entry ok (in2)',  ($f3['ok'] ?? false) === true && ($f3['slot'] ?? '') === 'in2');

        // ── Night shift: yesterday open, clocking out this morning ──────────
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Night Probe', :v, 'active')", [':v'=>$vid]);
        $nid = (int) db()->lastInsertId('hr_staff_id_seq');
        db_query("INSERT INTO attendance (hr_staff_id, work_date, in1) VALUES (:s, :d, 1140)", [':s'=>$nid, ':d'=>$yest]); // 19:00

        $n = clock_record_punch($nid, 'out', 420, $today, $devId, $vid, null);   // 07:00 today
        check('night out accepted',      ($n['ok'] ?? false) === true);
        check('night out hit yesterday', ($n['work_date'] ?? '') === $yest);
        $nrow = db_query("SELECT in1, out1 FROM attendance WHERE hr_staff_id = :s AND work_date = :d",
                         [':s'=>$nid, ':d'=>$yest])->fetch();
        check('night out stored past midnight', (int)$nrow['out1'] === 1860);
        check('night in1 untouched',            (int)$nrow['in1']  === 1140);

        check('rate limiter is off at low volume', clock_rate_limited($sid) === false);
        check('rate limiter trips at the cap',      clock_rate_limited($sid, 1) === true);

        // ── Photo retention ─────────────────────────────────────────────────
        // The image is purged after 30 days; the punch record is kept forever.
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Purge Probe', :v, 'active')", [':v'=>$vid]);
        $pid = (int) db()->lastInsertId('hr_staff_id_seq');
        foreach ([['-1 hour', 'recent.jpg'], ['-45 days', 'old.jpg']] as [$age, $pkey]) {
            db_query("INSERT INTO attendance_punches (hr_staff_id, kind, work_date, slot, photo_key, punched_at)
                      VALUES (:s, 'in', CURRENT_DATE, 'in1', :k, now() + (:a)::interval)",
                     [':s'=>$pid, ':k'=>$pkey, ':a'=>$age]);
        }
        $before = (int) db_query("SELECT count(*) FROM attendance_punches WHERE hr_staff_id = :s", [':s'=>$pid])->fetchColumn();
        $dry = clock_purge_old_photos(true);
        check('dry run finds the old one', ($dry['checked'] ?? 0) === 1);
        check('dry run deletes nothing',   ($dry['deleted'] ?? -1) === 0);
        $pur = clock_purge_old_photos();
        check('purge deletes the old one', ($pur['deleted'] ?? 0) === 1);
        check('recent photo survives',
              (string) db_query("SELECT photo_key FROM attendance_punches WHERE hr_staff_id = :s AND photo_key IS NOT NULL", [':s'=>$pid])->fetchColumn() === 'recent.jpg');
        check('punch rows are kept',
              (int) db_query("SELECT count(*) FROM attendance_punches WHERE hr_staff_id = :s", [':s'=>$pid])->fetchColumn() === $before);
        check('purge is idempotent',       (clock_purge_old_photos()['deleted'] ?? -1) === 0);

        // ── The owner's kill switch ─────────────────────────────────────────
        // Default OFF so the feature can ship dark, and a punch from an
        // already-registered tablet must stop the moment it is switched off.
        $wasOn = clock_kiosk_enabled();
        clock_kiosk_set_enabled(false);
        check('switch reads off',        clock_kiosk_enabled() === false);
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Switch Probe', :v, 'active')", [':v'=>$vid]);
        $swid = (int) db()->lastInsertId('hr_staff_id_seq');
        clock_kiosk_set_enabled(true);
        check('switch reads on',         clock_kiosk_enabled() === true);
        $onPunch = clock_record_punch($swid, 'in', 420, $today, $devId, $vid, null);
        check('punch works while on',    ($onPunch['ok'] ?? false) === true);
        clock_kiosk_set_enabled($wasOn);   // restore, though the tx rolls back anyway

        // A leave day refuses a punch outright.
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Leave Probe', :v, 'active')", [':v'=>$vid]);
        $lid = (int) db()->lastInsertId('hr_staff_id_seq');
        db_query("INSERT INTO attendance (hr_staff_id, work_date, status) VALUES (:s, :d, 'LV')", [':s'=>$lid, ':d'=>$today]);
        check('leave day refuses a punch', (clock_record_punch($lid, 'in', 420, $today, $devId, $vid, null)['ok'] ?? true) === false);
    } finally {
        db()->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
