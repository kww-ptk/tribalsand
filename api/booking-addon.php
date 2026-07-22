<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$ip   = client_ip();
$str  = fn($v) => is_scalar($v) ? trim((string)$v) : '';

$hold = resolve_booking_by_ref($str($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
if (!in_array($hold['status'], ['pending','confirmed'], true)) {
    http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'This booking can no longer take additions.']));
}

if (!verify_captcha($str($data['cf-turnstile-response'] ?? ''), $ip)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Security check failed. Please try again.']));
}

// Rate limit — max 10 add-on requests per hold / 10 min
$cnt = db_query("SELECT COUNT(*) c FROM booking_addons WHERE hold_id=:h AND created_at > NOW() - INTERVAL '10 minutes'",
    [':h'=>$hold['id']])->fetch()['c'];
if ((int)$cnt >= 10) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many requests. Please wait a few minutes.'])); }

// Validate
$kind = $str($data['kind'] ?? '');
if (!in_array($kind, ['tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant'], true)) {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown add-on type.']));
}
$details = $str($data['details'] ?? '');
$tour_id = null;

if ($kind === 'tour') {
    $slug = $str($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_by_slug($slug) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid tour.'])); }
    $tour_id = (int)$tour['id'];
    if ($details === '') $details = $tour['name'];
} elseif ($kind === 'transfer') {
    $opt = $str($data['transfer'] ?? '');
    if (!array_key_exists($opt, TRANSFER_OPTIONS)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a transfer option.'])); }
    $label = TRANSFER_OPTIONS[$opt];
    $details = $details === '' ? $label : "{$label} — {$details}";
} else { // itinerary / other
    if ($details === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please add a few details.'])); }
}

try {
    db_query(
        "INSERT INTO booking_addons (hold_id, kind, tour_id, details) VALUES (:h, :k, :t, :d)",
        [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details]
    );
    if (function_exists('send_addon_request_notification')) {
        send_addon_request_notification($hold, ['kind'=>$kind,'details'=>$details]);
    }
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[booking-addon] failed: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
