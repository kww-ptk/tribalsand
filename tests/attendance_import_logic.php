<?php
declare(strict_types=1);
// Attendance import — reading HR's Excel workbook (1904 dates, night shifts, notes in
// the status column, headings, code grid) and what an import does to each day.
// Run: php tests/attendance_import_logic.php
// The workbook is tests/fixtures/attendance-mini.xlsx — a made-up copy of HR's layout
// (no real staff). DB part runs inside ONE transaction that is ROLLED BACK; it SKIPs
// without a database or before add_attendance_import.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-import.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Cells ───────────────────────────────────────────────────────────────────
check('minutes: 08:00 stored as 1/3 day', att_imp_minutes('0.33333333333333331') === 480);
check('minutes: 1904 next-day 06:00 = 1800', att_imp_minutes('1.25') === 1800);
check('minutes: typed 8:00:00', att_imp_minutes('8:00:00') === 480);
check('minutes: typed 07:00+1', att_imp_minutes('07:00+1') === 1860);
check('minutes: a full date-time keeps only its time', att_imp_minutes('44470.75') === 1080);
check('minutes: blank / text = null', att_imp_minutes('') === null && att_imp_minutes('OFF') === null);

check('date: 1904 serial', att_imp_date('44834', true) === '2026-10-01');
check('date: 1900 serial', att_imp_date('46296', false) === '2026-10-01');
check('date: text', att_imp_date('2026-10-01 00:00:00', true) === '2026-10-01');
check('date: a time is not a date', att_imp_date('0.5', true) === null);

check('status: P / OFF / SICK', att_imp_status('P')[0] === 'P' && att_imp_status('off')[0] === 'OFF' && att_imp_status('Sick')[0] === 'SK');
check('status: LEAVE / REC / PH', att_imp_status('Leave')[0] === 'LV' && att_imp_status('Rec')[0] === 'REC' && att_imp_status('PH')[0] === 'PH');
check('status: UNP/L = unpaid leave', att_imp_status('UNP/L')[0] === 'UL');
check('status: a property name = on duty there', att_imp_status('ZURI') === ['P', true, 'On duty at Zuri']);
check('status: junk is no code', att_imp_status('0')[0] === '' && att_imp_status('FRI')[0] === '' && att_imp_status('Not empl')[0] === '');
check('status: a sentence becomes a note', att_imp_status('DATE OF HIRE - 11 AUGUST 2026') === ['', true, 'DATE OF HIRE - 11 AUGUST 2026']);
check('status: unknown code kept and flagged, never guessed', att_imp_status('OFF R') === ['OFF R', false, ''] && att_imp_status('N') === ['N', false, '']);

check('segment: plain 18:00→06:00 alone = a night', att_imp_segment(1080, 360, true) === [1080, 1800]);
check('segment: reversed 2nd section stays a typo', att_imp_segment(1045, 535, false) === [1045, 535]);
check('segment: reversed and > 14 h stays a typo', att_imp_segment(360, 201, true) === [360, 201]);
check('segment: equal times = 0 h, not 24', att_imp_segment(945, 945, true) === [945, 945]);
check('segment: a 1904 next-day out is left alone', att_imp_segment(1080, 1800, true) === [1080, 1800]);
check('hours: a reversed section counts 0, not minus', attendance_hours(['in1' => 420, 'out1' => 1020, 'in2' => 1045, 'out2' => 535]) === 10.0);

$d = att_imp_absence_wins(['in1' => 432, 'out1' => 780, 'in2' => 840, 'out2' => 1140, 'status' => 'OFF', 'note' => '']);
check('absence wins: OFF with times → OFF, times into the note', $d['in1'] === null && $d['status'] === 'OFF' && str_contains($d['note'], '07:12') && !empty($d['timesOnAbsence']));
$d = att_imp_absence_wins(['in1' => 480, 'out1' => 1020, 'in2' => null, 'out2' => null, 'status' => 'PH', 'note' => '']);
check('absence wins: a holiday worked keeps its times', $d['in1'] === 480);

$f = att_imp_day_flags(['in1' => 480, 'out1' => null, 'in2' => null, 'out2' => null, 'xh' => -8]);
check('flags: missing time out', $f['missing'] && !$f['differs']);
$f = att_imp_day_flags(['in1' => 480, 'out1' => 1020, 'in2' => null, 'out2' => null, 'xh' => 7]);
check('flags: hours differ from the sheet', $f['differs']);
$f = att_imp_day_flags(['in1' => null, 'out1' => null, 'in2' => null, 'out2' => null, 'xh' => 24]);
check('flags: the sheet\'s 24 h on an empty day is shown as a difference', $f['differs'] && !$f['missing']);

