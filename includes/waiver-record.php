<?php
/**
 * The signed waiver record — ONE renderer for guests (record.php), visitors
 * (includes/visitor-record.php) and the monthly ZIP (includes/waiver-archive.php).
 * Identity comes only from the frozen snapshots (legacy guest rows fall back to
 * the live columns, as before). The scan is shown only when the caller says so:
 *   'none'  guest links — never the scan
 *   'url'   owner / staff screen — <img> pointing at scan_url
 *   'embed' the ZIP — image bytes inlined as a data URI (a PDF sits beside the record)
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/visitor-store.php';

function waiver_record_model_guest(array $hold, array $guest, string $reference): array {
    $snap = fn(string $s, string $live) => trim((string)($guest[$s] ?? '')) !== '' ? trim((string)$guest[$s]) : trim((string)($guest[$live] ?? ''));
    $scan = checkin_guest_scan_key($guest);
    $stay = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
    return [
        'kind' => 'guest', 'title' => 'Guest Indemnity & Waiver', 'reference' => $reference,
        'booking' => 'TS-' . (int)($hold['id'] ?? 0) . (!empty($hold['access_code']) ? ' · code ' . $hold['access_code'] : ''),
        'stay' => $stay,
        'dates' => !empty($hold['check_in']) ? date('D j M Y', strtotime((string)$hold['check_in'])) . ' → ' . date('D j M Y', strtotime((string)($hold['check_out'] ?? $hold['check_in']))) : '',
        'name' => $snap('waiver_name_snapshot', 'passport_name'),
        'role' => !empty($guest['is_lead']) ? 'Lead guest' : 'Adult guest',
        'nationality' => $snap('waiver_nationality_snapshot', 'nationality'),
        // The document type as signed (frozen with the identity); no snapshot = a passport.
        'doc_label' => checkin_id_type_label(trim((string)($guest['waiver_id_type_snapshot'] ?? '')) ?: 'passport'),
        'doc_number' => $snap('waiver_passport_snapshot', 'passport_number'),
        'expiry' => substr($snap('waiver_passport_expiry_snapshot', 'passport_expiry'), 0, 10),
        'terms' => (string)($guest['waiver_terms_snapshot'] ?? ''), 'version' => (string)($guest['waiver_version'] ?? ''),
        'signature' => (string)($guest['waiver_signature'] ?? ''), 'signed_name' => (string)($guest['waiver_signed_name'] ?? ''),
        'signed_at' => (string)($guest['waiver_signed_at'] ?? ''),
        'ip' => (string)($guest['waiver_signed_ip'] ?? ''), 'device' => (string)($guest['waiver_signed_user_agent'] ?? ''),
        'method' => ((string)($guest['waiver_signed_method'] ?? '') === 'reception') ? 'On a Tribal Sand device, in person' : 'Own device, via personal check-in link',
        'scan_key' => $scan['key'], 'scan_frozen' => $scan['frozen'], 'scan_removed_at' => '',
        'hold_id' => (int)($hold['id'] ?? 0), 'guest_id' => (int)($guest['id'] ?? 0), 'visitor_id' => 0,
    ];
}

function waiver_record_model_visitor(array $v): array {
    $scan = visitor_scan_key($v);
    return [
        'kind' => 'visitor', 'title' => 'Visitor Indemnity & Waiver', 'reference' => (string)($v['waiver_reference'] ?? ''),
        'booking' => '', 'stay' => '', 'dates' => '',
        'name' => (string)($v['waiver_name_snapshot'] ?? ''), 'role' => 'Visitor',
        'nationality' => (string)($v['waiver_nationality_snapshot'] ?? ''),
        'doc_label' => checkin_id_type_label(trim((string)($v['waiver_id_type_snapshot'] ?? '')) ?: 'passport'),
        'doc_number' => trim((string)($v['waiver_id_number_snapshot'] ?? '')),
        'expiry' => substr(trim((string)($v['waiver_id_expiry_snapshot'] ?? '')), 0, 10),
        'terms' => (string)($v['waiver_terms_snapshot'] ?? ''), 'version' => (string)($v['waiver_version'] ?? ''),
        'signature' => (string)($v['waiver_signature'] ?? ''), 'signed_name' => (string)($v['waiver_signed_name'] ?? ''),
        'signed_at' => (string)($v['waiver_signed_at'] ?? ''),
        'ip' => (string)($v['signed_ip'] ?? ''), 'device' => (string)($v['signed_ua'] ?? ''),
        'method' => ($v['signed_via'] ?? '') === 'gate' ? 'On a Tribal Sand device at the gate' : 'Own device, via the registration link',
        'scan_key' => $scan['key'], 'scan_frozen' => $scan['frozen'],
        'scan_removed_at' => !empty($v['removed_at']) ? (string)$v['removed_at'] : '',
        'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => (int)($v['id'] ?? 0),
    ];
}

/** MIME of scan bytes (sniffed — the key's extension is a hint only). */
function waiver_scan_mime(string $bytes): string {
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($bytes, "\x89PNG")) return 'image/png';
    if (str_starts_with($bytes, '%PDF-')) return 'application/pdf';
    return 'application/octet-stream';
}

