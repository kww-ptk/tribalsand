<?php
declare(strict_types=1);
// Group allocation import — pure parsing/naming rules always; the create path runs
// in ONE rolled-back transaction when the database has the Maya Ilai rooms.
// Run: php tests/group_import_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/group-import.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Dates ───────────────────────────────────────────────────────────────────
check('date: ISO',            gi_parse_date('2026-10-24') === '2026-10-24');
check('date: 24 Oct 2026',    gi_parse_date('24 Oct 2026') === '2026-10-24');
check('date: 24 October 2026', gi_parse_date('24 October 2026') === '2026-10-24');
check('date: 24/10/2026',     gi_parse_date('24/10/2026') === '2026-10-24');
check('date: dash = none',    gi_parse_date('—') === null);
check('date: blank = none',   gi_parse_date('  ') === null);
check('date: impossible',     gi_parse_date('31 Feb 2026') === null);

// ── Names ───────────────────────────────────────────────────────────────────
check('clean: nickname dropped',   gi_clean_name('Li (Eric) Shuai') === 'Li Shuai');
check('clean: spaces collapsed',   gi_clean_name('  Hemlata  (Nani)   Gandhi ') === 'Hemlata Gandhi');
check('split: two names',          gi_split_names('Luke Scott-Berry, Natalie Meyer', 'Luke Scott-Berry') === ['Luke Scott-Berry', 'Natalie Meyer']);
check('split: first name → head',  gi_split_names('Mita, Ravi', 'Ravi Bhatt') === ['Mita', 'Ravi Bhatt']);
check('split: dash = nobody',      gi_split_names('—', 'X') === []);
check('split: "and" / "&"',        gi_split_names('Ann Lee & Bob Lee and Cy Lee', '') === ['Ann Lee', 'Bob Lee', 'Cy Lee']);
check('split: hyphen name kept',   gi_split_names('Neha Patel-Gandhi, Sundeep Gandhi', 'Sundeep Gandhi') === ['Neha Patel-Gandhi', 'Sundeep Gandhi']);

check('booking name: head in room',     gi_booking_name(['Devyani Parekh', 'Mayur Parekh'], 'Mayur Parekh') === 'Mayur Parekh');
check('booking name: head elsewhere',   gi_booking_name(['Kayen Gandhi', 'Krishav Gandhi'], 'Kishen Gandhi') === 'Kayen Gandhi');
check('booking name: case-insensitive', gi_booking_name(['hiten gandhi'], 'Hiten Gandhi') === 'Hiten Gandhi');
check('booking name: nobody → head',    gi_booking_name([], 'Jayen Patel') === 'Jayen Patel');
check('roster: lead first',             gi_roster(['Devyani Parekh', 'Mayur Parekh'], 'Mayur Parekh') === ['Mayur Parekh', 'Devyani Parekh']);
check('roster: head elsewhere keeps order', gi_roster(['Kayen Gandhi', 'Krishav Gandhi'], 'Kishen Gandhi') === ['Kayen Gandhi', 'Krishav Gandhi']);

// ── Maya Ilai labels ────────────────────────────────────────────────────────
$s = gi_resolve_label('maya-ilai', 'Studio No. 03A');
check('label: studio number',     ($s['kind'] ?? '') === 'studio' && ($s['n'] ?? 0) === 3);
$v = gi_resolve_label('maya-ilai', 'Villa 05: Room 501');
check('label: villa room 1 = double_a', ($v['kind'] ?? '') === 'villa' && $v['n'] === 5 && $v['component'] === 'double_a' && $v['product'] === 'maya-ilai-double');
$v = gi_resolve_label('maya-ilai', 'Villa 05: Room 502 Double bedroom');
check('label: room 2 = double_b',  ($v['component'] ?? '') === 'double_b');
$v = gi_resolve_label('maya-ilai', 'Villa 08: Room 803 Bunk room · 6 beds');
check('label: room 3 = bunk',      ($v['component'] ?? '') === 'bunk' && $v['product'] === 'maya-ilai-bunk-room' && $v['n'] === 8);
check('label: room/villa mismatch refused', gi_resolve_label('maya-ilai', 'Villa 05: Room 601') === null);
check('label: room 4 refused',     gi_resolve_label('maya-ilai', 'Villa 05: Room 504') === null);
check('label: other venue = map',  gi_resolve_label('maya-kobe', 'Villa 1 - Ocean View') === null);
check('label: maya_ilai slug too',  (gi_resolve_label('maya_ilai', 'Studio No. 01A')['n'] ?? 0) === 1);

