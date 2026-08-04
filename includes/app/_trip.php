<?php /** My trip — itinerary + add-to-plan. Expects $hold, $ref, $status. */ ?>
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
$__days = [];
for ($__d = new DateTime((string)$hold['check_in']); $__d <= new DateTime((string)$hold['check_out']); $__d->modify('+1 day')) { $__days[$__d->format('Y-m-d')] = $__d->format('D j M'); }
$__gcats = ['activity'=>'Activity','transfer'=>'Transfer','dining'=>'Restaurant','note'=>'Other'];
try { $__acts = fetch_portal_activities(); } catch (Throwable $e) { $__acts = []; }
?>
<details class="pa-details" open>
<summary class="pa-details__s">My Calendar</summary>
<div style="padding-top:2px">
<p class="pa-sub" style="margin-top:0">Your day-by-day itinerary. Tours and transfers you’ve booked appear automatically.</p>
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
        <div style="flex:1;min-width:0">
          <div class="pa-planit__t"><?php if ($__it['time']): ?><?= e($__it['time']) ?> · <?php endif; ?><?= e($__it['title']) ?><?php if ($__it['source'] === 'request'): ?><span class="pa-planit__tag">booked</span><?php endif; ?></div>
          <?php if (($__it['detail'] ?? '') !== '' && $__it['detail'] !== 'from your request'): ?><div class="pa-planit__d"><?= e($__it['detail']) ?></div><?php endif; ?>
        </div>
        <?php if (($__it['source'] ?? '') === 'guest' && !empty($__it['id'])): ?>
        <form data-bm data-bm-success="Removed." action="/api/itinerary.php" style="margin:0">
          <input type="hidden" name="ref" value="<?= e($ref) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="item_id" value="<?= (int)$__it['id'] ?>">
          <button type="submit" aria-label="Remove" title="Remove" style="background:none;border:none;color:var(--pa-muted);cursor:pointer;font-size:18px;line-height:1;padding:0 2px">&times;</button>
          <span class="bm-status" style="display:none"></span>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div style="margin:6px 0 20px">
  <button type="button" class="pa-btn" id="planAddBtn" style="width:auto;padding:9px 16px">+ Add to plan</button>
  <form data-bm data-bm-success="Added to your plan." action="/api/itinerary.php" id="planAddForm" style="display:none;margin-top:12px">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <input type="hidden" name="action" value="add">
    <label class="pa-field">Day
      <select name="day" required><?php foreach ($__days as $__dv=>$__dl): ?><option value="<?= e($__dv) ?>"><?= e($__dl) ?></option><?php endforeach; ?></select>
    </label>
    <label class="pa-field">Type
      <select name="category" id="planCat" required><?php foreach ($__gcats as $__cv=>$__cl): ?><option value="<?= e($__cv) ?>"><?= e($__cl) ?></option><?php endforeach; ?></select>
    </label>
    <?php if ($__acts): ?>
    <label class="pa-field" id="planActWrap">Activity
      <select id="planActPick">
        <option value="">— choose an activity —</option>
        <?php foreach ($__acts as $__a): ?><option value="<?= e($__a['name']) ?>"><?= e($__a['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label class="pa-field" id="planWhatWrap">What<input type="text" name="title" id="planTitle" required placeholder="e.g. Dinner at Somewhere Café"></label>
    <label class="pa-field">Time (optional)<input type="time" name="at_time"></label>
    <label class="pa-field">Notes (optional)<input type="text" name="detail"></label>
    <button type="submit" class="pa-btn pa-btn--primary">Add to plan</button>
    <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
  </form>
</div>
<script>
(function(){
  var b=document.getElementById('planAddBtn'),f=document.getElementById('planAddForm');
  if(b&&f)b.addEventListener('click',function(){var open=f.style.display!=='none';f.style.display=open?'none':'block';if(!open)f.scrollIntoView({behavior:'smooth',block:'nearest'});});
  // When Type = Activity, pick from our catalog; that choice fills the title.
  var cat=document.getElementById('planCat'),pick=document.getElementById('planActPick'),
      actWrap=document.getElementById('planActWrap'),whatWrap=document.getElementById('planWhatWrap'),
      title=document.getElementById('planTitle');
  function sync(){
    if(!cat||!pick||!actWrap||!whatWrap||!title)return;
    if(cat.value==='activity'){
      actWrap.style.display=''; whatWrap.style.display='none';
      title.required=false; pick.required=true;
      title.value=pick.value; // may be empty until a choice is made (required select blocks submit)
    } else {
      actWrap.style.display='none'; whatWrap.style.display='';
      title.required=true; pick.required=false;
    }
  }
  if(cat){cat.addEventListener('change',sync);}
  if(pick){pick.addEventListener('change',function(){title.value=pick.value;});}
  sync();
})();
</script>
</div>
</details>
