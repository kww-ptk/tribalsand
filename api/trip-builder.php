<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (!empty($data['website'])) { exit(json_encode(['ok' => true])); } // honeypot

require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';
if (!verify_captcha($data['cf-turnstile-response'] ?? '', client_ip())) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Security check failed. Please try again.']));
}

$guest = $data['guest'] ?? [];
$trip  = $data['trip']  ?? [];
$name  = trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? ''));
$email = trim($guest['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'valid email required'])); }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$window = date('Y-m-d H:i:s', time() - 600);
$count = (int)db_query("SELECT COUNT(*) AS cnt FROM submissions WHERE ip_address=:ip AND created_at>:w", [':ip'=>$ip, ':w'=>$window])->fetch()['cnt'];
if ($count >= 5) { http_response_code(429); exit(json_encode(['ok' => false, 'error' => 'rate limited'])); }

try {
    db_query(
        "INSERT INTO submissions
            (type, guest_name, guest_email, guest_phone, message,
             check_in, check_out, guests_adults, guests_children, payload_json,
             source_page, referrer, ip_address, user_agent)
         VALUES
            ('enquiry', :name, :email, :phone, :msg,
             :ci, :co, :adults, :children, :payload,
             :src, :ref, :ip, :ua)",
        [
            ':name'     => $name,
            ':email'    => $email,
            ':phone'    => trim($guest['phone'] ?? ''),
            ':msg'      => 'Trip Builder request — see payload for full itinerary.',
            ':ci'       => ($trip['arrDate']   ?? '') ?: null,
            ':co'       => ($trip['depDate']   ?? '') ?: null,
            ':adults'   => (int)($trip['adults']   ?? 1),
            ':children' => (int)($trip['children'] ?? 0),
            ':payload'  => json_encode($data, JSON_UNESCAPED_SLASHES),
            // No UTM/session tracking on this endpoint: trip-builder posts cross-origin-style
            // from the page's own JS, so source_page = the form page (referer); external
            // referrer is not knowable here.
            ':src'      => $_SERVER['HTTP_REFERER'] ?? '',
            ':ref'      => '',
            ':ip'       => $ip,
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]
    );
} catch (Throwable $e) {
    error_log('[trip-builder] insert failed: ' . $e->getMessage());
    http_response_code(500); exit(json_encode(['ok' => false]));
}
$new_id = (int)db()->lastInsertId();

// Dedicated Trip Builder itinerary emails — rich guest acknowledgement + staff
// notification laid out from the full payload (best-effort; never blocks the response).
try {
    send_trip_builder_emails($data, $new_id);
} catch (Throwable $e) {
    error_log('[trip-builder] mail failed: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'id' => $new_id]);
