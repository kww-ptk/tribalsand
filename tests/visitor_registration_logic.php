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

// ── Invite token (VI) ──
$it = visitor_invite_token(12, $sec);
check('invite: shape VI-<id>-<12 hex>', (bool)preg_match('/^VI-12-[0-9a-f]{12}$/', $it));
check('invite: verifies', visitor_invite_verify($it, $sec) === 12);
check('invite: upper case still verifies', visitor_invite_verify(strtoupper($it), $sec) === 12);
check('invite: other secret refused', visitor_invite_verify($it, 'other') === false);
check('invite: other id refused', visitor_invite_verify('VI-13-' . substr($it, 6), $sec) === false);
check('invite: a VP pass is not an invite', visitor_invite_verify($t, $sec) === false && visitor_invite_verify('VI-12-' . substr($t, 6), $sec) === false);
check('invite: an invite is not a VP pass', visitor_pass_verify($it, $sec) === false && visitor_pass_verify('VP-12-' . substr($it, 6), $sec) === false);
check('invite: no secret = no token', visitor_invite_token(12, '') === '' && visitor_invite_verify($it, '') === false);

// ── Return address after a form post ──
check('ret: extensionless register kept', visitor_safe_ret('/register?step=visitor') === '/register?step=visitor');
check('ret: register.php with gate kept', visitor_safe_ret('/register.php?step=visitor&gate=1') === '/register.php?step=visitor&gate=1');
check('ret: extensionless pass kept', visitor_safe_ret('/visitor-pass?t=VP-1-abc') === '/visitor-pass?t=VP-1-abc');
check('ret: bare /register kept', visitor_safe_ret('/register') === '/register');
check('ret: protocol-relative refused', visitor_safe_ret('//evil.com') === '/register.php');
check('ret: absolute URL refused', visitor_safe_ret('https://evil.com/register') === '/register.php');
check('ret: path after .php refused', visitor_safe_ret('/register.php/../x') === '/register.php');
check('ret: admin page refused', visitor_safe_ret('/admin/x.php') === '/register.php');
check('ret: empty = default', visitor_safe_ret('') === '/register.php');

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
// ── Standing pass (members, friends, community, contractors) ──
check('standing: none without a type', visitor_standing(['pass_type' => null], '2026-10-15') === null);
check('standing: unknown type = none', visitor_standing(['pass_type' => 'vip'], '2026-10-15') === null);
$sp = visitor_standing(['pass_type' => 'member', 'pass_until' => '2026-12-31', 'pass_place_ids' => '{3,7}'], '2026-10-15');
check('standing: label, until, places', $sp['label'] === 'Member' && $sp['until'] === '2026-12-31' && $sp['places'] === [3, 7] && $sp['active']);
check('standing: last day still active', visitor_standing(['pass_type' => 'friend', 'pass_until' => '2026-10-15'], '2026-10-15')['active']);
$spx = visitor_standing(['pass_type' => 'friend', 'pass_until' => '2026-10-14'], '2026-10-15');
check('standing: day after = expired', !$spx['active'] && $spx['expired']);
$spn = visitor_standing(['pass_type' => 'community', 'pass_until' => null, 'pass_place_ids' => null], '2026-10-15');
check('standing: no end, all places', $spn['active'] && $spn['until'] === '' && $spn['places'] === null && $spn['label'] === 'Local community');
$visit7 = ['place_name' => 'Tribal Table', 'venue_id' => 7, 'place_id' => 7];
$sv = visitor_gate_verdict($v, $visit7, [], true, true, $sp);
check('standing verdict: ok names the pass + end date', $sv['level'] === 'ok' && str_contains($sv['msg'], 'Member') && str_contains($sv['msg'], '31 Dec 2026'));
$so = visitor_gate_verdict($v, ['place_id' => 9, 'place_name' => 'Zuri'] + $visit7, [], true, true, $sp);
check('standing verdict: place not covered = warn, comes as a visitor', $so['level'] === 'warn' && str_contains($so['msg'], 'Zuri'));
$se = visitor_gate_verdict($v, $visit7, [], true, true, $spx);
check('standing verdict: expired = warn, comes as a visitor', $se['level'] === 'warn' && str_contains($se['msg'], 'ended'));
check('standing verdict: still asks the place', visitor_gate_verdict($v, null, [], true, true, $sp)['level'] === 'warn');
check('standing verdict: unsigned still stops', visitor_gate_verdict(['waiver_signature' => ''] + $v, $visit7, [], false, true, $sp)['level'] === 'stop');
check('standing: places a pass allows', visitor_standing_allows($sp, 7) && !visitor_standing_allows($sp, 9) && visitor_standing_allows($spn, 9) && visitor_standing_allows(null, 9));
check('people: lead + adults + children', visitor_people_count([['name' => 'a']], [['id' => 1], ['id' => 2]]) === 4);

