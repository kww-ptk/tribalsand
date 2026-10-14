<?php
/**
 * Accommodation confirmation for one guest's Kenya eTA application.
 * Guest-facing URL (never /admin — the browser prints the URL in the PDF footer).
 * Auth like record.php: the lead's ref (any guest on the booking), a co-guest's
 * own g token (their own letter only), else a staff session with guest-document
 * access. Rules + content: includes/eta-letter.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/checkin.php';
require_once __DIR__ . '/includes/eta-letter.php';

$holdId  = (int)($_GET['hold'] ?? 0);
$guestId = (int)($_GET['guest'] ?? 0);
$ref     = trim((string)($_GET['ref'] ?? ''));
$gTok    = trim((string)($_GET['g'] ?? ''));
if (!$holdId || !$guestId) { http_response_code(400); exit('Bad request.'); }

$viewer = 'guest';
if ($ref !== '' && verify_guest_ref($ref) === $holdId) {
    // lead — may open any guest's letter on their booking
} elseif ($gTok !== '' && ($gc = verify_guest_pass_token($gTok)) !== false && $gc[0] === $holdId && $gc[1] === $guestId) {
    // co-guest — own letter only
} else {
    require_login();
    if (!can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }
    $viewer = 'admin';
}

$hold  = fetch_hold_for_guest($holdId);
$guest = $hold ? (db_query('SELECT * FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $guestId, ':h' => $holdId])->fetch() ?: null) : null;
if (!$hold || !$guest) { http_response_code(404); exit('Not found.'); }

$status = eta_letter_status($hold, $guest);
header('X-Robots-Tag: noindex, nofollow');
if (!$status['ok']) {
    http_response_code(409);
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>eTA letter</title><style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f1eb;color:#1a1a1a;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px}
.c{background:#fff;border:1px solid #e5e0d6;border-radius:14px;padding:26px 24px;max-width:420px;text-align:center}h1{font-size:19px;font-weight:600;margin:0 0 8px}p{color:#6b7280;line-height:1.55;margin:0}</style></head>
<body><div class="c"><h1>eTA letter not available yet</h1><p><?= e($status['reason']) ?></p></div></body></html><?php
    exit;
}

$venueId = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
$hold['venue_address'] = eta_letter_address($venueId);
$m = eta_letter_model($hold, $guest, eta_letter_issuer($venueId), date('Y-m-d'));
audit_log('checkin.eta_letter', 'hold', $holdId, 'guest ' . $guestId . ' (' . $viewer . ')');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Accommodation confirmation · <?= e($m['guest_name']) ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .subh{font-size:10px;letter-spacing:2px;opacity:.75;text-transform:uppercase;margin-top:3px}
  .bd{padding:26px 30px 30px;font-size:13px;line-height:1.6}
  h1{font-size:19px;margin:0 0 2px;color:#123c30}
  .lede{color:#555;margin:0 0 18px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:20px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  td{padding:4px 0;vertical-align:top}
  td.k{color:#666;width:170px}
  .note{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;margin-top:20px}
  .stamp{border:1.5px solid #123c30;color:#123c30;font-size:9px;letter-spacing:1.5px;font-weight:700;padding:6px 10px;border-radius:3px;transform:rotate(-3deg);white-space:nowrap}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}}
  @media (max-width:560px){body{margin:0}.head,.bd{padding-left:18px;padding-right:18px}td.k{width:120px}.printbtn{top:auto;bottom:14px}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>
<div class="head">
  <div><div class="brand">TRIBAL SAND</div><div class="subh">Kilifi · Watamu · Kenya Coast</div></div>
  <div style="text-align:right;font-size:11px;opacity:.85;line-height:1.5">Accommodation confirmation<br><strong style="opacity:1">For the Kenya eTA</strong></div>
</div>
<div class="bd">
  <h1>Accommodation Confirmation</h1>
  <p class="lede">Issued <?= e($m['issued_label']) ?> · Booking <?= e($m['booking_ref']) ?><?= $m['access_code'] !== '' ? ' · code ' . e($m['access_code']) : '' ?></p>

  <p>To whom it may concern,</p>
  <p>This letter confirms that the guest named below holds a <strong>confirmed accommodation booking</strong> with us in Kenya for the dates shown.</p>

  <div class="sec">Guest</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e($m['guest_name']) ?></strong></td></tr>
    <tr><td class="k">Passport number</td><td><strong><?= e($m['passport_number']) ?></strong></td></tr>
    <tr><td class="k">Nationality</td><td><?= e($m['nationality']) ?></td></tr>
    <tr><td class="k">Travelling party</td><td><?= e($m['party']) ?><?= $m['is_lead'] ? ' · lead guest' : '' ?></td></tr>
  </table>

  <div class="sec">Accommodation</div>
  <table>
    <tr><td class="k">Property</td><td><strong><?= e($m['property']) ?></strong><?= $m['room'] !== '' && $m['room'] !== $m['property'] ? ' · ' . e($m['room']) : '' ?></td></tr>
    <tr><td class="k">Address</td><td><?= $m['address'] !== '' ? e($m['address']) . '<br>' : '' ?>Kenya</td></tr>
    <tr><td class="k">Check-in</td><td><strong><?= e($m['check_in_label']) ?></strong></td></tr>
    <tr><td class="k">Check-out</td><td><strong><?= e($m['check_out_label']) ?></strong></td></tr>
    <tr><td class="k">Length of stay</td><td><?= (int)$m['nights'] ?> night<?= $m['nights'] === 1 ? '' : 's' ?></td></tr>
    <tr><td class="k">Booking status</td><td><strong>Confirmed</strong></td></tr>
  </table>

  <div class="sec">Issued by</div>
  <table>
    <tr><td class="k">Company</td><td><strong><?= e($m['issuer_name']) ?></strong></td></tr>
    <?php if ($m['issuer_pin'] !== ''): ?><tr><td class="k">KRA PIN</td><td><?= e($m['issuer_pin']) ?></td></tr><?php endif; ?>
    <?php if ($m['issuer_address'] !== ''): ?><tr><td class="k">Address</td><td><?= nl2br(e($m['issuer_address'])) ?></td></tr><?php endif; ?>
    <tr><td class="k">Contact</td><td><?= e(ETA_CONTACT_EMAIL) ?> · <?= e(ETA_CONTACT_PHONE) ?></td></tr>
  </table>

  <div class="note">
    <strong>About this letter.</strong> This confirmation is provided so the guest can show proof of accommodation when applying for the
    Kenya Electronic Travel Authorisation (eTA). It is not a visa or an official government document. Each traveller applies for
    their own eTA on the official site, <strong><?= e(preg_replace('#^https?://#', '', ETA_OFFICIAL_SITE)) ?></strong>, before travelling.
    If the booking dates change, download a new letter from the booking page.
  </div>

  <div style="margin-top:26px;padding-top:12px;border-top:1px solid #e0e2e0;display:flex;justify-content:space-between;align-items:center;gap:12px">
    <div style="font-size:10px;color:#999;line-height:1.5">Generated by the Tribal Sand guest system · <?= e($m['booking_ref']) ?> · <?= e($m['issued_label']) ?></div>
    <div class="stamp">CONFIRMED&nbsp;BOOKING</div>
  </div>
</div>
</body></html>
