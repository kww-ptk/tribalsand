<?php
declare(strict_types=1);
// Staff balances — a month's figures (entitled off days, taken, overtime, leave),
// HR's summary sheet as opening balances, Close month, corrections.
// Run: php tests/hr_balances_logic.php
// DB part runs inside ONE transaction that is ROLLED BACK; it SKIPs without a
// database or before add_hr_balances.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr-balances.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function day(?int $i1, ?int $o1, ?int $i2 = null, ?int $o2 = null, ?string $st = null): array {
    return ['in1' => $i1, 'out1' => $o1, 'in2' => $i2, 'out2' => $o2, 'status' => $st];
}

// ── HR's January 2026 (from their consolidated summary) ─────────────────────
// Judy: off day THU. January 2026 has five Thursdays (1, 8, 15, 22, 29) and New
// Year's Day (a Thursday) — HR counts both: 6 entitled. She worked all 31 days, 8 h each.
$hol = ['2026-01-01' => "New Year's Day"];
$days = [];
for ($d = 1; $d <= 31; $d++) $days[sprintf('2026-01-%02d', $d)] = day(480, 780, 840, 1020);
$f = hr_bal_month_figures($days, 'THU', '2026-01', $hol);
check('Jan: holiday on the off day counts twice, as HR does (6)', $f['entitled'] === 6);
check('Jan: 31 worked, 248 h, 0 overtime', $f['worked'] === 31 && $f['hours'] === 248.0 && $f['ot_hours'] === 0.0);
check('Jan: off days earned 6 (HR: OFF + PH accumulated 6)', $f['off_earned'] === 6.0);
check('Jan: rest days + holiday worked counted', $f['rest_day_worked'] === 5 && $f['holiday_worked'] === 1);
check('Jan: a full month of leave accrual', $f['leave_accrued'] === 1.75 && $f['leave_change'] === 1.75);

// Caroline (off SUN): 4 Sundays + New Year = 5; HR's overtime 18.5 days = 148 h over 31 × 8.
$days = [];
for ($d = 1; $d <= 31; $d++) $days[sprintf('2026-01-%02d', $d)] = day(480, 780, 840, 1020);
$days['2026-01-02'] = day(480, 1257);   // a 12.95 h day → 4.95 h over
$f = hr_bal_month_figures($days, '', '2026-01', $hol);   // '' = Sunday off
check('Jan: blank off day = Sunday (4) + holiday = 5', $f['entitled'] === 5);
check('Jan: overtime = Σ(hours − 8)', abs($f['ot_hours'] - 4.95) < 0.01 && abs($f['ot_days'] - 0.619) < 0.001);

// A month with days off, sick, leave, unpaid, recovery, a holiday not worked, and gaps.
$hol = ['2026-04-03' => 'Good Friday', '2026-04-06' => 'Easter Monday'];
$days = [
    '2026-04-01' => day(480, 1080),                       // 10 h
    '2026-04-02' => day(480, 900),                        // 7 h (short)
    '2026-04-03' => day(null, null, null, null, 'PH'),    // holiday not worked
    '2026-04-05' => day(null, null, null, null, 'OFF'),   // Sunday off
    '2026-04-06' => day(480, 960),                        // holiday worked, 8 h
    '2026-04-07' => day(null, null, null, null, 'SK'),
    '2026-04-08' => day(null, null, null, null, 'LV'),
    '2026-04-09' => day(null, null, null, null, 'UL'),
    '2026-04-10' => day(null, null, null, null, 'REC'),
];
$f = hr_bal_month_figures($days, 'SUN', '2026-04', $hol);
check('Apr: entitled = 4 Sundays + 2 holidays', $f['entitled'] === 6);
check('Apr: taken = OFF + REC + PH not worked', $f['off_taken'] === 3 && $f['rec'] === 1 && $f['ph_taken'] === 1);
check('Apr: sick / leave / unpaid counted, not taken', $f['sick'] === 1 && $f['leave'] === 1 && $f['unpaid'] === 1);
check('Apr: overtime nets short days (+2 −1 +0)', $f['ot_hours'] === 1.0);
check('Apr: blank days are "not recorded"', $f['not_recorded'] === 30 - count($days));
check('Apr: off earned = 6 − 3 + 1/8', $f['off_earned'] === 3.125);
check('Apr: leave change = 1.75 − 1', $f['leave_change'] === 0.75);
check('Apr: holiday worked with its hours', $f['holiday_worked'] === 1 && $f['holiday_hours'] === 8.0);

