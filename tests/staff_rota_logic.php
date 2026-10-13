<?php
declare(strict_types=1);
// Staff rota — planned shifts, copying a week, plan vs worked, and shift swaps.
// Run: php tests/staff_rota_logic.php
// DB part runs inside ONE transaction that is ROLLED BACK; it SKIPs without a
// database or before add_staff_rota.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-rota.php';
require_once __DIR__ . '/../includes/staff-requests.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$night = ['shift' => 'secnight', 'start_min' => 1080, 'end_min' => 1800];
check('label: short and with times', staff_rota_label($night) === 'Night' && staff_rota_label($night, true) === 'Night 18:00–06:00+1');
check('shifts: night ends next morning', staff_rota_shifts()['secnight'][3] === 1800 && staff_rota_shifts()['off'][2] === null);
$worked = ['in1' => 480, 'out1' => 1020, 'in2' => null, 'out2' => null, 'status' => null];
check('flag: planned, day passed, nothing recorded', staff_rota_day_flag(['shift' => 'day'], null, '2026-10-01', '2026-10-13') === 'missing');
check('flag: planned today, nothing yet = fine', staff_rota_day_flag(['shift' => 'day'], null, '2026-10-13', '2026-10-13') === '');
check('flag: worked on a planned Off day', staff_rota_day_flag(['shift' => 'off'], $worked, '2026-10-01', '2026-10-13') === 'unplanned');
check('flag: sick on a planned day is recorded, not missing', staff_rota_day_flag(['shift' => 'day'], ['status' => 'SK'] + $worked, '2026-10-01', '2026-10-13') === '');
check('flag: no plan, no flag', staff_rota_day_flag(null, null, '2026-10-01', '2026-10-13') === '');

try { db(); $haveDb = staff_rota_supported(); } catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  DB checks (no database or add_staff_rota not applied)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        unset($_SESSION['admin_id']);
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('ROTA A', 'WATCHMAN', 'Security', 'active', 'MON')");
        $a = (int)$pdo->lastInsertId();
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('ROTA B', 'WATCHMAN', 'Security', 'active', 'TUE')");
        $b = (int)$pdo->lastInsertId();
        check('set: plans a day', staff_rota_set($a, '2026-11-02', 'secnight', null));
        check('set: same shift again changes nothing', !staff_rota_set($a, '2026-11-02', 'secnight', null));
        check('set: unknown shift refused', !staff_rota_set($a, '2026-11-03', 'party', null));
        $row = staff_rota_for([$a], '2026-11-02', '2026-11-02')[$a]['2026-11-02'];
        check('set: the times are copied onto the row', (int)$row['start_min'] === 1080 && (int)$row['end_min'] === 1800);
        staff_rota_set($a, '2026-11-03', 'day', null);
        staff_rota_set($a, '2026-11-04', 'off', null);
        staff_rota_set($a, '2026-11-10', 'late', null);           // already planned in the target week
        $n = staff_rota_copy_week([$a], '2026-11-02', '2026-11-09', null);
        $next = staff_rota_for([$a], '2026-11-09', '2026-11-15')[$a];
        check('copy: last week day for day (2 copied, 1 kept)', $n === 2 && $next['2026-11-09']['shift'] === 'secnight' && $next['2026-11-11']['shift'] === 'off');
        check('copy: a day already planned is kept', $next['2026-11-10']['shift'] === 'late');
        check('set: "—" removes the plan', staff_rota_set($a, '2026-11-04', '', null) && !isset(staff_rota_for([$a], '2026-11-04', '2026-11-04')[$a]));

        // A shift swap: A (night) swaps with B (off) on 2 Nov.
        staff_rota_set($b, '2026-11-02', 'off', null);
        if (staff_requests_supported()) {
            $id = staff_request_create($a, ['type' => 'swap', 'from' => '2026-11-02', 'with_staff_id' => $b, 'accepted' => '1'], null, 'hr');
            $r = staff_rota_for([$a, $b], '2026-11-02', '2026-11-02');
            check('swap: approval exchanges the two planned shifts', $r[$a]['2026-11-02']['shift'] === 'off' && $r[$b]['2026-11-02']['shift'] === 'secnight');
            staff_request_cancel($id, 'hr', null, true, 'changed plan');
            $r = staff_rota_for([$a, $b], '2026-11-02', '2026-11-02');
            check('swap: cancelling puts them back', $r[$a]['2026-11-02']['shift'] === 'secnight' && $r[$b]['2026-11-02']['shift'] === 'off');
            $id2 = staff_request_create($a, ['type' => 'swap', 'from' => '2026-11-05', 'with_staff_id' => $b], null, 'hr');
            $r = staff_rota_for([$a, $b], '2026-11-05', '2026-11-05');
            check('swap: nothing planned on either side → both get Off planned', ($r[$a]['2026-11-05']['shift'] ?? '') === 'off' && ($r[$b]['2026-11-05']['shift'] ?? '') === 'off');
            staff_request_cancel($id2, 'hr', null, true, 'x');
            check('swap: cancelling removes what the swap added', !staff_rota_for([$a, $b], '2026-11-05', '2026-11-05'));
        }
    } finally {
        $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
