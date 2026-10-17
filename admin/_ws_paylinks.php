<?php
/**
 * Workspace Bill tab — Paystack payment links for this booking: a "Payment link"
 * button (opens the Payments generator with the booking and its balance filled in)
 * and the links already sent, with their status. Expects $holdId.
 * Rules: includes/pay-links.php.
 */
require_once __DIR__ . '/../includes/pay-links.php';
if (!paystack_supported() || !pay_links_allowed()) return;
$__pl = pay_link_list(['hold_id' => $holdId], null, 20);
// A guest who paid and closed the page: ask Paystack about this booking's open links now.
foreach ($__pl as $__i => $__l) {
    if ($__l['status'] === 'waiting' && !empty($__l['started_at']) && $__l['mode'] !== 'practice') {
        try { $__f = pay_link_check((int)$__l['id']); if ($__f) $__pl[$__i] = array_merge($__l, $__f); } catch (Throwable $e) {}
    }
}
$__plBadge = ['waiting' => 'badge--orange', 'paid' => 'badge--green', 'expired' => 'badge--grey', 'cancelled' => 'badge--grey'];
?>
<div class="card wpl" id="paylinks">
  <div class="card__head wpl__head"><span class="card__title">Payment links <span class="text-muted wpl__sub">· Paystack</span></span>
    <a href="/admin/payments.php?hold=<?= (int)$holdId ?>&amp;purpose=bill" class="btn-primary btn-sm"><?= admin_icon('link', 14) ?> Payment link</a></div>
  <?php if (!$__pl): ?>
    <p class="text-muted wpl__empty">Send the guest a link to pay the balance, a deposit or one extra by M-Pesa or card. When they pay, it is recorded on this bill by itself.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Sent</th><th>For</th><th class="num">Amount</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($__pl as $__l): $__st = pay_link_effective_status($__l); ?>
      <tr>
        <td><?= e(date('j M', strtotime((string)$__l['created_at']))) ?><div class="text-muted wpl__small"><?= e(pay_link_number((int)$__l['id'])) ?></div></td>
        <td><?= e((string)$__l['description']) ?><?php if (trim((string)$__l['note']) !== ''): ?><div class="text-muted wpl__small"><?= e((string)$__l['note']) ?></div><?php endif; ?></td>
        <td class="num"><?= e(pay_link_money($__l['amount'], (string)$__l['currency'])) ?></td>
        <td><span class="badge <?= $__plBadge[$__st] ?? 'badge--grey' ?>"><?= e(PAY_LINK_STATUSES[$__st] ?? $__st) ?><?= $__st === 'paid' && $__l['paid_channel'] !== '' ? ' · ' . e(str_replace('_', ' ', (string)$__l['paid_channel'])) : '' ?></span>
          <?php if ($__st === 'waiting'): ?> <a href="/admin/payments.php?created=<?= (int)$__l['id'] ?>" class="wpl__small">share</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<style>
.wpl{margin-bottom:16px}
.wpl__head{flex-wrap:wrap;gap:8px}
.wpl__sub{font-weight:400;font-size:13px}
.wpl__empty{margin:0;padding:14px 18px;font-size:13px}
.wpl__small{font-size:12px}
.wpl .num{text-align:right;white-space:nowrap}
</style>
