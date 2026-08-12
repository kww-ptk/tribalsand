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
check('waiver complete when signed',      checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00', 'waiver_signature' => 'sig']) === true);

// ── Arrival modes (pure) ────────────────────────────────────────────────────
check('arrival modes has three',          count(checkin_arrival_modes()) === 3);
check('arrival modes keyed by value',     array_keys(checkin_arrival_modes()) === ['flight', 'road', 'other']);
check('airports has three',               count(checkin_airports()) === 3);
check('arrival legacy needs flight+time', checkin_arrival_complete(['flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival legacy without flight',    checkin_arrival_complete(['arrival_at' => '2026-09-01 14:00']) === false);
check('arrival flight needs airport',     checkin_arrival_complete(['arrival_mode' => 'flight', 'flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === false);
check('arrival flight needs flight no',   checkin_arrival_complete(['arrival_mode' => 'flight', 'arrival_airport' => 'Malindi', 'arrival_at' => '2026-09-01 14:00']) === false);
check('arrival flight complete',          checkin_arrival_complete(['arrival_mode' => 'flight', 'arrival_airport' => 'Malindi', 'flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival road needs only time',     checkin_arrival_complete(['arrival_mode' => 'road', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival road without time',        checkin_arrival_complete(['arrival_mode' => 'road']) === false);
check('arrival other needs only time',    checkin_arrival_complete(['arrival_mode' => 'other', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival unknown mode = legacy',    checkin_arrival_complete(['arrival_mode' => 'teleport', 'arrival_at' => '2026-09-01 14:00']) === false);
check('arrival null data incomplete',     checkin_arrival_complete(null) === false);
check('step_complete delegates to mode',  checkin_step_complete('arrival', ['arrival_mode' => 'road', 'arrival_at' => '2026-09-01 14:00'], null) === true);

// ── Missing-steps aggregation ──────────────────────────────────────────────
$cfgReq = ['passport' => ['enabled' => true, 'required' => true], 'waiver' => ['enabled' => true, 'required' => true], 'dietary' => ['enabled' => true, 'required' => false]];
check('missing lists passport+waiver',   checkin_missing_steps($cfgReq, [], null) === ['passport', 'waiver']);
$fullLead = ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'k', 'waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06', 'waiver_signature' => 'sig'];
$fullData = [];
check('missing empty when all done',     checkin_missing_steps($cfgReq, $fullData, $fullLead) === []);
check('optional step never missing',     !in_array('dietary', checkin_missing_steps($cfgReq, [], null), true));

// ── Multi-guest helpers (pure) ─────────────────────────────────────────────
$adult = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k','waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06','waiver_signature'=>'sig'];
check('guest passport complete',   checkin_guest_passport_complete($adult) === true);
check('guest passport incomplete', checkin_guest_passport_complete(['passport_name'=>'A','passport_number'=>'B']) === false);
check('guest waiver signed',       checkin_guest_waiver_signed($adult) === true);
check('guest waiver unsigned',     checkin_guest_waiver_signed(['waiver_signed_name'=>'A']) === false);
check('waiver needs a signature',  checkin_guest_waiver_signed(['waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06']) === false);
check('waiver signed w/ signature', checkin_guest_waiver_signed(['waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06','waiver_signature'=>'sig']) === true);
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

// ── Signing method + self-sign integrity (pure) ─────────────────────────────
check('method reception',        checkin_signing_method('reception') === 'reception');
check('method default own_link',  checkin_signing_method('') === 'own_link');
check('method other own_link',    checkin_signing_method('whatever') === 'own_link');
check('co-guest signs self',      checkin_can_sign_self(42, false) === true);
check('lead signs own lead row',  checkin_can_sign_self(null, true) === true);
check('lead cannot sign other',   checkin_can_sign_self(null, false) === false);

// ── Consent completeness (pure) ─────────────────────────────────────────────
// A minimal valid PNG data-URL: the 8 magic bytes plus padding to clear the
// 8-byte floor in checkin_valid_signature().
$sigOk = 'data:image/png;base64,' . base64_encode(hex2bin('89504e470d0a1a0a') . str_repeat("\0", 16));
check('consent fixture is a valid sig', checkin_valid_signature($sigOk) === true);
check('consent complete',               checkin_consent_missing(true, 'Jess Achieng', $sigOk) === []);
check('consent needs agreement',        checkin_consent_missing(false, 'Jess', $sigOk) === ['agree to the terms']);
check('consent needs typed name',       checkin_consent_missing(true, '   ', $sigOk) === ['type your full name']);
check('consent needs signature',        checkin_consent_missing(true, 'Jess', '') === ['draw your signature']);
check('consent rejects junk signature', checkin_consent_missing(true, 'Jess', 'data:image/png;base64,bm90YXBuZw==') === ['draw your signature']);
check('consent alreadySigned skips sig', checkin_consent_missing(true, 'Jess', '', true) === []);
check('consent lists all three',        count(checkin_consent_missing(false, '', '')) === 3);
check('consent order is stable',        checkin_consent_missing(false, '', '') === ['agree to the terms', 'type your full name', 'draw your signature']);

// ── Waiver text resolution (default + override) ─────────────────────────────
$prevWaiver = setting('checkin_waiver_text', '');
set_setting('checkin_waiver_text', '');
check('waiver text default non-empty', trim(checkin_waiver_text()) !== '');
set_setting('checkin_waiver_text', 'Custom terms XYZ');
check('waiver text uses override',      checkin_waiver_text() === 'Custom terms XYZ');
set_setting('checkin_waiver_text', $prevWaiver); // restore

// ── Co-guest view state (pure) ──────────────────────────────────────────────
$cfgPW = ['passport'=>['enabled'=>true,'required'=>true], 'waiver'=>['enabled'=>true,'required'=>true]];
$ciDone = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k','waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06','waiver_signature'=>'sig'];
$ciPass = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k']; // passport done, unsigned
check('coguest state done',         checkin_coguest_view_state($ciDone, $cfgPW) === 'done');
check('coguest state review_sign',  checkin_coguest_view_state($ciPass, $cfgPW) === 'review_sign');
check('coguest state full',         checkin_coguest_view_state(['passport_name'=>'A'], $cfgPW) === 'full');
$cfgWaiverOnly = ['passport'=>['enabled'=>false], 'waiver'=>['enabled'=>true]];
check('coguest waiver-only=review', checkin_coguest_view_state([], $cfgWaiverOnly) === 'review_sign');
$cfgPassOnly = ['passport'=>['enabled'=>true], 'waiver'=>['enabled'=>false]];
check('coguest passport-only done', checkin_coguest_view_state($ciPass, $cfgPassOnly) === 'done');

// ── Outstanding adults + waiting-on label (pure) ────────────────────────────
$cfgPW3 = ['passport' => ['enabled' => true, 'required' => true], 'waiver' => ['enabled' => true, 'required' => true]];
$gDone  = ['passport_name' => 'Jess Achieng', 'passport_number' => 'B', 'passport_file_key' => 'k',
           'waiver_signed_name' => 'Jess', 'waiver_signed_at' => '2026-08-06', 'waiver_signature' => 'sig'];
$gTodo  = ['passport_name' => 'Patrik Otieno'];
$gTodo2 = ['passport_name' => 'Sarah Kim'];
$gKid   = ['is_child' => true, 'passport_name' => 'Small One'];
check('outstanding excludes complete',  checkin_outstanding_adults([$gDone], $cfgPW3) === []);
check('outstanding lists incomplete',   count(checkin_outstanding_adults([$gDone, $gTodo], $cfgPW3)) === 1);
check('outstanding excludes children',  checkin_outstanding_adults([$gDone, $gKid], $cfgPW3) === []);
check('outstanding empty roster',       checkin_outstanding_adults([], $cfgPW3) === []);
check('outstanding keeps roster order', array_column(checkin_outstanding_adults([$gTodo, $gTodo2], $cfgPW3), 'passport_name') === ['Patrik Otieno', 'Sarah Kim']);

check('waiting label one',    checkin_waiting_on_label(['Patrik'], 0) === 'Patrik');
check('waiting label two',    checkin_waiting_on_label(['Patrik', 'Sarah'], 0) === 'Patrik and Sarah');
check('waiting label three',  checkin_waiting_on_label(['A', 'B', 'C'], 0) === 'A, B and C');
check('waiting label 1 slot', checkin_waiting_on_label([], 1) === '1 more guest');
check('waiting label n slots', checkin_waiting_on_label([], 2) === '2 more guests');
check('waiting label mixed',  checkin_waiting_on_label(['Patrik'], 2) === 'Patrik and 2 more guests');
check('waiting label empty',  checkin_waiting_on_label([], 0) === '');

// ── Guest label: name, else "Guest N" by ROSTER position (pure) ─────────────
$lblRoster = [
    ['id' => 1, 'passport_name' => 'Jessica Mwangi'],
    ['id' => 2, 'passport_name' => ''],
    ['id' => 3, 'passport_name' => 'Patrik Otieno'],
];
check('guest label full name',        checkin_guest_label($lblRoster[0], $lblRoster) === 'Jessica Mwangi');
check('guest label short name',       checkin_guest_label($lblRoster[0], $lblRoster, true) === 'Jessica');
check('guest label unnamed by roster', checkin_guest_label($lblRoster[1], $lblRoster) === 'Guest 2');
check('guest label third keeps name', checkin_guest_label($lblRoster[2], $lblRoster) === 'Patrik Otieno');
check('guest label short of unnamed',  checkin_guest_label($lblRoster[1], $lblRoster, true) === 'Guest 2');
check('guest label absent from roster', checkin_guest_label(['id' => 99, 'passport_name' => ''], $lblRoster) === 'Guest 4');
check('guest label null guest',       checkin_guest_label(null, $lblRoster) === 'Guest 4');
// The bug this replaces: numbering by filtered-list position named a guest who had finished.
$lblFiltered = [$lblRoster[1]];   // only guest 2 outstanding, but roster position is still 2
check('guest label ignores filtered index', checkin_guest_label($lblFiltered[0], $lblRoster) === 'Guest 2');

// ── Shared portal: display name (pure) ──────────────────────────────────────
check('display name first word',  guest_display_name(['passport_name'=>'Jess Achieng']) === 'Jess');
check('display name blank=Guest',  guest_display_name(['passport_name'=>'']) === 'Guest');
check('display name null=Guest',   guest_display_name(null) === 'Guest');

// ── Attribution name: falls back to the booking name for an unnamed lead ────
check('attributed own name wins',        attributed_display_name('Patrik Otieno', false, 'Jessica Mwangi') === 'Patrik');
check('attributed lead uses booking',    attributed_display_name('', true, 'Jessica Mwangi') === 'Jessica');
check('attributed co-guest = Guest',     attributed_display_name('', false, 'Jessica Mwangi') === 'Guest');
check('attributed lead no booking name', attributed_display_name('', true, '') === 'Guest');
check('attributed trims whitespace',     attributed_display_name('   ', true, 'Jessica Mwangi') === 'Jessica');
check('attributed first word only',      attributed_display_name('Anne Marie Wanjiru', false, '') === 'Anne');
check('attributed lead own name beats booking', attributed_display_name('Jess Achieng', true, 'Jessica Mwangi') === 'Jess');

// ── Message sender label (pure, takes a raw fetch_thread_messages row) ──────
check('sender label admin = Staff',   message_sender_label(['sender' => 'admin']) === 'Staff');
check('sender label named guest',     message_sender_label(['sender' => 'guest', 'sender_name' => 'Patrik Otieno']) === 'Patrik');
check('sender label unnamed lead',    message_sender_label(['sender' => 'guest', 'sender_name' => '', 'sender_is_lead' => true, 'hold_guest_name' => 'Jessica Mwangi']) === 'Jessica');
check('sender label unknown = Guest', message_sender_label(['sender' => 'guest']) === 'Guest');

// ── C-2 support guards return a bool (pure shape) ───────────────────────────
check('bill_item_guest_supported is bool',     is_bool(bill_item_guest_supported()));
check('message_sender_guest_supported is bool', is_bool(message_sender_guest_supported()));
check('checkin_arrival_mode_supported is bool', is_bool(checkin_arrival_mode_supported()));

// ── needs_transfer must never be bound as a PHP bool ────────────────────────
// db() uses PDO::ATTR_EMULATE_PREPARES, which renders a bound PHP false as ''.
// Postgres rejects '' for a boolean, so answering "No" to the transfer question
// used to fatal in api/checkin-save.php. Assert the real column accepts what
// that endpoint now binds, and rejects what it used to.
if (checkin_supported()) {
    db_query('CREATE TEMP TABLE zz_nt_check (b BOOLEAN)');
    $ntBind = function ($v): string {
        try { db_query('INSERT INTO zz_nt_check (b) VALUES (:b)', [':b' => $v]); return 'accepted'; }
        catch (Throwable $e) { return 'rejected'; }
    };
    check('bool false is rejected by the driver', $ntBind(false) === 'rejected');
    check("'FALSE' is accepted",                  $ntBind('FALSE') === 'accepted');
    check("'TRUE' is accepted",                   $ntBind('TRUE')  === 'accepted');
    check('null is accepted (unanswered)',        $ntBind(null)    === 'accepted');
    // The exact expression api/checkin-save.php uses, for each posted answer.
    $ntOf = fn(array $post) => array_key_exists('needs_transfer', $post) && $post['needs_transfer'] !== ''
        ? ($post['needs_transfer'] === '1' ? 'TRUE' : 'FALSE') : null;
    check('answer "yes" binds TRUE',   $ntOf(['needs_transfer' => '1']) === 'TRUE');
    check('answer "no" binds FALSE',   $ntOf(['needs_transfer' => '0']) === 'FALSE');
    check('unanswered binds null',     $ntOf([]) === null);
    check('no bind is ever a PHP bool', !is_bool($ntOf(['needs_transfer' => '0'])));
    db_query('DROP TABLE zz_nt_check');
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
