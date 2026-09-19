<?php
declare(strict_types=1);
/**
 * "Become Our Partner" — a travel agency registers itself from /for-agents.php.
 *
 * Writes the normal `agency` submission AND, when the agency supplied a logo or
 * website, an UNPUBLISHED agency_partners row so the owner can approve it into
 * the partner ticker (see includes/partners.php). Approval is the gate: nothing
 * a visitor uploads reaches the public page — or earns them a backlink — until
 * the owner publishes it in Admin → Partners.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/partners.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

// The form posts multipart/form-data when the agency attaches a logo (a file
// can't ride in a JSON body). Accept either shape so the original JSON contract
// still works for any other caller.
$data = !empty($_POST) ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?? []);
if (!is_array($data)) $data = [];

// Honeypot
if (!empty($data['website'])) {
    exit(json_encode(['ok' => true]));
}

// Turnstile
$ip = client_ip();
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
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

// The partner's own site, normalised to an absolute https href (NOT the
// honeypot field, which is called 'website').
$agencyWebsite = partner_website_href($data['agency_website'] ?? '');

// Logo (optional). Rejecting it must not reject the registration — record the
// reason in the payload and let the enquiry through; the owner can chase the
// logo by email. GD re-encodes the image, so nothing executable survives.
$logoKey = ''; $logoError = '';
if (!empty($_FILES['agency_logo']['name'] ?? '')) {
    $stored = partner_store_logo($_FILES['agency_logo'], $err);
    if ($stored === false) $logoError = (string)($err ?? 'Could not read that logo.');
    else                   $logoKey   = $stored;
}

$payload = [
    'agency_name'    => $agency,
    'agency_website' => $agencyWebsite,
    'agency_logo'    => $logoKey,
    'logo_error'     => $logoError,
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

// Queue the agency for the partner ticker — hidden until the owner approves it.
// Best-effort: the enquiry is already saved, so a partners failure changes
// nothing the agency sees.
if ($logoKey !== '' || $agencyWebsite !== '') {
    partner_register_pending($agency, $agencyWebsite, $logoKey, $email, $id);
}

send_notification([
    'id'          => $id,
    'type'        => 'agency',
    'guest_name'  => $name,
    'guest_email' => $email,
    'guest_phone' => $data['phone'] ?? '',
    'message'     => $message . "\n\nAgency: {$agency}\nWebsite: {$payload['agency_website']}\nIATA: {$payload['iata']}\nCountry: {$payload['country']}"
                     . ($logoKey !== ''   ? "\nLogo: uploaded — approve it in Admin → Partners" : '')
                     . ($logoError !== '' ? "\nLogo: rejected ({$logoError}) — ask the agency to resend" : ''),
    'created_at'  => date('Y-m-d H:i:s'),
] + $tracking);

send_guest_acknowledgement([
    'kind'        => 'agency',
    'guest_name'  => $name,
    'guest_email' => $email,
    'agency_name' => $agency,
    'message'     => $message,
]);

echo json_encode(['ok' => true, 'id' => $id]);
