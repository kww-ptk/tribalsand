<?php
/**
 * Outbound iCal feed for ONE unit — the link pasted into Airbnb / Booking.com /
 * VRBO / Expedia so they close the dates we can't sell. Copied from Admin →
 * Bookings → iCal feeds. Authenticated by the unit's random feed_token.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/ical-sync.php';   // ical_export_blocks() (loads db.php)

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

// Everything that makes this unit unavailable: our bookings + 24h holds +
// closures + other channels' imported bookings + the buyout rule
// (ical_export_blocks()). Each range goes out as an anonymous "Not available":
// guest names and staff notes never leave our system.
$blocks = ical_export_blocks($unit_id);

$now_utc  = gmdate('Ymd\THis\Z');
$cal_name = 'Tribal Sand — ' . $unit['room_name'] . ' — ' . $unit['name'];

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="tribalsand-unit-' . $unit_id . '.ics"');
header('Cache-Control: no-cache');

$out = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Tribal Sand//Availability//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:' . ical_escape($cal_name),
    'X-WR-TIMEZONE:Africa/Nairobi',
];

foreach ($blocks as $block) {
    $dtstart = str_replace('-', '', (string) $block['date_from']);
    $dtend   = str_replace('-', '', (string) $block['date_to']);
    // Stable for the same range, so an OTA updates rather than duplicates.
    $out[] = 'BEGIN:VEVENT';
    $out[] = 'UID:ts-u' . $unit_id . '-' . $dtstart . '-' . $dtend . '@tribalsand.com';
    $out[] = 'DTSTAMP:' . $now_utc;
    $out[] = 'DTSTART;VALUE=DATE:' . $dtstart;
    $out[] = 'DTEND;VALUE=DATE:' . $dtend;
    $out[] = 'SUMMARY:Not available';
    $out[] = 'TRANSP:OPAQUE';
    $out[] = 'END:VEVENT';
}

$out[] = 'END:VCALENDAR';
echo implode("\r\n", $out) . "\r\n";

function ical_escape(string $str): string {
    // Backslash first, or the escapes added below would be doubled.
    $str = str_replace('\\', '\\\\', $str);
    return str_replace(["\r\n", "\n", "\r", ',', ';'], ['\\n', '\\n', '\\n', '\\,', '\\;'], $str);
}
