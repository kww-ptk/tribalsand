<?php
/**
 * Printable POS receipt (80 mm), /pos/receipt.php?sale=<id>[&print=1].
 * Readable by the till that may sell at the sale's outlet (pos_current()), or by
 * an admin who manages that outlet (for reprints from Admin → POS sales).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/pos-auth.php';

session_init();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$sale = pos_supported() ? pos_fetch_sale((int)($_GET['sale'] ?? 0)) : false;
$ok = false;
if ($sale) {
    $ctx = pos_current();
    if ($ctx && in_array((int)$sale['outlet_id'], $ctx['outlet_ids'], true)) $ok = true;
    elseif (!pos_current_terminal() && ($a = current_admin()) && in_array((int)$sale['outlet_id'], pos_manageable_outlet_ids($a), true)) $ok = true;
}
if (!$ok) { http_response_code(404); exit('Receipt not found.'); }

$outlet = pos_fetch_outlet((int)$sale['outlet_id']);
$cur    = (string)$sale['currency'];
$m      = fn($v) => pos_money((float)$v, $cur);
$hold   = $sale['hold_id'] ? pos_fetch_hold((int)$sale['hold_id']) : null;
$sig    = $sale['signed'] ? pos_sale_signature((int)$sale['id']) : null;
$vatAmt = (float)($sale['vat_amount'] ?? 0);
$vatInc = pos_bool($sale['vat_inclusive'] ?? true);
$vatPct = rtrim(rtrim((string)($sale['vat_pct'] ?? '0'), '0'), '.');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($sale['reference']) ?> — Tribal Sand</title>
<style>
  @page { size: 80mm auto; margin: 4mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f2efe8; font: 13px/1.4 ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; color: #111; }
  .r { width: 80mm; max-width: 100%; margin: 16px auto; background: #fff; padding: 14px 12px; }
  .c { text-align: center; }
  .brand { letter-spacing: .25em; font-weight: 700; font-size: 14px; }
  .muted { color: #555; font-size: 11.5px; }
  hr { border: 0; border-top: 1px dashed #999; margin: 10px 0; }
  .row { display: flex; justify-content: space-between; gap: 10px; }
  .row span:last-child { white-space: nowrap; text-align: right; }
  .tot { font-weight: 700; font-size: 15px; margin-top: 4px; }
  .sig { margin-top: 8px; border-top: 1px dashed #999; padding-top: 6px; }
  .sig img { display: block; width: 100%; max-height: 90px; object-fit: contain; }
  .void { border: 2px solid #c0392b; color: #c0392b; text-align: center; font-weight: 700; padding: 4px; margin: 8px 0; letter-spacing: .15em; }
  .actions { width: 80mm; max-width: 100%; margin: 0 auto 20px; display: flex; gap: 8px; }
  .actions button { flex: 1; height: 42px; border: 0; border-radius: 8px; background: #1E5C6B; color: #fff; font: 600 14px system-ui, sans-serif; cursor: pointer; }
  .actions button.sec { background: #fff; color: #1E5C6B; border: 1px solid #1E5C6B; }
  @media print { body { background: #fff; } .r { margin: 0; width: auto; padding: 0; } .actions { display: none; } }
</style>
</head>
<body>
<div class="r">
  <div class="c brand">TRIBAL SAND</div>
  <div class="c muted"><?= e($sale['outlet_name']) ?><?= !empty($outlet['venue_name']) ? ' · ' . e($outlet['venue_name']) : '' ?></div>
  <hr>
  <div class="row"><span>Receipt</span><span><?= e($sale['reference']) ?></span></div>
  <div class="row"><span>Date</span><span><?= e(date('j M Y, H:i', strtotime((string)$sale['created_at']))) ?></span></div>
  <div class="row"><span>Served by</span><span><?= e($sale['user_name'] ?? '—') ?></span></div>
  <div class="row"><span>Customer</span><span><?= e($sale['customer_name']) ?></span></div>
  <?php if ($hold): ?><div class="row"><span>Staying at</span><span><?= e(trim(($hold['venue_name'] ? $hold['venue_name'] . ' · ' : '') . ($hold['unit_name'] ?: $hold['room_name']))) ?></span></div><?php endif; ?>
  <?php if ($sale['status'] === 'voided'): ?><div class="void">VOIDED</div><?php if ($sale['void_reason']): ?><div class="muted c"><?= e($sale['void_reason']) ?></div><?php endif; ?><?php endif; ?>
  <hr>
  <?php foreach ($sale['lines'] as $l): ?>
  <div class="row"><span><?= (int)$l['qty'] ?> × <?= e($l['name']) ?></span><span><?= e($m($l['line_total'])) ?></span></div>
  <?php if ((int)$l['qty'] > 1): ?><div class="muted">&nbsp;&nbsp;@ <?= e($m($l['unit_price'])) ?></div><?php endif; ?>
  <?php endforeach; ?>
  <hr>
  <div class="row"><span>Subtotal</span><span><?= e($m($sale['subtotal'])) ?></span></div>
  <?php if ((float)$sale['service_charge'] > 0): ?><div class="row"><span>Service charge<?= (float)($sale['service_pct'] ?? 0) > 0 ? ' (' . e(rtrim(rtrim((string)$sale['service_pct'], '0'), '.')) . '%)' : '' ?></span><span><?= e($m($sale['service_charge'])) ?></span></div><?php endif; ?>
  <?php if ($vatAmt > 0 && !$vatInc): ?><div class="row"><span>VAT (<?= e($vatPct) ?>%)</span><span><?= e($m($vatAmt)) ?></span></div><?php endif; ?>
  <?php if ((float)($sale['tip_amount'] ?? 0) > 0): ?><div class="row"><span>Tip</span><span><?= e($m($sale['tip_amount'])) ?></span></div><?php endif; ?>
  <div class="row tot"><span>TOTAL</span><span><?= e($m($sale['total'])) ?></span></div>
  <?php if ($vatAmt > 0 && $vatInc): ?><div class="row muted"><span>Includes VAT <?= e($vatPct) ?>%</span><span><?= e($m($vatAmt)) ?></span></div><?php endif; ?>
  <hr>
  <div class="row"><span>Paid by</span><span><?= e(POS_PAYMENT_METHODS[$sale['payment_method']] ?? $sale['payment_method']) ?></span></div>
  <?php if (!empty($sale['payment_ref'])): ?><div class="row"><span>Ref</span><span><?= e($sale['payment_ref']) ?></span></div><?php endif; ?>
  <?php if ($sale['cash_tendered'] !== null): ?>
  <div class="row"><span>Cash</span><span><?= e($m($sale['cash_tendered'])) ?></span></div>
  <div class="row"><span>Change</span><span><?= e($m(max(0, pos_from_cents(pos_cents($sale['cash_tendered']) - pos_cents($sale['total']))))) ?></span></div>
  <?php endif; ?>
  <?php if ($sale['payment_method'] === 'room_charge'): ?>
  <?php if (!empty($sale['bill_currency']) && $sale['bill_currency'] !== $cur && $sale['bill_amount'] !== null): ?>
  <div class="row"><span>On room bill</span><span><?= e(pos_money((float)$sale['bill_amount'], (string)$sale['bill_currency'])) ?></span></div>
  <div class="muted">Rate: 1 <?= e($sale['bill_currency']) ?> = <?= e(rtrim(rtrim(number_format((float)$sale['fx_rate'], 4, '.', ''), '0'), '.')) ?> <?= e($cur) ?></div>
  <?php endif; ?>
  <div class="muted" style="margin-top:6px">Charged to the room bill — settled at check-out.</div>
  <?php if ($sig): ?><div class="sig"><img src="<?= e($sig['signature']) ?>" alt="Guest signature"><div class="muted c"><?= e($sig['signer_name'] ?? '') ?> · signed <?= e(date('j M Y, H:i', strtotime((string)$sig['signed_at']))) ?></div></div><?php endif; ?>
  <?php endif; ?>
  <hr>
  <div class="c muted">Asante sana — thank you!</div>
</div>
<div class="actions"><button type="button" onclick="window.print()">Print</button><button type="button" class="sec" onclick="window.close()">Close</button></div>
<?php if (!empty($_GET['print'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script><?php endif; ?>
</body>
</html>
