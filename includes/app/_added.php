<?php
/**
 * My trip → "Added to my stay": every extra and request on the booking with its
 * status (Waiting / Confirmed / Done / Not available), when and price — the
 * guest's view of what staff are working on. Expects $hold, $ref, $status.
 */
$__aPu = '/booking.php?ref=' . urlencode($ref);
$__aRows = [];
try { $__aRows = array_values(array_filter(fetch_booking_addons((int)$hold['id']), fn($a) => ($a['status'] ?? '') !== 'cancelled')); } catch (Throwable $e) {}
$__aCur = setting('site_currency', 'USD');
$__aIco = [
    'transfer' => '<path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/>',
    'tour'     => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
    'other'    => '<path d="M4 5h16v11H8l-4 4z"/>',
];
?>
<div class="pa-xhead" style="margin-top:4px"><b>Added to my stay</b><a href="<?= e($__aPu) ?>&amp;view=extras">Add more</a></div>
<?php if (!$__aRows): ?>
  <div class="pa-card" style="padding:14px 16px">
    <p style="margin:0;font-size:14px;color:var(--pa-muted)">Nothing added yet. Massages, activities and airport transfers can be added from <a href="<?= e($__aPu) ?>&amp;view=extras">Extras</a>.</p>
  </div>
<?php else: ?>
  <?php foreach ($__aRows as $a):
    $__k = in_array($a['kind'], ['tour', 'transfer'], true) ? $a['kind'] : 'other';
    [$__sl, $__st] = guest_extra_status_view((string)$a['status']);
    $__when = !empty($a['scheduled_for']) ? strtotime((string)$a['scheduled_for']) : false;
    $__whenTxt = $__when ? date('D j M', $__when) . (date('H:i', $__when) !== '00:00' ? ' · ' . date('H:i', $__when) : '') : '';
  ?>
  <div class="pa-added">
    <div class="pa-added__head">
      <span class="pa-added__ico" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $__aIco[$__k] ?></svg></span>
      <span class="pa-added__t"><b><?= e(addon_label($a)) ?></b><span><?= e($__k === 'tour' ? 'Activity' : ($__k === 'transfer' ? 'Transfer' : ucfirst((string)$a['kind']))) ?></span></span>
      <?php if ($__sl !== ''): ?><span class="pa-pill pa-pill--<?= e($__st) ?>"><?= e($__sl) ?></span><?php endif; ?>
    </div>
    <?php if ($__whenTxt !== '' || !empty($a['pax']) || is_priced($a['price_amount'] ?? null)): ?>
    <dl class="pa-post__inset">
      <?php if ($__whenTxt !== ''): ?><div><dt>When</dt><dd><?= e($__whenTxt) ?></dd></div><?php endif; ?>
      <?php if (!empty($a['pax'])): ?><div><dt>People</dt><dd><?= (int)$a['pax'] ?></dd></div><?php endif; ?>
      <div><dt>Price</dt><dd><?= is_priced($a['price_amount'] ?? null) ? e(format_price((float)$a['price_amount'], $__aCur)) : 'Confirmed by our team' ?></dd></div>
    </dl>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php if (share_reservation_on($hold)): ?>
<a class="pa-btn pa-btn--primary" style="margin:6px 0 16px" href="<?= e($__aPu) ?>&amp;view=bill">Bill preview</a>
<?php endif; ?>
