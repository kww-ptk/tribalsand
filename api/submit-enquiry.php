<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/ghl.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

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
$ip = client_ip();
if (!verify_captcha($data['h-captcha-response'] ?? '', $ip)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Security check failed. Please try again.']));
}

// Rate limit — max 5 submissions per IP or email in 10 minutes
$window = date('Y-m-d H:i:s', time() - 600);
$email_raw = trim($data['email'] ?? '');
$ip_count = db_query(
    "SELECT COUNT(*) AS cnt FROM submissions WHERE ip_address = :ip AND created_at > :window",
    [':ip' => $ip, ':window' => $window]
)->fetch()['cnt'];
$email_count = $email_raw ? db_query(
    "SELECT COUNT(*) AS cnt FROM submissions WHERE guest_email = :email AND created_at > :window",
    [':email' => strtolower($email_raw), ':window' => $window]
)->fetch()['cnt'] : 0;

if ((int)$ip_count >= 5 || (int)$email_count >= 5) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']));
}

// Validate
$errors = [];
$name    = trim($data['name']    ?? '');
$email   = trim($data['email']   ?? '');
$checkin = trim($data['checkin'] ?? '');
$checkout= trim($data['checkout']?? '');

if (!$name)                                          $errors['name']  = 'Your name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))      $errors['email'] = 'A valid email is required.';

if ($errors) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => $errors]));
}

// Look up room or tour
$slug      = trim($data['room_slug'] ?? '');
$room      = $slug ? fetch_room_by_slug($slug) : false;
$tourSlug  = trim($data['tour_slug'] ?? '');
$tour      = $tourSlug ? fetch_tour_by_slug($tourSlug) : false;

// Check form mode — availability mode creates a 24h hold
// Per-room override takes precedence over the global setting
$form_mode = ($room && !empty($room['form_mode']))
    ? $room['form_mode']
    : setting('form_mode', 'enquiry');
$unit      = false;

if ($form_mode === 'availability' && $room) {
    if (!$checkin || !$checkout) {
        http_response_code(422);
        exit(json_encode(['ok' => false, 'errors' => [
            'checkin'  => $checkin  ? null : 'Check-in date is required.',
            'checkout' => $checkout ? null : 'Check-out date is required.',
        ]]));
    }
    $unit = find_available_unit($room['id'], $checkin, $checkout);
    if (!$unit) {
        http_response_code(409);
        exit(json_encode(['ok' => false, 'error' => 'No availability for those dates. Please try different dates or contact us directly.']));
    }
}

// Tracking from session
if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

// Insert + GHL push + hold/enquiry response — wrapped so any DB/GHL failure returns clean JSON
try {
    db_query(
        "INSERT INTO submissions
            (type, room_id, tour_id, guest_name, guest_email, guest_phone, message,
             check_in, check_out, guests_adults, guests_children, payload_json,
             source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
             user_agent, ip_address)
         VALUES
            ('enquiry', :room_id, :tour_id, :name, :email, :phone, :message,
             :checkin, :checkout, :adults, :children, :payload,
             :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
             :user_agent, :ip)",
        [
            ':room_id'     => $room ? $room['id'] : null,
            ':tour_id'     => $tour ? $tour['id'] : null,
            ':name'        => $name,
            ':email'       => $email,
            ':phone'       => trim($data['phone'] ?? ''),
            ':message'     => trim($data['message'] ?? ''),
            ':checkin'     => $checkin ?: null,
            ':checkout'    => $checkout ?: null,
            ':adults'      => max(1, (int)($data['adults'] ?? 1)),
            ':children'    => max(0, (int)($data['children'] ?? 0)),
            ':payload'     => json_encode(['submitted_from' => $_SERVER['HTTP_REFERER'] ?? '']),
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

    // Mirror to GHL (skips automatically if GHL_API_KEY is unset)
    $nameParts = explode(' ', $name, 2);
    ghl_push([
        'firstName' => $nameParts[0] ?? $name,
        'lastName'  => $nameParts[1] ?? '',
        'email'     => $email,
        'phone'     => trim($data['phone'] ?? ''),
        'property'  => $room ? $room['name'] : '',
        'arrival'   => $checkin,
        'departure' => $checkout,
        'adults'    => (int)($data['adults'] ?? 1),
        'children'  => (int)($data['children'] ?? 0),
        'message'   => trim($data['message'] ?? ''),
        'source'    => 'Website Booking Request',
        'tags'      => ['website-enquiry', 'booking-request'],
        'note'      => "Booking request via tribalsand.com\nRoom: " . ($room['name'] ?? '-') . "\nDates: {$checkin} → {$checkout}",
    ]);

    // Availability mode: create hold + block dates
    if ($form_mode === 'availability' && $unit) {
        $hold_id = create_hold_with_block($unit['id'], $id, $checkin, $checkout, $name, $email);
        $hold_row = db_query(
            "SELECT h.*, u.name AS unit_name, r.name AS room_name
             FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = u.room_id
             WHERE h.id = :id",
            [':id' => $hold_id]
        )->fetch();
        if ($hold_row) send_hold_notification($hold_row);
        echo json_encode(['ok' => true, 'id' => $id, 'mode' => 'hold']);
    } else {
        send_notification([
            'id'         => $id,
            'type'       => 'enquiry',
            'room_name'  => $room ? $room['name'] : ($tour ? 'Tour: ' . $tour['name'] : ''),
            'guest_name' => $name,
            'guest_email'=> $email,
            'guest_phone'=> $data['phone'] ?? '',
            'message'    => $data['message'] ?? '',
            'check_in'   => $checkin,
            'check_out'  => $checkout,
            'guests_adults'   => $data['adults']   ?? 1,
            'guests_children' => $data['children'] ?? 0,
            'created_at' => date('Y-m-d H:i:s'),
        ] + $tracking);
        echo json_encode(['ok' => true, 'id' => $id, 'mode' => 'enquiry']);
    }
} catch (Throwable $e) {
    error_log('[submit-enquiry] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong saving your request. Please try again or contact us directly.']);
}
