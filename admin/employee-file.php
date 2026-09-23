<?php
/**
 * Admin: stream one private employee document (contract / ID / certificate).
 * Same gate as admin/employee.php — owner or manager, and the person must be in
 * the account's venue scope — re-checked on every view, because the document id
 * comes from the URL. Files are never public; this is the only way to read one.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/hr-documents.php';
require_login();
require_manager();

$docId = (int)($_GET['doc'] ?? 0);
$doc   = hr_staff_documents_supported() ? fetch_hr_staff_document($docId) : false;
$p     = $doc ? fetch_hr_staff_row((int)$doc['hr_staff_id']) : false;
if (!$doc || !$p || !hr_staff_in_venue_scope((int)$p['id'], $p['venue_id'] !== null ? (int)$p['venue_id'] : null, admin_venue_ids())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Document not found.');
}

// Resolve the bytes BEFORE any content headers, so a missing file shows its real
// error instead of a broken PDF/image.
$r = hr_doc_read_bytes((string)$doc['file_key']);
if (!$r['ok']) {
    http_response_code($r['status']);
    header('Content-Type: text/plain; charset=utf-8');
    exit($r['error']);
}

audit_log('hr.document_view', 'hr_staff', (int)$p['id'], (string)$doc['filename']);

$ct       = (string)$doc['content_type'];
$download = !empty($_GET['download']) || !hr_doc_is_inline($ct);
$fname    = (string)$doc['filename'];   // already sanitised at upload (hr_doc_safe_filename)

header('Content-Type: ' . $ct);
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
     . '; filename="' . str_replace('"', '', $fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
header('Content-Length: ' . strlen($r['data']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $r['data'];
