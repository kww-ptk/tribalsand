<?php
declare(strict_types=1);
/**
 * Wedding / event enquiry — the lead form on events.php.
 *
 * Mirrors api/submit-contact.php's protections (honeypot, Turnstile fail-closed,
 * IP rate limit) and stores the event-specific answers in payload_json, which
 * the admin submission view renders as labelled rows.
 *
 * UTM values come from the session tracking captured on first touch, so paid
 * traffic is attributable back to the campaign that produced the lead.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (!empty($data['website'])) { exit(json_encode(['ok' => true])); }   // honeypot

$ip = client_ip();
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403);
    exit(json_encode(['ok'=>false, 'error'=>'Security check failed. Please try again.']));
}

// Rate limit: 5 submissions per IP per 10 minutes, same as the other forms.
$window = date('Y-m-d H:i:s', time() - 600);
$count  = (int) db_query(
    'SELECT COUNT(*) AS cnt FROM submissions WHERE ip_address = :ip AND created_at > :w',
    [':ip' => $ip, ':w' => $window]
)->fetch()['cnt'];
if ($count >= 5) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many enquiries. Please try again shortly.'])); }

$str = fn($k) => is_scalar($data[$k] ?? null) ? trim((string)$data[$k]) : '';

$errors = [];
$name  = $str('name');
$email = $str('email');
if ($name === '')                                 $errors['name']  = 'Your name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors['email'] = 'A valid email is required.';
if ($errors) { http_response_code(422); exit(json_encode(['ok'=>false,'errors'=>$errors])); }

/**
 * 'event' is only a legal submissions.type once add_event_submission_type.sql
 * has run. Probe the CHECK constraint rather than assume — an unapplied
 * migration must never cost a lead, so we fall back to 'contact'.
 */
function submission_event_type_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $def = (string) db_query(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint
             WHERE conrelid = 'submissions'::regclass AND conname = 'submissions_type_check'"
        )->fetchColumn();
        $ok = $def === '' || str_contains($def, "'event'");   // no constraint at all = permissive
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}
$subType = submission_event_type_supported() ? 'event' : 'contact';

if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

// Event answers live in the payload; the admin view renders them as labelled rows.
$ctx = $str('context') !== '' ? $str('context') : 'Wedding / event';
$payload = [
    'enquiry_for'  => $ctx,          // 'Wedding / event' | 'Retreat'
    'event_type'   => $str('event_type'),
    'event_date'   => $str('event_date'),
    'guest_count'  => $str('guest_count'),
    'venue'        => $str('venue'),
    'budget'       => $str('budget'),
    'submitted_from' => $_SERVER['HTTP_REFERER'] ?? '',
];
$summary = $ctx . ' enquiry'
    . ($payload['event_type']  !== '' ? ' — ' . $payload['event_type'] : '')
    . ($payload['event_date']  !== '' ? ' — ' . $payload['event_date'] : '')
    . ($payload['guest_count'] !== '' ? ' — ' . $payload['guest_count'] . ' guests' : '');
$message = $str('message') !== '' ? $str('message') : $summary;

try {
    db_query(
        "INSERT INTO submissions
            (type, guest_name, guest_email, guest_phone, message, payload_json,
             source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
             user_agent, ip_address)
         VALUES
            (:stype, :name, :email, :phone, :message, :payload,
             :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
             :user_agent, :ip)",
        [
            ':stype'=>$subType,
            ':name'=>$name, ':email'=>$email, ':phone'=>$str('phone'),
            ':message'=>$message, ':payload'=>json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':source_page' => $tracking['source_page'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
            ':referrer'    => $tracking['referrer']    ?? '',
            ':utm_source'  => $tracking['utm_source']  ?? '',
            ':utm_medium'  => $tracking['utm_medium']  ?? '',
            ':utm_campaign'=> $tracking['utm_campaign']?? '',
            ':utm_term'    => $tracking['utm_term']    ?? '',
            ':utm_content' => $tracking['utm_content'] ?? '',
            ':user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':ip'          => $ip,
        ]
    );
} catch (Throwable $e) {
    error_log('[submit-event] insert failed: ' . $e->getMessage());
    http_response_code(500); exit(json_encode(['ok'=>false,'error'=>'Something went wrong. Please try again.']));
}

// Staff notification is best-effort — a mail failure must not lose the lead.
try {
    // send_notification() reads guest_email unguarded for the Reply-To header,
    // and id when logging a failure — pass the full shape it expects.
    send_notification([
        'id'          => (int) db()->lastInsertId(),
        'type'        => $subType,
        'guest_name'  => $name,
        'guest_email' => $email,
        'guest_phone' => $str('phone'),
        'message'     => $message,
        'room_name'   => $payload['venue'] ?: ($ctx . ' enquiry'),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    error_log('[submit-event] mail failed: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
