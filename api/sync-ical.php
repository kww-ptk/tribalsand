<?php
/**
 * iCal pull sync — fetches the OTA feeds (Airbnb, Booking.com…) into the calendar.
 * Protected by ICAL_SYNC_SECRET (Authorization: Bearer, or the legacy ?secret=).
 *
 * Run every 15 minutes by docker/scheduler.sh. Staff use Admin → iCal feeds →
 * "Sync now", which runs the same code under their session (no secret in a page).
 * The logic lives in includes/ical-sync.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/ical-sync.php';

// tests/maya_ilai_inventory.php requires this file for ical_import_event() only.
if (defined('ICAL_SYNC_LIBRARY_ONLY')) return;

header('Content-Type: application/json');

$env        = parse_env();
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $secret = trim(substr($authHeader, 7));
} else {
    $secret = trim($_GET['secret'] ?? '');
}
$expected = trim($env['ICAL_SYNC_SECRET'] ?? '');

if (!$expected || !hash_equals($expected, $secret)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden — set ICAL_SYNC_SECRET in environment.']));
}

$results = ical_sync_all();
if (!$results) {
    exit(json_encode(['ok' => true, 'message' => 'No feeds configured.', 'feeds' => []]));
}
echo json_encode(['ok' => true, 'synced_at' => date('c'), 'feeds' => $results], JSON_PRETTY_PRINT);
