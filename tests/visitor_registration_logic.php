<?php
declare(strict_types=1);
// Visitor registration (general registration link).
// Pure always: tokens, scan, name/phone matching, cleaning + validation, waiver
// version, identity-change detection, the gate verdict, the audit hash.
// With a reachable DB and add_visitor_registration.sql applied, the DB block runs
// inside ONE transaction that is rolled back.
// Run: php tests/visitor_registration_logic.php
require_once __DIR__ . '/../includes/visitor-registration.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Token ──
$sec = 'test-secret';
$t = visitor_pass_token(12, $sec);
check('token: shape VP-<id>-<12 hex>', (bool)preg_match('/^VP-12-[0-9a-f]{12}$/', $t));
check('token: verifies', visitor_pass_verify($t, $sec) === 12);
check('token: upper case still verifies', visitor_pass_verify(strtoupper($t), $sec) === 12);
check('token: other id refused', visitor_pass_verify('VP-13-' . substr($t, 6), $sec) === false);
check('token: other secret refused', visitor_pass_verify($t, 'other') === false);
check('token: no secret = no token', visitor_pass_token(12, '') === '' && visitor_pass_verify($t, '') === false);
check('token: domain-separated from the gate pass', substr($t, 6) !== substr(hash_hmac('sha256', 'gate:12', $sec), 0, 12));
check('token: a GP- pass is not a visitor pass', visitor_pass_verify('GP-12-' . substr($t, 6), $sec) === false);
check('scan: token inside the gate link', visitor_pass_from_scan('https://tribalsand.com/admin/gate.php?vp=' . $t) === $t);
check('scan: GP pass is not read as VP', visitor_pass_from_scan('GP-12-0123456789ab') === '');

// ── Names and phones ──
check('name: same surname matches', visitor_booking_name_match('anna smith', 'Anna Smith'));
check('name: surname only matches', visitor_booking_name_match('Smith', 'Anna Smith'));
check('name: apostrophe ignored', visitor_booking_name_match("Sean O'Brien", 'Sean OBrien'));
check('name: accents folded', visitor_booking_name_match('Muller', 'Jürgen Müller'));
check('name words: accents folded without iconv', visitor_name_words('Jürgen Müller') === ['jurgen', 'muller']);
check('name words: curly apostrophe dropped', visitor_name_words('O’Brien') === ['obrien']);
check('name: second surname matches', visitor_booking_name_match('Garcia', 'Maria Lopez Garcia'));
check('name: other surname refused', !visitor_booking_name_match('Jones', 'Anna Smith'));
check('name: one letter refused', !visitor_booking_name_match('S', 'Anna S'));
check('name: empty refused', !visitor_booking_name_match('', 'Anna Smith'));
check('phone: digits only', visitor_phone_digits('+254 712-345 678') === '254712345678');
check('phone: key = last 9', visitor_phone_key('+254 712 345 678') === '712345678' && visitor_phone_key('0712345678') === '712345678');
check('phone: too short = no key', visitor_phone_key('12345') === '');

// ── Cleaning + validation ──
$in = visitor_clean_input(['passport_name' => '  Anna  Smith ', 'phone' => '+254712345678', 'email' => ' Anna@Example.COM ',
    'id_type' => 'kenya_id', 'passport_number' => 'ab 12-345 6', 'nationality' => 'Kenya', 'passport_expiry' => '2030-02-30']);
check('clean: name squeezed', $in['full_name'] === 'Anna Smith');
check('clean: email lower-cased', $in['email'] === 'anna@example.com');
check('clean: ID number upper alnum', $in['id_number'] === 'AB123456');
check('clean: bad date dropped', $in['id_expiry'] === '');
check('clean: phone key', $in['phone_key'] === '712345678');
check('clean: unknown id type → passport', visitor_clean_input(['id_type' => 'x'])['id_type'] === 'passport');
$good = $in + ['place_id' => 3, 'photo' => true, 'signed_name' => 'Anna Smith', 'signature' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40)), 'agree' => true];
check('valid: complete registration', visitor_validate($good, 'register', false, [3, 4]) === []);
check('valid: place must be offered', isset(visitor_validate(['place_id' => 9] + $good, 'register', false, [3, 4])['place_id']));
check('valid: photo required', isset(visitor_validate(['photo' => false] + $good, 'register', false, [3])['photo']));
check('valid: email required in public mode', isset(visitor_validate(['email' => ''] + $good, 'register', false, [3])['email']));
check('valid: email optional on the gate tablet', !isset(visitor_validate(['email' => ''] + $good, 'register', true, [3])['email']));
check('valid: bad email refused even on the tablet', isset(visitor_validate(['email' => 'nope'] + $good, 'register', true, [3])['email']));
check('valid: phone required', isset(visitor_validate(['phone_key' => ''] + $good, 'register', false, [3])['phone']));
check('valid: short ID number refused', isset(visitor_validate(['id_number' => 'AB1'] + $good, 'register', false, [3])['passport_number']));
check('valid: signature required', isset(visitor_validate(['signature' => ''] + $good, 'register', false, [3])['signature']));
check('valid: agree required', isset(visitor_validate(['agree' => false] + $good, 'register', false, [3])['agree']));
check('valid: edit mode needs no place or signature', visitor_validate(['place_id' => 0, 'signature' => '', 'agree' => false, 'signed_name' => ''] + $good, 'edit', false, [3]) === []);

