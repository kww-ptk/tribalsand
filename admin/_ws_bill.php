<?php /** Workspace Bill tab. Expects $hold, $holdId. */ ?>
<?php
$__lines = fetch_bill_lines($holdId);
$__items = fetch_bill_items($holdId);
$__total = bill_total($holdId);
$__cur   = setting('site_currency', 'USD');
/** number for a value input: 500.00 → "500", 12.50 → "12.5", null → "" */
$__numval = fn($v) => ($v === null || $v === '') ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head">
    <span class="card__title">Charges from requests</span>
    <a href="/admin/bill-print.php?hold=<?= $holdId ?>" target="_blank" class="btn-sm btn-primary">Print bill →</a>
  </div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Item</th><th>Date</th><th style="text-align:right">Price</th></tr></thead>
      <tbody>
        <?php if (!$__lines): ?><tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--muted)">No confirmed charges yet.</td></tr><?php endif; ?>
        <?php foreach ($__lines as $l):
          $__priced = isset($l['price_amount']) && $l['price_amount'] !== null && (float)$l['price_amount'] > 0;
        ?>
        <tr<?= $__priced ? '' : ' style="background:#fff7ed"' ?>>
          <td><?= e(addon_label($l)) ?><?php if (($l['kind'] ?? '') === 'tour' && !empty($l['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$l['pax'] ?> pax</span><?php endif; ?><?php if (!$__priced): ?> <span class="badge badge--orange">set a price</span><?php endif; ?></td>
          <td class="text-muted" style="font-size:12px"><?= !empty($l['scheduled_for']) ? e(date('j M', strtotime((string)$l['scheduled_for']))) : '—' ?></td>
          <td style="text-align:right">
            <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=bill" style="display:inline-flex;gap:4px;align-items:center;justify-content:flex-end">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="bill_set_price">
              <input type="hidden" name="hold_id" value="<?= $holdId ?>">
              <input type="hidden" name="addon_id" value="<?= (int)$l['id'] ?>">
              <span class="text-muted" style="font-size:12px"><?= e($__cur) ?></span>
              <input type="number" name="price_amount" step="0.01" min="0" value="<?= e($__numval($l['price_amount'] ?? '')) ?>" style="width:90px;padding:5px 7px;text-align:right">
              <button type="submit" class="btn-sm btn-outline">Save</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Other charges</span></div>
  <div class="card__body">
    <?php foreach ($__items as $it): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border,#e7ded7)">
      <span style="flex:1"><?= e($it['label']) ?></span>
      <span style="white-space:nowrap"><?= e(format_price((float)$it['amount'], $__cur)) ?></span>
      <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=bill" style="margin:0" onsubmit="return confirm('Remove this charge?')"><?= csrf_field() ?><input type="hidden" name="action" value="bill_del"><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button type="submit" class="btn-sm" style="color:#c0392b;border:1px solid #c0392b;background:#fff;border-radius:4px">Remove</button></form>
    </div>
    <?php endforeach; ?>
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=bill" style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bill_add">
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <input type="text" name="label" placeholder="e.g. Minibar" required style="flex:1;min-width:160px;padding:7px 9px">
      <span class="text-muted" style="font-size:12px"><?= e($__cur) ?></span>
      <input type="number" name="amount" step="0.01" min="0" value="0" style="width:110px;padding:7px 9px;text-align:right">
      <button type="submit" class="btn-sm btn-outline">+ Add charge</button>
    </form>
  </div>
</div>

<div class="card"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center">
  <strong style="font-size:16px">Total extras</strong>
  <strong style="font-size:18px"><?= e(format_price($__total, $__cur)) ?></strong>
</div></div>
