<?php
/**
 * Tribal Sand · GHL Submit Handler
 * ─────────────────────────────────
 * 1. Creates / upserts a contact in GHL
 * 2. Creates an opportunity in "New Enquiry"
 * 3. Posts a conversation note so the enquiry appears in GHL Conversations
 * 4. Adds a contact timeline note
 * 5. Sends internal notification email to reservations@tribalsand.com
 *
 * NOTE: lives at root (not includes/) — the includes/ directory is
 * blocked from web access via .htaccess.
 */

/* GHL_BASE and GHL_VERSION are provided as constants by includes/ghl.php (required below);
   they are intentionally NOT re-defined here to avoid a duplicate-constant fatal. */
/* ── Config (from env; logic ports to includes/ghl.php) ── */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ghl.php';
$__env = parse_env();
define('GHL_API_KEY',     $__env['GHL_API_KEY']     ?? '');
define('GHL_LOCATION_ID', $__env['GHL_LOCATION_ID'] ?? '');
define('GHL_PIPELINE_ID', $__env['GHL_PIPELINE_ID'] ?? '');
define('GHL_STAGE_ID',    $__env['GHL_STAGE_ID']    ?? '');
define('NOTIFY_EMAIL',    setting('notify_email', 'reservations@tribalsand.com'));

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

/* ────────────────────────────────────────────────
   Helper: make a GHL API request via cURL
   ──────────────────────────────────────────────── */
function ghl(string $method, string $path, array $body = []): array {
    // Intentionally returns ok=true on skip so the existing contactId fatal-exit
    // checks (gated by $ghlSkipped) are bypassed and the request still reaches the
    // Postgres insert. A real failure with a key present still returns ok=false.
    if (!GHL_API_KEY) return ['ok' => true, 'status' => 0, 'data' => [], 'skipped' => true];
    $ch = curl_init(GHL_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . GHL_API_KEY,
            'Content-Type: application/json',
            'Version: '             . GHL_VERSION,
        ],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS,    json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch); // suppress deprecation in PHP 8.5

    if ($err) return ['ok' => false, 'error' => $err, 'status' => 0,    'data' => []];
    $decoded = json_decode($resp, true) ?? [];
    return          ['ok' => $code < 300, 'status' => $code, 'data' => $decoded];
}

/* ────────────────────────────────────────────────
   STEP 1 · Create / upsert contact
   ──────────────────────────────────────────────── */
$guest = $data['guest'] ?? [];
$trip  = $data['trip']  ?? [];

// Build GHL custom fields — populates {{contact.xxx}} merge tags in GHL email templates
// Field types: property=Text, arrival_date=Date, departure_date=Date,
//              adults=Number, children=Number, enquiry_message=TextArea,
//              enquiry_source=Text, nights=Number

$customFields = [];

// Helper: normalise date to YYYY-MM-DD (GHL Date fields accept ISO strings)
$toDate = function(string $d): string {
    if (!$d) return '';
    // Already ISO format
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : '';
};

// Helper: extract leading integer from strings like "2 Adults", "10+", "0"
$toNum = function(string $v): string {
    preg_match('/\d+/', $v, $m);
    return isset($m[0]) ? $m[0] : '';
};

$arrivalRaw   = $trip['arrival']   ?? ($data['customData']['arrival_date']   ?? '');
$departureRaw = $trip['departure'] ?? ($data['customData']['departure_date'] ?? '');
$adultsRaw    = $trip['adults']    ?? ($data['customData']['adults']         ?? '');
$childrenRaw  = $trip['children']  ?? ($data['customData']['children']       ?? '');

// Calculate nights if both dates present
$nights = '';
if ($arrivalRaw && $departureRaw) {
    $diff = strtotime($departureRaw) - strtotime($arrivalRaw);
    if ($diff > 0) $nights = (string) round($diff / 86400);
}

// Exact GHL field keys confirmed in GHL Settings → Custom Fields → Contacts
$cfMap = [
    'property'        => $trip['prop']    ?? ($data['customData']['property']        ?? ''),
    'arrivaldate'     => $toDate($arrivalRaw),    // GHL key: contact.arrivaldate (no underscore)
    'departuredate'   => $toDate($departureRaw),  // GHL key: contact.departuredate (no underscore)
    'adults'          => $toNum($adultsRaw),
    'children'        => $toNum($childrenRaw),
    'nights'          => $nights,
    'enquiry_message' => $data['message'] ?? ($data['customData']['enquiry_message'] ?? ''),
    'enquiry_souce'   => $data['opportunity']['source'] ?? ($data['customData']['enquiry_source'] ?? ''), // typo in GHL — use as-is
];

