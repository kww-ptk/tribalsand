<?php
declare(strict_types=1);
/**
 * A room COMBINATION request from the property availability widget ("Hold these
 * rooms"). Saves the lead first, then — when every room takes online holds — holds
 * ALL the rooms for 24h in one transaction (or none), and sends ONE acknowledgement.
 * If any room takes enquiries only, the whole request is an enquiry (the v1 path).
 * Guards mirror api/submit-enquiry.php: honeypot, Turnstile (fail-closed), IP/email
 * rate limit. The room list is re-validated server-side (includes/hold-groups.php).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/ghl.php';
require_once __DIR__ . '/../includes/hold-groups.php';
require_once __DIR__ . '/../includes/rates.php';   // rates_window_ymd()

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (!empty($data['website'])) exit(json_encode(['ok' => true, 'mode' => 'enquiry']));   // honeypot

$ip = client_ip();
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Security check failed. Please try again.']));
}
$window = date('Y-m-d H:i:s', time() - 600);
$email  = strtolower(trim((string)($data['email'] ?? '')));
$ipN    = (int) db_query('SELECT COUNT(*) FROM submissions WHERE ip_address = :ip AND created_at > :w', [':ip' => $ip, ':w' => $window])->fetchColumn();
$emN    = $email ? (int) db_query('SELECT COUNT(*) FROM submissions WHERE guest_email = :e AND created_at > :w', [':e' => $email, ':w' => $window])->fetchColumn() : 0;
if ($ipN >= 5 || $emN >= 5) { http_response_code(429); exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.'])); }

// ── Validate ──
$errors = [];
$name = trim((string)($data['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 160) $errors['name'] = 'Your name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'A valid email is required.';
$ci = rates_window_ymd((string)($data['checkin'] ?? ''));
$co = rates_window_ymd((string)($data['checkout'] ?? ''));
if (!$ci || !$co || $co <= $ci)  $errors['dates'] = 'Pick your check-in and check-out dates.';
elseif ($ci < date('Y-m-d'))     $errors['dates'] = 'Check-in can’t be in the past.';
$adults   = max(1, min(40, (int)($data['adults'] ?? 1)));
$children = max(0, min(40, (int)($data['children'] ?? 0)));
[$rooms, $roomErr] = hold_group_clean_rooms($data['rooms'] ?? null);
if ($roomErr) $errors['rooms'] = $roomErr;
$venue = db_query('SELECT * FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => trim((string)($data['venue'] ?? ''))])->fetch();
if (!$venue) $errors['venue'] = 'That property could not be found.';
if ($errors) { http_response_code(422); exit(json_encode(['ok' => false, 'errors' => $errors, 'error' => reset($errors)])); }

$resolved = hold_group_resolve($venue, $rooms, $adults + $children);
if ($resolved['error']) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => $resolved['error']])); }
// Holds only when the owner lets the website place them (off by default — the
// reservations team converts the request to holds instead).
$holdMode = $resolved['mode'] === 'hold' && hold_groups_supported() && website_holds_enabled();
$roomsLabel = implode(' + ', array_map(fn($r) => $r['room']['name'] . ($r['units'] > 1 ? ' ×' . $r['units'] : ''), $resolved['rooms']));

if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];
$phone = mb_substr(trim((string)($data['phone'] ?? '')), 0, 40);
$note  = mb_substr(trim((string)($data['message'] ?? '')), 0, 2000);
$message = "Room combination for {$venue['name']}: {$roomsLabel}" . ($note !== '' ? "\n\nGuest note: {$note}" : '');

try {
    // The lead first — it is kept even if the rooms are gone by the time we hold them.
    db_query(
        "INSERT INTO submissions (type, room_id, guest_name, guest_email, guest_phone, message, check_in, check_out, guests_adults, guests_children,
                                  payload_json, source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content, user_agent, ip_address)
         VALUES ('enquiry', :room, :name, :email, :phone, :msg, :ci, :co, :a, :c, :payload, :sp, :ref, :us, :um, :uc, :ut, :uco, :ua, :ip)",
        [':room' => (int)$resolved['rooms'][0]['room']['id'], ':name' => $name, ':email' => $email, ':phone' => $phone, ':msg' => $message,
         ':ci' => $ci, ':co' => $co, ':a' => $adults, ':c' => $children,
         ':payload' => json_encode(array_filter([
             'submitted_from'  => $_SERVER['HTTP_REFERER'] ?? '',
             'combination'     => array_map(fn($r) => ['slug' => $r['room']['slug'], 'name' => $r['room']['name'], 'units' => $r['units']], $resolved['rooms']),
             'quoted_total'    => (isset($data['quoted_total']) && is_numeric($data['quoted_total']) && (float)$data['quoted_total'] > 0) ? round((float)$data['quoted_total'], 2) : null,
             'quoted_currency' => trim((string)($data['quoted_currency'] ?? '')) ?: null,
         ], fn($v) => $v !== null && $v !== '' && $v !== [])),
         ':sp' => $tracking['source_page'] ?? '', ':ref' => $tracking['referrer'] ?? '', ':us' => $tracking['utm_source'] ?? '',
         ':um' => $tracking['utm_medium'] ?? '', ':uc' => $tracking['utm_campaign'] ?? '', ':ut' => $tracking['utm_term'] ?? '',
         ':uco' => $tracking['utm_content'] ?? '', ':ua' => $tracking['user_agent'] ?? '', ':ip' => $ip]);
    $subId = (int) db()->lastInsertId();

    if ($holdMode) {
        $ids = hold_group_create($resolved, $subId, $ci, $co, $name, $email);
        if ($ids === false) {
            http_response_code(409);
            exit(json_encode(['ok' => false, 'error' => 'One of those rooms was just taken. Please check availability again, or contact us — we have your request.']));
        }
        $row = hold_group_mail_row(hold_group_rows($ids[0]));
        send_hold_notification($row);                         // ONE email to reservations for the whole request
        send_guest_acknowledgement([
            'submission_id' => $subId,
            'kind' => 'hold', 'guest_name' => $name, 'guest_email' => $email, 'room_name' => $row['room_name'],
            'check_in' => $ci, 'check_out' => $co, 'guests_adults' => $adults, 'guests_children' => $children,
            'message' => $note, 'hold_id' => $ids[0], 'access_code' => $row['access_code'] ?? '',
        ]);
        ghl_respond_json(['ok' => true, 'id' => $subId, 'mode' => 'hold', 'rooms' => count($ids)]);
        ghl_push_submission($subId, ['tags' => ['website-enquiry', 'website-hold', 'room-combination'], 'source' => 'Website Hold (24h, combination)']);
        exit;
    }

    send_notification(['id' => $subId, 'type' => 'enquiry', 'room_name' => $roomsLabel, 'guest_name' => $name, 'guest_email' => $email,
                       'guest_phone' => $phone, 'message' => $message, 'check_in' => $ci, 'check_out' => $co,
                       'guests_adults' => $adults, 'guests_children' => $children, 'created_at' => date('Y-m-d H:i:s')] + $tracking);
    send_guest_acknowledgement(['submission_id' => $subId, 'kind' => 'enquiry', 'guest_name' => $name, 'guest_email' => $email, 'room_name' => $roomsLabel,
                                'check_in' => $ci, 'check_out' => $co, 'guests_adults' => $adults, 'guests_children' => $children, 'message' => $note]);
    ghl_respond_json(['ok' => true, 'id' => $subId, 'mode' => 'enquiry']);
    ghl_push_submission($subId, ['tags' => ['website-enquiry', 'room-combination']]);
} catch (Throwable $e) {
    error_log('[submit-combo] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong saving your request. Please try again or contact us directly.']);
}
