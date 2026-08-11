<?php /** Guest bill view (shown only on shared bookings). Read-only, itemized by guest name. Expects $hold, $ref. */ ?>
<?php
$__hid   = (int)$hold['id'];
$__lines = fetch_bill_lines($__hid);
$__items = fetch_bill_items($__hid);
$__total = bill_total($__hid);
$__cur   = setting('site_currency', 'USD');
// An unnamed lead falls back to the booking name rather than showing nothing.
$__bookName = (string)($hold['guest_name'] ?? '');
$__who = function (array $r, string $nameKey, string $leadKey) use ($__bookName): string {
    $isLead = !empty($r[$leadKey]);
    $n = trim((string)($r[$nameKey] ?? ''));
    if ($n === '' && !$isLead) return '';
    return attributed_display_name($n, $isLead, $__bookName) . ($isLead ? ' (lead)' : '');
};
?>
<h2 class="pa-h2">Your bill</h2>
<p class="pa-sub">Charges for your stay, itemized by who requested them.</p>
<?php if (!$__lines && !$__items): ?>
<div class="pa-card"><div class="pa-card__body"><p class="pa-sub" style="margin:0">No extra charges yet.</p></div></div>
<?php else: ?>
<div class="pa-card"><div class="pa-card__body" style="padding:0">
  <?php foreach ($__lines as $l): $__w = $__who($l, 'requested_by_name', 'requested_by_is_lead'); ?>
  <div style="display:flex;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--pa-line)">
    <div><div><?= e(addon_label($l)) ?></div><?php if ($__w !== ''): ?><div style="font-size:12px;color:var(--pa-muted);margin-top:2px"><?= e($__w) ?></div><?php endif; ?></div>
    <div style="white-space:nowrap;font-variant-numeric:tabular-nums"><?= (isset($l['price_amount']) && (float)$l['price_amount'] > 0) ? e(format_price((float)$l['price_amount'], $__cur)) : '<span style="color:var(--pa-muted)">—</span>' ?></div>
  </div>
  <?php endforeach; ?>
  <?php foreach ($__items as $it): $__w = $__who($it, 'guest_name', 'guest_is_lead'); ?>
  <div style="display:flex;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--pa-line)">
    <div><div><?= e($it['label']) ?></div><?php if ($__w !== ''): ?><div style="font-size:12px;color:var(--pa-muted);margin-top:2px"><?= e($__w) ?></div><?php endif; ?></div>
    <div style="white-space:nowrap;font-variant-numeric:tabular-nums"><?= e(format_price((float)$it['amount'], $__cur)) ?></div>
  </div>
  <?php endforeach; ?>
  <div style="display:flex;justify-content:space-between;padding:14px 16px;font-weight:700"><span>Total</span><span style="font-variant-numeric:tabular-nums"><?= e(format_price($__total, $__cur)) ?></span></div>
</div></div>
<?php endif; ?>
