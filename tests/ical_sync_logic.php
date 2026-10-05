<?php
declare(strict_types=1);
// Two-way iCal sync — parsing, tracking diff, cancel / move / echo, export.
// Run: php tests/ical_sync_logic.php
// Pure checks always; DB checks run inside ONE transaction that is ROLLED BACK.
require_once __DIR__ . '/../includes/ical-sync.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function done(): void {
    echo $GLOBALS['failures'] ? "{$GLOBALS['failures']} FAILURE(S)\n" : "ALL PASS\n";
    exit($GLOBALS['failures'] ? 1 : 0);
}
/** Build a feed body: [[uid, from Ymd, to Ymd, summary], …] */
function ics(array $events): string {
    $out = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Test//EN"];
    foreach ($events as [$uid, $from, $to, $summary]) {
        $out[] = 'BEGIN:VEVENT';
        if ($uid !== null) $out[] = "UID:{$uid}";
        $out[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $from);
        $out[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $to);
        $out[] = "SUMMARY:{$summary}";
        $out[] = 'END:VEVENT';
    }
    $out[] = 'END:VCALENDAR';
    return implode("\r\n", $out) . "\r\n";
}

// ── Pure ────────────────────────────────────────────────────────────────────
$today = '2026-10-05';
$skipped = 0;
$ev = ical_feed_events(ics([
    ['a1@airbnb', '2026-11-01', '2026-11-04', 'Reserved'],
    ['old',       '2026-01-01', '2026-01-03', 'Reserved'],     // past
    ['bad',       '2026-12-05', '2026-12-05', 'Reserved'],     // zero nights
    [null,        '2026-12-10', '2026-12-12', 'CLOSED - Not available'],
]), $today, 'Booking.com', $skipped);
check('events keyed by UID', isset($ev['a1@airbnb']) && $ev['a1@airbnb']['from'] === '2026-11-01' && $ev['a1@airbnb']['to'] === '2026-11-04');
check('past + zero-night events dropped and counted', !isset($ev['old']) && !isset($ev['bad']) && $skipped === 2);
check('event without UID keyed on its dates', isset($ev['nouid:2026-12-10|2026-12-12']));
check('checkout today is still current', count(ical_feed_events(ics([['t', '2026-10-01', '2026-10-05', 'x']]), $today)) === 1);
check('folded long line is unfolded', isset(ical_feed_events("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:long\r\n -uid\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\nEND:VEVENT\r\nEND:VCALENDAR", $today)['long-uid']));

$plan = ical_diff_tracked(
    ['k1' => ['from' => '2026-11-01', 'to' => '2026-11-04'], 'k2' => ['from' => '2026-11-10', 'to' => '2026-11-12'], 'k3' => ['from' => '2026-12-01', 'to' => '2026-12-02']],
    [['id' => 1, 'ical_uid' => 'k1', 'date_from' => '2026-11-01', 'date_to' => '2026-11-04'],
     ['id' => 2, 'ical_uid' => 'k2', 'date_from' => '2026-11-09', 'date_to' => '2026-11-12'],
     ['id' => 3, 'ical_uid' => 'gone', 'date_from' => '2026-11-20', 'date_to' => '2026-11-22'],
     ['id' => 4, 'ical_uid' => 'k1', 'date_from' => '2026-11-01', 'date_to' => '2026-11-04']]
);
check('diff: unchanged kept', $plan['keep'] === ['k1']);
check('diff: changed dates = move', $plan['move'] === ['k2' => 2]);
check('diff: gone from feed = remove, duplicate removed too', $plan['remove'] === [3, 4]);
check('diff: unseen = new', $plan['new'] === ['k3']);

check('calendar detected', ical_is_calendar(ics([])) && !ical_is_calendar('<html>Sign in</html>'));
check('Airbnb "Reserved" is a reservation', ical_summary_is_reservation('Reserved') && !ical_summary_is_reservation('Airbnb (Not available)'));
check('label from Airbnb link', ical_label_from_url('https://www.airbnb.com/calendar/ical/1.ics?s=x') === 'Airbnb');
check('label from Booking.com link', ical_label_from_url('https://ical.booking.com/v1/export?t=x') === 'Booking.com');
check('label from VRBO/HomeAway link', ical_label_from_url('https://www.homeaway.com/icalendar/x.ics') === 'VRBO');
check('unknown host → no label', ical_label_from_url('https://example.org/x.ics') === '');
check('https feed accepted', ical_feed_url_ok('https://www.airbnb.com/calendar/ical/1.ics'));
check('http feed refused (no metadata endpoints)', !ical_feed_url_ok('http://169.254.170.2/v2/credentials'));
check('junk refused', !ical_feed_url_ok('airbnb'));

