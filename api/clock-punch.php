<?php
/**
 * Record one clock in/out from a registered kiosk.
 *
 * Authenticated by the DEVICE token, not a staff session — no one is signed in
 * at a shared tablet. There is deliberately no CSRF token: there is no session
 * to ride, and the device token is the credential.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

header('Content-Type: application/json');

/** Answer and stop. */
function punch_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') punch_fail('Method not allowed', 405);
if (!attendance_punches_supported())       punch_fail('Clocking in isn’t enabled yet.');
// The owner's kill switch. A tablet registered earlier stops recording the
// moment this is turned off — that is the point of it being checked here and
// not only in the nav.
if (!clock_kiosk_enabled())               punch_fail('Clocking in is switched off. Ask a manager.', 403);

$device = clock_device_by_token((string)($_POST['device_token'] ?? ''));
if (!$device) punch_fail('This tablet is no longer registered. Ask a manager to set it up again.', 403);

$staff = clock_staff_by_token((string)($_POST['card'] ?? ''));
if (!$staff) punch_fail('Card not recognised. See a manager.', 404);

$kind = (string)($_POST['kind'] ?? '');
if (!in_array($kind, ['in', 'out'], true)) punch_fail('Choose clock in or clock out.');

// Rate limit per card: a scanned card must not be replayable in a loop.
if (clock_rate_limited((int)$staff['id'])) {
    punch_fail('Too many attempts. Wait a few minutes.', 429);
}

$today   = frontdesk_today_ymd();
$minutes = clock_minutes_from_hms(date('H:i:s'));

// Store the photo FIRST so the punch row can carry its key — but never let a
// photo failure cost someone their shift. A punch with no picture beats none.
$photoKey = null;
if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $info = @getimagesize($_FILES['photo']['tmp_name']);
    if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        $key = 'attendance/' . (int)$staff['id'] . '/' . $today . '/' . bin2hex(random_bytes(8)) . '.jpg';
        if (storage_put_private($_FILES['photo']['tmp_name'], $key, 'image/jpeg')) $photoKey = $key;
        else error_log('[clock] photo store failed for staff ' . (int)$staff['id']);
    }
}

$res = clock_record_punch(
    (int)$staff['id'], $kind, $minutes, $today,
    (int)$device['id'], (int)($device['venue_id'] ?: 0) ?: null, $photoKey
);

if (!$res['ok']) punch_fail((string)$res['error']);

clock_touch_device((int)$device['id']);

echo json_encode([
    'ok'     => true,
    'error'  => null,
    'name'   => $staff['full_name'],
    'kind'   => $kind,
    'time'   => date('H:i'),
    'photo'  => $photoKey !== null,
    'date'   => $res['work_date'],
]);
