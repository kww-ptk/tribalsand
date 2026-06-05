<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$unit_id = (int)($_GET['unit']  ?? 0);
$token   = trim($_GET['token'] ?? '');

if (!$unit_id || !$token) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Missing unit or token parameter.');
}

$unit = db_query(
    "SELECT u.*, r.name AS room_name
     FROM units u JOIN rooms r ON r.id = u.room_id
     WHERE u.id = :id AND u.feed_token = :token AND u.is_active = TRUE",
    [':id' => $unit_id, ':token' => $token]
)->fetch();

if (!$unit) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Invalid token or unit not found.');
}

// All confirmed/blocked blocks for this unit (future-only)
$blocks = db_query(
    "SELECT ab.id, ab.date_from, ab.date_to, ab.block_type, ab.notes,
            h.guest_name
     FROM availability_blocks ab
     LEFT JOIN holds h ON h.id = ab.hold_id
     WHERE ab.unit_id = :uid
       AND ab.date_to > CURRENT_DATE
       AND ab.block_type IN ('booked','blocked')
     ORDER BY ab.date_from ASC",
    [':uid' => $unit_id]
)->fetchAll();

$env      = parse_env();
$site_url = rtrim($env['SITE_URL'] ?? 'https://sevenislandswatamu.com', '/');
$now_utc  = gmdate('Ymd\THis\Z');
$cal_name = $unit['room_name'] . ' — ' . $unit['name'];

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="7islands-unit-' . $unit_id . '.ics"');
header('Cache-Control: no-cache');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Seven Islands Resort//Availability//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:" . ical_escape($cal_name) . "\r\n";
echo "X-WR-TIMEZONE:Africa/Nairobi\r\n";
echo "X-WR-CALDESC:Availability feed for " . ical_escape($cal_name) . "\r\n";

foreach ($blocks as $block) {
    $uid     = 'block-' . $block['id'] . '-u' . $unit_id . '@sevenislandswatamu.com';
    $dtstart = str_replace('-', '', $block['date_from']);
    $dtend   = str_replace('-', '', $block['date_to']);

    if ($block['block_type'] === 'booked') {
        $summary = !empty($block['guest_name'])
            ? 'Booked — ' . $block['guest_name']
            : 'Booked';
    } else {
        $summary = !empty($block['notes']) ? $block['notes'] : 'Blocked';
    }

    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo "DTSTAMP:{$now_utc}\r\n";
    echo "DTSTART;VALUE=DATE:{$dtstart}\r\n";
    echo "DTEND;VALUE=DATE:{$dtend}\r\n";
    echo "SUMMARY:" . ical_escape($summary) . "\r\n";
    echo "TRANSP:OPAQUE\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";

function ical_escape(string $str): string {
    return str_replace(["\r\n", "\n", "\r", ',', ';', '\\'], ['\\n', '\\n', '\\n', '\\,', '\\;', '\\\\'], $str);
}
