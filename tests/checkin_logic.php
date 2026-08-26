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
check('config has 7 steps',            count($cfg) === 7);
check('passport enabled by default',   $cfg['passport']['enabled'] === true);
check('passport required by default',  $cfg['passport']['required'] === true);
check('waiver required by default',    $cfg['waiver']['required'] === true);
check('arrival optional by default',   $cfg['arrival']['required'] === false);
check('deposit present, optional',     ($cfg['deposit']['enabled'] === true) && ($cfg['deposit']['required'] === false));
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

// ── Check-in / check-out windows (settings + defaults) ──────────────────────
$prevTimes = [];
foreach (['checkin_time_from','checkin_time_to','checkout_time_from','checkout_time_to','checkin_early_late_note'] as $__k) {
    $prevTimes[$__k] = setting($__k, '');
    set_setting($__k, '');
}
$t = checkin_times();
check('times has all five keys',  array_keys($t) === ['ci_from','ci_to','co_from','co_to','note']);
check('default check-in from',    $t['ci_from'] === '14:00');
check('default check-in to',      $t['ci_to']   === '20:00');
check('default check-out from',   $t['co_from'] === '10:00');
check('default check-out to',     $t['co_to']   === '11:00');
check('default note non-empty',   trim($t['note']) !== '');
set_setting('checkin_time_from', '15:30');
set_setting('checkin_early_late_note', 'Ask reception.');
$t2 = checkin_times();
check('override check-in from',   $t2['ci_from'] === '15:30');
check('override note',            $t2['note'] === 'Ask reception.');
check('unset key keeps default',  $t2['ci_to'] === '20:00');
foreach ($prevTimes as $__k => $__v) { set_setting($__k, $__v); }   // restore

