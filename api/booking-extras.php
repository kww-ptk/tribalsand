<?php
/**
 * GET /api/booking-extras?room=<slug> — the add-ons offered while booking that
 * room: activities placed on the booking surface + transfers marked "Offer when
 * booking", for the room's property (includes/upsells.php). Read-only, public;
 * the booking pop-up renders them as tick boxes and api/submit-enquiry.php
 * re-validates every posted id, so nothing here is trusted on the way back.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/upsells.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$slug = trim((string)($_GET['room'] ?? ''));
$room = $slug !== '' ? fetch_room_by_slug($slug) : null;   // published rooms only
if (!$room) {
    http_response_code(404);
    exit(json_encode(['activities' => [], 'transfers' => []]));
}

echo json_encode(upsell_booking_extras(((int)($room['venue_id'] ?? 0)) ?: null));
