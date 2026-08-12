<?php /** Workspace Bill tab. Expects $hold, $holdId. */ ?>
<?php
$__lines = fetch_bill_lines($holdId);
$__items = fetch_bill_items($holdId);
$__total = bill_total($holdId);
$__cur   = setting('site_currency', 'USD');
$__adults = array_values(array_filter(fetch_checkin_guests($holdId), fn($g) => empty($g['is_child'])));
/** number for a value input: 500.00 → "500", 12.50 → "12.5", null → "" */
$__numval = fn($v) => ($v === null || $v === '') ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head">
    <span class="card__title">Charges from requests</span>
    <a href="/admin/bill-print.php?hold=<?= $holdId ?>" target="_blank" class="btn-sm btn-primary">Print bill <?= admin_icon('arrow-right', 15) ?></a>
  </div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Item</th><th>Date</th><th class="num">Price</th></tr></thead>
      <tbody>
        <?php if (!$__lines): ?><tr><td colspan="3" style="padding:0"><?php dt_empty('No confirmed charges yet.'); ?></td></tr><?php endif; ?>
        <?php foreach ($__lines as $l):
          $__priced = isset($l['price_amount']) && $l['price_amount'] !== null && (float)$l['price_amount'] > 0;
        ?>
        <tr<?= $__priced ? '' : ' class="is-unpriced"' ?>>
          <td><?= e(addon_label($l)) ?><?php if (($l['kind'] ?? '') === 'tour' && !empty($l['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$l['pax'] ?> pax</span><?php endif; ?><?php if (!$__priced): ?> <span class="badge badge--orange">set a price</span><?php endif; ?><?php if (!empty($l['requested_by_name']) || !empty($l['requested_by_is_lead'])): ?><div class="text-muted" style="font-size:12px"><?= e(attributed_display_name((string)$l['requested_by_name'], !empty($l['requested_by_is_lead']), (string)($hold['guest_name'] ?? ''))) ?><?= !empty($l['requested_by_is_lead']) ? ' (lead)' : '' ?></div><?php endif; ?></td>
          <td class="text-muted" style="font-size:12px"><?= !empty($l['scheduled_for']) ? e(date('j M', strtotime((string)$l['scheduled_for']))) : '—' ?></td>
          <td class="num">
            <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=bill" style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="bill_set_price">
              <input type="hidden" name="hold_id" value="<?= $holdId ?>">
              <input type="hidden" name="addon_id" value="<?= (int)$l['id'] ?>">
              <span class="inp-money__cur"><?= e($__cur) ?></span>
              <input type="number" name="price_amount" step="0.01" min="0" value="<?= e($__numval($l['price_amount'] ?? '')) ?>" placeholder="0.00" class="inp inp--sm inp--num no-spin" style="width:96px" aria-label="Price">
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
  <div class="card__body" style="padding:16px 20px">
    <?php foreach ($__items as $it): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border)">
      <span style="flex:1"><?= e($it['label']) ?><?php if (!empty($it['guest_name']) || !empty($it['guest_is_lead'])): ?> <span class="text-muted" style="font-size:12px">· <?= e(attributed_display_name((string)$it['guest_name'], !empty($it['guest_is_lead']), (string)($hold['guest_name'] ?? ''))) ?></span><?php endif; ?></span>
      <span style="white-space:nowrap;font-variant-numeric:tabular-nums"><?= e(format_price((float)$it['amount'], $__cur)) ?></span>
      <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=bill" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="bill_del"><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
        <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Remove this charge?" data-tip="Remove charge" aria-label="Remove charge"><?= admin_icon('trash') ?></button>
      </form>
    </div>
    <?php endforeach; ?>
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=bill" class="ws-addform" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bill_add">
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <label class="wsf wsf--grow"><span>Charge</span>
        <input type="text" name="label" placeholder="e.g. Minibar" required class="inp inp--sm" style="width:100%">
      </label>
      <label class="wsf"><span>Amount</span>
        <span class="inp-money">
          <span class="inp-money__cur"><?= e($__cur) ?></span>
          <input type="number" name="amount" step="0.01" min="0" value="0" class="inp inp--sm inp--num no-spin" style="width:110px" aria-label="Amount">
        </span>
      </label>
      <?php if ($__adults): ?>
      <label class="wsf"><span>For</span>
        <select name="guest_id" class="inp inp--sm" style="width:130px">
          <option value="">Whole booking</option>
          <?php foreach ($__adults as $g): ?>
          <option value="<?= (int)$g['id'] ?>"><?= e(guest_display_name(['passport_name'=>$g['passport_name']])) ?><?= !empty($g['is_lead']) ? ' (lead)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <button type="submit" class="btn-outline"><?= admin_icon('plus', 15) ?> Add charge</button>
    </form>
  </div>
</div>

<div class="card"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px">
  <strong style="font-size:16px">Total extras</strong>
  <strong style="font-size:18px;font-variant-numeric:tabular-nums"><?= e(format_price($__total, $__cur)) ?></strong>
</div></div>
