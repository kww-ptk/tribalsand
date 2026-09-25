<?php
/**
 * "Join the waitlist" sign-ups for the coming-soon pages (off-duty.php,
 * somewhere-cafe.php, tribal-gym.php). These used to POST straight from the
 * browser to a GHL webhook — no bot check, no rate limit, invisible in our
 * admin. Now they come here first like every other public form:
 *
 *   honeypot → Turnstile (fail-closed) → IP rate limit → email check →
 *   save a `contact` submission (admin inbox) → answer → forward to the SAME GHL
 *   webhook trigger server-side (ghl_forward_webhook), so the existing GHL
 *   workflow keeps working unchanged.
 *
 * A repeat sign-up of the same email to the same list is accepted but not stored
 * or forwarded twice.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/ghl.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

/** The waitlists we accept: list key => [label, page]. */
function waitlist_lists(): array {
    return [
        'off-duty'       => ['Off Duty', 'off-duty.php'],
        'somewhere-cafe' => ['Somewhere Café', 'somewhere-cafe.php'],
        'tribal-gym'     => ['Tribal Gym', 'tribal-gym.php'],
    ];
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Honeypot — pretend success, store nothing.
if (!empty($data['website'])) exit(json_encode(['ok' => true]));

$ip = client_ip();
if (!verify_captcha((string)($data['cf-turnstile-response'] ?? ''), $ip)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Security check failed. Please try again.']));
}

$window = date('Y-m-d H:i:s', time() - 600);
$count  = (int) db_query(
    "SELECT COUNT(*) FROM submissions WHERE ip_address = :ip AND created_at > :w",
    [':ip' => $ip, ':w' => $window]
)->fetchColumn();
if ($count >= 5) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']));
}

$list  = (string)($data['list'] ?? '');
$lists = waitlist_lists();
$email = strtolower(trim((string)($data['email'] ?? '')));
if (!isset($lists[$list])) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'Unknown waitlist.']));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => ['email' => 'Please enter a valid email address.']]));
}
[$label, $page] = $lists[$list];
$source = $list . '-waitlist';

// Already on this list? Say yes, but don't store or forward it again.
$dup = db_query(
    "SELECT id FROM submissions WHERE type = 'contact' AND lower(guest_email) = :e AND payload_json->>'source' = :s LIMIT 1",
    [':e' => $email, ':s' => $source]
)->fetchColumn();
if ($dup) exit(json_encode(['ok' => true, 'already' => true]));

if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

db_query(
    "INSERT INTO submissions
        (type, guest_name, guest_email, message, payload_json,
         source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
         user_agent, ip_address)
     VALUES
        ('contact', :name, :email, :msg, :payload,
         :sp, :ref, :us, :um, :uc, :ut, :uco, :ua, :ip)",
    [
        ':name'    => $label . ' waitlist',
        ':email'   => $email,
        ':msg'     => "Waitlist sign-up for {$label} — let them know when it opens.",
        ':payload' => json_encode([
            'subject'        => $label . ' waitlist',
            'source'         => $source,
            'waitlist'       => $list,
            'submitted_from' => $_SERVER['HTTP_REFERER'] ?? '',
        ], JSON_UNESCAPED_UNICODE),
        ':sp'  => $tracking['source_page']  ?? ($_SERVER['HTTP_REFERER'] ?? ''),
        ':ref' => $tracking['referrer']     ?? '',
        ':us'  => $tracking['utm_source']   ?? '',
        ':um'  => $tracking['utm_medium']   ?? '',
        ':uc'  => $tracking['utm_campaign'] ?? '',
        ':ut'  => $tracking['utm_term']     ?? '',
        ':uco' => $tracking['utm_content']  ?? '',
        ':ua'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ':ip'  => $ip,
    ]
);
$id = (int) db()->lastInsertId();

// Answer first, then hand it to the GHL workflow with the exact body it used to
// receive from the browser (so nothing on the GHL side has to change).
ghl_respond_json(['ok' => true, 'id' => $id]);
ghl_forward_webhook([
    'email'  => $email,
    'source' => $source,
    'tags'   => [$source, 'coming-soon'],
    'note'   => "{$label} waitlist signup from tribalsand.com/{$page}",
    'admin_submission_id' => $id,
]);
