<?php
declare(strict_types=1);
/**
 * Public Maya Ilai atomic multi-room booking (Phase B). Turns a configurator
 * offer into a real, all-or-nothing set of 24h holds. Same public-form guards as
 * api/submit-enquiry.php (honeypot, Turnstile fail-closed, IP/email rate limit);
 * the booking itself — repricing, allocation, ledger price freeze — is
 * mi_book_configuration().
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/maya-ilai-hold.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Honeypot — accept-and-ignore.
if (!empty($data['website'])) { exit(json_encode(['ok'=>true])); }

// Turnstile, fail-closed (this endpoint blocks the calendar, so it must not be
// open to bots).
$ip = client_ip();
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403);
    exit(json_encode(['ok'=>false, 'error'=>'Security check failed. Please try again.']));
}

// Rate limit — 5 per IP or email in 10 minutes (same as the enquiry endpoint).
$window = date('Y-m-d H:i:s', time() - 600);
$emailRaw = strtolower(trim($data['email'] ?? ''));
$ipCount = (int) db_query("SELECT COUNT(*) FROM submissions WHERE ip_address = :ip AND created_at > :w",
    [':ip'=>$ip, ':w'=>$window])->fetchColumn();
$emailCount = $emailRaw ? (int) db_query("SELECT COUNT(*) FROM submissions WHERE guest_email = :e AND created_at > :w",
    [':e'=>$emailRaw, ':w'=>$window])->fetchColumn() : 0;
if ($ipCount >= 5 || $emailCount >= 5) {
    http_response_code(429);
    exit(json_encode(['ok'=>false, 'error'=>'Too many requests. Please wait a few minutes.']));
}

// Validate the essentials.
$name  = trim($data['name']  ?? '');
$email = trim($data['email'] ?? '');
$units = (isset($data['units']) && is_array($data['units'])) ? $data['units'] : [];
$errors = [];
if ($name === '')                               $errors['name']  = 'Your name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'A valid email is required.';
if (!$units)                                    $errors['rooms'] = 'No rooms were selected.';
if ($errors) { http_response_code(422); exit(json_encode(['ok'=>false, 'errors'=>$errors])); }

if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = ($_SESSION['tracking'] ?? []) + ['ip' => $ip];

$res = mi_book_configuration(
    $units,
    (string)($data['check_in'] ?? ''),
    (string)($data['check_out'] ?? ''),
    $name, $email,
    trim($data['phone'] ?? ''),
    trim($data['message'] ?? ''),
    $tracking
);

if (empty($res['ok'])) {
    http_response_code((int)($res['code'] ?? 422));
    exit(json_encode(['ok'=>false, 'error'=>$res['error'] ?? 'Could not complete the booking.']));
}

// After the commit: tell staff and acknowledge the guest. Best-effort — a mail
// hiccup must not un-book a confirmed set of holds.
$firstCode = $res['holds'][0]['access_code'] ?? '';
try {
    send_notification([
        'id'         => (int)$res['submission_id'],
        'type'       => 'hold',
        'room_name'  => 'Maya Ilai · ' . $res['rooms'],
        'guest_name' => $name,
        'guest_email'=> $email,
        'guest_phone'=> trim($data['phone'] ?? ''),
        'message'    => 'Multi-room hold: ' . $res['rooms'] . ' · ' . $res['currency'] . ' ' . number_format((float)$res['total'], 2),
        'check_in'   => (string)($data['check_in'] ?? ''),
        'check_out'  => (string)($data['check_out'] ?? ''),
        'created_at' => date('Y-m-d H:i:s'),
    ] + $tracking);

    send_guest_acknowledgement([
        'kind'        => 'hold',
        'guest_name'  => $name,
        'guest_email' => $email,
        'room_name'   => 'Maya Ilai — ' . $res['rooms'],
        'check_in'    => (string)($data['check_in'] ?? ''),
        'check_out'   => (string)($data['check_out'] ?? ''),
        'message'     => trim($data['message'] ?? ''),
        'access_code' => $firstCode,
    ]);
} catch (Throwable $e) {
    error_log('[maya-ilai-book] mail failed: ' . $e->getMessage());
}

echo json_encode([
    'ok'          => true,
    'mode'        => 'hold',
    'rooms'       => $res['rooms'],
    'nights'      => $res['nights'],
    'total'       => $res['total'],
    'currency'    => $res['currency'],
    'holds'       => count($res['holds']),
    'access_code' => $firstCode,
]);
