<?php
declare(strict_types=1);
// Staff establishment — name matching, payroll keys, unit mapping, list parsing,
// company scope, and an import round-trip. Run: php tests/staff_establishment_logic.php
// DB part runs inside ONE transaction that is ROLLED BACK (needs add_companies +
// add_hr_staff_establishment); it SKIPs without a database.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr-establishment.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Names ───────────────────────────────────────────────────────────────────
check('tokens: order-free words', hr_est_name_tokens('OKAKA Henry  Ouma') === ['OKAKA', 'HENRY', 'OUMA']);
check('tokens: roster tags dropped', hr_est_name_tokens('ALOICE CHARO - M. ILAI') === ['ALOICE', 'CHARO']);
check('tokens: nickname in brackets dropped', hr_est_name_tokens('(ALICE) MNYAZI JOSHUA') === ['MNYAZI', 'JOSHUA']);
check('tokens: trainee tag dropped', hr_est_name_tokens('JAMES BAYA - TRAINEE') === ['JAMES', 'BAYA']);

check('word eq: one typo (5+ letters)', hr_est_word_eq('SHUKRANI', 'SHUKURANI'));
check('word eq: swapped letters', hr_est_word_eq('LEYIAN', 'LEIYAN'));
check('word eq: initial', hr_est_word_eq('C', 'CHONDO'));
check('word eq: short words must be exact', !hr_est_word_eq('OLE', 'OLA'));
check('word eq: JUMA / JUMAA', hr_est_word_eq('JUMA', 'JUMAA'));
check('word eq: 4-letter swap is not a match', !hr_est_word_eq('JUMA', 'JUMB') && !hr_est_word_eq('JUMA', 'KUMAA'));
check('word eq: different names', !hr_est_word_eq('BARAKA', 'MICHAEL'));

$s = hr_est_name_score(hr_est_name_tokens('OKAKA Henry Ouma'), hr_est_name_tokens('HENRY OUMA OKAKA'));
check('score: same 3 words, any order = contained', $s['overlap'] === 3 && $s['contained']);
$s = hr_est_name_score(hr_est_name_tokens('KEYA Judy Adhiambo'), hr_est_name_tokens('JUDY ADHIAMBO'));
check('score: shorter name fully inside = contained', $s['contained']);
$s = hr_est_name_score(hr_est_name_tokens('CHARO Baraka Kazungu'), hr_est_name_tokens('MICHAEL KAZUNGU CHARO'));
check('score: two shared surnames are NOT the same person', $s['overlap'] === 2 && !$s['contained']);

$n = hr_est_parse_list_name('OKAKA Henry Ouma');
check('parse: SURNAME First Middle', $n['surname'] === 'Okaka' && $n['first_names'] === 'Henry Ouma' && $n['display'] === 'HENRY OUMA OKAKA' && !$n['unsure']);
$n = hr_est_parse_list_name('AUGUSTUS MUMO Mulwa');
check('parse: two capital words → surname last, flagged', $n['surname'] === 'Mulwa' && $n['first_names'] === 'Augustus Mumo' && $n['unsure']);
$n = hr_est_parse_list_name('RAMADHAN MZONJE MWACHIZI');
check('parse: all capitals → natural order', $n['surname'] === 'Mwachizi' && $n['display'] === 'RAMADHAN MZONJE MWACHIZI' && !$n['unsure']);

check('better name: one-word entry takes the full name', hr_est_better_name('LENICE', hr_est_parse_list_name('NYONGINDI Lenice Auma')) === 'LENICE AUMA NYONGINDI');
check('better name: roster tag replaced', hr_est_better_name('ALOICE CHARO - M. ILAI', hr_est_parse_list_name('FONDO Aloice Charo')) === 'ALOICE CHARO FONDO');
check('better name: same length keeps own spelling', hr_est_better_name('VICTOR MATATA SARUSI', hr_est_parse_list_name('VICTOR Matata Surusi')) === 'VICTOR MATATA SARUSI');
check('better name: a different word is never swapped in', hr_est_better_name('JOHN ROE', hr_est_parse_list_name('DOE John Michael')) === 'JOHN ROE');
check('payroll key: MKH032 ≡ MKH32', hr_est_payroll_key('MKH032') === hr_est_payroll_key('MKH32'));
check('payroll key: 03 ≡ 3', hr_est_payroll_key('03') === '3');
check('payroll key: TT010 ≠ TTR010 (different prefix)', hr_est_payroll_key('TT010') !== hr_est_payroll_key('TTR010'));
check('payroll key: 0 stays 0', hr_est_payroll_key('00') === '0');

