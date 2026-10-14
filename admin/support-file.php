<?php
/**
 * Admin: stream one Support guide PDF — ?v=<version id> to view in the page,
 * &download=1 to save it. Signed-in only, and the guide must be meant for this
 * account's role (support_visible_to()); a removed guide is the owner's only.
 * Older versions are readable the same way (Version history).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/support-docs.php';
require_login();

$ver = support_version_get((int)($_GET['v'] ?? 0));
$doc = $ver['doc'] ?? null;
if (!$ver || !$doc || !support_visible_to($doc['audience'], support_viewer_key()) || (empty($doc['is_active']) && !is_owner())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Guide not found.');
}
$download = !empty($_GET['download']);
if ($download) audit_log('support.download', 'support_doc', (int)$doc['id'], 'v' . (int)$ver['version_no']);
support_stream($ver, $download);
