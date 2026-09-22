<?php
declare(strict_types=1);
/**
 * Admin: serve one clock-in punch's captured photo.
 *
 * The photo is a convenience for a manager reviewing attendance — not a legal
 * record — but it is still private, so it is served through this scoped
 * endpoint rather than a public URL. Same shape as admin/checkin-file.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$id = (int)($_GET['punch'] ?? 0);
$row = $id ? db_query(
    "SELECT p.photo_key, s.venue_id
       FROM attendance_punches p
       JOIN hr_staff s ON s.id = p.hr_staff_id
      WHERE p.id = :i", [':i' => $id])->fetch() : false;

$scope = admin_venue_ids();                       // null = owner (all)
if (!$row || ($scope !== null && !in_array((int)$row['venue_id'], array_map('intval', $scope), true))) {
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Forbidden');
}
$key = (string)($row['photo_key'] ?? '');
if ($key === '') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('No photo was captured for this punch.'); }

// Resolve the bytes BEFORE sending any content headers — otherwise a missing
// file returns a text error body under image/jpeg and the browser shows a
// broken image instead of the real problem. Same order as admin/checkin-file.php.
$signed = storage_signed_get_url($key);
if ($signed !== '') {
    $data = @file_get_contents($signed);
    if ($data === false) { http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); exit('Photo is stored remotely but could not be fetched.'); }
} else {
    $path = storage_local_path($key);
    if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Photo is missing from storage — most likely no persistent private bucket is configured, so it was lost on a deploy.'); }
    $data = file_get_contents($path);
    if ($data === false) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit('Photo could not be read.'); }
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, no-store');
echo $data;