// ── CSV ─────────────────────────────────────────────────────────────────────
$csv = "\xEF\xBB\xBFProperty,Room,Guests,Head / Group,Email,Check-in,Check-out\n"
     . "maya-ilai,Studio No. 01A,\"Avinash Patel, Anita Patel\",Avinash Patel,apatel2@hfhs.org,24 Oct 2026,29 Oct 2026\n"
     . "maya-kobe,Villa 4 - Behind Villa 3,Jayen Patel,Jayen Patel,jayenpatel@yahoo.com,—,—\n"
     . "\n";
$p = gi_parse_csv($csv);
check('csv: two rows (blank skipped)', count($p['rows']) === 2);
check('csv: header aliases',           ($p['rows'][0]['head'] ?? '') === 'Avinash Patel' && ($p['rows'][0]['check_in'] ?? '') === '2026-10-24');
check('csv: quoted comma kept',        ($p['rows'][0]['guests'] ?? '') === 'Avinash Patel, Anita Patel');
check('csv: missing dates = null',     $p['rows'][1]['check_in'] === null);
check('csv: line numbers',             $p['rows'][1]['line'] === 3);
$bad = gi_parse_csv("Name,Foo\nA,B\n");
check('csv: missing columns reported', $bad['rows'] === [] && count($bad['errors']) === 1);

// ── Share text + slug ───────────────────────────────────────────────────────
$txt = gi_share_text('Kishen Gandhi', [
    ['room' => 'Villa 05: Room 501', 'property' => 'Maya Ilai', 'guest' => 'Kishen Gandhi', 'link' => 'https://x/b?ref=1'],
    ['room' => 'Villa 05: Room 503', 'property' => 'Maya Ilai', 'guest' => 'Kayen Gandhi',  'link' => 'https://x/b?ref=2'],
], 'Chris & Bini wedding');
check('share: greets head',      str_starts_with($txt, 'Hi Kishen,'));
check('share: lists both links', str_contains($txt, 'https://x/b?ref=1') && str_contains($txt, 'https://x/b?ref=2'));
check('share: names the event',  str_contains($txt, 'Chris & Bini wedding'));
check('slug: label',             gi_batch_slug('Chris & Bini wedding') === 'chris-bini-wedding');