// A part month: joined on the 16th.
$f = hr_bal_month_figures(['2026-09-16' => day(480, 960)], 'SUN', '2026-09', [], '2026-09-16');
check('part month: only 15 days counted', $f['days'] === 15 && $f['entitled'] === 2);
check('part month: leave pro-rata (1.75 × 15/30)', $f['leave_accrued'] === 0.875);
check('a window that ends before it starts = nothing', hr_bal_month_figures([], 'SUN', '2026-09', [], '2026-10-01') === null);

// ── HR's summary sheet → opening balances ───────────────────────────────────
$rows = [
    0 => [0 => 'ATTENDANCE SUMMARY & LEAVE DAYS BALANCES'],
    2 => [0 => 'Staff Name', 1 => 'Position', 9 => 'TOTAL PENDING AS AT JAN 2026 (DEC+JAN)', 34 => 'TOTAL PENDING AS AT BAL. MAY 2026',
          36 => 'CONTRACT PERIOD ', 37 => 'CONTRACT MONTHS ', 38 => 'PRORATA LEAVE DAYS ', 39 => 'LEAVE BALANCE '],
    3 => [0 => 'CAROLINE MAKENA MURUNGI', 1 => 'HOUSE SUPERVISOR', 2 => 'SUN', 9 => '38.5', 34 => '13.125', 36 => '1/12/2025 - 31/5/2026', 37 => '6', 38 => '10.5', 39 => '23.625'],
    4 => [0 => 'TRIBAL TABLE'],
    5 => [0 => 'MARIAM SAID', 34 => '-2', 38 => '5.25'],
    6 => [0 => 'Watamu - Plot  6', 34 => '0'],
];
$b = hr_bal_parse_summary($rows);
check('summary: the NEWEST "total pending" column (May)', $b !== null && $b['as_of'] === '2026-05-31' && str_contains($b['label'], 'MAY 2026'));
check('summary: people read, headings skipped', count($b['people']) === 2 && $b['people'][1]['name'] === 'MARIAM SAID');
check('summary: pending / pro-rata / leave balance', $b['people'][0]['pending'] === 13.125 && $b['people'][0]['prorata'] === 10.5 && $b['people'][0]['leave_balance'] === 23.625);
check('summary: a person with no position is still a person', $b['people'][1]['pending'] === -2.0);
check('summary: contract period, day first', $b['people'][0]['contract_from'] === '2025-12-01' && $b['people'][0]['contract_to'] === '2026-05-31');
check('summary: not a summary → null', hr_bal_parse_summary([[0 => 'nothing']]) === null);
check('heading: places', hr_bal_is_heading('ZURI - WATAMU') && hr_bal_is_heading('Watamu - Plot  6') && hr_bal_is_heading('Tribal Sand Center (office)'));
check('heading: people are not places', !hr_bal_is_heading('MARIAM SAID') && !hr_bal_is_heading('JOHN KAZUNGU CHARO'));
check('format: one decimal, real minus', hr_bal_fmt(13.125) === '13.1' && hr_bal_fmt(-2.0) === '−2' && hr_bal_fmt(0.0) === '0');

