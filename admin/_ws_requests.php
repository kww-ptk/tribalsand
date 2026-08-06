<?php
/** Workspace Requests tab. Expects $hold, $holdId, $__addons, $__changes. */
$__asgOn     = addon_assigned_supported();
$__canAssign = $__asgOn && (is_owner() || is_manager());
$__cands     = $__canAssign ? assignable_team_for_venue(isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : [];
$__cols      = $__asgOn ? 5 : 4;
?>
<div class="card"><div class="card__head"><span class="card__title">Requests</span></div>
<div class="card__body" style="padding:0">
  <table class="data-table">
    <thead><tr><th>Service</th><th>Details</th><th>Status</th><?php if ($__asgOn): ?><th>Assigned</th><?php endif; ?><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$__addons && !$__changes): ?>
        <tr><td colspan="<?= $__cols ?>" style="text-align:center;padding:1.5rem;color:var(--muted)">No requests yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($__addons as $a):
        $bc = ['requested'=>'badge--orange','confirmed'=>'badge--blue','completed'=>'badge--green','declined'=>'badge--red','cancelled'=>'badge--grey'][$a['status']] ?? 'badge--grey';
        $gname = $hold['guest_name'] ?? 'the guest';
        $gkind = $a['kind'];
      ?>
      <tr>
        <td style="text-transform:capitalize"><?= e($a['kind']) ?></td>
        <td><?= e(addon_label($a)) ?><?php if (($a['kind'] ?? '') === 'tour' && !empty($a['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$a['pax'] ?> pax</span><?php endif; ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?><?php if (!empty($a['scheduled_for'])): ?> <span class="text-muted" style="font-size:12px">· <?= e(date('j M, H:i', strtotime((string)$a['scheduled_for']))) ?></span><?php endif; ?></td>
        <td><span class="badge <?= $bc ?>"><?= e(addon_status_label($a['status'])) ?></span></td>
        <?php if ($__asgOn): ?>
        <td>
          <?php $curAsg = isset($a['assigned_to']) ? (int)$a['assigned_to'] : 0; ?>
          <?php if ($__canAssign): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:flex;gap:6px;align-items:center">
            <?= csrf_field() ?><input type="hidden" name="type" value="assign"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
            <select name="assigned_to" style="padding:5px 7px;border:1px solid #d9d2c6;border-radius:6px;max-width:150px">
              <option value="">— Unassigned —</option>
              <?php foreach ($__cands as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $curAsg === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= ($c['role'] ?? '')==='manager' ? ' (mgr)' : (!empty($c['job_type']) ? ' · '.e($c['job_type']) : '') ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn-outline btn-sm">Save</button>
          </form>
          <?php else: ?>
            <?php $an = function_exists('team_member_name') ? team_member_name($curAsg ?: null) : ''; ?>
            <?= $an !== '' ? e($an) : '<span class="text-muted">Unassigned</span>' ?>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($a['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="confirmed" class="btn-primary btn-sm" aria-label="Accept <?= e($gkind) ?> request from <?= e($gname) ?>">Accept</button></form>
          <?php endif; ?>
          <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="completed" class="btn-outline btn-sm" aria-label="Mark <?= e($gkind) ?> request from <?= e($gname) ?> as done">Done</button></form>
          <?php endif; ?>
          <?php if ($a['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="declined" class="btn-danger btn-sm" aria-label="Decline <?= e($gkind) ?> request from <?= e($gname) ?>" data-confirm="Decline this <?= e($gkind) ?> request from <?= e($gname) ?>? They'll be notified.">Decline</button></form>
          <?php endif; ?>
          <?php if (!in_array($a['status'], ['requested','confirmed'], true)): ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($__changes as $c):
        $bc = ['requested'=>'badge--orange','handled'=>'badge--green','declined'=>'badge--red'][$c['status']] ?? 'badge--grey';
        $parts = [];
        if ($c['requested_check_in'] || $c['requested_check_out']) $parts[] = trim((string)($c['requested_check_in'] ?? '').' → '.(string)($c['requested_check_out'] ?? ''), ' →');
        if ($c['requested_guests']) $parts[] = (int)$c['requested_guests'].' guests';
        if (($c['note'] ?? '') !== '') $parts[] = $c['note'];
      ?>
      <tr>
        <td>Change</td>
        <td><?= e(implode(' · ', $parts)) ?></td>
        <td><span class="badge <?= $bc ?>"><?= e(ucfirst($c['status'])) ?></span></td>
        <?php if ($__asgOn): ?><td><span class="text-muted">—</span></td><?php endif; ?>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($c['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="change"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="handled" class="btn-primary btn-sm">Handled</button></form>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="change"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="declined" class="btn-danger btn-sm" aria-label="Decline change request from <?= e($hold['guest_name'] ?? 'the guest') ?>" data-confirm="Decline this change request from <?= e($hold['guest_name'] ?? 'the guest') ?>? They'll be notified.">Decline</button></form>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
