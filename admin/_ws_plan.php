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
  <div class="card__body">
    <form method="POST" action="/admin/booking.php" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
      <input type="hidden" name="action" value="itin_add">
      <label style="font-size:13px">Day<br><select name="day" required><?php foreach ($__wdays as $dv=>$dl): ?><option value="<?= e($dv) ?>"><?= e($dl) ?></option><?php endforeach; ?></select></label>
      <label style="font-size:13px">Time<br><input type="time" name="at_time"></label>
      <label style="font-size:13px">Category<br><select name="category"><?php foreach ($__wcats as $cv=>$cl): ?><option value="<?= e($cv) ?>"><?= e($cl) ?></option><?php endforeach; ?></select></label>
      <label style="font-size:13px;flex:1;min-width:160px">Title<br><input type="text" name="title" required placeholder="Enter title" style="width:100%"></label>
      <label style="font-size:13px;flex:1;min-width:160px">Detail<br><input type="text" name="detail" placeholder="Enter detail" style="width:100%"></label>
      <button type="submit" class="btn-primary">Add</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Plan</span></div>
  <div class="card__body">
    <?php foreach ($__witin as $day): ?>
    <div style="margin-bottom:12px">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px"><?= e($day['label']) ?><?= $day['is_today'] ? ' · today' : '' ?></div>
      <?php if (!$day['items']): ?>
        <div style="font-size:13px;color:var(--muted);font-style:italic">—</div>
      <?php else: foreach ($day['items'] as $it): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid #f0f0f0;font-size:14px">
          <span style="min-width:44px;color:var(--muted)"><?= e($it['time'] ?? '—') ?></span>
          <span style="text-transform:capitalize;min-width:74px;color:var(--muted);font-size:12px"><?= e($it['category']) ?></span>
          <span style="flex:1"><strong><?= e($it['title']) ?></strong><?php if (($it['detail'] ?? '') !== '' && $it['detail'] !== 'from your request'): ?> <span style="color:var(--muted)">· <?= e($it['detail']) ?></span><?php endif; ?></span>
          <span style="font-size:11px;color:var(--muted)"><?= e($it['source']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($__witems): ?>
    <div style="margin-top:16px;border-top:1px solid #eee;padding-top:12px">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:8px">Added items</div>
      <?php foreach ($__witems as $it): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:5px 0;font-size:14px">
        <span style="flex:1"><?= e((string)$it['day']) ?><?php if (!empty($it['at_time'])): ?> <?= e(substr((string)$it['at_time'],0,5)) ?><?php endif; ?> · <strong><?= e($it['title']) ?></strong> <span style="color:var(--muted);text-transform:capitalize">(<?= e($it['category']) ?>)</span><?php if (($it['created_by'] ?? 'admin') === 'guest'): ?> <span class="badge badge--blue" style="font-size:10px">guest</span><?php endif; ?></span>
        <form method="POST" action="/admin/booking.php" onsubmit="return confirm('Remove this item?')"><?= csrf_field() ?><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><input type="hidden" name="action" value="itin_del"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn-danger btn-sm">Delete</button></form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