// ── Arrival flag against the window (pure) ──────────────────────────────────
check('flag before window = early', checkin_arrival_flag('10:00', '14:00', '20:00') === 'early');
check('flag after window = late',   checkin_arrival_flag('22:30', '14:00', '20:00') === 'late');
check('flag inside window = none',  checkin_arrival_flag('16:00', '14:00', '20:00') === '');
check('flag on opening boundary',   checkin_arrival_flag('14:00', '14:00', '20:00') === '');
check('flag on closing boundary',   checkin_arrival_flag('20:00', '14:00', '20:00') === '');
check('flag one minute early',      checkin_arrival_flag('13:59', '14:00', '20:00') === 'early');
check('flag one minute late',       checkin_arrival_flag('20:01', '14:00', '20:00') === 'late');
check('flag null = none',           checkin_arrival_flag(null, '14:00', '20:00') === '');
check('flag empty = none',          checkin_arrival_flag('', '14:00', '20:00') === '');
check('flag garbage = none',        checkin_arrival_flag('not a time', '14:00', '20:00') === '');
check('flag accepts seconds',       checkin_arrival_flag('10:00:00', '14:00', '20:00') === 'early');

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
// Real PHP bools — this is what pdo_pgsql actually hands back (PHP >= 8.1), so
// these three are the only coverage of the real driver path. The 't'/'f' cases
// in the transfer block below are the synthetic ones. Do not delete as dupes.
check('transfer no = complete',           checkin_step_complete('transfer', ['needs_transfer' => false, 'needs_departure_transfer' => false], null) === true);
// The mode is explicit here on purpose: a row with no mode reads as flight
// (checkin_effective_mode()), for which transfer_details is NOT the detail —
// these two are testing the road/pickup branch, so they must say so.
check('transfer yes needs details',       checkin_step_complete('transfer', ['needs_transfer' => true, 'arrival_mode' => 'road', 'needs_departure_transfer' => false], null) === false);
check('transfer yes + details = complete',checkin_step_complete('transfer', ['needs_transfer' => true, 'arrival_mode' => 'road', 'needs_departure_transfer' => false, 'transfer_details' => 'JKIA 2pm'], null) === true);
check('passport needs name+num+file',     checkin_step_complete('passport', [], ['passport_name' => 'A', 'passport_number' => 'B']) === false);
check('passport complete w/ file',        checkin_step_complete('passport', [], ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'checkin/1/x.jpg']) === true);
check('waiver needs signature',           checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A']) === false);
check('waiver complete when signed',      checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00', 'waiver_signature' => 'sig']) === true);

// ── Arrival modes (pure) ────────────────────────────────────────────────────
check('arrival modes has three',          count(checkin_arrival_modes()) === 3);
check('arrival modes keyed by value',     array_keys(checkin_arrival_modes()) === ['flight', 'road', 'other']);

// ── checkin_effective_mode(): unset or unrecognised reads as flight ─────────
// add_checkin_arrival.sql adds arrival_mode with NO backfill, so legacy rows
// have the column PRESENT and NULL. Guarding on the column existing instead of
// on the value made the transfer step paint the pickup textarea for a legacy
// row while step 1 pre-checked Flight — a server render contradicting itself.
check('effective mode: absent key',       checkin_effective_mode([]) === 'flight');
check('effective mode: null value',       checkin_effective_mode(['arrival_mode' => null]) === 'flight');
check('effective mode: empty string',     checkin_effective_mode(['arrival_mode' => '']) === 'flight');
check('effective mode: whitespace only',  checkin_effective_mode(['arrival_mode' => '   ']) === 'flight');
check('effective mode: unrecognised',     checkin_effective_mode(['arrival_mode' => 'zzz']) === 'flight');
check('effective mode: null data',        checkin_effective_mode(null) === 'flight');
check('effective mode: flight',           checkin_effective_mode(['arrival_mode' => 'flight']) === 'flight');
check('effective mode: road',             checkin_effective_mode(['arrival_mode' => 'road']) === 'road');
check('effective mode: other',            checkin_effective_mode(['arrival_mode' => 'other']) === 'other');
check('airports has three',               count(checkin_airports()) === 3);
check('arrival legacy needs flight+time', checkin_arrival_complete(['flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival legacy without flight',    checkin_arrival_complete(['arrival_at' => '2026-09-01 14:00']) === false);
// The airport/flight fields moved to the transfer step, so the arrival step no
// longer demands them — the mode alone completes it.
check('arrival flight w/o airport is ok', checkin_arrival_complete(['arrival_mode' => 'flight', 'flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival flight w/o flight no ok',  checkin_arrival_complete(['arrival_mode' => 'flight', 'arrival_airport' => 'Malindi', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival flight complete',          checkin_arrival_complete(['arrival_mode' => 'flight', 'arrival_airport' => 'Malindi', 'flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival road + time still ok',     checkin_arrival_complete(['arrival_mode' => 'road', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival other + time still ok',    checkin_arrival_complete(['arrival_mode' => 'other', 'arrival_at' => '2026-09-01 14:00']) === true);
// An unknown mode no longer falls back to the legacy rule — it fails outright.
// The fixture carries flight_number + arrival_at so it would pass under the old
// rule and fail under the new one, which is the whole point of the assertion.
check('arrival unknown mode is incomplete',
    checkin_arrival_complete(['arrival_mode' => 'teleport', 'flight_number' => 'KQ1', 'arrival_at' => '2026-09-10 10:00:00+03']) === false);
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
check('checkin_property_arrival_supported is bool', is_bool(checkin_property_arrival_supported()));
check('checkin_departure_transfer_supported is bool', is_bool(checkin_departure_transfer_supported()));

// ── Desired check-in time, with legacy prefill ──────────────────────────────
check('desired: uses property_arrival_time',
    checkin_desired_time(['arrival_mode'=>'flight','property_arrival_time'=>'10:00:00']) === '10:00');
check('desired: trims seconds',
    checkin_desired_time(['property_arrival_time'=>'09:05:00']) === '09:05');
check('desired: road falls back to arrival_at',
    checkin_desired_time(['arrival_mode'=>'road','arrival_at'=>'2026-09-10 09:00:00+03']) === '09:00');
check('desired: other falls back to arrival_at',
    checkin_desired_time(['arrival_mode'=>'other','arrival_at'=>'2026-09-10 21:30:00+03']) === '21:30');
check('desired: flight NEVER falls back to the landing time',
    checkin_desired_time(['arrival_mode'=>'flight','arrival_at'=>'2026-09-10 10:00:00+03']) === '');
check('desired: no mode NEVER falls back (legacy flight-only form)',
    checkin_desired_time(['arrival_at'=>'2026-09-10 10:00:00+03']) === '');
check('desired: set value beats the fallback',
    checkin_desired_time(['arrival_mode'=>'road','arrival_at'=>'2026-09-10 09:00:00+03','property_arrival_time'=>'15:00:00']) === '15:00');
check('desired: empty data',            checkin_desired_time([]) === '');
check('desired: null data',             checkin_desired_time(null) === '');
check('desired: road with no arrival_at', checkin_desired_time(['arrival_mode'=>'road']) === '');
check('desired: unparseable arrival_at is blank, not a fatal',
    checkin_desired_time(['arrival_mode'=>'road','arrival_at'=>'not a date']) === '');

// ── Arrival step is complete once a mode is chosen ──────────────────────────
check('arrival: flight alone is enough now',  checkin_arrival_complete(['arrival_mode'=>'flight']) === true);
check('arrival: road alone is enough',        checkin_arrival_complete(['arrival_mode'=>'road'])   === true);
check('arrival: other alone is enough',       checkin_arrival_complete(['arrival_mode'=>'other'])  === true);
check('arrival: nothing chosen is incomplete',checkin_arrival_complete([])                          === false);
check('arrival: unknown mode is incomplete',  checkin_arrival_complete(['arrival_mode'=>'zzz'])     === false);
check('arrival: legacy no-mode row still passes on its old rule',
    checkin_arrival_complete(['flight_number'=>'KQ610','arrival_at'=>'2026-09-10 10:00:00+03']) === true);

// ── Transfer step covers both legs ──────────────────────────────────────────
$tc = fn(array $d) => checkin_step_complete('transfer', $d, null);
check('transfer: nothing answered',        $tc([]) === false);
check('transfer: both no',
    $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'f']) === true);
check('transfer: arrival yes + flying needs the flight fields',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'flight','needs_departure_transfer'=>'f']) === false);
check('transfer: arrival yes + flying, fields given',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'flight','arrival_airport'=>'MBA',
         'flight_number'=>'KQ610','arrival_at'=>'2026-09-10 10:00:00+03',
         'needs_departure_transfer'=>'f']) === true);
check('transfer: arrival yes + road needs a pickup point',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'road','needs_departure_transfer'=>'f']) === false);
check('transfer: arrival yes + road, pickup given',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'road','transfer_details'=>'Likoni ferry',
         'needs_departure_transfer'=>'f']) === true);