// ── DB (rolled back) ────────────────────────────────────────────────────────
try { db(); $haveDb = hr_balances_supported(); } catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  DB checks (no database or add_hr_balances not applied)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        unset($_SESSION['admin_id']);
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('BALANCE TEST ONE', 'COOK', 'Kitchen', 'active', 'SUN')");
        $a = (int)$pdo->lastInsertId();
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('BALANCE TEST TWO', 'COOK', 'Kitchen', 'active', 'SUN')");
        $b2 = (int)$pdo->lastInsertId();
        // Opening at 31 May for A only. Both work June (8 h days, Sundays off).
        hr_bal_add_move($a, 'off_days', 'opening', 10, '2026-05-31', 'test', null);
        hr_bal_add_move($a, 'annual_leave', 'opening', 5, '2026-05-31', 'test', null);
        foreach ([$a, $b2] as $sid) {
            for ($d = 1; $d <= 30; $d++) {
                $ymd = sprintf('2026-06-%02d', $d);
                attendance_upsert($sid, $ymd, date('N', strtotime($ymd)) === '7' ? ['status' => 'OFF'] : ['in1' => '08:00', 'out1' => '13:00', 'in2' => '14:00', 'out2' => '17:00'], null);
            }
            attendance_upsert($sid, '2026-05-15', ['in1' => '08:00', 'out1' => '17:00'], null);   // a May day (covered by A's opening)
        }
        $n = hr_bal_close_month('2026-06', null);
        $bal = hr_bal_balances([$a, $b2]);
        // June 2026: 4 Sundays + Madaraka Day (1 Jun) = 5 entitled; 4 Sundays taken off → +1.
        check('close: opening + June (10 + 1)', abs($bal[$a]['off_days'] - 11.0) < 0.001);
        check('close: leave 5 + 1.75', abs($bal[$a]['annual_leave'] - 6.75) < 0.001);
        check('close: no opening → starts at 0', abs($bal[$b2]['off_days'] - 1.0) < 0.001);
        $again = hr_bal_close_month('2026-06', null);
        $bal2 = hr_bal_balances([$a]);
        check('close again: replaces, never doubles', abs($bal2[$a]['off_days'] - 11.0) < 0.001
            && (int)db_query("SELECT COUNT(*) FROM hr_balance_moves WHERE hr_staff_id = :s AND reason = 'month'", [':s' => $a])->fetchColumn() === 2);
        hr_bal_close_month('2026-05', null);
        check('close May: covered by A\'s opening → skipped for A', (int)db_query("SELECT COUNT(*) FROM hr_balance_moves WHERE hr_staff_id = :s AND period = '2026-05'", [':s' => $a])->fetchColumn() === 0
            && (int)db_query("SELECT COUNT(*) FROM hr_balance_moves WHERE hr_staff_id = :s AND period = '2026-05'", [':s' => $b2])->fetchColumn() === 2);
        check('balance as at a date', abs((hr_bal_balances([$a], '2026-05-31')[$a]['off_days'] ?? 0) - 10.0) < 0.001);
        hr_bal_add_move($a, 'off_days', 'paid_out', 3, '2026-07-01', 'paid with July salary', null);
        check('paid out is taken off', abs(hr_bal_balances([$a])[$a]['off_days'] - 8.0) < 0.001);
        $threw = false; try { hr_bal_add_move($a, 'off_days', 'adjustment', 1, '2026-07-01', '', null); } catch (RuntimeException $e) { $threw = true; }
        check('a correction needs a reason', $threw);
        $threw = false; try { hr_bal_close_month(date('Y-m'), null); } catch (RuntimeException $e) { $threw = true; }
        check('the running month cannot be closed', $threw);
        hr_bal_add_move($a, 'off_days', 'opening', 4, '2026-05-31', 'again', null);
        check('a new opening replaces the old one', (int)db_query("SELECT COUNT(*) FROM hr_balance_moves WHERE hr_staff_id = :s AND reason = 'opening' AND kind = 'off_days'", [':s' => $a])->fetchColumn() === 1);
        $rows = hr_bal_month('2026-06', null);
        $mine = array_values(array_filter($rows, fn($r) => (int)$r['staff']['id'] === $a))[0] ?? null;
        check('month view: before / after balances', $mine && abs(($mine['before']['off_days'] ?? 0) - 4.0) < 0.001 && $mine['counts'] === true);
        check('month closed record', hr_bal_month_closed('2026-06') !== null);
    } finally {
        $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
