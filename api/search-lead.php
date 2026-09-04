<?php
/**
 * Availability search — lead capture (Step 2 of the search bar).
 *
 * Records the visitor's contact details + search criteria as a lead
 * (submissions.type = 'availability') BEFORE the available-rooms page is shown,
 * so an abandoned search is still a captured lead. Returns the /search redirect
 * URL for the client to follow.
 *
 * Low-friction by design (this is the primary conversion path): honeypot + a
 * per-IP rate limit, no captcha. See db/migrations/add_submission_availability_type.sql.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Honeypot — silently accept so bots think they succeeded.
if (!empty($data['website'])) {
    exit(json_encode(['ok' => true, 'redirect' => '/search']));
}

$ip = client_ip();

// Rate limit: max 5 submissions per 10 min per IP (shared with the other forms).
$window = date('Y-m-d H:i:s', time() - 600);
$count  = (int) db_query(
    "SELECT COUNT(*) AS cnt FROM submissions WHERE ip_address = :ip AND created_at > :window",
    [':ip' => $ip, ':window' => $window]
)->fetch()['cnt'];
if ($count >= 5) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']));
}

// ── Validate contact (name + email required; phone optional) ──
$errors = [];
$name  = trim($data['name']  ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
if ($name === '')                                $errors['name']  = 'Your name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors['email'] = 'A valid email is required.';

// ── Validate search criteria ──
$checkin  = trim($data['checkin']  ?? '');
$checkout = trim($data['checkout'] ?? '');
$adults   = max(1, (int)($data['adults']   ?? 2));
$children = max(0, (int)($data['children'] ?? 0));
$today    = date('Y-m-d');
$datesOk  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin)
         && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)
         && $checkin >= $today && $checkout > $checkin;
if (!$datesOk) $errors['dates'] = 'Please choose a check-in date and a later check-out date.';

if ($errors) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => $errors]));
}

$nights = (int) ((strtotime($checkout) - strtotime($checkin)) / 86400);
$guestLabel = $adults . ' adult' . ($adults !== 1 ? 's' : '')
            . ($children ? ', ' . $children . ' child' . ($children !== 1 ? 'ren' : '') : '');
$message = "Availability search: {$checkin} → {$checkout} ({$nights} night" . ($nights !== 1 ? 's' : '') . "), {$guestLabel}.";

// Tracking (first-touch UTM, captured in session by includes/tracking.php).
if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

// The page the client should now open — the live available-rooms results.
$redirect = '/search?checkin=' . urlencode($checkin)
          . '&checkout=' . urlencode($checkout)
          . '&adults=' . urlencode((string)$adults)
          . '&children=' . urlencode((string)$children);

// Idempotency guard — a double-tap / retry within 30s reuses the existing lead
// instead of inserting a duplicate. Legitimate repeat searches (a minute later)
// are unaffected.
$dupId = find_recent_duplicate_submission('availability', $email, $checkin, $checkout);
if ($dupId) {
    echo json_encode(['ok' => true, 'id' => $dupId, 'redirect' => $redirect, 'dedupe' => true]);
    exit;
}

// ── Insert the lead ──
try {
db_query(
    "INSERT INTO submissions
        (type, guest_name, guest_email, guest_phone, message,
         check_in, check_out, guests_adults, guests_children, payload_json,
         source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
         user_agent, ip_address)
     VALUES
        ('availability', :name, :email, :phone, :message,
         :check_in, :check_out, :adults, :children, :payload,
         :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
         :user_agent, :ip)",
    [
        ':name'        => $name,
        ':email'       => $email,
        ':phone'       => $phone,
        ':message'     => $message,
        ':check_in'    => $checkin,
        ':check_out'   => $checkout,
        ':adults'      => $adults,
        ':children'    => $children,
        ':payload'     => json_encode([
            'nights'         => $nights,
            'submitted_from' => $_SERVER['HTTP_REFERER'] ?? '',
        ]),
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
$id = (int) db()->lastInsertId();
} catch (Throwable $e) {
    error_log('[search-lead] insert failed: ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Something went wrong saving your search. Please try again or contact us directly.']));
}

// Notify staff of the new lead (best-effort — never block the redirect).
try {
    send_notification([
        'id'          => $id,
        'type'        => 'availability',
        'guest_name'  => $name,
        'guest_email' => $email,
        'guest_phone' => $phone,
        'message'     => $message,
        'created_at'  => date('Y-m-d H:i:s'),
    ] + $tracking);
} catch (Throwable $e) { error_log('[search-lead] notify: ' . $e->getMessage()); }

echo json_encode(['ok' => true, 'id' => $id, 'redirect' => $redirect]);