// ── Units ───────────────────────────────────────────────────────────────────
$slugs = ['maya-kobe' => 2, 'maya_ilai' => 6, 'my-amani' => 1, 'enkare-bofa' => 4, 'sandbox' => 5, 'zuri' => 3, 'tribal-dunes' => 7];
$p = hr_est_unit_plan('MY AMANI', $slugs, null);
check('unit: My Amani → venue', $p['venue_id'] === 1 && $p['extra_ids'] === [] && $p['unit_label'] === 'My Amani');
$p = hr_est_unit_plan('ENKARE / SANDBOX / TRIBAL DUNES', $slugs, null);
check('unit: several places → home + extras', $p['venue_id'] === 4 && $p['extra_ids'] === [5, 7]);
$p = hr_est_unit_plan('TRIBAL DUNES', $slugs, 6);
check('unit: Tribal Dunes keeps a Maya Ilai person at Maya Ilai', $p['venue_id'] === 6);
$p = hr_est_unit_plan('TRIBAL DUNES', $slugs, 3);
check('unit: Tribal Dunes moves someone from elsewhere', $p['venue_id'] === 7);
$p = hr_est_unit_plan('TRIBAL DUNES', ['maya-kobe' => 2], null);
check('unit: no tribal-dunes venue → Maya Kobe', $p['venue_id'] === 2);
$p = hr_est_unit_plan('watamu office', $slugs, null);
check('unit: office has no property, keeps a label', $p['venue_id'] === null && $p['unit_label'] === 'Watamu office');
$p = hr_est_unit_plan('plot 22 watamu', $slugs, null);
check('unit: plot label', $p['unit_label'] === 'Watamu - Plot 22');
$p = hr_est_unit_plan('TRIBAL DUNES / WATAMU OFFICE', $slugs, 2);
check('unit: venue + office', $p['venue_id'] === 2 && $p['unit_label'] === 'Tribal Dunes / Watamu office');

// ── Reading a sheet ─────────────────────────────────────────────────────────
$sheet = [
    ['MK Holidays Limited', '', '', ''],
    ['PayrollNumber', 'Employee Full Name', 'Department', 'UNIT'],
    ['MKH32', 'PALMA Lisa Wanjira', 'FRONT OF HOUSE', 'TRIBAL DUNES'],
    ['', '', '', ''],
    ['TTR012', 'AUGUSTUS MUMO  Mulwa', 'Barback', 'TRIBAL TABLE'],
];
$r = hr_est_parse_sheet($sheet);
check('sheet: title + 2 rows', $r['title'] === 'MK Holidays Limited' && count($r['rows']) === 2);
check('sheet: columns read', $r['rows'][1] === ['payroll_no' => 'TTR012', 'name' => 'AUGUSTUS MUMO Mulwa', 'position' => 'Barback', 'unit' => 'TRIBAL TABLE']);
$zuri = [['RETREATS ZURI LIMITED'], ['PayrollNumber', 'Employee Full Name', '', ''], ['01', 'YAA Moses Mangi', 'House supervisor', 'Zuri']];
$r = hr_est_parse_sheet($zuri);
check('sheet: unlabelled position + unit columns (Zuri)', $r['rows'][0]['position'] === 'House supervisor' && $r['rows'][0]['unit'] === 'Zuri');
$tsh = [['TRIBALSAND HOLIDAYS LIMITED'], ['PayrollNumber', 'Employee Full Name', 'department', '', '', 'unit'], ['03', 'ABDALLA Nusra Hassan', 'HR', '23', '', 'watamu office']];
$r = hr_est_parse_sheet($tsh);
check('sheet: "23" column is not the unit', $r['rows'][0]['unit'] === 'watamu office');
$threw = false; try { hr_est_parse_sheet([['nothing'], ['here']]); } catch (RuntimeException $e) { $threw = true; }
check('sheet: no header → clear error', $threw);

