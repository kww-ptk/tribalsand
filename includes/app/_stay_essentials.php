<?php /** Your stay essentials (collapsible). */ ?>
<?php
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__stayVals = fetch_venue_stay(isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null);
$__vals = []; foreach ($__info as $__k => $__label) { $__vals[$__k] = $__stayVals[$__k] ?? ''; }
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } }
// The windows come from settings, so this block always has real content — every
// venues.stay_* field is empty today, which is why it read "details will appear
// here soon" for every property.
$__T = checkin_times();
?>
<details class="pa-details" style="margin-top:20px" open>
  <summary class="pa-details__s">Your stay — Wi-Fi, check-out, house rules</summary>
  <div style="padding-top:6px">
      <div class="pa-card">
        <div class="pa-card__body">
          <div style="font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:6px">Check-in &amp; check-out</div>
          <div style="font-size:14px;line-height:1.6">
            Check-in <strong><?= e($__T['ci_from']) ?>&ndash;<?= e($__T['ci_to']) ?></strong><br>
            Check-out <strong><?= e($__T['co_from']) ?>&ndash;<?= e($__T['co_to']) ?></strong>
          </div>
          <div style="font-size:13px;line-height:1.55;color:#6b7280;margin-top:8px"><?= e($__T['note']) ?></div>
        </div>
      </div>
    <?php if ($__any): foreach ($__info as $__k=>$__label): $__v = $__vals[$__k]; if ($__v==='') continue; ?>
      <div class="pa-card">
        <div class="pa-card__body">
          <div style="font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:6px"><?= e($__label) ?></div>
          <div style="font-size:14px;line-height:1.6;white-space:pre-wrap"><?= e($__v) ?></div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</details>
