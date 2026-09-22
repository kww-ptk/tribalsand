<?php /** Workspace Activity tab — assignment + history log (Items 2 & 3). Expects $hold, $holdId, $__noMessaging. */ ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Assignment</span></div>
  <div class="card__body" style="padding:16px 20px">
    <?php if (!hold_assignee_supported()): ?>
      <p class="text-muted" style="font-size:13px;margin:0">Assignment is unavailable — run the <code>add_lead_assignee.sql</code> migration.</p>
    <?php elseif ($__noMessaging):
      $__a = (int)($hold['assigned_to'] ?? 0); ?>
      <p style="font-size:13px;margin:0">Responsible: <strong><?= $__a ? e(team_member_name($__a)) : 'Unassigned' ?></strong></p>
    <?php else:
      $__vid        = (int)($hold['venue_id'] ?? 0);
      $__assignable = assignable_accounts($__vid ?: null);
      $__assignedTo = (int)($hold['assigned_to'] ?? 0);
    ?>
      <form method="POST" action="/admin/booking.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
        <input type="hidden" name="action" value="assign">
        <label class="detail-item__label" style="margin:0">Assigned to</label>
        <select name="assigned_to" class="inp" style="min-width:190px;max-width:240px">
          <option value="0">— Unassigned —</option>
          <?php foreach ($__assignable as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= $__assignedTo === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name'] ?: $m['email']) ?><?= $m['role'] !== 'staff' ? ' (' . e($m['role']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary btn-sm">Save</button>
        <?php if ($__assignedTo): ?><span class="text-muted" style="font-size:12.5px">Responsible: <strong><?= e(team_member_name($__assignedTo)) ?></strong></span><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Activity Log</span></div>
  <div class="card__body" style="padding:14px 20px">
    <?php activity_log_html('hold', $holdId); ?>
  </div>
</div>
