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

// Ref gate — must resolve to a real, actionable hold
$hold = resolve_booking_by_ref((string)($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
if (!in_array($hold['status'], ['pending','confirmed'], true)) {
    http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'This booking can no longer be changed.']));
}

// Turnstile
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Security check failed. Please try again.']));
}

// Rate limit — max 5 change requests per hold / 10 min
$window = date('Y-m-d H:i:s', time() - 600);
$cnt = db_query("SELECT COUNT(*) c FROM booking_change_requests WHERE hold_id=:h AND created_at>:w",
    [':h'=>$hold['id'], ':w'=>$window])->fetch()['c'];
if ((int)$cnt >= 5) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many requests. Please wait a few minutes.'])); }

// Validate: at least one of dates/guests/note present
$ci    = trim($data['check_in']  ?? '');
$co    = trim($data['check_out'] ?? '');
$guests= (int)($data['guests'] ?? 0);
$note  = trim($data['note'] ?? '');
$isDate = fn($d) => $d === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$isDate($ci) || !$isDate($co)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please use valid dates.'])); }
if ($ci === '' && $co === '' && $guests <= 0 && $note === '') {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please tell us what you’d like to change.']));
}

try {
    db_query(
        "INSERT INTO booking_change_requests (hold_id, requested_check_in, requested_check_out, requested_guests, note)
         VALUES (:h, :ci, :co, :g, :note)",
        [':h'=>$hold['id'], ':ci'=>$ci ?: null, ':co'=>$co ?: null, ':g'=>$guests > 0 ? $guests : null, ':note'=>$note]
    );
    if (function_exists('send_change_request_notification')) {
        send_change_request_notification($hold, ['check_in'=>$ci,'check_out'=>$co,'guests'=>$guests,'note'=>$note]);
    }
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
