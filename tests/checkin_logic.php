<?php
declare(strict_types=1);
// Pre-Check-in logic. Run: php tests/checkin_logic.php
// Pure helpers run always; DB/permission assertions SKIP until add_checkin.sql is applied.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';   // make_guest_pass_token / verify
require_once __DIR__ . '/../includes/checkin.php';

session_init();

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Config defaults (no override set) ──────────────────────────────────────
$cfg = checkin_config();
check('config has 6 steps',            count($cfg) === 6);
check('passport enabled by default',   $cfg['passport']['enabled'] === true);
check('passport required by default',  $cfg['passport']['required'] === true);
check('waiver required by default',    $cfg['waiver']['required'] === true);
check('arrival optional by default',   $cfg['arrival']['required'] === false);
check('order: arrival before waiver',  array_search('arrival', array_keys($cfg), true) < array_search('waiver', array_keys($cfg), true));

// ── Config override merge (does not persist — restore after) ───────────────
$prev = setting('checkin_steps', '');
set_setting('checkin_steps', json_encode(['arrival' => ['required' => true], 'waiver' => ['enabled' => false]]));
$cfg2 = checkin_config();
check('override makes arrival required',  $cfg2['arrival']['required'] === true);
check('override disables waiver',         $cfg2['waiver']['enabled'] === false);
check('enabled steps exclude waiver',     !array_key_exists('waiver', checkin_enabled_steps()));
check('unspecified step keeps default',   $cfg2['passport']['required'] === true);
set_setting('checkin_steps', $prev); // restore

// ── waiver_version ─────────────────────────────────────────────────────────
check('waiver_version stable',   waiver_version('  terms  ') === waiver_version('terms'));
check('waiver_version differs',   waiver_version('a') !== waiver_version('b'));
check('waiver_version 12 chars',  strlen(waiver_version('x')) === 12);

// ── Badge / state (pure — synthetic holds) ─────────────────────────────────
// checkin_required() needs checkin_supported(); when unmigrated it returns
// 'none' for all, so assert badge shape only where supported.
if (checkin_supported()) {
    check('badge none when not required', checkin_badge(['require_checkin' => false]) === null);
    $p = checkin_badge(['require_checkin' => true, 'checkin_completed_at' => null]);
    check('badge pending label',  $p['class'] === 'ci-badge--pending');
    $d = checkin_badge(['require_checkin' => true, 'checkin_completed_at' => '2026-08-06 10:00:00']);
    check('badge done label',     $d['class'] === 'ci-badge--done');
    $xn = checkin_badge(['require_checkin' => true, 'checkin_completed_at' => null, 'guest_count' => 3, 'ci_complete_count' => 1]);
    check('badge shows X/N',      $xn['label'] === 'Check-in 1/3');
} else {
    echo "SKIP  add_checkin.sql not applied — skipping state/badge/DB assertions\n";
}

