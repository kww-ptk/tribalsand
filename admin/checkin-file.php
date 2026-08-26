<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$holdId  = (int)($_GET['hold'] ?? 0);
$guestId = (int)($_GET['guest'] ?? 0);
$kind    = ($_GET['kind'] ?? '') === 'deposit' ? 'deposit' : 'passport';
if (!$holdId || !can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }

if ($kind === 'deposit') {
    // Booking-level credit-card image (add_checkin_deposit.sql).
    $row = checkin_deposit_supported()
        ? db_query('SELECT deposit_card_file_key FROM booking_checkin WHERE hold_id = :h', [':h' => $holdId])->fetch()
        : null;
    $key = $row['deposit_card_file_key'] ?? '';
    if ($key === '') { http_response_code(404); exit('No file'); }
    audit_log('checkin.file_view', 'hold', $holdId, 'deposit card');
} else {
    $row = db_query('SELECT passport_file_key FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $guestId, ':h' => $holdId])->fetch();
    $key = $row['passport_file_key'] ?? '';
    if ($key === '') { http_response_code(404); exit('No file'); }
    audit_log('checkin.file_view', 'hold', $holdId, 'guest ' . $guestId);
}

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$ct  = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';

// Resolve the bytes BEFORE sending any content headers — otherwise a missing
// file returns a text error body under an image/* type and the browser renders
// it as a broken image instead of showing the real problem.
$signed = storage_signed_get_url($key);
if ($signed !== '') {
    $data = @file_get_contents($signed);
    if ($data === false) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Scan is stored remotely but could not be fetched (check R2 credentials/bucket).');
    }
} else {
    $path = storage_local_path($key);   // filesystem fallback
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Scan file is missing from storage — it was likely lost on a deploy because no persistent check-in bucket (R2_CHECKIN_BUCKET) or disk (CHECKIN_STORAGE_DIR) is configured. The guest must re-upload.');
    }
    $data = file_get_contents($path);
    if ($data === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Scan file could not be read.');
    }
}

header('Content-Type: ' . $ct);
header('Content-Disposition: inline; filename="' . $kind . '.' . $ext . '"');
header('Cache-Control: private, no-store');
echo $data;
