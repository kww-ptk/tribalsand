<?php /** Stay info view. Expects $ref. Reads admin-edited settings. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref);
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__vals = [];
foreach ($__info as $__k => $__label) { $__vals[$__k] = trim((string)setting($__k, '')); }
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } } ?>
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
