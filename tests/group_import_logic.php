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
    } finally {
        db()->rollBack();
    }
}

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