// ── Day actions ─────────────────────────────────────────────────────────────
$new = ['in1' => 480, 'out1' => 1020, 'in2' => null, 'out2' => null, 'status' => 'P'];
check('action: nothing yet → write', att_imp_day_action($new, null, false, false) === 'write');
check('action: kiosk punches → kept', att_imp_day_action($new, null, true, false) === 'kiosk');
check('action: kiosk row → kept', att_imp_day_action($new, ['source' => 'kiosk'] + $new, false, true) === 'kiosk');
check('action: identical worked day (stored without P) → same', att_imp_day_action($new, ['source' => 'import', 'status' => null] + $new, false, false) === 'same');
check('action: earlier import changed → update', att_imp_day_action($new, ['source' => 'import', 'in1' => 470, 'status' => null] + $new, false, false) === 'update');
check('action: typed by hand → kept', att_imp_day_action($new, ['source' => 'manual', 'in1' => 470, 'status' => null] + $new, false, false) === 'manual');
check('action: typed by hand + replace → replace', att_imp_day_action($new, ['source' => null, 'in1' => 470, 'status' => null] + $new, false, true) === 'replace');

// ── Unknown codes ───────────────────────────────────────────────────────────
$unk = ['in1' => null, 'out1' => null, 'in2' => null, 'out2' => null, 'status' => 'OFF R', 'known' => false];
check('code: HR says OFF R = recovery', att_imp_apply_code($unk, ['OFF R' => 'REC'])['status'] === 'REC');
check('code: left out', att_imp_apply_code($unk, ['OFF R' => '']) === null);
check('code: no answer = kept as written', att_imp_apply_code($unk, [])['status'] === 'OFF R');

// ── Merging one person listed twice ─────────────────────────────────────────
$a = ['2026-10-01' => ['in1' => null, 'out1' => null, 'in2' => null, 'out2' => null, 'status' => 'OFF']];
$b = ['2026-10-01' => ['in1' => 480, 'out1' => 1020, 'in2' => null, 'out2' => null, 'status' => 'P'],
      '2026-10-02' => ['in1' => 480, 'out1' => 1020, 'in2' => null, 'out2' => null, 'status' => 'P']];
$m = att_imp_merge_days($a, $b);
check('merge: a day with times beats a code-only day', $m['2026-10-01']['in1'] === 480 && count($m) === 2);

