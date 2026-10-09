<?php
declare(strict_types=1);
/**
 * The branded A4 quotation — a STANDALONE page (no admin chrome) the browser
 * prints / saves as PDF. Figures are never taken from the client:
 *   GET  ?quote=<id>  a quote saved on an enquiry, printed from its stored
 *                     snapshot (the prices it was quoted at) — 404 unless the
 *                     enquiry is in the account's scope.
 *   POST sel=<json>   a live print from the Quote builder page: the selection is
 *                     re-priced with qb_price_selection(admin_venue_ids()); the
 *                     quote number is Q-YYYYMMDD-HHMM. CSRF-checked.
 * Same audience as the builder: require_bookings(). Model: qb_quote_document().
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-docs.php';

require_bookings();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function qp_fail(int $code, string $msg): never {
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><title>Quotation</title>'
       . '<p style="font:15px system-ui,sans-serif;padding:40px;color:#6B6050">' . e($msg) . '</p>';
    exit;
}

$doc = null;
$autoPrint = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $sel = json_decode((string)($_POST['sel'] ?? ''), true);
    if (!is_array($sel)) qp_fail(400, 'Nothing to print — build a quote first.');
    try {
        $priced = qb_price_selection($sel, admin_venue_ids());
    } catch (Throwable $e) {
        error_log('quote-print pricing failed: ' . $e->getMessage());
        qp_fail(500, 'Could not price this quote. Try again.');
    }
    if (!qb_quote_has_priced_lines($priced)) qp_fail(422, 'Nothing is priced yet.');
    $autoPrint = true;   // a live print from the builder opens straight to the print dialog
    $doc = qb_quote_document($priced, ['ref' => qb_quote_ref(null, null, date('Y-m-d H:i:s')), 'issued' => date('Y-m-d')], qb_quote_terms());
} else {
    $row = qb_quote_fetch((int)($_GET['quote'] ?? 0));
    if (!$row || !submission_in_scope((int)$row['submission_id'])) qp_fail(404, 'Quote not found.');
    $snap = $row['snapshot'];
    $meta = (array)($snap['meta'] ?? []);
    // A saved quote prints the terms it was issued under (stored at save time).
    $terms = trim((string)($meta['terms'] ?? '')) !== '' ? (string)$meta['terms'] : qb_quote_terms();
    $doc = qb_quote_document((array)($snap['quote'] ?? []), $meta, $terms);
    $autoPrint = ($_GET['print'] ?? '') === '1';   // "View PDF" from the Quotes list just shows the page
}

$pf = $doc['prepared_for'];
$stayLine = implode(' · ', array_filter([$pf['stay'], $pf['nights']], fn($x) => $x !== ''));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e('Quotation ' . $doc['ref'] . ' — Tribal Sand') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--brand:#1E5C6B;--brand-dk:#102F3A;--accent:#B8965A;--sand:#FAF6EE;--text:#141412;--muted:#6B6050;--line:#e7ded7}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:#e9e4dc;color:var(--text);font:10.5pt/1.5 'Jost',system-ui,-apple-system,'Segoe UI',sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.qp-bar{position:sticky;top:0;z-index:5;display:flex;gap:10px;justify-content:center;padding:12px 16px;background:var(--brand-dk)}
.qp-bar button{font:500 14px 'Jost',system-ui,sans-serif;border-radius:999px;padding:9px 20px;cursor:pointer;border:1px solid var(--accent)}
.qp-print{background:var(--accent);color:#fff}
.qp-close{background:transparent;color:#fff}
.qp-sheet{width:210mm;max-width:100%;margin:24px auto;background:#fff;box-shadow:0 6px 30px rgba(16,47,58,.18)}
.qp-head{display:flex;align-items:center;justify-content:space-between;gap:20px;background:var(--brand);color:#fff;padding:9mm 12mm}
.qp-head img{height:15mm;width:auto;display:block}
.qp-head__title{text-align:right}
.qp-head h1{font:500 26pt/1 'Cormorant Garamond',Georgia,serif;margin:0;letter-spacing:.01em}
.qp-head__meta{margin-top:5px;font-size:9pt;color:#e9dcc3}
.qp-head__meta b{font-weight:500;color:#fff}
.qp-body{padding:9mm 12mm 8mm}
.qp-eyebrow{font-size:7.5pt;letter-spacing:.16em;text-transform:uppercase;color:var(--accent);font-weight:500;margin:0 0 4px}
.qp-parties{display:grid;grid-template-columns:1fr 1fr;gap:8mm;padding-bottom:6mm;border-bottom:1px solid var(--line)}
.qp-parties--one{grid-template-columns:1fr}
.qp-name{font:500 17pt/1.15 'Cormorant Garamond',Georgia,serif;margin:0 0 3px}
.qp-stay{margin:0;color:var(--muted)}
.qp-req{margin:0;padding:0;list-style:none;color:var(--muted)}
.qp-req li{display:flex;gap:8px}
.qp-req span{color:var(--text);min-width:58px}
.qp-sec{margin-top:6mm}
.qp-sec h2{font:500 13pt/1.2 'Cormorant Garamond',Georgia,serif;margin:0 0 2mm;color:var(--brand)}
table{width:100%;border-collapse:collapse}
th{font-size:7.5pt;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:500;text-align:left;padding:0 0 5px;border-bottom:1px solid var(--accent)}
td{padding:7px 0;border-bottom:1px solid var(--line);vertical-align:top}
tr{break-inside:avoid}
th+th,td+td{padding-left:10px}
.num{text-align:right;white-space:nowrap}
.qp-mix{display:block;font-size:8.5pt;color:var(--muted)}
.qp-sub td{border-bottom:0;color:var(--muted);padding-top:6px}
.qp-disc td{color:#8a5a2b}
.qp-total{display:flex;justify-content:space-between;align-items:baseline;margin-top:6mm;padding:4mm 5mm;background:var(--sand);border-top:2px solid var(--accent)}
.qp-total span{font-size:8pt;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.qp-total strong{font:600 20pt/1 'Cormorant Garamond',Georgia,serif;color:var(--brand-dk)}
.qp-fx{margin:2mm 0 0;font-size:8.5pt;color:var(--muted)}
.qp-terms{margin-top:6mm;font-size:8.5pt;color:var(--muted)}
.qp-terms p{margin:0}
.qp-foot{display:flex;flex-wrap:wrap;justify-content:center;gap:4px 14px;margin:0;padding:4mm 12mm;border-top:1px solid var(--line);font-size:8.5pt;color:var(--brand)}
.qp-foot span+span::before{content:'·';color:var(--accent);margin-right:14px}
@page{size:A4;margin:14mm}
@media print{
  body{background:#fff}
  .qp-bar{display:none}
  .qp-sheet{width:auto;margin:0;box-shadow:none}
}
@media (max-width:640px){
  .qp-sheet{margin:0}
  .qp-head{flex-direction:column;align-items:flex-start;padding:20px 16px}
  .qp-head__title{text-align:left}
  .qp-body{padding:20px 16px}
  .qp-parties{grid-template-columns:1fr}
  .qp-foot{padding:14px 16px}
}
</style>
</head>
<body>
<div class="qp-bar">
  <button type="button" class="qp-print" onclick="window.print()">Print / Save as PDF</button>
  <button type="button" class="qp-close" onclick="window.close(); if (!window.closed) history.back();">Close</button>
</div>

<main class="qp-sheet">
  <header class="qp-head">
    <img src="<?= e(asset_url('images/whitelogo11.png')) ?>" alt="Tribal Sand">
    <div class="qp-head__title">
      <h1><?= e($doc['title']) ?></h1>
      <div class="qp-head__meta"><b><?= e($doc['ref']) ?></b> · Issued <?= e($doc['issued']) ?></div>
    </div>
  </header>

  <div class="qp-body">
    <section class="qp-parties<?= $doc['request'] ? '' : ' qp-parties--one' ?>">
      <div>
        <p class="qp-eyebrow">Prepared for</p>
        <?php if ($pf['name'] !== ''): ?><p class="qp-name"><?= e($pf['name']) ?></p><?php endif; ?>
        <?php if ($stayLine !== ''): ?><p class="qp-stay"><?= e($stayLine) ?></p><?php endif; ?>
        <?php if ($pf['party'] !== ''): ?><p class="qp-stay"><?= e($pf['party']) ?></p><?php endif; ?>
      </div>
      <?php if ($doc['request']): ?>
      <div>
        <p class="qp-eyebrow">Your request</p>
        <ul class="qp-req">
          <?php foreach ($doc['request'] as $r): ?>
          <li><span><?= e((string)$r['label']) ?></span><?= e((string)$r['value']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($doc['rooms']): ?>
    <section class="qp-sec">
      <h2>Accommodation</h2>
      <table>
        <thead><tr><th>Property</th><th>Room</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($doc['rooms'] as $r): ?>
          <tr>
            <td><?= e($r['property']) ?></td>
            <td><?= e($r['room']) ?><?php if ($r['mix'] !== ''): ?><span class="qp-mix"><?= e($r['mix']) ?></span><?php endif; ?></td>
            <td class="num"><?= (int)$r['qty'] ?></td>
            <td class="num"><?= e($r['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (count($doc['rooms']) > 1 || $doc['discount']): ?>
          <tr class="qp-sub"><td colspan="3">Accommodation subtotal</td><td class="num"><?= e($doc['accommodation']) ?></td></tr>
          <?php endif; ?>
          <?php if ($doc['discount']): ?>
          <tr class="qp-disc"><td colspan="3"><?= e($doc['discount']['label']) ?></td><td class="num"><?= e($doc['discount']['amount']) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>

    <?php if ($doc['extras']): ?>
    <section class="qp-sec">
      <h2>Extras</h2>
      <table>
        <thead><tr><th>Item</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($doc['extras'] as $x): ?>
          <tr><td><?= e($x['label']) ?></td><td class="num"><?= e($x['amount']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>

    <div class="qp-total"><span>Total<?= !empty($doc['fee_note']) ? '*' : '' ?> (<?= e($doc['currency']) ?>)</span><strong><?= e($doc['total']) ?></strong></div>
    <?php if (!empty($doc['fee_note'])): ?><p class="qp-fx"><?= e($doc['fee_note']) ?></p><?php endif; ?>
    <?php if ($doc['fx_note']): ?><p class="qp-fx"><?= e($doc['fx_note']) ?></p><?php endif; ?>

    <section class="qp-terms">
      <p class="qp-eyebrow">Terms</p>
      <p><?= nl2br(e($doc['terms'])) ?></p>
    </section>
  </div>

  <footer class="qp-foot">
    <span><?= e($doc['contact']['email']) ?></span>
    <span><?= e($doc['contact']['phone']) ?> (phone / WhatsApp)</span>
    <span><?= e($doc['contact']['web']) ?></span>
  </footer>
</main>

<?php if ($autoPrint): ?>
<script>
  // Open the print dialog once the page (fonts + logo) has loaded (only when asked: ?print=1 or a live print).
  window.addEventListener('load', function () {
    var go = function () { setTimeout(function () { window.print(); }, 150); };
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(go, go); else go();
  });
</script>
<?php endif; ?>
</body>
</html>
