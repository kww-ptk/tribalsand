<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$ref    = trim((string)($_POST['ref'] ?? ''));
$holdId = verify_guest_ref($ref);
if (!$holdId) { http_response_code(403); echo json_encode(['error' => 'bad ref']); exit; }
verify_csrf();

$hold = fetch_hold_for_guest($holdId);
if (!$hold || !checkin_required($hold)) { http_response_code(403); echo json_encode(['error' => 'not applicable']); exit; }

$f = $_FILES['passport'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error' => 'no file']); exit; }
if (($f['size'] ?? 0) > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'too large']); exit; }

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!isset($allowed[$mime])) { http_response_code(400); echo json_encode(['error' => 'type not allowed']); exit; }

$key = 'checkin/' . $holdId . '/' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
if (!storage_put_private($f['tmp_name'], $key, $mime)) { http_response_code(500); echo json_encode(['error' => 'store failed']); exit; }

// Store the KEY (not a public URL) so viewing always goes through the admin proxy.
// Atomic upsert on the partial unique index (hold_id WHERE is_lead) so a
// concurrent per-step save can't create a second lead row.
$prev = checkin_lead_guest($holdId);
db_query(
    "INSERT INTO checkin_guests (hold_id, is_lead, passport_file_key) VALUES (:h, TRUE, :k)
     ON CONFLICT (hold_id) WHERE is_lead DO UPDATE SET passport_file_key = :k",
    [':h' => $holdId, ':k' => $key]);
// Remove the previous file, if the key actually changed.
if ($prev && !empty($prev['passport_file_key']) && $prev['passport_file_key'] !== $key) {
    try { storage_delete_private($prev['passport_file_key']); } catch (Throwable $e) {}
}
echo json_encode(['ok' => true]);