foreach ($cfMap as $key => $val) {
    if ($val === '' || $val === null) continue;
    $customFields[] = ['key' => $key, 'field_value' => (string) $val];
}

// Merge any extra customData keys not already covered
foreach (($data['customData'] ?? []) as $k => $v) {
    if (!array_key_exists($k, $cfMap) && $v !== '') {
        $customFields[] = ['key' => $k, 'field_value' => (string) $v];
    }
}

$contactBody = [
    'locationId' => GHL_LOCATION_ID,
    'firstName'  => $guest['firstName'] ?? '',
    'lastName'   => $guest['lastName']  ?? '',
    'email'      => $guest['email']     ?? '',
    'phone'      => $guest['phone']     ?? '',
    'source'     => 'tribalsand.com',
    'tags'       => $data['tags']       ?? ['website-enquiry'],
];

if (!empty($guest['country'])) {
    $contactBody['country'] = $guest['country'];
}

if ($customFields) {
    $contactBody['customFields'] = $customFields;
}

$contactRes  = ghl('POST', '/contacts/', $contactBody);
$ghlSkipped  = !empty($contactRes['skipped']);
$contactId   = $contactRes['data']['contact']['id']
            ?? $contactRes['data']['meta']['contactId'] // GHL "duplicate" 400 returns existing ID here
            ?? null;
$isDuplicate = !$contactRes['ok'] && $contactId;

if (!$ghlSkipped) {
    if (!$contactRes['ok'] && !$contactId) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Failed to create contact', 'detail' => $contactRes]);
        exit;
    }
    if (!$contactId) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Contact created but ID missing', 'detail' => $contactRes]);
        exit;
    }
}

// Existing contact — push tags + custom fields via PUT so they're always recorded
if ($isDuplicate) {
    $putBody = [
        'tags'   => $data['tags'] ?? ['website-enquiry'],
        'source' => 'tribalsand.com',
    ];
    if ($customFields) $putBody['customFields'] = $customFields;
    ghl('PUT', '/contacts/' . $contactId, $putBody);
}

/* ────────────────────────────────────────────────
   STEP 2 · Create opportunity in "New Enquiry"
   ──────────────────────────────────────────────── */
$opp = $data['opportunity'] ?? [];
// $trip already defined in Step 1

$baseName = $opp['name']
    ?? trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? ''))
       . ' · ' . ($trip['prop'] ?? '');
$oppName  = $baseName . ' · ' . date('d M Y');

$oppRes = ghl('POST', '/opportunities/', [
    'locationId'      => GHL_LOCATION_ID,
    'pipelineId'      => GHL_PIPELINE_ID,
    'pipelineStageId' => GHL_STAGE_ID,
    'contactId'       => $contactId,
    'name'            => $oppName,
    'status'          => 'open',
    'monetaryValue'   => $opp['monetaryValue'] ?? 0,
    'source'          => $opp['source']        ?? 'Website Enquiry',
]);

$oppId = $oppRes['data']['opportunity']['id'] ?? null;

/* ────────────────────────────────────────────────
   STEP 3 · Post conversation note (appears in GHL Conversations)
   ──────────────────────────────────────────────── */
$cd       = $data['customData'] ?? [];
$convNote = "New enquiry received via tribalsand.com\n\n"
    . "Property:   " . ($trip['prop']          ?? $cd['property']  ?? '—') . "\n"
    . "Arrival:    " . ($trip['arrival']        ?? $cd['arrival']   ?? '—') . "\n"
    . "Departure:  " . ($trip['departure']      ?? $cd['departure'] ?? '—') . "\n"
    . "Adults:     " . ($trip['adults']         ?? $cd['adults']    ?? '—') . "\n"
    . "Children:   " . ($trip['children']       ?? $cd['children']  ?? '—') . "\n"
    . "Source:     " . ($opp['source']          ?? 'Website')              . "\n"
    . "\nMessage:\n" . ($data['note']           ?? '—');

ghl('POST', '/conversations/messages', [
    'locationId' => GHL_LOCATION_ID,
    'contactId'  => $contactId,
    'type'       => 'Note',
    'message'    => $convNote,
]);

/* ────────────────────────────────────────────────
   STEP 4 · Add timeline note to contact
   ──────────────────────────────────────────────── */
