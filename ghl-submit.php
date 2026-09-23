<?php
/**
 * Tribal Sand · Contact-page enquiry handler (contact.php → /ghl-submit)
 * ─────────────────────────────────────────────────────────────────────
 * 1. Validates (Turnstile, rate limit, required fields, spam names)
 * 2. Saves the lead to the `submissions` inbox FIRST
 * 3. Sends the staff notification + guest acknowledgement
 * 4. Answers the browser, THEN syncs to GHL through the shared ghl_push()
 *    path (includes/ghl.php) — contact upsert, opportunity, conversation note.
 *    A GHL outage no longer fails the guest's request or loses the lead.
 *
 * NOTE: lives at root (not includes/) — the includes/ directory is
 * blocked from web access via .htaccess.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ghl.php';
require_once __DIR__ . '/includes/mail.php';

/* ── Suppress deprecation warnings so JSON output stays clean ── */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* ── Headers ── */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

/* ── Parse body ── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

/* ── Honeypot + Turnstile ── */
if (!empty($data['website'])) { echo json_encode(['ok' => true]); exit; }
require_once __DIR__ . '/includes/turnstile.php';
$ip = client_ip();
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security check failed. Please try again.']);
    exit;
}

/* ── Rate limit ──
   Same window and ceiling as every other public lead endpoint
   (api/submit-contact.php et al). This one was the only public form without it,
   which is why the contact page was the soft target: Turnstile gates a single
   submission but says nothing about the hundredth from one address. */
$__rlWindow = date('Y-m-d H:i:s', time() - 600);
$__rlCount  = db_query(
    "SELECT COUNT(*) AS cnt FROM submissions WHERE ip_address = :ip AND created_at > :window",
    [':ip' => $ip, ':window' => $__rlWindow]
)->fetch()['cnt'];

if ((int)$__rlCount >= 5) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']);
    exit;
}

/* ── Validate ──
   Nothing here was checked server-side before: a POST with no name, no message
   and a junk email still created a GHL contact, an opportunity, a submissions
   row and two emails. The browser form marks these required, which only binds
   a browser — this endpoint is public and takes raw JSON. Mirrors the field
   rules in api/submit-contact.php. */
$__vGuest = $data['guest'] ?? [];
$__vName  = trim(($__vGuest['firstName'] ?? '') . ' ' . ($__vGuest['lastName'] ?? ''));
$__vEmail = trim((string)($__vGuest['email'] ?? ''));
$__vMsg   = trim((string)($data['message'] ?? ($data['customData']['enquiry_message'] ?? '')));

$__vErrors = [];
if ($__vName === '')                                    $__vErrors['name']    = 'Your name is required.';
if (!filter_var($__vEmail, FILTER_VALIDATE_EMAIL))      $__vErrors['email']   = 'A valid email is required.';
if ($__vMsg === '')                                     $__vErrors['message'] = 'A message is required.';

/* Generated-name check. The spam reaching this form solves Turnstile but fills
   the name with a random token (SupWYGQjIGReLzlHVZsXTO, tFdmmfPkMIXjLhTCbFq,
   SHdXjGDufISzVYcchiZLv). Each part is tested as well as the whole, so a bot
   filling both first and last name with tokens is caught too — the combined
   value would contain a space and slip past on its own. */
if (!isset($__vErrors['name'])) {
    require_once __DIR__ . '/includes/spam-heuristics.php';
    foreach ([$__vName, (string)($__vGuest['firstName'] ?? ''), (string)($__vGuest['lastName'] ?? '')] as $__vPart) {
        if (spam_looks_like_random_token($__vPart)) {
            $__vErrors['name'] = 'Please enter your real name.';
            break;
        }
    }
}

if ($__vErrors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $__vErrors]);
    exit;
}

/* ────────────────────────────────────────────────
   Normalise the posted fields
   ──────────────────────────────────────────────── */
$guest = $data['guest'] ?? [];
$trip  = $data['trip']  ?? [];
$cd    = $data['customData'] ?? [];
$opp   = $data['opportunity'] ?? [];

// Normalise a date to YYYY-MM-DD ('' when unparseable).
$toDate = function (string $d): string {
    if (!$d) return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : '';
};
// Leading integer from strings like "2 Adults", "10+", "0".
$toNum = function (string $v): string {
    preg_match('/\d+/', $v, $m);
    return $m[0] ?? '';
};

$arrivalRaw   = (string)($trip['arrival']   ?? ($cd['arrival_date']   ?? ($cd['arrival']   ?? '')));
$departureRaw = (string)($trip['departure'] ?? ($cd['departure_date'] ?? ($cd['departure'] ?? '')));
$adultsRaw    = (string)($trip['adults']    ?? ($cd['adults']         ?? ''));
$childrenRaw  = (string)($trip['children']  ?? ($cd['children']       ?? ''));

$guestName  = trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? ''));
$guestEmail = trim((string)($guest['email'] ?? ''));
$guestPhone = trim((string)($guest['phone'] ?? ''));
$property   = trim((string)($trip['prop'] ?? ($cd['property'] ?? '')));
$rooms      = (string)($trip['rooms'] ?? ($cd['rooms'] ?? ''));
$userMsg    = (string)($data['message'] ?? ($cd['enquiry_message'] ?? ''));
$source     = (string)($opp['source'] ?? 'Website Enquiry');
$ref        = (string)($data['ref'] ?? '');
$arrival    = $toDate($arrivalRaw);
$departure  = $toDate($departureRaw);

