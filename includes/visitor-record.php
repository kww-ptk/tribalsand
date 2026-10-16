<?php
/**
 * A visitor's signed waiver record, served from /record.php (never /admin/).
 * Auth: the visitor's own pass token (?vp=), else a signed-in gate account
 * that covers them (?vid=). Renders the FROZEN snapshots only, via the shared waiver_record_html().
 */
declare(strict_types=1);
require_once __DIR__ . '/visitor-store.php';

$vp = trim((string)($_GET['vp'] ?? ''));
$viewer = 'visitor';
if ($vp !== '') {
    $v = visitor_find_by_token($vp);
} else {
    require_login(); require_gate();
    $vid = (int)($_GET['vid'] ?? 0);
    if (!visitor_in_scope($vid, admin_venue_ids(), (int)($_SESSION['admin_id'] ?? 0))) { http_response_code(403); exit('Forbidden'); }
    $v = visitor_find($vid, true);
    $viewer = 'admin';
}
if (!$v || !visitor_signed($v)) { http_response_code(404); exit('Record not found.'); }

visitor_log_step('record_viewed', ['reference' => (string)$v['waiver_reference'], 'visitor_id' => (int)$v['id'],
    'waiver_version' => (string)$v['waiver_version'], 'method' => $viewer, 'detail' => 'Record viewed']);

require_once __DIR__ . '/waiver-record.php';
$m = waiver_record_model_visitor($v);
echo waiver_record_html($m, $viewer === 'admin'
    ? ['scan' => 'url', 'scan_url' => '/admin/visitor-file.php?id=' . (int)$v['id'] . '&signed=1']
    : ['scan' => 'none']);