// ── Matching ────────────────────────────────────────────────────────────────
$existing = [
    ['id' => 1, 'full_name' => 'BARAKA KAZUNGU CHARO', 'company_id' => null, 'payroll_no' => null],
    ['id' => 2, 'full_name' => 'MICHAEL KAZUNGU CHARO', 'company_id' => null, 'payroll_no' => null],
    ['id' => 3, 'full_name' => 'HENRY OUMA OKAKA', 'company_id' => null, 'payroll_no' => null],
    ['id' => 4, 'full_name' => 'MOSES YAA', 'company_id' => 9, 'payroll_no' => '01'],
    ['id' => 5, 'full_name' => 'JOHN DOE', 'company_id' => 8, 'payroll_no' => '7'],
];
$rows = [
    ['payroll_no' => 'A1', 'name' => 'CHARO Baraka Kazungu'],
    ['payroll_no' => 'A2', 'name' => 'CHARO John Kazungu'],
    ['payroll_no' => 'A3', 'name' => 'OKAKA Henry Ouma'],
    ['payroll_no' => '1',  'name' => 'YAA Moses Mangi'],
    ['payroll_no' => 'A5', 'name' => 'NEW Person Here'],
    ['payroll_no' => 'A6', 'name' => 'DOE John'],
];
$m = hr_est_match($rows, $existing, 9);
check('match: clear name match', $m[0]['status'] === 'name' && $m[0]['staff_id'] === 1);
check('match: shares only two surnames → decide', $m[1]['status'] === 'decide' && $m[1]['staff_id'] === null);
check('match: reordered name', $m[2]['status'] === 'name' && $m[2]['staff_id'] === 3);
check('match: payroll 1 ≡ 01 in the same company', $m[3]['status'] === 'payroll' && $m[3]['staff_id'] === 4);
check('match: nobody similar → new', $m[4]['status'] === 'new');
check('match: person filed under another company → decide, never auto', $m[5]['status'] === 'decide');
$m = hr_est_match([['payroll_no' => 'X', 'name' => 'HENRY OKAKA'], ['payroll_no' => 'Y', 'name' => 'OKAKA Henry Ouma']], $existing, 9);
check('match: two rows wanting one person → both decide', $m[0]['status'] === 'decide' && $m[1]['status'] === 'decide');

$auto = ['status' => 'name', 'staff_id' => 3, 'candidates' => [['id' => 3]]];
check('decision: automatic', hr_est_decision($auto, '') === ['do' => 'update', 'staff_id' => 3]);
check('decision: foreign id refused', hr_est_decision($auto, 'match:99')['do'] === 'skip');
check('decision: candidate accepted', hr_est_decision(['status' => 'decide', 'staff_id' => null, 'candidates' => [['id' => 1], ['id' => 2]]], 'match:2') === ['do' => 'update', 'staff_id' => 2]);
check('decision: undecided row is left out', hr_est_decision(['status' => 'decide', 'staff_id' => null, 'candidates' => []], '')['do'] === 'skip');
check('decision: add as new', hr_est_decision($auto, 'new')['do'] === 'create');