// A legacy row came from the flight-only form, so "yes, arrange it" means the
// flight fields ARE the detail and a pickup note is not a substitute. Asserted
// both directions, and for both shapes a legacy row can take: the column
// present-and-NULL (the real post-migration shape, no backfill) and the key
// absent entirely (pre-migration). The absence of this pair is what let the
// NULL-mode bug ship.
check('transfer: legacy null mode is not satisfied by a pickup note',
    $tc(['needs_transfer'=>'t','arrival_mode'=>null,'transfer_details'=>'Likoni ferry',
         'needs_departure_transfer'=>'f']) === false);
check('transfer: legacy null mode, flight fields given',
    $tc(['needs_transfer'=>'t','arrival_mode'=>null,'arrival_airport'=>'MBA',
         'flight_number'=>'KQ610','arrival_at'=>'2026-09-10 10:00:00+03',
         'needs_departure_transfer'=>'f']) === true);
check('transfer: absent mode key behaves identically',
    $tc(['needs_transfer'=>'t','transfer_details'=>'Likoni ferry',
         'needs_departure_transfer'=>'f']) === false);
check('transfer: absent mode key, flight fields given',
    $tc(['needs_transfer'=>'t','arrival_airport'=>'MBA','flight_number'=>'KQ610',
         'arrival_at'=>'2026-09-10 10:00:00+03','needs_departure_transfer'=>'f']) === true);

// The departure leg only exists once add_departure_transfer.sql is applied —
// before that checkin_step_complete() forces the outbound answer to "no", so an
// unanswered departure is legitimately complete and these would fail.
if (checkin_departure_transfer_supported()) {
    check('transfer: arrival answered, departure not',
        $tc(['needs_transfer'=>'f']) === false);
    check('transfer: departure yes needs a destination and a time',
        $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'t']) === false);
    check('transfer: departure yes, destination only',
        $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'t','departure_destination'=>'MBA']) === false);
    check('transfer: departure yes, both given',
        $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'t','departure_destination'=>'MBA',
             'departure_time'=>'12:00:00']) === true);
} else {
    echo "SKIP  add_departure_transfer.sql not applied — departure leg forced complete\n";
}