// ── Waiver + identity ──
$signed = ['waiver_signed_at' => '2026-10-14 10:00', 'waiver_signed_name' => 'Anna Smith', 'waiver_signature' => 'data:x', 'waiver_version' => 'abc123abc123'];
check('signed: all three present', visitor_signed($signed));
check('signed: missing signature = not signed', !visitor_signed(['waiver_signature' => ''] + $signed));
check('current: same version', visitor_waiver_current($signed, 'abc123abc123'));
check('current: changed terms = not current', !visitor_waiver_current($signed, 'fff000fff000'));
$stored = ['full_name' => 'Anna Smith', 'id_type' => 'passport', 'id_number' => 'AB123456', 'nationality' => 'Kenya', 'id_expiry' => '2030-01-01'];
check('identity: nothing changed', visitor_identity_changed($stored, $stored) === []);
check('identity: case of number ignored', visitor_identity_changed($stored, ['id_number' => 'ab123456']) === []);
check('identity: new number', visitor_identity_changed($stored, ['id_number' => 'ZZ999999']) === ['id_number']);
check('identity: absent fields not compared', visitor_identity_changed($stored, ['phone' => '1']) === []);
check('reference: TSV-<6 digits>-<4>', (bool)preg_match('/^TSV-000012-[A-Z0-9]{4}$/', visitor_make_reference(12)));

// ── Gate verdict ──
$v = $signed + ['full_name' => 'Anna Smith', 'removed_at' => null];
$visit = ['place_name' => 'Tribal Table', 'venue_id' => 7];
$ok = visitor_gate_verdict($v, $visit, [['name' => 'Ben', 'current' => true]], true, true);
check('verdict: ok', $ok['level'] === 'ok' && str_contains($ok['msg'], 'Tribal Table'));
check('verdict: removed = stop', visitor_gate_verdict(['removed_at' => '2026-10-01'] + $v, $visit, [], true, true)['level'] === 'stop');
$far = visitor_gate_verdict(['waiver_signature' => ''] + $v, $visit, [['name' => 'Ben', 'current' => false]], false, false);
check('verdict: other property checked FIRST and names only the place', $far['level'] === 'stop' && str_contains($far['msg'], 'Tribal Table') && !str_contains($far['msg'], 'Ben'));
check('verdict: unsigned = stop', visitor_gate_verdict(['waiver_signature' => ''] + $v, $visit, [], false, true)['level'] === 'stop');
$terms = visitor_gate_verdict($v, $visit, [], false, true);
check('verdict: changed terms = stop, says sign again', $terms['level'] === 'stop' && str_contains($terms['msg'], 'sign again'));
$wait = visitor_gate_verdict($v, $visit, [['name' => 'Ben Ode', 'current' => false]], true, true);
check('verdict: an unsigned adult = stop naming them', $wait['level'] === 'stop' && str_contains($wait['msg'], 'Ben Ode'));
check('verdict: no place today = warn', visitor_gate_verdict($v, null, [], true, true)['level'] === 'warn');
check('people: lead + adults + children', visitor_people_count([['name' => 'a']], [['id' => 1], ['id' => 2]]) === 4);