// ── Group code: what a guest types at /register ─────────────────────────────
check('code: upper, letters + digits only', gi_code_norm(' bini-26 ') === 'BINI26');
check('code: too short / too long refused', gi_code_norm('ab1') === '' && gi_code_norm(str_repeat('A', 13)) === '');
$roster = [
    ['hold_id' => 1, 'guest_id' => 11, 'name' => 'Vishal Shah',       'is_lead' => true],
    ['hold_id' => 1, 'guest_id' => 12, 'name' => 'Neha Shah',         'is_lead' => false],
    ['hold_id' => 2, 'guest_id' => 21, 'name' => 'Anna Smith',        'is_lead' => true],
    ['hold_id' => 3, 'guest_id' => 31, 'name' => 'Anna Maria Smith',  'is_lead' => true],
    ['hold_id' => 4, 'guest_id' => 41, 'name' => 'Jürgen Müller',     'is_lead' => true],
    ['hold_id' => 5, 'guest_id' => 51, 'name' => 'Sam Lee',           'is_lead' => true],
    ['hold_id' => 6, 'guest_id' => 61, 'name' => 'Sam Lee',           'is_lead' => true],
];
check('member: first + last finds the guest',        (gi_member_match($roster, 'Neha Shah')['guest_id'] ?? 0) === 12);
check('member: any order, any case',                 (gi_member_match($roster, 'shah NEHA')['guest_id'] ?? 0) === 12);
check('member: accents folded',                      (gi_member_match($roster, 'Jurgen Muller')['guest_id'] ?? 0) === 41);
check('member: surname alone is not enough',         gi_member_match($roster, 'Shah') === null);
check('member: exact name wins over a longer one',   (gi_member_match($roster, 'Anna Smith')['guest_id'] ?? 0) === 21);
check('member: middle name narrows it',              (gi_member_match($roster, 'Anna Maria Smith')['guest_id'] ?? 0) === 31);
check('member: two people with the same name = none', gi_member_match($roster, 'Sam Lee') === null);
check('member: someone not on the list = none',      gi_member_match($roster, 'John Doe') === null);

// ── DB: plan + create, rolled back ──────────────────────────────────────────
$haveMi = false;
try { $haveMi = (bool) db_query("SELECT 1 FROM rooms WHERE slug = 'maya-ilai-villa'")->fetchColumn()
             && (bool) db_query("SELECT 1 FROM rooms WHERE slug = 'maya-ilai-studio'")->fetchColumn(); }
