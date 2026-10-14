<?php
/** A registered visitor's ID photo. Private: gate accounts that cover the visitor only. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login();
require_gate();

$id = (int)($_GET['id'] ?? 0);
if (!visitor_in_scope($id, admin_venue_ids(), (int)($_SESSION['admin_id'] ?? 0))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}
$v   = visitor_find($id, true);
$key = (string)($v['id_photo_key'] ?? '');
if ($key === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No file');
}
audit_log('visitor.file_view', 'visitor', $id, '');

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$ct  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

// Resolve the bytes BEFORE any content header (same order as admin/checkin-file.php),
// so a missing file never goes out as a broken image/* body.
$signed = storage_signed_get_url($key);
if ($signed !== '') {
    $data = @file_get_contents($signed);
    if ($data === false) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The photo is stored remotely but could not be fetched.');
    }
} else {
    $path = storage_local_path($key);
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The photo file is missing from storage.');
    }
    $data = file_get_contents($path);
    if ($data === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The photo file could not be read.');
    }
}

header('Content-Type: ' . $ct);
header('Content-Disposition: inline; filename="id-photo.' . ($ext !== '' ? $ext : 'jpg') . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $data;
