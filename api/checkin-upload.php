<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

[$holdId, $onlyGuestId] = checkin_auth_context();   // lead (ref) → any guest; co-guest (g) → own row only
verify_csrf();

$hold = fetch_hold_for_guest($holdId);
if (!$hold || !checkin_required($hold)) { http_response_code(403); echo json_encode(['error' => 'not applicable']); exit; }

$f = $_FILES['passport'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error' => 'no file']); exit; }
if (($f['size'] ?? 0) > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'too large']); exit; }

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!isset($allowed[$mime])) { http_response_code(400); echo json_encode(['error' => 'type not allowed']); exit; }

// Per-guest private key. Files are stored by hold + guest, never a public URL.
$guestId = checkin_target_guest_id($holdId, $onlyGuestId);
$key = 'checkin/' . $holdId . '/' . $guestId . '/' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
if (!storage_put_private($f['tmp_name'], $key, $mime)) { http_response_code(500); echo json_encode(['error' => 'store failed']); exit; }

$prev = db_query('SELECT passport_file_key FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $guestId, ':h' => $holdId])->fetch();
db_query('UPDATE checkin_guests SET passport_file_key = :k WHERE id = :g AND hold_id = :h', [':k' => $key, ':g' => $guestId, ':h' => $holdId]);
if ($prev && !empty($prev['passport_file_key']) && $prev['passport_file_key'] !== $key) {
    try { storage_delete_private($prev['passport_file_key']); } catch (Throwable $e) {}
}
checkin_recompute_completion($holdId);   // flips green + notifies iff this was the last passport
echo json_encode(['ok' => true]);