// ── The fixture workbook ────────────────────────────────────────────────────
$wb = att_imp_read_workbook(__DIR__ . '/fixtures/attendance-mini.xlsx');
check('workbook: 1904 date system read', $wb['is_1904'] === true);
check('workbook: one month, both sheets', array_keys($wb['months']) === ['2026-03'] && count($wb['months']['2026-03']['sheets']) === 2);
check('workbook: summary sheet ignored', in_array('SUMMARY MARCH', $wb['skipped'], true));
$people = [];
foreach ($wb['months']['2026-03']['people'] as $p) $people[$p['name']] = $p;
check('workbook: heading with stray OFF is not a person', !isset($people['SECTION B']) && count($people) === 3);
$al = $people['ALPHA BETA TESTER']['days'];
check('workbook: name tidied (double space)', isset($people['ALPHA BETA TESTER']));
check('workbook: normal day', $al['2026-03-01']['in1'] === 480 && $al['2026-03-01']['out2'] === 1020 && $al['2026-03-01']['xh'] === 8.0);
check('workbook: OFF with times = OFF', $al['2026-03-08']['status'] === 'OFF' && $al['2026-03-08']['in1'] === null);
check('workbook: unknown code flagged', $al['2026-03-09']['status'] === 'XQ' && !$al['2026-03-09']['known']);
$ga = $people['GAMMA DELTA NIGHTMAN'];
check('workbook: section remembered', $ga['section'] === 'SECTION B');
check('workbook: 1904 next-day night = 12 h', att_imp_day_hours($ga['days']['2026-03-01']) === 12.0);
check('workbook: plain reversed night = 12 h', att_imp_day_hours($ga['days']['2026-03-02']) === 12.0);
check('workbook: typo second section flagged', att_imp_day_flags($ga['days']['2026-03-03'])['reversed']);
check('workbook: missing time out flagged', att_imp_day_flags($ga['days']['2026-03-04'])['missing']);
check('workbook: a sentence is not a day', !isset($ga['days']['2026-03-05']));
check('workbook: ZURI = worked at another unit', $ga['days']['2026-03-06']['status'] === 'P' && $ga['days']['2026-03-06']['note'] === 'On duty at Zuri');
check('workbook: grid fills sick days the time sheet left empty', $ga['days']['2026-03-07']['status'] === 'SK' && $ga['days']['2026-03-08']['status'] === 'SK');
$ep = $people['EPSILON ONLYGRID'];
check('workbook: a person only on the grid', $ep['from'] === 'grid' && $ep['days']['2026-03-02']['status'] === 'LV' && $ep['days']['2026-03-03']['status'] === 'UL');
check('workbook: "Not empl" is not a day', !isset($ep['days']['2026-03-04']));

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
try { db(); $haveDb = attendance_import_supported() && hr_establishment_supported(); } catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  DB checks (no database or add_attendance_import not applied)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        unset($_SESSION['admin_id']);
        db_query("DELETE FROM attendance_import_names WHERE name_key IN ('ALPHA BETA TESTER','DELTA GAMMA NIGHTMAN','EPSILON ONLYGRID')");
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('TESTER ALPHA BETA', 'HOUSE KEEPER', 'Housekeeping', 'active', 'SUN')");
        $alpha = (int)$pdo->lastInsertId();
        db_query("INSERT INTO hr_staff (full_name, position, department, status, off_day) VALUES ('GAMMA DELTA NIGHTMAN', 'WATCHMAN', 'Security', 'active', 'MON')");
        $gamma = (int)$pdo->lastInsertId();
        // A day already clocked on the kiosk, and one typed in by hand.
        attendance_upsert($alpha, '2026-03-02', ['in1' => '07:00', 'out1' => '15:00'], null, 'kiosk');
        attendance_upsert($alpha, '2026-03-03', ['in1' => '09:00', 'out1' => '15:00'], null, 'manual');

        $id  = att_imp_store(__DIR__ . '/fixtures/attendance-mini.xlsx', 'attendance-mini.xlsx', null);
        $imp = att_imp_fetch($id);
        check('db: workbook stored', $imp && isset($imp['months']['2026-03']));
        $pv = att_imp_preview($imp, '2026-03', false, [], ['XQ' => 'keep']);
        $byName = [];
        foreach ($pv['people'] as $p) $byName[$p['name']] = $p;
        check('db: order-free name match', $byName['ALPHA BETA TESTER']['match']['staff_id'] === $alpha);
        check('db: kiosk + typed days counted as kept', ($byName['ALPHA BETA TESTER']['counts']['kiosk'] ?? 0) === 1 && ($byName['ALPHA BETA TESTER']['counts']['manual'] ?? 0) === 1);
        check('db: grid-only person has nobody yet', $byName['EPSILON ONLYGRID']['match']['status'] === 'new');
        check('db: preview wrote nothing', (int)db_query("SELECT COUNT(*) FROM attendance WHERE source = 'import' AND import_id = :i", [':i' => $id])->fetchColumn() === 0);

        $n = att_imp_apply($id, '2026-03', [att_imp_name_key('EPSILON ONLYGRID') => 'former'], false, null, ['XQ' => 'REC']);
        check('db: kiosk day kept, typed day kept', $n['kept_kiosk'] === 1 && $n['kept_manual'] === 1);
        check('db: former staff added inactive', $n['former'] === 1
            && db_query("SELECT status FROM hr_staff WHERE full_name = 'EPSILON ONLYGRID'")->fetchColumn() === 'inactive');
        $k = db_query("SELECT in1, out1, source FROM attendance WHERE hr_staff_id = :s AND work_date = '2026-03-02'", [':s' => $alpha])->fetch();
        check('db: kiosk day untouched', (int)$k['in1'] === 420 && $k['source'] === 'kiosk');
        $r = db_query("SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date = '2026-03-01'", [':s' => $alpha])->fetch();
        check('db: imported worked day', (int)$r['in1'] === 480 && (int)$r['out2'] === 1020 && $r['status'] === null && $r['source'] === 'import' && (int)$r['import_id'] === $id);
        check('db: unknown code mapped by HR', db_query("SELECT status FROM attendance WHERE hr_staff_id = :s AND work_date = '2026-03-09'", [':s' => $alpha])->fetchColumn() === 'REC');
        $g = db_query("SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date = '2026-03-01'", [':s' => $gamma])->fetch();
        check('db: night shift stored across midnight', (int)$g['out1'] === 1800 && attendance_hours($g) === 12.0);
        check('db: note kept', db_query("SELECT note FROM attendance WHERE hr_staff_id = :s AND work_date = '2026-03-06'", [':s' => $gamma])->fetchColumn() === 'On duty at Zuri');
        check('db: names remembered', att_imp_name_map()[att_imp_name_key('ALPHA BETA TESTER')] === $alpha);

        $again = att_imp_apply($id, '2026-03', [], false, null, ['XQ' => 'REC']);
        check('db: re-import changes nothing', $again['written'] === 0 && $again['updated'] === 0 && $again['same'] > 0);
        $rep = att_imp_apply($id, '2026-03', [], true, null, ['XQ' => 'REC']);
        check('db: replace typed-in day on request, never the kiosk day', $rep['replaced'] === 1 && $rep['kept_kiosk'] === 1);
        check('db: OT rule — 12 h night = 4 h overtime', attendance_overtime($g, 'Security') === 4.0);
    } finally {
        $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
