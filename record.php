<?php
/**
 * Printable signed-consent record for one guest — the electronic registration
 * evidence document. Served from a clean, guest-facing URL (NOT /admin/) so the
 * generated PDF never carries an internal backend route; the record is keyed by
 * a controlled reference ID (?r=TSR-…). Auth is unchanged: the guest's own token
 * (lead ref = any guest on the hold; co-guest g = own row only), else an admin
 * session. Legacy ?hold=&guest= params still resolve for existing links.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/checkin.php';

$reference = trim((string)($_GET['r'] ?? ''));
$holdId    = (int)($_GET['hold'] ?? 0);
$guestId   = (int)($_GET['guest'] ?? 0);
$ref       = trim((string)($_GET['ref'] ?? ''));
$gTok      = trim((string)($_GET['g'] ?? ''));

if (!checkin_signature_supported()) { http_response_code(503); exit('Run the add_checkin_signature.sql migration first.'); }

// A controlled reference resolves to its hold/guest when raw ids were not passed.
if ($reference !== '' && (!$holdId || !$guestId) && checkin_reference_supported()) {
    $r = db_query('SELECT hold_id, id FROM checkin_guests WHERE waiver_reference = :r', [':r'=>$reference])->fetch();
    if ($r) { $holdId = (int)$r['hold_id']; $guestId = (int)$r['id']; }
}

// Auth: guest token (lead ref = any guest on the hold; co-guest g = own row only), else admin session.
$viewer = 'guest';
if ($ref !== '' && verify_guest_ref($ref) === $holdId) {
    // lead — PII Policy A: may view any guest on their hold
} elseif ($gTok !== '' && ($gc = verify_guest_pass_token($gTok)) !== false && $gc[0] === $holdId && $gc[1] === $guestId) {
    // co-guest — own record only
} else {
    require_login();
    if (!can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }
    $viewer = 'admin';
}
if (!$holdId || !$guestId) { http_response_code(400); exit('Bad request.'); }

$hold  = fetch_hold_for_guest($holdId);
$guest = db_query('SELECT * FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetch();
if (!$hold || !$guest)                     { http_response_code(404); exit('Record not found.'); }
if (!checkin_guest_waiver_signed($guest))  { http_response_code(404); exit('This guest has not signed the waiver yet.'); }

// Controlled identifier for this record (minted on first view for older records).
$reference = checkin_ensure_reference($guest);

audit_log('checkin.consent_view', 'hold', $holdId, 'guest ' . $guestId);
checkin_log_signing_step('record_viewed', [
    'reference'      => $reference,
    'hold_id'        => $holdId,
    'guest_id'       => $guestId,
    'waiver_version' => (string)($guest['waiver_version'] ?? ''),
    'personal_link'  => (string)($guest['waiver_signed_link'] ?? ''),
    'method'         => $viewer,
    'detail'         => 'Record viewed',
]);

// Identity is rendered from the frozen at-signing SNAPSHOT so the record can never
// change after signing. Live columns are used only as a fallback for legacy records
// signed before the snapshot existed (those are additionally protected because any
// later identity edit voids the signature via checkin_void_signature_if_identity_changed()).
$snap     = checkin_identity_snapshot_supported();
$dispName = ($snap && trim((string)($guest['waiver_name_snapshot'] ?? '')) !== '')
          ? (string)$guest['waiver_name_snapshot'] : (string)($guest['passport_name'] ?? '');
$dispNat  = ($snap && trim((string)($guest['waiver_nationality_snapshot'] ?? '')) !== '')
          ? (string)$guest['waiver_nationality_snapshot'] : (string)($guest['nationality'] ?? '');
$ppNum    = ($snap && trim((string)($guest['waiver_passport_snapshot'] ?? '')) !== '')
          ? trim((string)$guest['waiver_passport_snapshot']) : trim((string)($guest['passport_number'] ?? ''));
$ppMask = $ppNum === '' ? '—' : (strlen($ppNum) <= 2 ? str_repeat('•', strlen($ppNum))
          : substr($ppNum, 0, 1) . str_repeat('•', max(1, strlen($ppNum) - 2)) . substr($ppNum, -1));
$stayLoc     = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
$terms       = (string)($guest['waiver_terms_snapshot'] ?? '');
$signedAt    = (string)($guest['waiver_signed_at'] ?? '');
$methodLabel = ((string)($guest['waiver_signed_method'] ?? '') === 'reception')
             ? 'On a Tribal Sand device, in person' : 'Own device, via personal check-in link';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Signed consent · <?= e($dispName !== '' ? $dispName : 'Guest') ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .subh{font-size:10px;letter-spacing:2px;opacity:.75;text-transform:uppercase;margin-top:3px}
  .bd{padding:26px 30px 30px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:22px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:12.5px}
  td{padding:4px 0;vertical-align:top}
  td.k{color:#666;width:150px}
  .terms{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;white-space:pre-wrap}
  .sigbox{border:1px solid #d8dbd8;border-radius:4px;padding:8px;max-width:280px;background:#fff}
  .sigbox img{display:block;max-width:100%;height:auto}
  .stamp{border:1.5px solid #123c30;color:#123c30;font-size:9px;letter-spacing:1.5px;font-weight:700;padding:6px 10px;border-radius:3px;transform:rotate(-3deg)}
  code{background:#eee;padding:1px 5px;border-radius:3px}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>
<div class="head">
  <div><div class="brand">TRIBAL SAND</div><div class="subh">Kilifi · Kenya Coast</div></div>
  <div style="text-align:right;font-size:11px;opacity:.85;line-height:1.5">Guest Indemnity &amp; Waiver<br><strong style="opacity:1">Signed Consent Record</strong></div>
</div>
<div class="bd">
  <table>
    <?php if ($reference !== ''): ?><tr><td class="k">Record reference</td><td><strong><?= e($reference) ?></strong></td></tr><?php endif; ?>
    <tr><td class="k">Booking reference</td><td><strong>TS-<?= (int)$holdId ?><?= !empty($hold['access_code']) ? ' · code ' . e((string)$hold['access_code']) : '' ?></strong></td></tr>
    <tr><td class="k">Stay</td><td><strong><?= e($stayLoc) ?></strong></td></tr>
    <tr><td class="k">Dates</td><td><strong><?= e(date('D j M Y', strtotime((string)$hold['check_in']))) ?> &rarr; <?= e(date('D j M Y', strtotime((string)$hold['check_out']))) ?></strong></td></tr>
  </table>

  <div class="sec">1 · Signatory</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e($dispName !== '' ? $dispName : '—') ?></strong></td></tr>
    <tr><td class="k">Role</td><td><?= !empty($guest['is_lead']) ? 'Lead guest' : 'Adult guest' ?></td></tr>
    <tr><td class="k">Nationality</td><td><?= e($dispNat !== '' ? $dispNat : '—') ?></td></tr>
    <tr><td class="k">Passport</td><td>No. <?= e($ppMask) ?><?= !empty($guest['passport_file_key']) ? ' · scan on file' : '' ?></td></tr>
  </table>

  <div class="sec">2 · Terms agreed</div>
  <div class="terms"><?= e($terms) ?></div>
  <div style="font-size:10.5px;color:#888;margin-top:8px">Waiver version <code><?= e((string)($guest['waiver_version'] ?? '')) ?></code> — the exact terms shown to the guest at signing.</div>

  <div class="sec">3 · Signature</div>
  <div style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap">
    <div class="sigbox"><img src="<?= e((string)$guest['waiver_signature']) ?>" alt="Signature"></div>
    <div style="font-size:12px;line-height:1.7">
      <div style="color:#666">Signed by</div>
      <div style="font-weight:600"><?= e((string)$guest['waiver_signed_name']) ?></div>
      <div style="color:#666;margin-top:6px">&#9745; Confirmed &ldquo;I have read and agree&rdquo;</div>
    </div>
  </div>

  <div class="sec">4 · Evidence of signing</div>
  <table>
    <?php if ($reference !== ''): ?><tr><td class="k">Reference ID</td><td><strong><?= e($reference) ?></strong></td></tr><?php endif; ?>
    <tr><td class="k">Date &amp; time</td><td><strong><?= $signedAt ? e(date('j M Y, H:i:s', strtotime($signedAt))) : '—' ?></strong></td></tr>
    <tr><td class="k">IP address</td><td><?= e((string)($guest['waiver_signed_ip'] ?: '—')) ?></td></tr>
    <tr><td class="k">Device</td><td><?= e((string)($guest['waiver_signed_user_agent'] ?: '—')) ?></td></tr>
    <tr><td class="k">Method</td><td><?= e($methodLabel) ?></td></tr>
  </table>

  <div style="margin-top:26px;padding-top:12px;border-top:1px solid #e0e2e0;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:10px;color:#999;line-height:1.5">Electronic record generated by the Tribal Sand guest system<?= $reference !== '' ? ' · ' . e($reference) : '' ?></div>
    <div class="stamp">ELECTRONICALLY&nbsp;SIGNED</div>
  </div>
</div>
</body></html>