// ── Per-step completeness (pure) ───────────────────────────────────────────
check('arrival incomplete when empty',   checkin_step_complete('arrival', [], null) === false);
check('arrival complete w/ flight+time',  checkin_step_complete('arrival', ['flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00'], null) === true);
check('transfer needs answer',            checkin_step_complete('transfer', [], null) === false);
check('transfer no = complete',           checkin_step_complete('transfer', ['needs_transfer' => false], null) === true);
check('transfer yes needs details',       checkin_step_complete('transfer', ['needs_transfer' => true], null) === false);
check('transfer yes + details = complete',checkin_step_complete('transfer', ['needs_transfer' => true, 'transfer_details' => 'JKIA 2pm'], null) === true);
check('passport needs name+num+file',     checkin_step_complete('passport', [], ['passport_name' => 'A', 'passport_number' => 'B']) === false);
check('passport complete w/ file',        checkin_step_complete('passport', [], ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'checkin/1/x.jpg']) === true);
check('waiver needs signature',           checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A']) === false);
check('waiver complete when signed',      checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00']) === true);

// ── Missing-steps aggregation ──────────────────────────────────────────────
$cfgReq = ['passport' => ['enabled' => true, 'required' => true], 'waiver' => ['enabled' => true, 'required' => true], 'dietary' => ['enabled' => true, 'required' => false]];
check('missing lists passport+waiver',   checkin_missing_steps($cfgReq, [], null) === ['passport', 'waiver']);
$fullLead = ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'k', 'waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06'];
$fullData = [];
check('missing empty when all done',     checkin_missing_steps($cfgReq, $fullData, $fullLead) === []);
check('optional step never missing',     !in_array('dietary', checkin_missing_steps($cfgReq, [], null), true));

// ── Multi-guest helpers (pure) ─────────────────────────────────────────────
$adult = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k','waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06'];
check('guest passport complete',   checkin_guest_passport_complete($adult) === true);
check('guest passport incomplete', checkin_guest_passport_complete(['passport_name'=>'A','passport_number'=>'B']) === false);
check('guest waiver signed',       checkin_guest_waiver_signed($adult) === true);
check('guest waiver unsigned',     checkin_guest_waiver_signed(['waiver_signed_name'=>'A']) === false);
check('adult complete needs both', checkin_guest_complete($adult, $cfgReq) === true);
check('adult missing waiver',      checkin_guest_complete(['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k'], $cfgReq) === false);
check('child never counts',        checkin_guest_complete(['is_child'=>true] + $adult, $cfgReq) === false);
check('party 1/1 done',            checkin_party_status(1,1)['all_done'] === true);
check('party 2/3 not done',        checkin_party_status(3,2)['all_done'] === false);
check('party clamps over',         checkin_party_status(2,5) === ['complete'=>2,'total'=>2,'all_done'=>true]);
$party = [$adult, ['passport_name'=>'C'], ['is_child'=>true,'passport_name'=>'Kid']];
check('party complete count = 1',  checkin_party_complete_count($party, $cfgReq) === 1);

// ── can_view_guest_docs (needs role fixtures + a hold; SKIP if unmigrated) ──
if (checkin_supported()) {
    $vrows = db()->query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchAll(PDO::FETCH_COLUMN);
    $unit  = $vrows ? db()->query('SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id WHERE r.venue_id='.(int)$vrows[0].' LIMIT 1')->fetchColumn() : null;
    if ($vrows && $unit) {
        $V1 = (int)$vrows[0];
        db_query("DELETE FROM admin_users WHERE name LIKE 'ZZ Chk %'");
        $mk = function(string $role, ?string $email = null) {
            db_query("INSERT INTO admin_users (name, role, email, access_code, is_active) VALUES (:n,:r,:e,:c,TRUE)",
                [':n' => 'ZZ Chk '.$role, ':r' => $role, ':e' => $email, ':c' => gen_staff_code()]);
            return (int)db()->lastInsertId();
        };
        $owner = $mk('owner');
        $mgrA  = $mk('manager', 'zz-chk-mgra@example.com');
        $mgrB  = $mk('manager', 'zz-chk-mgrb@example.com');
        $staff = $mk('staff');
        db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a' => $mgrA, ':v' => $V1]);
        db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a' => $staff, ':v' => $V1]);
        db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at) VALUES (:u,'2031-07-01','2031-07-03','ZZ Chk Guest','zz-chk@example.com','confirmed',NOW())", [':u' => (int)$unit]);
        $hold = (int)db()->lastInsertId();

        $_SESSION['admin_id'] = $owner;  check('owner can view docs',        can_view_guest_docs($hold) === true);
        $_SESSION['admin_id'] = $mgrA;   check('venue manager can view docs', can_view_guest_docs($hold) === true);
        $_SESSION['admin_id'] = $mgrB;   check('non-venue manager cannot',    can_view_guest_docs($hold) === false);
        $_SESSION['admin_id'] = $staff;  check('venue staff cannot view docs', can_view_guest_docs($hold) === false);

        // fetch_checkin round-trip
        db_query("INSERT INTO booking_checkin (hold_id, dietary) VALUES (:h,'none') ON CONFLICT (hold_id) DO UPDATE SET dietary='none'", [':h' => $hold]);
        check('fetch_checkin returns row', (fetch_checkin($hold)['dietary'] ?? '') === 'none');

        // Per-guest token round-trip (seed a lead guest row on the hold).
        db_query("INSERT INTO checkin_guests (hold_id, is_lead) VALUES (:h, TRUE) ON CONFLICT (hold_id) WHERE is_lead DO NOTHING", [':h' => $hold]);
        $gid = (int)db_query('SELECT id FROM checkin_guests WHERE hold_id=:h AND is_lead', [':h'=>$hold])->fetchColumn();
        check('guest token round-trips',    verify_guest_pass_token(make_guest_pass_token($hold, $gid)) === [$hold, $gid]);
        check('guest token rejects tamper', verify_guest_pass_token($gid.'-0000000000') === false);

        db_query('DELETE FROM holds WHERE id = :h', [':h' => $hold]);
        db_query("DELETE FROM admin_users WHERE id IN (:a,:b,:c,:d)", [':a' => $owner, ':b' => $mgrA, ':c' => $mgrB, ':d' => $staff]);
        unset($_SESSION['admin_id']);
    } else {
        echo "SKIP  no venue/unit seeded — skipping permission assertions\n";
    }
}

// ── Signature validation (pure) ─────────────────────────────────────────────
$pngBytes = "\x89PNG\r\n\x1a\n" . str_repeat("x", 200);
check('valid png data-url',      checkin_valid_signature('data:image/png;base64,' . base64_encode($pngBytes)) === true);
check('reject non-png mime',     checkin_valid_signature('data:image/gif;base64,' . base64_encode($pngBytes)) === false);
check('reject plain string',     checkin_valid_signature('hello') === false);
check('reject empty',            checkin_valid_signature('') === false);
check('reject non-png bytes',    checkin_valid_signature('data:image/png;base64,' . base64_encode(str_repeat("x", 200))) === false);
$oversize = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("x", 300 * 1024));
check('reject oversize',         checkin_valid_signature($oversize) === false);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