catch (Throwable $e) {}
if (!$haveMi) {
    echo "SKIP  plan/create (no Maya Ilai rooms in this database)\n";
} else {
    db()->beginTransaction();
    try {
        $rows = gi_parse_csv("property,room,guests,head,email,check_in,check_out\n"
            . "maya-ilai,Villa 01: Room 101,\"Neha Shah, Vishal Shah\",Vishal Shah,v@example.com,2099-10-26,2099-10-29\n"
            . "maya-ilai,Villa 01: Room 103,Purav Vagadia,Purav Vagadia,p@example.com,2099-10-26,2099-10-29\n"
            . "maya-ilai,Villa 01: Room 101,Someone Else,Someone Else,s@example.com,2099-10-27,2099-10-28\n"
            . "maya-ilai,Studio No. 02A,\"Aarti Vagadia, Pankaj Vagadia\",Pankaj Vagadia,a@example.com,2099-10-26,2099-10-29\n")['rows'];
        $plan = gi_plan($rows, [], null);
        check('plan: villa bedroom ready',      $plan[0]['status'] === 'ready' && $plan[0]['components'] === ['double_a']);
        check('plan: bunk ready',               $plan[1]['status'] === 'ready' && $plan[1]['components'] === ['bunk']);
        check('plan: same bedroom in batch taken', $plan[2]['status'] === 'taken');
        check('plan: studio ready',             $plan[3]['status'] === 'ready' && $plan[3]['components'] === null);
        check('plan: adults counted',           $plan[0]['adults'] === 2);

        $res = gi_create($plan, 'Test group 2099', 1);
        check('create: three bookings',         count($res['hold_ids']) === 3);
        $h = db_query('SELECT * FROM holds WHERE id = :h', [':h' => $res['hold_ids'][0]])->fetch();
        check('create: confirmed, no expiry',   $h['status'] === 'confirmed' && $h['expires_at'] === null);
        check('create: booking name = head',    $h['guest_name'] === 'Vishal Shah' && $h['guest_email'] === 'v@example.com');
        if (array_key_exists('require_checkin', $h)) check('create: check-in required', !empty($h['require_checkin']) && (int)$h['guest_count'] === 2);
        $blk = db_query('SELECT components::text c FROM availability_blocks WHERE hold_id = :h', [':h' => $res['hold_ids'][0]])->fetchColumn();
        check('create: only that bedroom blocked', str_contains((string)$blk, 'double_a') && !str_contains((string)$blk, 'bunk'));
        $roster = db_query('SELECT passport_name, is_lead FROM checkin_guests WHERE hold_id = :h ORDER BY is_lead DESC, id', [':h' => $res['hold_ids'][0]])->fetchAll();
        check('create: roster lead = head',     ($roster[0]['passport_name'] ?? '') === 'Vishal Shah' && !empty($roster[0]['is_lead']));
        check('create: roster second adult',    ($roster[1]['passport_name'] ?? '') === 'Neha Shah');
        if (bookings_supported()) {
            $led = db_query('SELECT gross_amount, source, agent FROM bookings WHERE hold_id = :h', [':h' => $res['hold_ids'][0]])->fetch();
            check('create: ledger at 0, direct',  $led && (float)$led['gross_amount'] === 0.0 && $led['source'] === 'direct' && $led['agent'] === 'Test group 2099');
            bookings_sync_hold($res['hold_ids'][0]);
            $g2 = (float) db_query('SELECT gross_amount FROM bookings WHERE hold_id = :h', [':h' => $res['hold_ids'][0]])->fetchColumn();
            check('create: a later sync keeps 0',  $g2 === 0.0);
        }
        $again = gi_plan($rows, [], null);
        check('re-plan: already imported',      $again[0]['status'] === 'imported' && (int)$again[0]['hold_id'] === $res['hold_ids'][0]);

        $batch = gi_batch($res['slug']);
        check('batch saved',                    $batch !== null && $batch['hold_ids'] === $res['hold_ids']);
        check('batch listed',                   in_array($res['slug'], array_column(gi_batches(), 'slug'), true));
        $links = gi_batch_links($batch['hold_ids'], $batch['heads']);
        check('links: grouped by email',        count($links) === 3 && str_contains($links[0]['rooms'][0]['link'], '/booking.php?ref='));
        check('links: head from the file',      in_array('Vishal Shah', array_column($links, 'head'), true));
        check('links: sorted by head',          array_column($links, 'head') === ['Pankaj Vagadia', 'Purav Vagadia', 'Vishal Shah']);
        $vishal = array_values(array_filter($links, fn($g) => $g['head'] === 'Vishal Shah'))[0];
        check('links: bedroom named',           str_ends_with($vishal['rooms'][0]['room'], '· first double'));

        // Group code: minted on create, found by code, a guest finds their own check-in.
        $code = (string)($batch['code'] ?? '');
        check('code: minted on create',         gi_code_norm($code) === $code && $code !== '');
        check('code: finds the batch',          (gi_batch_by_code(strtolower($code))['slug'] ?? '') === $res['slug']);
        $hit = gi_group_lookup($code, 'Neha Shah', '2099-10-20');
        check('lookup: a guest gets their own link', $hit['kind'] === 'guest' && str_contains($hit['url'], '/booking.php?g='));
        $lead = gi_group_lookup($code, 'Vishal Shah', '2099-10-20');
        check('lookup: the room lead gets the check-in', $lead['kind'] === 'lead' && str_contains($lead['url'], 'view=checkin'));
        check('lookup: unknown name = nomatch, no link', gi_group_lookup($code, 'John Doe', '2099-10-20') === ['kind' => 'nomatch', 'url' => '']);
        check('lookup: after the stay = notfound', gi_group_lookup($code, 'Neha Shah', '2099-11-30')['kind'] === 'notfound');
        check('lookup: unknown code = notfound',   gi_group_lookup('ZZZZ99', 'Neha Shah', '2099-10-20')['kind'] === 'notfound');
        gi_batch_set_code($res['slug'], 'test-2099');
        check('code: owner can set their own',  (gi_batch_by_code('TEST2099')['slug'] ?? '') === $res['slug'] && gi_batch_by_code($code) === null);
        $clash = db_query("SELECT access_code FROM holds WHERE access_code IS NOT NULL AND access_code <> '' LIMIT 1")->fetchColumn();
        if ($clash) {
            $refused = false; try { gi_batch_set_code($res['slug'], (string)$clash); } catch (InvalidArgumentException $e) { $refused = true; }
            check('code: a booking code is refused', $refused);
        }
    } finally {
        db()->rollBack();
    }
}

