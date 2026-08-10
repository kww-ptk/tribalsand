<?php /** Workspace Plan tab. Expects $hold, $holdId. */ ?>
<?php
$__wcats = ['flight'=>'Flight','transfer'=>'Transfer','tour'=>'Tour','dining'=>'Dining','activity'=>'Activity','note'=>'Note'];
$__wdays = [];
for ($__d = new DateTime((string)$hold['check_in']); $__d <= new DateTime((string)$hold['check_out']); $__d->modify('+1 day')) { $__wdays[$__d->format('Y-m-d')] = $__d->format('D j M Y'); }
$__witin  = fetch_itinerary($hold);
$__witems = fetch_itinerary_items($holdId);
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Add item</span></div>
  <div class="card__body" style="padding:16px 20px">
    <form method="POST" action="/admin/booking.php" class="ws-addform">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
      <input type="hidden" name="action" value="itin_add">
      <label class="wsf"><span>Day</span>
        <select name="day" required aria-label="Day"><?php foreach ($__wdays as $dv=>$dl): ?><option value="<?= e($dv) ?>"><?= e($dl) ?></option><?php endforeach; ?></select>
      </label>
      <label class="wsf"><span>Time</span>
        <input type="text" name="at_time" class="inp inp--sm" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" style="width:92px" aria-label="Time (HH:MM)">
      </label>
      <label class="wsf"><span>Category</span>
        <select name="category" aria-label="Category"><?php foreach ($__wcats as $cv=>$cl): ?><option value="<?= e($cv) ?>"><?= e($cl) ?></option><?php endforeach; ?></select>
      </label>
      <label class="wsf wsf--grow"><span>Title</span>
        <input type="text" name="title" required placeholder="Enter title" class="inp inp--sm" style="width:100%">
      </label>
      <label class="wsf wsf--grow"><span>Detail</span>
        <input type="text" name="detail" placeholder="Enter detail" class="inp inp--sm" style="width:100%">
      </label>
      <button type="submit" class="btn-primary"><?= admin_icon('plus', 15) ?> Add</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Plan</span></div>
  <div class="card__body" style="padding:16px 20px">
    <?php foreach ($__witin as $day): ?>
    <div class="plan-day">
      <div class="plan-day__label"><?= e($day['label']) ?><?= $day['is_today'] ? ' · today' : '' ?></div>
      <?php if (!$day['items']): ?>
        <div class="plan-empty">—</div>
      <?php else: foreach ($day['items'] as $it): ?>
        <div class="plan-item">
          <span class="plan-item__time"><?= e($it['time'] ?? '—') ?></span>
          <span class="plan-item__cat"><?= e($it['category']) ?></span>
          <span class="plan-item__body"><strong><?= e($it['title']) ?></strong><?php if (($it['detail'] ?? '') !== '' && $it['detail'] !== 'from your request'): ?> <span class="text-muted">· <?= e($it['detail']) ?></span><?php endif; ?></span>
          <span class="plan-item__src"><?= e($it['source']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($__witems): ?>
    <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px">
      <div class="plan-day__label" style="margin-bottom:8px">Added items</div>
      <?php foreach ($__witems as $it): ?>
      <div class="plan-item">
        <span class="plan-item__body"><?= e((string)$it['day']) ?><?php if (!empty($it['at_time'])): ?> <?= e(substr((string)$it['at_time'],0,5)) ?><?php endif; ?> · <strong><?= e($it['title']) ?></strong> <span class="text-muted" style="text-transform:capitalize">(<?= e($it['category']) ?>)</span><?php if (($it['created_by'] ?? 'admin') === 'guest'): ?> <span class="badge badge--blue" style="font-size:10px">guest</span><?php endif; ?></span>
        <form method="POST" action="/admin/booking.php" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><input type="hidden" name="action" value="itin_del"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <button class="btn-icon btn-icon--danger" data-confirm="Remove this plan item?" data-tip="Remove item" aria-label="Remove item"><?= admin_icon('trash') ?></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