/* ────────────────────────────────────────────────
   STEP 1 · Save to Postgres (admin inbox) — FIRST,
   so a GHL outage can never lose the lead.
   ──────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

$submissionId = 0;
try {
    db_query(
        "INSERT INTO submissions
            (type, guest_name, guest_email, guest_phone, message,
             check_in, check_out, guests_adults, guests_children, payload_json,
             source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
             ip_address, user_agent)
         VALUES
            ('enquiry', :name, :email, :phone, :message,
             :ci, :co, :adults, :children, :payload,
             :src, :ref, :us, :um, :uc, :ut, :uco,
             :ip, :ua)",
        [
            ':name'     => $guestName,
            ':email'    => $guestEmail,
            ':phone'    => $guestPhone,
            ':message'  => $userMsg ?: (string)($data['note'] ?? ''),
            ':ci'       => $arrival ?: null,
            ':co'       => $departure ?: null,
            ':adults'   => (int)($toNum($adultsRaw) ?: 1),
            ':children' => (int)($toNum($childrenRaw) ?: 0),
            ':payload'  => json_encode(array_filter([
                'property'       => $property,
                'rooms'          => $rooms,
                'ref'            => $ref,
                'source'         => $source,
                'submitted_from' => $_SERVER['HTTP_REFERER'] ?? '',
            ], fn($v) => $v !== '' && $v !== null)),
            ':src'      => $tracking['source_page'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
            ':ref'      => $tracking['referrer']     ?? '',
            ':us'       => $tracking['utm_source']   ?? '',
            ':um'       => $tracking['utm_medium']   ?? '',
            ':uc'       => $tracking['utm_campaign'] ?? '',
            ':ut'       => $tracking['utm_term']     ?? '',
            ':uco'      => $tracking['utm_content']  ?? '',
            ':ip'       => client_ip(),
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]
    );
    $submissionId = (int) db()->lastInsertId();
} catch (Throwable $e) {
    error_log('[ghl-submit] submissions insert failed: ' . $e->getMessage());
}

/* ────────────────────────────────────────────────
   STEP 2 · Emails via the site mailer (SES in prod)
   These are what actually reach the guest + the
   reservations inbox, regardless of GHL.
   ──────────────────────────────────────────────── */
send_notification([
    'id'              => $submissionId,
    'type'            => 'enquiry',
    'guest_name'      => $guestName,
    'guest_email'     => $guestEmail,
    'guest_phone'     => $guestPhone,
    'room_name'       => $property,
    'check_in'        => $arrival,
    'check_out'       => $departure,
    'guests_adults'   => $toNum($adultsRaw),
    'guests_children' => $toNum($childrenRaw),
    'message'         => $userMsg,
    'created_at'      => date('Y-m-d H:i:s'),
]);

if (filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    send_guest_acknowledgement([
        'kind'            => 'enquiry',
        'guest_name'      => $guestName ?: 'Guest',
        'guest_email'     => $guestEmail,
        'room_name'       => $property,
        'check_in'        => $arrival,
        'check_out'       => $departure,
        'guests_adults'   => $toNum($adultsRaw),
        'guests_children' => $toNum($childrenRaw),
        'message'         => $userMsg,
    ]);
}

/* ────────────────────────────────────────────────
   STEP 3 · Answer the browser, then sync to GHL
   through the shared ghl_push() path (contact upsert
   → opportunity → conversation note). Best-effort.
   ──────────────────────────────────────────────── */
ghl_respond_json(['ok' => true, 'id' => $submissionId, 'ref' => $ref]);

// Extra customData keys (anything the form sent beyond the standard fields)
// still reach GHL as contact custom fields, as before.
$extraCf = [];
foreach ($cd as $k => $v) {
    if (in_array($k, ['property', 'arrival_date', 'departure_date', 'arrival', 'departure', 'adults', 'children', 'enquiry_message', 'enquiry_source', 'rooms'], true)) continue;
    if ($v !== '' && $v !== null) $extraCf[(string)$k] = $v;
}
$tags = is_array($data['tags'] ?? null) ? array_values(array_filter(array_map('strval', $data['tags']))) : ['website-enquiry'];

$overrides = [
    'tags'            => $tags ?: ['website-enquiry'],
    'source'          => $source,
    'country'         => (string)($guest['country'] ?? ''),
    'opportunityName' => trim((string)($opp['name'] ?? '')) !== '' ? trim((string)$opp['name']) . ' · ' . date('d M Y') : '',
    'monetaryValue'   => is_numeric($opp['monetaryValue'] ?? null) ? (float)$opp['monetaryValue'] : 0,
    'customFields'    => $extraCf,
];
if (trim((string)($data['note'] ?? '')) !== '') $overrides['note'] = trim((string)$data['note']);

if ($submissionId > 0) {
    ghl_push_submission($submissionId, $overrides);
} else {
    // The DB insert failed — still get the lead into the CRM.
    [$fn, $ln] = ghl_split_name($guestName);
    ghl_push(array_merge([
        'firstName' => $fn, 'lastName' => $ln, 'email' => $guestEmail, 'phone' => $guestPhone,
        'property'  => $property, 'arrival' => $arrival, 'departure' => $departure,
        'adults'    => $toNum($adultsRaw), 'children' => $toNum($childrenRaw), 'message' => $userMsg,
        'note'      => $userMsg,
    ], $overrides));
}
