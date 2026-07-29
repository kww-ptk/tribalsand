<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$str  = fn($v) => is_scalar($v) ? trim((string)$v) : '';

$hold = resolve_booking_by_ref($str($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }

$body = $str($data['body'] ?? '');
if ($body === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please type a message.'])); }
if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);

$addonId = null;
if (($data['addon_id'] ?? '') !== '' && $data['addon_id'] !== null) {
    $addonId = (int)$data['addon_id'];
    $own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$addonId, ':h'=>$hold['id']])->fetchColumn();
    if (!$own) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown request.'])); }
}

// Rate limit: max 20 messages per hold / 10 min
$cnt = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND created_at > NOW() - INTERVAL '10 minutes'", [':h'=>$hold['id']])->fetchColumn();
if ($cnt >= 20) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many messages. Please wait a few minutes.'])); }

try {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
        [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body]
    );
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[booking-message] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not send your message. Please try again.']);
}
