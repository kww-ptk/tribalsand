<?php /** Stay info view. Expects $ref. Reads admin-edited settings. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref);
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__vals = [];
foreach ($__info as $__k => $__label) { $__vals[$__k] = trim((string)setting($__k, '')); }
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } } ?>
<p style="margin:0 0 16px"><a href="<?= e($__u) ?>" class="pa-back">&larr; Back to home</a></p>
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