// ── no boolean is ever bound as a PHP bool ─────────────────────────────────
// db() uses PDO::ATTR_EMULATE_PREPARES, which renders a bound PHP false as ''.
// Postgres rejects '' for a boolean, so answering "No" to the transfer question
// used to fatal in api/checkin-save.php. This calls the REAL checkin_bool_param()
// the endpoint calls — an earlier version of this test re-implemented the
// expression, so a regression in the endpoint could not have failed it.
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
    // Every boolean api/checkin-save.php binds, through the function it uses.
    foreach (['needs_transfer', 'needs_departure_transfer'] as $k) {
        check("{$k}: answer \"yes\" binds TRUE",  checkin_bool_param([$k => '1'], $k) === 'TRUE');
        check("{$k}: answer \"no\" binds FALSE",  checkin_bool_param([$k => '0'], $k) === 'FALSE');
        check("{$k}: unanswered binds null",      checkin_bool_param([], $k) === null);
        check("{$k}: blank binds null",           checkin_bool_param([$k => ''], $k) === null);
        // Each result also has to survive the real column, not just look right.
        foreach (['1', '0', ''] as $posted) {
            check("{$k}: posted '{$posted}' is accepted by a BOOLEAN column",
                $ntBind(checkin_bool_param([$k => $posted], $k)) === 'accepted');
        }
        check("{$k}: no bind is ever a PHP bool", !array_filter(
            ['1', '0', ''], fn($v) => is_bool(checkin_bool_param([$k => $v], $k))
        ) && !is_bool(checkin_bool_param([], $k)));
    }
    db_query('DROP TABLE zz_nt_check');
}

// ── checkin_clock_time(): a bad value must degrade to null, never reach a TIME ──
// The check-in upsert is atomic and carries the whole form, so an exception on one
// clock value would lose the guest's transfer and dietary answers with it.
check("clock: '12:00' passes",       checkin_clock_time('12:00') === '12:00');
check("clock: '09:15' passes",       checkin_clock_time('09:15') === '09:15');
check("clock: '9:05' passes",        checkin_clock_time('9:05')  === '9:05');
check("clock: '23:59' passes",       checkin_clock_time('23:59') === '23:59');
check("clock: '00:00' passes",       checkin_clock_time('00:00') === '00:00');
check("clock: ' 10:00 ' is trimmed", checkin_clock_time(' 10:00 ') === '10:00');
check("clock: '99:99' → null",       checkin_clock_time('99:99') === null);
check("clock: '25:00' → null",       checkin_clock_time('25:00') === null);
check("clock: '12:60' → null",       checkin_clock_time('12:60') === null);
check("clock: '24:00' → null",       checkin_clock_time('24:00') === null);
check("clock: 'noon' → null",        checkin_clock_time('noon')  === null);
check("clock: '' → null",            checkin_clock_time('')      === null);
check('clock: null → null',          checkin_clock_time(null)    === null);
// Stricter than the read-side parser on purpose — writing must not guess.
check("clock: stored '12:00:00' → null (write-side is strict)", checkin_clock_time('12:00:00') === null);
check("flag parser still accepts '12:00:00' (read-side is lenient)",
    checkin_arrival_flag('12:00:00', '14:00', '20:00') === 'early');
// Whatever it returns must survive a real TIME column.
if (checkin_supported()) {
    db_query('CREATE TEMP TABLE zz_ct_check (t TIME)');
    $ctBind = function ($v): string {
        try { db_query('INSERT INTO zz_ct_check (t) VALUES (:t)', [':t' => $v]); return 'accepted'; }
        catch (Throwable $e) { return 'rejected'; }
    };
    check("raw '99:99' really is rejected by a TIME column", $ctBind('99:99') === 'rejected');
    foreach (['12:00', '9:05', '99:99', '25:00', 'noon', '', '12:00:00'] as $v) {
        check("clock: '{$v}' sanitised is accepted by a TIME column", $ctBind(checkin_clock_time($v)) === 'accepted');
    }
    db_query('DROP TABLE zz_ct_check');
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