// ── Check-in status on the links page ──────────────────────────────────────
$b = gi_checkin_badge(['require_checkin' => true, 'checkin_completed_at' => '2026-10-15 10:00'], 2, 2);
check('badge: everyone done = checked in', $b['key'] === 'done' && $b['label'] === 'Checked in');
$b = gi_checkin_badge(['require_checkin' => true, 'checkin_completed_at' => null], 1, 3);
check('badge: some done = "1 of 3 checked in"', $b['key'] === 'part' && $b['label'] === '1 of 3 checked in');
$b = gi_checkin_badge(['require_checkin' => true, 'checkin_completed_at' => null], 0, 2);
check('badge: nobody yet = not started', $b['key'] === 'none' && $b['label'] === 'Not checked in');
$b = gi_checkin_badge(['require_checkin' => true, 'checkin_completed_at' => null], 2, 2);
check('badge: everyone done but not stamped yet still counts as checked in', $b['key'] === 'done');
$b = gi_checkin_badge(['require_checkin' => false], 0, 2);
check('badge: check-in not required', $b['key'] === 'off');
$b = gi_checkin_badge(['require_checkin' => true, 'status' => 'cancelled'], 0, 2);
check('badge: cancelled room is not counted', $b['key'] === 'off');
$s = gi_checkin_summary([['rooms' => [['checkin' => ['key' => 'done']], ['checkin' => ['key' => 'part']]]], ['rooms' => [['checkin' => ['key' => 'none']], ['checkin' => ['key' => 'off']]]]]);
check('summary: counts per state, off left out of the total', $s === ['done' => 1, 'part' => 1, 'none' => 1, 'total' => 3]);

// ── DB: a mapped property (room picked on the preview), rolled back ────────
$cot = null;
try { $cot = db_query("SELECT r.id, r.venue_id FROM rooms r WHERE r.slug = 'maya-kobe-cottages'")->fetch() ?: null; } catch (Throwable $e) {}
if (!$cot || count(db_query('SELECT id FROM units WHERE room_id = :r AND is_active', [':r' => $cot['id']])->fetchAll()) < 2) {
    echo "SKIP  mapped room (no two-unit maya-kobe-cottages here)\n";
} else {
    db()->beginTransaction();
    try {
        $rows = gi_parse_csv("property,room,guests,head,email,check_in,check_out\n"
            . "maya-kobe,Cottage,A One,A One,a1@example.com,2099-11-02,2099-11-05\n"
            . "maya-kobe,Cottage,B Two,B Two,b2@example.com,2099-11-02,2099-11-05\n"
            . "maya-kobe,Cottage,C Three,C Three,c3@example.com,2099-11-03,2099-11-04\n"
            . "maya-kobe,Villa 9,D Four,D Four,d4@example.com,2099-11-03,2099-11-04\n")['rows'];
        $map  = [(string)$cot['venue_id'] => ['Cottage' => (int)$cot['id']]];
        $plan = gi_plan($rows, $map, null);
        check('mapped: first unit',            $plan[0]['status'] === 'ready');
        check('mapped: second unit',           $plan[1]['status'] === 'ready' && $plan[1]['unit_id'] !== $plan[0]['unit_id']);
        check('mapped: third = no unit left',  $plan[2]['status'] === 'taken');
        check('mapped: unknown label unmapped', $plan[3]['status'] === 'unmapped');
        check('scope: other property refused', gi_plan($rows, $map, [999999])[0]['status'] === 'scope');
    } finally {
        db()->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
