<?php
/**
 * Register this browser as a kiosk. Manager-authed, one-time: the response
 * carries a long-lived token the tablet stores and replays on every punch.
 *
 * Refuses when private storage is unconfigured — see below.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/attendance-clock.php';
require_login();
require_manager();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }
verify_csrf();

if (!attendance_devices_supported()) {
    http_response_code(400);
    exit(json_encode(['ok'=>false,'error'=>'Run the add_attendance_punches.sql migration first.']));
}

/**
 * Photo evidence is the point of this feature, and storage_put_private() falls
 * back to sys_get_temp_dir() when no private bucket or disk is configured — on
 * ECS that is the container's ephemeral filesystem, wiped every deploy. Refuse
 * to register rather than let the kiosk run while silently discarding evidence.
 */
$env = parse_env();
$hasPrivate = trim((string)($env['R2_CHECKIN_BUCKET'] ?? '')) !== ''
           || trim((string)($env['S3_CHECKIN_BUCKET'] ?? '')) !== ''
           || trim((string)($env['CHECKIN_STORAGE_DIR'] ?? '')) !== '';
if (!$hasPrivate) {
    http_response_code(400);
    exit(json_encode(['ok'=>false,'error'=>'No private storage is configured, so clock-in photos would be lost on the next deploy. Set R2_CHECKIN_BUCKET, S3_CHECKIN_BUCKET or CHECKIN_STORAGE_DIR first.']));
}

$name    = trim((string)($_POST['name'] ?? ''));
$venueId = (int)($_POST['venue_id'] ?? 0);

$scope = admin_venue_ids();                       // null = owner (all)
if ($scope !== null && !in_array($venueId, array_map('intval', $scope), true)) {
    http_response_code(403);
    exit(json_encode(['ok'=>false,'error'=>'That property isn’t yours to register a device for.']));
}
if ($name === '') { http_response_code(400); exit(json_encode(['ok'=>false,'error'=>'Give the tablet a name.'])); }

[$id, $token] = clock_register_device($name, $venueId ?: null, (int)($_SESSION['admin_id'] ?? 0) ?: null);
audit_log('attendance_device.register', 'attendance_device', $id, $name);

echo json_encode(['ok' => true, 'error' => null, 'device_id' => $id, 'token' => $token]);
