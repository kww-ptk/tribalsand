<?php
/**
 * Public share link for a Support guide meant for guests or travel agents:
 * /guide?d=<guide id>&t=<signature>[&download=1]. Serves the guide's CURRENT
 * version, so a link already sent keeps working after a new version is imported.
 * Refused unless the signature matches (support_share_sig()) and the guide is
 * still active and still shared with guests or travel agents.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/support-docs.php';

header('X-Robots-Tag: noindex');
$id  = (int)($_GET['d'] ?? 0);
$doc = support_share_ok($id, (string)($_GET['t'] ?? '')) ? support_doc_get($id) : null;
$ver = ($doc && !empty($doc['is_active']) && support_is_shareable($doc['audience'])) ? support_current_version($id) : null;
if (!$ver) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This guide link is not valid any more. Please ask Tribal Sand for a new one.');
}
support_stream($ver, !empty($_GET['download']));