// ── Audit hash ──
$row = ['reference' => 'TSV-000012-ABCD', 'visitor_id' => 12, 'step' => 'signed', 'waiver_version' => 'abc', 'ip' => '1.2.3.4', 'user_agent' => 'UA', 'method' => 'self', 'detail' => 'x'];
check('audit: hash is stable', visitor_audit_hash(str_repeat('0', 64), $row) === visitor_audit_hash(str_repeat('0', 64), $row));
check('audit: an edit changes the hash', visitor_audit_hash(str_repeat('0', 64), $row) !== visitor_audit_hash(str_repeat('0', 64), ['detail' => 'y'] + $row));
check('audit: int and string ids hash the same', visitor_audit_hash('p', $row) === visitor_audit_hash('p', ['visitor_id' => '12'] + $row));

// ── Share text ──
$s = visitor_invite_share_text('Ben Ode', 'Tribal Table', 'https://x/register.php?i=VI-1-a');
check('share: first name, place and link', str_contains($s, 'Ben') && !str_contains($s, 'Ode') && str_contains($s, 'Tribal Table') && str_contains($s, 'https://x/register.php?i=VI-1-a'));

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

        $invId = visitor_invite_adult($id, 'Ivy Invitee');
        check('db invite: an invited row resolves by its invite token', ($r = visitor_find_by_invite(visitor_invite_token($invId))) && (int)$r['id'] === $invId);
        check('db invite: its VP token is not an invite', visitor_find_by_invite(visitor_pass_token($invId)) === null);
        check('db invite: the invite url carries ?i= and no VP token', str_contains(visitor_invite_url($invId), '/register.php?i=VI-') && !str_contains(visitor_invite_url($invId), 'VP-'));
        visitor_sign($invId, 'Ivy Invitee', 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40)), 'self');
        check('db invite: after signing the invite no longer resolves', visitor_find_by_invite(visitor_invite_token($invId)) === null);
        check('db invite: ...but their own pass still does', ($r = visitor_find_by_token(visitor_pass_token($invId))) && (int)$r['id'] === $invId);
        db_query('UPDATE registered_visitors SET removed_at = now() WHERE id = :id', [':id' => $invId]);

        $sig = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40));
        $ref = visitor_sign($id, 'Anna Smith', $sig, 'self');
        $v = visitor_find($id);
        check('db sign: reference + snapshot', str_starts_with($ref, 'TSV-') && $v['waiver_name_snapshot'] === 'Anna Smith' && $v['waiver_id_number_snapshot'] === 'AB123456');
        check('db sign: current for today\'s terms', visitor_waiver_current($v, waiver_version(visitor_waiver_text())));
        if (visitor_photo_snapshot_supported()) check('db sign: the photo on file is frozen with the signature', ($v['waiver_photo_snapshot'] ?? null) === $v['id_photo_key']);

        check('db identity: unchanged details keep the signature', visitor_update_identity($id, $d, 'visitor') === false && visitor_signed(visitor_find($id)));
        check('db identity: new ID number voids', visitor_update_identity($id, ['id_number' => 'ZZ999999'] + $d, 'visitor') === true && !visitor_signed(visitor_find($id)));
        check('db identity: reference kept after void', visitor_find($id)['waiver_reference'] === $ref);
        visitor_sign($id, 'Anna Smith', $sig, 'self');
        check('db identity: re-sign snapshots the new number', visitor_find($id)['waiver_id_number_snapshot'] === 'ZZ999999');

        visitor_update_identity($id, ['photo_key' => 'visitors/new.jpg', 'id_number' => 'ZZ999999'] + $d, 'visitor');
        check('db identity: a new photo alone keeps the signature and stores the key', visitor_signed(visitor_find($id)) && visitor_find($id)['id_photo_key'] === 'visitors/new.jpg');
        visitor_update_identity($id, ['id_number' => 'QQ1'] + $d, 'visitor');
        check('db identity: void and change land together (new number + signature gone)', visitor_find($id)['id_number'] === 'QQ1' && !visitor_signed(visitor_find($id)));
        visitor_update_identity($id, ['id_number' => 'ZZ999999'] + $d, 'visitor');
        visitor_sign($id, 'Anna Smith', $sig, 'self');

        visitor_discard_file_deletes();
        $oldKey = (string)visitor_find($id)['id_photo_key'];
        visitor_update_identity($id, ['photo_key' => 'visitors/newer.jpg', 'id_number' => 'ZZ999999'] + $d, 'visitor');
        if (visitor_photo_snapshot_supported()) {
            // The photo the signature was given with is frozen: replacing it keeps the file (nothing queued).
            check('db identity: a signed photo is kept when only the photo is replaced', $oldKey !== '' && visitor_pending_file_deletes() === []);
            // Voiding the signature releases the replaced photo AND the frozen one.
            visitor_update_identity($id, ['photo_key' => 'visitors/newest.jpg', 'id_number' => 'VV7'] + $d, 'visitor');
            check('db identity: voiding queues the replaced and the frozen photo', visitor_pending_file_deletes() === ['visitors/newer.jpg', $oldKey]);
            visitor_update_identity($id, ['id_number' => 'ZZ999999'] + $d, 'visitor');
            visitor_sign($id, 'Anna Smith', $sig, 'self');   // back to signed, as the checks below expect
        } else {
            check('db identity: inside a caller transaction the old photo is queued, not deleted', $oldKey !== '' && visitor_pending_file_deletes() === [$oldKey]);
        }
        visitor_discard_file_deletes();
        check('db identity: discard empties the queue', visitor_pending_file_deletes() === []);

        if (visitor_photo_snapshot_supported()) {
            // Replace the photo of a signed visitor (frozen file A kept), then re-sign after a terms change: A is released.
            $frozenA = (string)visitor_find($id)['waiver_photo_snapshot'];
            visitor_update_identity($id, ['photo_key' => 'visitors/resign-b.jpg', 'id_number' => 'ZZ999999'] + $d, 'visitor');
            check('db re-sign: replacing the photo keeps the frozen file for now', $frozenA !== '' && visitor_pending_file_deletes() === []);
            visitor_sign($id, 'Anna Smith', $sig, 'self');
            check('db re-sign: the old frozen photo is queued for deletion', visitor_pending_file_deletes() === [$frozenA]);
            check('db re-sign: the new snapshot is the current photo', visitor_find($id)['waiver_photo_snapshot'] === 'visitors/resign-b.jpg');
            visitor_sign($id, 'Anna Smith', $sig, 'self');
            check('db re-sign: signing again with the same photo queues nothing more', visitor_pending_file_deletes() === [$frozenA]);
            visitor_discard_file_deletes();
        }

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

        check('db gate: refused let-in leaves no visit row behind', visitor_visit_on($id, '2026-10-15') === null);
        $venue2 = (int)db_query('SELECT id FROM venues WHERE id <> :v ORDER BY id LIMIT 1', [':v' => $venue])->fetchColumn();
        if ($venue2) {
            $place2 = visitor_place_save(null, 'Test Bar2 ' . bin2hex(random_bytes(3)), $venue2, 98, true);
            $r2 = visitor_let_in($id, '2026-10-15', $place2, 0, [$venue2]);
            check('db gate: refused let-in at another property is refused', !$r2['ok']);
            check('db gate: ...and does not widen that property\'s registry scope', !visitor_in_scope($id, [$venue2], 0) && visitor_visit_on($id, '2026-10-15') === null);
        } else {
            echo "SKIP  second-property scope check (only one venue)\n";
        }
        // Sign the lead out, then try a place behind a property the guard doesn't cover: the place-validity path is really reached.
        db_query('UPDATE visitors SET time_out = now() WHERE registered_visitor_id = :v AND time_out IS NULL', [':v' => $id]);
        if ($venue2) {
            $oos = visitor_let_in($id, '2026-10-16', $placeId, 0, [$venue2]);
            check('db gate: place out of the guard\'s scope = refused, no visit, no scope widening',
                !$oos['ok'] && visitor_visit_on($id, '2026-10-16') === null && !visitor_in_scope($id, [$venue2], 0));
        }
        $solo = visitor_create(visitor_clean_input(['passport_name' => 'Cleo Solo', 'phone' => '0733 111 222', 'email' => 'cleo.vtest@example.com',
            'passport_number' => 'EF111222', 'nationality' => 'Kenya']) + ['photo_key' => ''], 'self');
        visitor_sign($solo, 'Cleo Solo', $sig, 'self');
        $bad = visitor_let_in($solo, '2026-10-14', null, 0, [$venue]);
        check('db gate: no place picked = refused, no visit written', !$bad['ok'] && visitor_visit_on($solo, '2026-10-14') === null);
        $good = visitor_let_in($solo, '2026-10-14', $placeId, 0, [$venue]);
        check('db gate: gate picks the place and lets in together', $good['ok'] && visitor_visit_on($solo, '2026-10-14') !== null && visitor_on_site($solo) !== null);

        // Registry "Last place / Last visit" only among the account's own properties.
        if ($venue2) {
            $two = visitor_create(visitor_clean_input(['passport_name' => 'Tess Twoplace', 'phone' => '0744 222 333', 'email' => 'tess.vtest@example.com',
                'passport_number' => 'GH333444', 'nationality' => 'Kenya']) + ['photo_key' => ''], 'self');
            visitor_choose_visit($two, $placeId, '2026-10-01', [], [], 'self');   // in scope, older
            visitor_choose_visit($two, $place2, '2026-10-05', [], [], 'self');    // other property, newer
            $rowsIn = visitor_registry_list([$venue], 0, 'Tess Twoplace', 25, 0)['rows'];
            check('db registry: scoped list shows only the in-scope last place',
                count($rowsIn) === 1 && $rowsIn[0]['last_place'] === visitor_place($placeId)['name'] && (string)$rowsIn[0]['last_visit'] === '2026-10-01');
            $rowsAll = visitor_registry_list(null, 0, 'Tess Twoplace', 25, 0)['rows'];
            check('db registry: the owner sees the latest place anywhere', count($rowsAll) === 1 && (string)$rowsAll[0]['last_visit'] === '2026-10-05');
        }

        // Abandoned ID photos: an unattached draft older than 24 h is pruned, an attached one is left alone.
        db_query("INSERT INTO visitor_reg_attempts (ip, kind, subject, created_at) VALUES
                  ('t', 'upload', 'visitors/vtest-orphan.jpg', now() - interval '25 hours'),
                  ('t', 'upload_gate', 'visitors/vtest-orphan2.jpg', now() - interval '25 hours'),
                  ('t', 'upload', 'visitors/vtest-fresh.jpg', now() - interval '1 hour'),
                  ('t', 'upload', :used, now() - interval '25 hours')", [':used' => (string)visitor_find($id)['id_photo_key']]);
        $dry = visitor_prune_drafts(24, true);
        check('db prune: dry run counts the orphans and the attached one', $dry['deleted'] >= 2 && $dry['kept'] >= 1
            && (int)db_query("SELECT COUNT(*) FROM visitor_reg_attempts WHERE subject LIKE 'visitors/vtest-orphan%'")->fetchColumn() === 2);
        visitor_prune_drafts(24, false);
        check('db prune: orphans processed once (subject cleared)', (int)db_query("SELECT COUNT(*) FROM visitor_reg_attempts WHERE subject LIKE 'visitors/vtest-orphan%'")->fetchColumn() === 0);
        check('db prune: a fresh draft is kept', (int)db_query("SELECT COUNT(*) FROM visitor_reg_attempts WHERE subject = 'visitors/vtest-fresh.jpg'")->fetchColumn() === 1);
        check('db prune: the attached photo still on the visitor', (string)visitor_find($id)['id_photo_key'] !== '');

        // Standing passes.
        if (visitor_standing_supported()) {
            visitor_set_standing($id, 'member', '2099-12-31', [$placeId], 'Beach club', 0, '2026-10-14');
            $card = visitor_gate_card($id, '2026-10-14', [$venue]);
            check('db standing: card carries the pass, OK names it', $card['standing']['label'] === 'Member' && $card['verdict']['level'] === 'ok' && str_contains($card['verdict']['msg'], 'Member'));
            $other = visitor_place_save(null, 'Test Other ' . bin2hex(random_bytes(3)), $venue, 97, true);
            visitor_set_standing($id, 'member', '', [$other], '', 0, '2026-10-14');
            check('db standing: place not covered = warn', visitor_gate_card($id, '2026-10-14', [$venue])['verdict']['level'] === 'warn');
            $bad = []; foreach ([['vip', ''], ['member', '2020-01-01'], ['member', '2099-02-30']] as [$t, $u]) {
                try { visitor_set_standing($id, $t, $u, null, '', 0, '2026-10-14'); $bad[] = false; } catch (InvalidArgumentException $e) { $bad[] = true; }
            }
            check('db standing: bad kind / past date / impossible date refused', $bad === [true, true, true]);
            check('db standing: end pass keeps the visitor', visitor_end_standing($id) && visitor_find($id) !== null && visitor_gate_card($id, '2026-10-14', [$venue])['standing'] === null);
            $inv = visitor_invite_standing('Kim Vtest', '0700 111 222', 'kim.vtest@example.com', 'friend', '', null, '', 0, '2026-10-14');
            check('db standing: invite = unsigned row with the pass, single-use link works', ($r = visitor_find_by_invite(visitor_invite_token($inv))) && (int)$r['id'] === $inv && $r['pass_type'] === 'friend');
            $badInv = false; try { visitor_invite_standing('Kim', '', '', 'friend', '', null, '', 0, '2026-10-14'); } catch (InvalidArgumentException $e) { $badInv = true; }
            check('db standing: invite needs a full name', $badInv);
        } else {
            echo "SKIP  standing passes (run add_visitor_standing_pass.sql)\n";
        }

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
            db_query("UPDATE holds SET status = 'pending', expires_at = now() - interval '1 hour' WHERE id = :id", [':id' => $h['id']]);
            check('db lookup: a pending hold whose 24h ran out = notfound', visitor_booking_lookup((string)$h['access_code'], (string)end($words), date('Y-m-d'))['kind'] === 'notfound');
        } else {
            echo "SKIP  booking lookup (no current booking with a code)\n";
        }

        check('db remove: removed', visitor_remove($id));
        check('db remove: token stops working', visitor_find_by_token(visitor_pass_token($id)) === null);
        $rm = visitor_find($id, true);
        check('db remove: photo key cleared, signature evidence kept', $rm['id_photo_key'] === null && $rm['waiver_reference'] !== null);
        if (visitor_photo_snapshot_supported()) check('db remove: frozen photo cleared too', $rm['waiver_photo_snapshot'] === null);
    } catch (Throwable $e) {
        check('db checks ran without error: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
