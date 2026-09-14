<?php
declare(strict_types=1);
// Attendance rules engine — time parsing, hours, overtime, off-days, upsert,
// month summary. Run: php tests/attendance_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK, so no real
// attendance rows are left behind. Requires add_hr_staff + add_attendance.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ──────────────────────────────────────────────────────
check('hhmm→min 08:00 = 480',            attendance_hhmm_to_min('08:00') === 480);
check('hhmm→min 19:00 = 1140',           attendance_hhmm_to_min('19:00') === 1140);
check('hhmm→min next-day 07:00+1 = 1860', attendance_hhmm_to_min('07:00+1') === 1860);
check('hhmm→min blank = null',           attendance_hhmm_to_min('') === null);
check('hhmm→min garbage = null',         attendance_hhmm_to_min('nope') === null);
check('min→hhmm 1860 = 07:00+1',         attendance_min_to_hhmm(1860) === '07:00+1');
check('min→hhmm null = empty',           attendance_min_to_hhmm(null) === '');

$std     = ['in1'=>480,'out1'=>780,'in2'=>840,'out2'=>1020];  // 8h split
$secNight= ['in1'=>1140,'out1'=>1860,'in2'=>null,'out2'=>null]; // 19:00→07:00 = 12h
check('hours: standard split = 8',       attendance_hours($std) === 8.0);
check('hours: security night = 12',      attendance_hours($secNight) === 12.0);
check('hours: empty = 0',                attendance_hours(['in1'=>null,'out1'=>null,'in2'=>null,'out2'=>null]) === 0.0);

check('standard hrs: Security = 12',     attendance_standard_hours('Security') === 12);
check('standard hrs: Housekeeping = 8',  attendance_standard_hours('Housekeeping') === 8);
check('OT: 8h normal = 0',               attendance_overtime($std, 'Housekeeping') === 0.0);
check('OT: 12h security = 0',            attendance_overtime($secNight, 'Security') === 0.0);
check('OT: 12h normal = +4',            attendance_overtime($secNight, 'Housekeeping') === 4.0);

check('effective: worked ⇒ P',           attendance_effective_status($std) === 'P');
check('effective: stored OFF',           attendance_effective_status(['in1'=>null,'out1'=>null,'in2'=>null,'out2'=>null,'status'=>'OFF']) === 'OFF');

check('off-day: blank ⇒ Sunday off',     attendance_is_off_day('', '2026-09-13') === true);   // 2026-09-13 is a Sun
check('off-day: blank not off Monday',   attendance_is_off_day('', '2026-09-14') === false);
check('off-day: TUE matches a Tuesday',  attendance_is_off_day('TUE', '2026-09-15') === true);

check('status label: SK',                attendance_status_label('SK') === 'Sick');
check('status badge: ABS → red',         attendance_status_badge('ABS') === 'badge--red');
check('status badge: P → green',         attendance_status_badge('P') === 'badge--green');

// ── Person summary (pure over rows) ─────────────────────────────────────────
$days = [
    1 => $std,                                   // P 8h
    2 => ['in1'=>480,'out1'=>780,'in2'=>840,'out2'=>1080], // P 9h (+1 OT normal)
    3 => ['in1'=>null,'out1'=>null,'in2'=>null,'out2'=>null,'status'=>'OFF'],
    4 => ['in1'=>null,'out1'=>null,'in2'=>null,'out2'=>null,'status'=>'SK'],
    5 => ['in1'=>null,'out1'=>null,'in2'=>null,'out2'=>null,'status'=>'PH'],
];
$sum = attendance_person_summary($days, 'Housekeeping');
check('summary: daysWorked = 2',         $sum['daysWorked'] === 2);
check('summary: hours = 17',             $sum['hours'] === 17.0);
check('summary: ot = 1',                 $sum['ot'] === 1.0);
check('summary: normalOff = 1',          $sum['normalOff'] === 1);
check('summary: sick = 1',               $sum['sick'] === 1);
check('summary: ph = 1',                 $sum['ph'] === 1);

if (!attendance_supported() || !hr_staff_supported()) {
    echo "\nSKIP  DB assertions (add_attendance / add_hr_staff not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

$sid = (int) db_query("SELECT id FROM hr_staff ORDER BY id LIMIT 1")->fetchColumn();
if (!$sid) { echo "\nSKIP  no hr_staff rows (run seed_hr_staff)\n"; echo ($failures?"\n{$failures} FAILURE(S)\n":"\nALL PASS\n"); exit($failures?1:0); }

db()->beginTransaction();

// Upsert a night shift → stored as minutes, status implied P.
$id = attendance_upsert($sid, '2026-05-01', ['in1'=>'19:00','out1'=>'07:00+1'], null);
$row = db_query("SELECT * FROM attendance WHERE id=:i", [':i'=>$id])->fetch();
check('upsert: night stored in1=1140',   (int)$row['in1'] === 1140);
check('upsert: night stored out1=1860',  (int)$row['out1'] === 1860);
check('upsert: worked ⇒ status NULL',    $row['status'] === null);
check('upsert: hours = 12',              attendance_hours($row) === 12.0);

// Upsert a status day clears times; re-upsert is idempotent on (staff,date).
attendance_upsert($sid, '2026-05-01', ['status'=>'OFF'], null);
$row2 = db_query("SELECT * FROM attendance WHERE hr_staff_id=:s AND work_date='2026-05-01'", [':s'=>$sid])->fetch();
check('upsert: OFF clears times',        $row2['in1'] === null && $row2['status'] === 'OFF');
check('upsert: one row per (staff,date)', (int)db_query("SELECT COUNT(*) FROM attendance WHERE hr_staff_id=:s AND work_date='2026-05-01'", [':s'=>$sid])->fetchColumn() === 1);

// ── Leave workflow (create → approve stamps LV, skipping the off-day) ────────
if (leave_requests_supported()) {
    db_query("UPDATE hr_staff SET off_day='TUE' WHERE id=:i", [':i'=>$sid]);   // deterministic off-day
    check('leave: bad range rejected',   leave_create($sid, '2026-05-10', '2026-05-08', 'annual', '', null) === 0);
    $lid = leave_create($sid, '2026-05-04', '2026-05-06', 'annual', 'Family', null); // Mon–Wed (Tue off)
    check('leave: created pending',      $lid > 0 && (fetch_leave_request($lid)['status'] ?? '') === 'pending');
    $stamped = leave_decide($lid, 'approved', null);
    check('leave: approve stamps 2 (skips off Tue)', $stamped === 2);
    check('leave: Mon marked LV',        (db_query("SELECT status FROM attendance WHERE hr_staff_id=:s AND work_date='2026-05-04'", [':s'=>$sid])->fetchColumn()) === 'LV');
    check('leave: Tue (off) NOT stamped', db_query("SELECT COUNT(*) FROM attendance WHERE hr_staff_id=:s AND work_date='2026-05-05'", [':s'=>$sid])->fetchColumn() == 0);
    check('leave: request now approved', (fetch_leave_request($lid)['status'] ?? '') === 'approved');
    check('leave: decide is idempotent', leave_decide($lid, 'approved', null) === 0);
} else {
    echo "SKIP  leave assertions (add_leave_requests not applied)\n";
}

db()->rollBack();

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
