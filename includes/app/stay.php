<?php /** Stay info view. Expects $ref. Reads admin-edited settings. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref);
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__vals = [];
foreach ($__info as $__k => $__label) { $__vals[$__k] = trim((string)setting($__k, '')); }
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } } ?>
<?php
$__itin = fetch_itinerary($hold);
$__icat = [
  'checkin'  => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
  'checkout' => '<path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M14 17l5-5-5-5"/><path d="M19 12H7"/>',
  'flight'   => '<path d="M10 5l-7 7 2 1 5-2 3 6 2-1-1-7 4-3a1.5 1.5 0 0 0-2-2l-3 4-7-1-1 2z"/>',
  'transfer' => '<path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/><circle cx="8" cy="16" r="1"/><circle cx="16" cy="16" r="1"/>',
  'tour'     => '<circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>',
  'dining'   => '<path d="M6 3v7a2 2 0 0 0 4 0V3M8 10v11"/><path d="M17 3c-1.5 0-3 1.8-3 4.5S15.5 12 17 12v9"/>',
  'activity' => '<path d="M12 3l2.5 5.5L20 9l-4 4 1 6-5-3-5 3 1-6-4-4 5.5-.5z"/>',
  'note'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/>',
];
$__isvg = fn(string $cat) => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($__icat[$cat] ?? $__icat['note']) . '</svg>';
?>
<h2 class="pa-h2">Your plan</h2>
<p class="pa-sub">Your day-by-day itinerary. Tours and transfers you’ve booked appear automatically.</p>
<?php foreach ($__itin as $__day): ?>
<div class="pa-planday <?= $__day['is_today'] ? 'pa-planday--today' : '' ?>">
  <div class="pa-planday__h"><?= e($__day['label']) ?><?php if ($__day['is_today']): ?><span class="pa-planday__today">Today</span><?php endif; ?></div>
  <?php if (!$__day['items']): ?>
    <div class="pa-plantl"><span class="pa-planempty">Nothing planned — browse Activities or ask the concierge.</span></div>
  <?php else: ?>
    <div class="pa-plantl">
      <?php foreach ($__day['items'] as $__it): ?>
      <div class="pa-planit">
        <span class="pa-planit__ico"><?= $__isvg($__it['category']) ?></span>
        <div>
          <div class="pa-planit__t"><?php if ($__it['time']): ?><?= e($__it['time']) ?> · <?php endif; ?><?= e($__it['title']) ?><?php if ($__it['source'] === 'request'): ?><span class="pa-planit__tag">booked</span><?php endif; ?></div>
          <?php if (($__it['detail'] ?? '') !== '' && $__it['detail'] !== 'from your request'): ?><div class="pa-planit__d"><?= e($__it['detail']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div style="height:8px"></div>
<h2 class="pa-h2">Stay info</h2>
<?php if (!$__any): ?>
  <p style="color:#6b7280;font-size:14px">Stay details will appear here soon.</p>
<?php else: foreach ($__info as $__k=>$__label): $__v = $__vals[$__k]; if ($__v==='') continue; ?>
  <div class="pa-card">
    <div class="pa-card__body">
      <div style="font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:6px"><?= e($__label) ?></div>
      <div style="font-size:14px;line-height:1.6;white-space:pre-wrap"><?= e($__v) ?></div>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php if (in_array($status ?? '', ['pending','confirmed'], true)): ?>
<div class="pa-card">
  <div class="pa-card__body">
    <h2 class="pa-h2" style="font-size:18px">Request a change</h2>
    <p class="pa-sub">Update your dates or guest count — our team confirms availability by email.</p>
    <form data-bm action="/api/booking-change.php">
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <label class="pa-field">New check-in (optional)<input type="date" name="check_in"></label>
      <label class="pa-field">New check-out (optional)<input type="date" name="check_out"></label>
      <label class="pa-field">Guests (optional)<input type="number" name="guests" min="1" max="30"></label>
      <label class="pa-field">Notes<textarea name="note" rows="3" placeholder="Tell us what you’d like to change"></textarea></label>
      <button type="submit" class="pa-btn pa-btn--primary">Send change request</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
  </div>
</div>
<?php endif; ?>
