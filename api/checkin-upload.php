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

// The deposit credit-card image is booking-level (one card for the reservation),
// so only the lead may post it — a co-guest ?g= link (onlyGuestId set) cannot.
$isDeposit = isset($_FILES['deposit_card']);
if ($isDeposit && ($onlyGuestId !== null || !checkin_deposit_supported())) {
    http_response_code(403); echo json_encode(['error' => 'not allowed']); exit;
}

$f = $isDeposit ? ($_FILES['deposit_card'] ?? null) : ($_FILES['passport'] ?? null);
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error' => 'no file']); exit; }
if (($f['size'] ?? 0) > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'too large']); exit; }

// A card photo is an image; a passport scan may also be a PDF.
$allowed = $isDeposit
    ? ['image/jpeg' => 'jpg', 'image/png' => 'png']
    : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!isset($allowed[$mime])) { http_response_code(400); echo json_encode(['error' => 'type not allowed']); exit; }

if ($isDeposit) {
    // Booking-level private key. Same private store as passports; served only via
    // admin/checkin-file.php?kind=deposit. Never a public URL.
    $key = 'checkin/' . $holdId . '/deposit/' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!storage_put_private($f['tmp_name'], $key, $mime)) { http_response_code(500); echo json_encode(['error' => 'store failed']); exit; }

    $prev = db_query('SELECT deposit_card_file_key FROM booking_checkin WHERE hold_id = :h', [':h' => $holdId])->fetch();
    db_query(
        'INSERT INTO booking_checkin (hold_id, deposit_card_file_key, updated_at)
         VALUES (:h, :k, now())
         ON CONFLICT (hold_id) DO UPDATE SET deposit_card_file_key = :k, updated_at = now()',
        [':h' => $holdId, ':k' => $key]);
    if ($prev && !empty($prev['deposit_card_file_key']) && $prev['deposit_card_file_key'] !== $key) {
        try { storage_delete_private($prev['deposit_card_file_key']); } catch (Throwable $e) {}
    }
    checkin_recompute_completion($holdId);   // flips green iff the deposit step was the last thing outstanding
    echo json_encode(['ok' => true]);
    exit;
}

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