if (!empty($data['note'])) {
    ghl('POST', '/contacts/' . $contactId . '/notes/', [
        'body' => $data['note'],
    ]);
}

/* ────────────────────────────────────────────────
   STEP 5 · Internal notification email
   ──────────────────────────────────────────────── */
$guestName  = trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? ''));
$guestEmail = $guest['email']     ?? '';
$guestPhone = $guest['phone']     ?? '—';
$property   = $trip['prop']       ?? $cd['property']        ?? '—';
$arrival    = $trip['arrival']    ?? $cd['arrival_date']    ?? $cd['arrival']   ?? '—';
$departure  = $trip['departure']  ?? $cd['departure_date']  ?? $cd['departure'] ?? '—';
$adults     = $trip['adults']     ?? $cd['adults']          ?? '—';
$children   = $trip['children']   ?? $cd['children']        ?? '—';
$rooms      = $trip['rooms']      ?? $cd['rooms']           ?? '—';
$userMsg    = $data['message']    ?? $cd['enquiry_message'] ?? '';   // dedicated message field
$source     = $opp['source']      ?? 'Website Enquiry';
$ref        = $data['ref']        ?? '';

$emailSubject = "New Enquiry — {$guestName} · {$property}";

$sep = str_repeat('-', 48);

$emailBody  = "NEW WEBSITE ENQUIRY\n{$sep}\n\n";
$emailBody .= "Name:        {$guestName}\n";
$emailBody .= "Email:       {$guestEmail}\n";
$emailBody .= "Phone:       {$guestPhone}\n\n";
$emailBody .= "Property:    {$property}\n";
$emailBody .= "Arrival:     {$arrival}\n";
$emailBody .= "Departure:   {$departure}\n";
$emailBody .= "Adults:      {$adults}\n";
$emailBody .= "Children:    {$children}\n";
if ($rooms !== '—') $emailBody .= "Rooms:       {$rooms}\n";
$emailBody .= "Source:      {$source}\n\n";
if ($userMsg) {
    $emailBody .= "{$sep}\n";
    $emailBody .= "Message:\n{$userMsg}\n\n";
}
$emailBody .= "{$sep}\n";
$emailBody .= "GHL contact: https://app.gohighlevel.com/ (ID: {$contactId})\n";
$emailBody .= "Ref:         {$ref}\n";

$emailHeaders  = "From: noreply@tribalsand.com\r\n";
$emailHeaders .= "Reply-To: {$guestEmail}\r\n";
$emailHeaders .= "X-Mailer: TribalSand/PHP\r\n";
$emailHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";

mail(NOTIFY_EMAIL, $emailSubject, $emailBody, $emailHeaders);

/* ── Persist to Postgres (admin inbox) ── */
try {
    db_query(
        "INSERT INTO submissions
            (type, guest_name, guest_email, guest_phone, message,
             check_in, check_out, guests_adults, guests_children, payload_json,
             source_page, referrer, ip_address, user_agent)
         VALUES
            ('enquiry', :name, :email, :phone, :message,
             :ci, :co, :adults, :children, :payload,
             :src, :ref, :ip, :ua)",
        [
            ':name'     => trim(($guest['firstName'] ?? '') . ' ' . ($guest['lastName'] ?? '')),
            ':email'    => $guest['email'] ?? '',
            ':phone'    => $guest['phone'] ?? '',
            ':message'  => $userMsg ?: ($data['note'] ?? ''),
            ':ci'       => $toDate($arrivalRaw) ?: null,
            ':co'       => $toDate($departureRaw) ?: null,
            ':adults'   => (int)($toNum((string)$adultsRaw) ?: 1),
            ':children' => (int)($toNum((string)$childrenRaw) ?: 0),
            ':payload'  => json_encode(['property' => $property, 'rooms' => $rooms, 'ghl_contact' => $contactId, 'ref' => $ref, 'source' => $source]),
            ':src'      => $_SERVER['HTTP_REFERER'] ?? '',
            ':ref'      => '', // external referrer not available server-side at this endpoint
            ':ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]
    );
} catch (Throwable $e) {
    error_log('[ghl-submit] submissions insert failed: ' . $e->getMessage());
}

/* ── Respond ── */
echo json_encode([
    'ok'        => true,
    'contactId' => $contactId,
    'oppId'     => $oppId,
    'oppOk'     => $oppRes['ok'],
    'ref'       => $ref,
]);
