<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Honeypot
if (!empty($data['website'])) {
    exit(json_encode(['ok' => true]));
}

// Turnstile
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!verify_captcha($data['h-captcha-response'] ?? '', $ip)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Security check failed. Please try again.']));
}

// Rate limit
$window = date('Y-m-d H:i:s', time() - 600);
$count  = db_query(
    "SELECT COUNT(*) AS cnt FROM submissions WHERE ip_address = :ip AND created_at > :window",
    [':ip' => $ip, ':window' => $window]
)->fetch()['cnt'];

if ((int)$count >= 5) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']));
}

// Validate
$errors  = [];
$name    = trim($data['name']    ?? '');
$email   = trim($data['email']   ?? '');
$agency  = trim($data['agency']  ?? '');
$message = trim($data['message'] ?? '');

if (!$name)                          $errors['name']   = 'Your name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'A valid email is required.';
if (!$agency)                        $errors['agency'] = 'Agency name is required.';

if ($errors) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => $errors]));
}

// Tracking
if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

$payload = [
    'agency_name'    => $agency,
    'iata'           => trim($data['iata']    ?? ''),
    'country'        => trim($data['country'] ?? ''),
    'submitted_from' => $_SERVER['HTTP_REFERER'] ?? '',
];

// Insert
db_query(
    "INSERT INTO submissions
        (type, guest_name, guest_email, guest_phone, message, payload_json,
         source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
         user_agent, ip_address)
     VALUES
        ('agency', :name, :email, :phone, :message, :payload,
         :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
         :user_agent, :ip)",
    [
        ':name'        => $name,
        ':email'       => $email,
        ':phone'       => trim($data['phone'] ?? ''),
        ':message'     => $message,
        ':payload'     => json_encode($payload),
        ':source_page' => $tracking['source_page'] ?? '',
        ':referrer'    => $tracking['referrer']    ?? '',
        ':utm_source'  => $tracking['utm_source']  ?? '',
        ':utm_medium'  => $tracking['utm_medium']  ?? '',
        ':utm_campaign'=> $tracking['utm_campaign']?? '',
        ':utm_term'    => $tracking['utm_term']    ?? '',
        ':utm_content' => $tracking['utm_content'] ?? '',
        ':user_agent'  => $tracking['user_agent']  ?? '',
        ':ip'          => $ip,
    ]
);

$id = (int)db()->lastInsertId();

send_notification([
    'id'          => $id,
    'type'        => 'agency',
    'guest_name'  => $name,
    'guest_email' => $email,
    'guest_phone' => $data['phone'] ?? '',
    'message'     => $message . "\n\nAgency: {$agency}\nIATA: {$payload['iata']}\nCountry: {$payload['country']}",
    'created_at'  => date('Y-m-d H:i:s'),
] + $tracking);

echo json_encode(['ok' => true, 'id' => $id]);