// ── Audit hash ──
$row = ['reference' => 'TSV-000012-ABCD', 'visitor_id' => 12, 'step' => 'signed', 'waiver_version' => 'abc', 'ip' => '1.2.3.4', 'user_agent' => 'UA', 'method' => 'self', 'detail' => 'x'];
check('audit: hash is stable', visitor_audit_hash(str_repeat('0', 64), $row) === visitor_audit_hash(str_repeat('0', 64), $row));
check('audit: an edit changes the hash', visitor_audit_hash(str_repeat('0', 64), $row) !== visitor_audit_hash(str_repeat('0', 64), ['detail' => 'y'] + $row));
check('audit: int and string ids hash the same', visitor_audit_hash('p', $row) === visitor_audit_hash('p', ['visitor_id' => '12'] + $row));

// ── Share text ──
$s = visitor_invite_share_text('Ben Ode', 'Tribal Table', 'https://x/register.php?t=VP-1-a');
check('share: first name, place and link', str_contains($s, 'Ben') && !str_contains($s, 'Ode') && str_contains($s, 'Tribal Table') && str_contains($s, 'https://x/register.php?t=VP-1-a'));

// ── DB (rolled back) ──
require_once __DIR__ . '/../includes/visitor-store.php';
try { $pdo = db(); $pdo->query('SELECT 1'); } catch (Throwable $e) { $pdo = null; }
if (!$pdo) {
    echo "SKIP  DB checks (no database)\n";
} elseif (!visitor_reg_supported()) {
    echo "SKIP  DB checks (run add_visitor_registration.sql)\n";
} else {
    $pdo->beginTransaction();
    try {
        $_SERVER['HTTP_USER_AGENT'] = 'visitor-test';
        $venue = (int)db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
        $placeId = visitor_place_save(null, 'Test Bar ' . bin2hex(random_bytes(3)), $venue, 99, true);
        check('db place: saved and listed', (bool)array_filter(visitor_places(true, [$venue]), fn($p) => (int)$p['id'] === $placeId));
        $dup = false; try { visitor_place_save(null, strtoupper((string)visitor_place($placeId)['name']), $venue, 1, true); } catch (InvalidArgumentException $e) { $dup = true; }
        check('db place: duplicate name refused (any case)', $dup);

        $d = visitor_clean_input(['passport_name' => 'Anna Smith', 'phone' => '0712 345 678', 'email' => 'anna.vtest@example.com',
            'id_type' => 'passport', 'passport_number' => 'AB123456', 'nationality' => 'Kenya']) + ['photo_key' => 'visitors/test.jpg'];
        $id = visitor_create($d, 'self');
        check('db create: found by token', ($r = visitor_find_by_token(visitor_pass_token($id))) && (int)$r['id'] === $id);
        check('db create: found by phone in another format', ($r = visitor_find_by_contact('+254712345678')) && (int)$r['id'] === $id);
        check('db create: found by email any case', ($r = visitor_find_by_contact('ANNA.VTEST@example.com')) && (int)$r['id'] === $id);

        $sig = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40));
        $ref = visitor_sign($id, 'Anna Smith', $sig, 'self');
        $v = visitor_find($id);
        check('db sign: reference + snapshot', str_starts_with($ref, 'TSV-') && $v['waiver_name_snapshot'] === 'Anna Smith' && $v['waiver_id_number_snapshot'] === 'AB123456');
        check('db sign: current for today\'s terms', visitor_waiver_current($v, waiver_version(visitor_waiver_text())));

        check('db identity: unchanged details keep the signature', visitor_update_identity($id, $d, 'visitor') === false && visitor_signed(visitor_find($id)));
        check('db identity: new ID number voids', visitor_update_identity($id, ['id_number' => 'ZZ999999'] + $d, 'visitor') === true && !visitor_signed(visitor_find($id)));
        check('db identity: reference kept after void', visitor_find($id)['waiver_reference'] === $ref);
        visitor_sign($id, 'Anna Smith', $sig, 'self');
        check('db identity: re-sign snapshots the new number', visitor_find($id)['waiver_id_number_snapshot'] === 'ZZ999999');

        $kid = visitor_add_child($id, 'Mia Smith', 6);
        $ben = visitor_invite_adult($id, 'Ben Ode');
        $g = visitor_group($id);
        check('db group: one adult, one child', count($g['adults']) === 1 && count($g['children']) === 1);
        $visitId = visitor_choose_visit($id, $placeId, '2026-10-14', [$ben, 999999], [$kid], 'self');
        $visit = visitor_visit_on($id, '2026-10-14');
        check('db visit: foreign ids dropped from the party', $visit && $visit['party']['adults'] === [$ben] && $visit['party']['children'] === [$kid]);
        check('db visit: choosing again updates the same row', visitor_choose_visit($id, $placeId, '2026-10-14', [], [], 'self') === $visitId);
        visitor_choose_visit($id, $placeId, '2026-10-14', [$ben], [$kid], 'self');

        $card = visitor_gate_card($id, '2026-10-14', [$venue]);
        check('db gate: unsigned adult in the party = stop', $card['verdict']['level'] === 'stop' && str_contains($card['verdict']['msg'], 'Ben Ode'));
        check('db gate: let in refused while an adult hasn\'t signed', !visitor_let_in($id, '2026-10-14', null, 0, [$venue])['ok']);
        visitor_update_identity($ben, visitor_clean_input(['passport_name' => 'Ben Ode', 'phone' => '0799 000 111', 'email' => 'ben.vtest@example.com',
            'passport_number' => 'CD654321', 'nationality' => 'Kenya']) + ['photo_key' => 'visitors/ben.jpg'], 'visitor');
        visitor_sign($ben, 'Ben Ode', $sig, 'self');
        $card = visitor_gate_card($id, '2026-10-14', [$venue]);
        check('db gate: everyone signed = ok, 3 people', $card['verdict']['level'] === 'ok' && $card['people'] === 3);
        check('db gate: other property sees a stop', visitor_gate_card($id, '2026-10-14', [-1])['verdict']['level'] === 'stop');
        $in = visitor_let_in($id, '2026-10-14', null, 0, [$venue]);
        check('db gate: let in writes the register row', $in['ok'] && ($os = visitor_on_site($id)) && (int)$os['people'] === 3 && (int)$os['visit_id'] === $visitId);
        $again = visitor_let_in($id, '2026-10-15', $placeId, 0, [$venue]);   // next day, a place picked: only the open sign-in blocks it
        check('db gate: next day refused while still signed in', !$again['ok'] && str_contains($again['msg'], 'sign them out first'));

        set_setting('visitor_waiver_text', 'Changed terms ' . bin2hex(random_bytes(3)));
        check('db terms: changed waiver = stop "sign again"', str_contains(visitor_gate_card($id, '2026-10-14', [$venue])['verdict']['msg'], 'sign again'));

        $audit = visitor_audit_verify($id);
        check('db audit: chain verifies', $audit['ok'] && $audit['checked'] >= 3);
        db_query("UPDATE visitor_signing_audit SET detail = 'edited' WHERE id = (SELECT MAX(id) FROM visitor_signing_audit WHERE visitor_id = :v)", [':v' => $id]);
        check('db audit: an edited row is detected', !visitor_audit_verify($id)['ok']);

        for ($i = 0; $i < VISITOR_CODE_FAIL_MAX; $i++) visitor_attempt_log('code_fail');
        check('db limit: code failures counted per IP', visitor_attempt_count('code_fail', 10) >= VISITOR_CODE_FAIL_MAX);
        visitor_attempt_log('recover_mail', 'anna.vtest@example.com');
        check('db limit: counted per subject', visitor_attempt_count('recover_mail', 10, 'anna.vtest@example.com') === 1);

        check('db lookup: unknown code = notfound', visitor_booking_lookup('ZZZZZZZZ', 'Smith', '2026-10-14')['kind'] === 'notfound');
        $h = db_query("SELECT id, access_code, guest_name FROM holds WHERE status IN ('pending','confirmed') AND check_out >= CURRENT_DATE AND access_code IS NOT NULL AND guest_name <> '' ORDER BY id DESC LIMIT 1")->fetch();
        if ($h) {
            $words = visitor_name_words((string)$h['guest_name']);
            $hit = visitor_booking_lookup((string)$h['access_code'], (string)end($words), date('Y-m-d'));
            check('db lookup: code + surname = the lead\'s link', $hit['kind'] === 'lead' && str_contains($hit['url'], 'ref='));
            check('db lookup: code + wrong name = nomatch, no link', visitor_booking_lookup((string)$h['access_code'], 'Qqqzzx', date('Y-m-d')) === ['kind' => 'nomatch', 'url' => '']);
        } else {
            echo "SKIP  booking lookup (no current booking with a code)\n";
        }

        check('db remove: removed', visitor_remove($id));
        check('db remove: token stops working', visitor_find_by_token(visitor_pass_token($id)) === null);
        $rm = visitor_find($id, true);
        check('db remove: photo key cleared, signature evidence kept', $rm['id_photo_key'] === null && $rm['waiver_reference'] !== null);
    } catch (Throwable $e) {
        check('db checks ran without error: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
