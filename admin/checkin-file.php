<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$holdId  = (int)($_GET['hold'] ?? 0);
$guestId = (int)($_GET['guest'] ?? 0);
if (!$holdId || !can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }

$row = db_query('SELECT passport_file_key FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $guestId, ':h' => $holdId])->fetch();
$key = $row['passport_file_key'] ?? '';
if ($key === '') { http_response_code(404); exit('No file'); }

audit_log('checkin.file_view', 'hold', $holdId, 'guest ' . $guestId);

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$ct  = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $ct);
header('Content-Disposition: inline; filename="passport.' . $ext . '"');
header('Cache-Control: private, no-store');

$signed = storage_signed_get_url($key);
if ($signed !== '') {
    $data = @file_get_contents($signed);
    if ($data === false) { http_response_code(502); exit('Fetch failed'); }
    echo $data;
} else {
    $path = storage_local_path($key);   // filesystem fallback
    if (!is_file($path)) { http_response_code(404); exit('No file'); }
    readfile($path);
}