// ── DB ──────────────────────────────────────────────────────────────────────
try { db(); } catch (Throwable $e) { echo "SKIP  DB checks (no database)\n"; done(); }
if (!ical_tracking_supported()) { echo "SKIP  DB checks (add_ical_sync_tracking.sql not applied)\n"; done(); }

$unit = db_query(
    "SELECT u.id, r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id
      WHERE u.is_active AND NOT COALESCE(r.is_entire_place, FALSE) AND r.slug NOT LIKE 'maya-ilai%'
      ORDER BY u.id LIMIT 1"
)->fetch();
if (!$unit) { echo "SKIP  DB checks (no ordinary unit)\n"; done(); }
$U = (int) $unit['id'];

db()->beginTransaction();
try {
    $mkFeed = fn (string $label) => db_query(
        "INSERT INTO ical_feeds (unit_id, label, feed_url) VALUES (:u, :l, 'https://example.test/x.ics') RETURNING *",
        [':u' => $U, ':l' => $label])->fetch();
    $A = $mkFeed('Booking.com');
    $B = $mkFeed('Airbnb');
    $blocksOf = fn (array $f) => db_query(
        "SELECT id, date_from::text AS df, date_to::text AS dt, ical_uid FROM availability_blocks WHERE ical_feed_id = :f ORDER BY id",
        [':f' => (int) $f['id']])->fetchAll();

    $r = ical_sync_feed($A, ics([['bk1', '2098-03-01', '2098-03-05', 'CLOSED - Not available']]));
    $bl = $blocksOf($A);
    check('new OTA booking imported + tracked', $r['status'] === 'ok' && $r['imported'] === 1 && count($bl) === 1 && $bl[0]['ical_uid'] === 'bk1');

    $r = ical_sync_feed($A, ics([['bk1', '2098-03-01', '2098-03-05', 'CLOSED - Not available']]));
    check('unchanged feed re-sync adds nothing', $r['imported'] === 0 && count($blocksOf($A)) === 1);

    $r = ical_sync_feed($A, ics([['bk1', '2098-03-02', '2098-03-06', 'CLOSED - Not available']]));
    $bl = $blocksOf($A);
    check('booking moved on the OTA moves here', $r['moved'] === 1 && count($bl) === 1 && $bl[0]['df'] === '2098-03-02' && $bl[0]['dt'] === '2098-03-06');

    $r = ical_sync_feed($B, ics([['ab-echo', '2098-03-02', '2098-03-06', 'Airbnb (Not available)']]));
    check('Airbnb echo of the Booking.com dates is not imported', $r['echoes'] === 1 && $r['imported'] === 0 && count($blocksOf($B)) === 0);

    $r = ical_sync_feed($A, ics([]));
    check('booking cancelled on the OTA is cleared here', $r['removed'] === 1 && count($blocksOf($A)) === 0);

    $r = ical_sync_feed($B, ics([['ab-echo', '2098-03-02', '2098-03-06', 'Airbnb (Not available)']]));
    $gone = db_query("SELECT origin_gone_at FROM ical_echoes WHERE feed_id = :f AND uid = 'ab-echo'", [':f' => (int) $B['id']])->fetchColumn();
    check('leftover echo ignored inside the grace window', $r['imported'] === 0 && count($blocksOf($B)) === 0 && $gone !== null);

    db_query("UPDATE ical_echoes SET origin_gone_at = NOW() - INTERVAL '7 hours' WHERE feed_id = :f", [':f' => (int) $B['id']]);
    $r = ical_sync_feed($B, ics([['ab-echo', '2098-03-02', '2098-03-06', 'Airbnb (Not available)']]));
    check('still there after the grace window = a real block, imported', $r['imported'] === 1 && count($blocksOf($B)) === 1);

    $r = ical_sync_feed($B, '<html><body>Please sign in</body></html>');
    check('a non-calendar answer is an error and removes nothing', $r['status'] === 'error' && count($blocksOf($B)) === 1);

    $r = ical_sync_feed($B, ics([]));
    check('empty calendar clears this feed’s blocks', $r['removed'] === 1 && count($blocksOf($B)) === 0
        && !db_query("SELECT 1 FROM ical_echoes WHERE feed_id = :f", [':f' => (int) $B['id']])->fetchColumn());

    // A block "Keep OTA" wrote (feed known, UID not) is adopted, not duplicated.
    db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes, ical_feed_id)
              VALUES (:u, '2098-05-01', '2098-05-04', 'blocked', 'iCal (conflict resolved): x', :f)", [':u' => $U, ':f' => (int) $A['id']]);
    $r = ical_sync_feed($A, ics([['bk9', '2098-05-01', '2098-05-04', 'CLOSED - Not available']]));
    $bl = $blocksOf($A);
    check('Keep-OTA block adopted by UID', $r['imported'] === 0 && count($bl) === 1 && $bl[0]['ical_uid'] === 'bk9');
    $r = ical_sync_feed($A, ics([]));
    check('…and cleared when that booking is cancelled', $r['removed'] === 1 && count($blocksOf($A)) === 0);

    // A staff closure is never touched by a feed.
    db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
              VALUES (:u, '2098-06-01', '2098-06-03', 'blocked', 'Maintenance')", [':u' => $U]);
    ical_sync_feed($A, ics([]));
    check('staff closure survives a sync', (bool) db_query("SELECT 1 FROM availability_blocks WHERE unit_id = :u AND notes = 'Maintenance' AND date_from = '2098-06-01'", [':u' => $U])->fetchColumn());

    $st = db_query("SELECT last_status, last_event_count FROM ical_feeds WHERE id = :id", [':id' => (int) $A['id']])->fetch();
    check('feed status recorded', $st['last_status'] === 'ok' && (int) $st['last_event_count'] === 0);

    // Export: closures + holds go out; identical ranges once.
    db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
              VALUES (:u, '2098-07-01', '2098-07-02', 'hold', 'test hold')", [':u' => $U]);
    $exp = array_map(fn ($b) => $b['date_from'] . '|' . $b['date_to'], ical_export_blocks($U));
    check('export carries closures', in_array('2098-06-01|2098-06-03', $exp, true));
    check('export carries 24h holds', in_array('2098-07-01|2098-07-02', $exp, true));
    check('export lists each range once', count($exp) === count(array_unique($exp)));

    // Buyout rule: a booked whole-property unit closes its rooms' feeds.
    $pair = db_query(
        "SELECT ue.id AS whole, ur.id AS room
           FROM rooms re JOIN units ue ON ue.room_id = re.id AND ue.is_active
           JOIN rooms rr ON rr.venue_id = re.venue_id AND rr.id <> re.id AND NOT COALESCE(rr.is_entire_place, FALSE)
           JOIN units ur ON ur.room_id = rr.id AND ur.is_active
          WHERE re.is_entire_place AND re.slug NOT LIKE 'maya-ilai%'
          LIMIT 1"
    )->fetch();
    if ($pair) {
        db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
                  VALUES (:u, '2098-08-01', '2098-08-05', 'booked', 'buyout')", [':u' => (int) $pair['whole']]);
        $exp = array_map(fn ($b) => $b['date_from'] . '|' . $b['date_to'], ical_export_blocks((int) $pair['room']));
        check('whole-property booking closes each room’s export', in_array('2098-08-01|2098-08-05', $exp, true));
        $feedR = db_query("INSERT INTO ical_feeds (unit_id, label, feed_url) VALUES (:u, 'Airbnb', 'https://example.test/r.ics') RETURNING *",
            [':u' => (int) $pair['room']])->fetch();
        $r = ical_sync_feed($feedR, ics([['room-echo', '2098-08-01', '2098-08-05', 'Airbnb (Not available)']]));
        check('…and the room’s echo of it is recognised', $r['echoes'] === 1 && $r['imported'] === 0);
    } else {
        echo "SKIP  buyout export (no whole-property room with sibling rooms)\n";
    }
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
done();