function waiver_record_html(array $m, array $opt = []): string {
    $mode = $opt['scan'] ?? 'none';
    $d = fn(string $ymd) => $ymd !== '' ? date('j M Y', strtotime($ymd)) : '—';
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Signed consent · <?= e($m['name'] !== '' ? $m['name'] : 'Guest') ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .subh{font-size:10px;letter-spacing:2px;opacity:.75;text-transform:uppercase;margin-top:3px}
  .bd{padding:26px 30px 30px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:22px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:12.5px} td{padding:4px 0;vertical-align:top} td.k{color:#666;width:150px}
  .terms{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;white-space:pre-wrap}
  .sigbox{border:1px solid #d8dbd8;border-radius:4px;padding:8px;max-width:280px;background:#fff} .sigbox img{display:block;max-width:100%;height:auto}
  .scan img{display:block;max-width:100%;max-height:520px;border:1px solid #d8dbd8;border-radius:4px}
  .note{font-size:11px;color:#888;margin-top:6px}
  .stamp{border:1.5px solid #123c30;color:#123c30;font-size:9px;letter-spacing:1.5px;font-weight:700;padding:6px 10px;border-radius:3px;transform:rotate(-3deg)}
  code{background:#eee;padding:1px 5px;border-radius:3px}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}.scan{break-inside:avoid}}
</style></head><body>
<?php if ($opt['print_button'] ?? true): ?><button class="printbtn" onclick="window.print()">Print / Save PDF</button><?php endif; ?>
<div class="head">
  <div><div class="brand">TRIBAL SAND</div><div class="subh">Kilifi · Kenya Coast</div></div>
  <div style="text-align:right;font-size:11px;opacity:.85;line-height:1.5"><?= e($m['title']) ?><br><strong style="opacity:1">Signed Consent Record</strong></div>
</div>
<div class="bd">
  <table>
    <?php if ($m['reference'] !== ''): ?><tr><td class="k">Record reference</td><td><strong><?= e($m['reference']) ?></strong></td></tr><?php endif; ?>
    <?php if ($m['booking'] !== ''): ?><tr><td class="k">Booking reference</td><td><strong><?= e($m['booking']) ?></strong></td></tr><?php endif; ?>
    <?php if ($m['stay'] !== ''): ?><tr><td class="k">Stay</td><td><strong><?= e($m['stay']) ?></strong></td></tr><?php endif; ?>
    <?php if ($m['dates'] !== ''): ?><tr><td class="k">Dates</td><td><strong><?= e($m['dates']) ?></strong></td></tr><?php endif; ?>
  </table>
  <div class="sec">1 · Signatory</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e($m['name'] !== '' ? $m['name'] : '—') ?></strong></td></tr>
    <tr><td class="k">Role</td><td><?= e($m['role']) ?></td></tr>
    <tr><td class="k">Nationality</td><td><?= e($m['nationality'] !== '' ? $m['nationality'] : '—') ?></td></tr>
    <tr><td class="k">Document</td><td><?= e($m['doc_label']) ?></td></tr>
    <tr><td class="k">Document number</td><td><strong><?= e($m['doc_number'] !== '' ? $m['doc_number'] : '—') ?></strong></td></tr>
    <tr><td class="k">Expiry</td><td><?= e($d($m['expiry'])) ?></td></tr>
  </table>
  <div class="sec">2 · Terms agreed</div>
  <div class="terms"><?= e($m['terms']) ?></div>
  <div style="font-size:10.5px;color:#888;margin-top:8px">Waiver version <code><?= e($m['version']) ?></code> — the exact terms shown at signing.</div>
  <div class="sec">3 · Signature</div>
  <div style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap">
    <div class="sigbox"><img src="<?= e($m['signature']) ?>" alt="Signature"></div>
    <div style="font-size:12px;line-height:1.7"><div style="color:#666">Signed by</div><div style="font-weight:600"><?= e($m['signed_name']) ?></div>
      <div style="color:#666;margin-top:6px">&#9745; Confirmed &ldquo;I have read and agree&rdquo;</div></div>
  </div>
  <div class="sec">4 · Evidence of signing</div>
  <table>
    <?php if ($m['reference'] !== ''): ?><tr><td class="k">Reference ID</td><td><strong><?= e($m['reference']) ?></strong></td></tr><?php endif; ?>
    <tr><td class="k">Date &amp; time</td><td><strong><?= $m['signed_at'] !== '' ? e(date('j M Y, H:i:s', strtotime($m['signed_at']))) : '—' ?></strong></td></tr>
    <tr><td class="k">IP address</td><td><?= e($m['ip'] !== '' ? $m['ip'] : '—') ?></td></tr>
    <tr><td class="k">Device</td><td><?= e($m['device'] !== '' ? $m['device'] : '—') ?></td></tr>
    <tr><td class="k">Method</td><td><?= e($m['method']) ?></td></tr>
  </table>
  <?php if ($mode !== 'none'): ?>
  <div class="sec">5 · Document scan</div>
  <div class="scan">
    <?php if ($m['scan_removed_at'] !== '' && $m['scan_key'] === ''): ?>
      <p style="font-size:12px">ID photo removed on <?= e(date('j M Y', strtotime($m['scan_removed_at']))) ?>.</p>
    <?php elseif ($m['scan_key'] === ''): ?>
      <p style="font-size:12px">No scan on file.</p>
    <?php elseif ($mode === 'embed'): ?>
      <?php $b = (string)($opt['scan_bytes'] ?? ''); $mime = waiver_scan_mime($b); ?>
      <?php if ($b === ''): ?><p style="font-size:12px">The scan file could not be read from storage.</p>
      <?php elseif (str_starts_with($mime, 'image/')): ?><img src="data:<?= e($mime) ?>;base64,<?= base64_encode($b) ?>" alt="Document scan">
      <?php else: ?><p style="font-size:12px">PDF scan — see the file beside this record: <strong><?= e((string)($opt['scan_file'] ?? '')) ?></strong></p><?php endif; ?>
    <?php elseif ((string)($opt['scan_url'] ?? '') !== ''): ?>
      <?php if (preg_match('/\.pdf$/i', $m['scan_key'])): ?><p style="font-size:12px"><a href="<?= e((string)$opt['scan_url']) ?>" target="_blank" rel="noopener">Open PDF scan</a></p>
      <?php else: ?><img src="<?= e((string)$opt['scan_url']) ?>" alt="Document scan"><?php endif; ?>
    <?php endif; ?>
    <?php if ($m['scan_key'] !== '' && !$m['scan_frozen']): ?><div class="note">Scan on file now — this record was signed before scans were frozen at signing.</div><?php endif; ?>
  </div>
  <?php endif; ?>
  <div style="margin-top:26px;padding-top:12px;border-top:1px solid #e0e2e0;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:10px;color:#999;line-height:1.5">Electronic record generated by the Tribal Sand guest system<?= $m['reference'] !== '' ? ' · ' . e($m['reference']) : '' ?></div>
    <div class="stamp">ELECTRONICALLY&nbsp;SIGNED</div>
  </div>
</div>
</body></html>
<?php return (string) ob_get_clean();
}
