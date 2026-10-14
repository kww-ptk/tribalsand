<?php
/**
 * Visitor ID photo upload (register.php / the guard's tablet). The photo is
 * stored privately BEFORE the visitor row exists, so the key is random and held
 * in the session ($_SESSION['vr_photo']) until api/visitor-register.php attaches
 * it. Then the photo is read like a check-in passport (same prompt, same never-
 * trust rules, same per-session cap). JPEG/PNG only.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/visitor-store.php';
session_init();
header('Content-Type: application/json');

$fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'method');
// A cookie-less request has no session token; verify_csrf() would then match
// '' against '' and pass, so require a real page load that minted the token first.
if (empty($_SESSION['csrf_token'])) $fail(403, 'Invalid CSRF token.');
verify_csrf();
if (!visitor_reg_supported()) $fail(503, 'not available');

$gate = !empty($_POST['gate']) && !empty($_SESSION['admin_id']);
if (!$gate) {
    if (visitor_attempt_count('upload', 60) >= VISITOR_UPLOAD_MAX) $fail(429, 'Too many photos — please wait a little and try again.');
    visitor_attempt_log('upload');
}

$f = $_FILES['passport'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $fail(400, 'no file');
if (($f['size'] ?? 0) > 8 * 1024 * 1024) $fail(400, 'too large');
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!isset($allowed[$mime])) $fail(400, 'Please take a photo (JPG or PNG).');

$bytes = (string) @file_get_contents($f['tmp_name']);   // read now: storage may move the temp file
$key = 'visitors/' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!storage_put_private($f['tmp_name'], $key, $mime)) $fail(500, 'store failed');

// A newer photo in this session replaces the unattached older one.
$old = (string)($_SESSION['vr_photo'] ?? '');
if ($old !== '' && $old !== $key) { try { storage_delete_private($old); } catch (Throwable $e) {} }
$_SESSION['vr_photo'] = $key;

$out = ['ok' => true];
$reads = (int)($_SESSION['vr_reads'] ?? 0);
if ($bytes !== '' && ai_assistant_supported() && $reads < CHECKIN_PASSPORT_READS_MAX) {
    $_SESSION['vr_reads'] = $reads + 1;
    $out['read'] = false;
    try {
        $raw = ai_read_image_json($bytes, $mime, checkin_passport_read_prompt());
        if ($raw !== null) {
            $read = checkin_passport_from_ai($raw, date('Y-m-d'));
            $out['read'] = true; $out['fields'] = $read['fields']; $out['warnings'] = $read['warnings'];
        }
    } catch (Throwable $e) { error_log('[visitor-upload] read failed: ' . $e->getMessage()); }
}
echo json_encode($out);
