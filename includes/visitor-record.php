<?php
/**
 * A visitor's signed waiver record, served from /record.php (never /admin/).
 * Auth: the visitor's own pass token (?vp=), else a signed-in gate account
 * that covers them (?vid=). Renders the FROZEN snapshots only.
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

$num  = trim((string)($v['waiver_id_number_snapshot'] ?? ''));
$mask = $num === '' ? '—' : (strlen($num) <= 2 ? str_repeat('•', strlen($num)) : substr($num, 0, 1) . str_repeat('•', max(1, strlen($num) - 2)) . substr($num, -1));
$doc  = checkin_id_type_label((string)($v['waiver_id_type_snapshot'] ?? 'passport'));
$at   = (string)$v['waiver_signed_at'];
$how  = ($v['signed_via'] ?? '') === 'gate' ? 'On a Tribal Sand device at the gate' : 'Own device, via the registration link';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Signed consent · <?= e((string)$v['waiver_name_snapshot']) ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .bd{padding:26px 30px 30px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:22px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:12.5px} td{padding:4px 0;vertical-align:top} td.k{color:#666;width:150px}
  .terms{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;white-space:pre-wrap}
  .sigbox{border:1px solid #d8dbd8;border-radius:4px;padding:8px;max-width:280px} .sigbox img{display:block;max-width:100%}
  code{background:#eee;padding:1px 5px;border-radius:3px}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>
<div class="head"><div class="brand">TRIBAL SAND</div><div style="text-align:right;font-size:11px;line-height:1.5">Visitor Indemnity &amp; Waiver<br><strong>Signed Consent Record</strong></div></div>
<div class="bd">
  <table><tr><td class="k">Record reference</td><td><strong><?= e((string)$v['waiver_reference']) ?></strong></td></tr></table>
  <div class="sec">1 · Signatory</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e((string)$v['waiver_name_snapshot']) ?></strong></td></tr>
    <tr><td class="k">Role</td><td>Visitor</td></tr>
    <tr><td class="k">Nationality</td><td><?= e((string)($v['waiver_nationality_snapshot'] ?: '—')) ?></td></tr>
    <tr><td class="k"><?= e($doc) ?></td><td>No. <?= e($mask) ?></td></tr>
  </table>
  <div class="sec">2 · Terms agreed</div>
  <div class="terms"><?= e((string)$v['waiver_terms_snapshot']) ?></div>
  <div style="font-size:10.5px;color:#888;margin-top:8px">Waiver version <code><?= e((string)$v['waiver_version']) ?></code> — the exact terms shown at signing.</div>
  <div class="sec">3 · Signature</div>
  <div class="sigbox"><img src="<?= e((string)$v['waiver_signature']) ?>" alt="Signature"></div>
  <p style="font-size:12px">Signed by <strong><?= e((string)$v['waiver_signed_name']) ?></strong> · &#9745; “I have read and agree”</p>
  <div class="sec">4 · Evidence of signing</div>
  <table>
    <tr><td class="k">Date &amp; time</td><td><strong><?= e(date('j M Y, H:i:s', strtotime($at))) ?></strong></td></tr>
    <tr><td class="k">IP address</td><td><?= e((string)($v['signed_ip'] ?: '—')) ?></td></tr>
    <tr><td class="k">Device</td><td><?= e((string)($v['signed_ua'] ?: '—')) ?></td></tr>
    <tr><td class="k">Method</td><td><?= e($how) ?></td></tr>
  </table>
</div>
</body></html>