// ── Departments for the new titles ──────────────────────────────────────────
check('dept: driver → Transport', hr_department('DRIVER') === 'Transport');
check('dept: floor supervisor → Service', hr_department('Floor Supervisor') === 'Service');
check('dept: barback → Service', hr_department('Barback') === 'Service');
check('dept: dogs care → Security', hr_department('Dogs Care') === 'Security');
check('dept: house supervisor still Housekeeping', hr_department('House Supervisor') === 'Housekeeping');
check('dept: pastry cook → Kitchen', hr_department('Pastry Cook') === 'Kitchen');
check('dept: "HuseKeeper" (typo) → Housekeeping', hr_department('HuseKeeper') === 'Housekeeping');
check('dept: front of house → Service, not Housekeeping', hr_department('FRONT OF HOUSE') === 'Service' && hr_department('FOH') === 'Service');
check('dept: store keeper stays Stores', hr_department('STORE KEEPER') === 'Stores' && hr_department('StoreKeeper') === 'Stores');

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
try { db(); $haveDb = hr_establishment_supported(); } catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  DB checks (no database or add_hr_staff_establishment not applied)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        unset($_SESSION['admin_id']);
        $co = hr_est_ensure_company('Test Establishment Co ' . bin2hex(random_bytes(3)), 'T' . strtoupper(bin2hex(random_bytes(2))));
        check('db: company created', $co > 0);
        db_query("INSERT INTO hr_staff (full_name, position, department, status) VALUES ('ZEBEDEE QUUX TESTER', 'COOK', 'Kitchen', 'active')");
        $existingId = (int)$pdo->lastInsertId();
        $rows = [
            ['payroll_no' => 'T01', 'name' => 'TESTER Zebedee Quux', 'position' => 'Head Chef', 'unit' => 'ZURI'],
            ['payroll_no' => 'T02', 'name' => 'NEWBIE Qwerty Zxcv', 'position' => 'DRIVER', 'unit' => 'Transfers'],
        ];
        $pv = hr_est_preview($co, $rows);
        check('db: preview matches the existing person by name', $pv['rows'][0]['match']['staff_id'] === $existingId);
        $n = hr_est_apply($co, $rows, [], null);
        check('db: one updated, one created', $n === ['updated' => 1, 'created' => 1, 'skipped' => 0]);
        $p = fetch_hr_staff_row($existingId);
        check('db: company + payroll + surname written, name kept',
            (int)$p['company_id'] === $co && $p['payroll_no'] === 'T01' && $p['surname'] === 'Tester' && $p['full_name'] === 'ZEBEDEE QUUX TESTER');
        check('db: position + department updated', $p['position'] === 'Head Chef' && $p['department'] === 'Kitchen');
        $new = db_query("SELECT * FROM hr_staff WHERE company_id = :c AND payroll_no = 'T02'", [':c' => $co])->fetch();
        check('db: new person created in natural order', $new && $new['full_name'] === 'QWERTY ZXCV NEWBIE' && $new['department'] === 'Transport' && $new['venue_id'] === null);
        $again = hr_est_preview($co, $rows);
        check('db: re-run matches both by payroll number', $again['counts']['payroll'] === 2);
        $n = hr_est_apply($co, $rows, [], null);
        check('db: re-run creates nobody', $n['created'] === 0 && $n['updated'] === 2);

        // Company scope: a manager with no property sees the company's staff only through the grant.
        $where = ''; $params = [];
        hr_scope_sql([], $where, $params, '', [$co]);
        $ids = array_map('intval', array_column(db_query("SELECT id FROM hr_staff WHERE 1=1 $where", $params)->fetchAll(), 'id'));
        check('db: company scope shows that company', in_array($existingId, $ids, true) && in_array((int)$new['id'], $ids, true));
        $where = ''; $params = [];
        hr_scope_sql([], $where, $params, '', []);
        check('db: no venues + no companies → nobody', (int)db_query("SELECT COUNT(*) FROM hr_staff WHERE 1=1 $where", $params)->fetchColumn() === 0);
        check('db: row guard honours the company', hr_staff_in_venue_scope($existingId, null, [], [$co]) && !hr_staff_in_venue_scope($existingId, null, [], []));
        check('db: fetch_hr_staff company filter', count(fetch_hr_staff(null, ['company_id' => $co])) === 2);
    } finally {
        $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
