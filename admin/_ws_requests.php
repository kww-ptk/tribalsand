<?php /** Workspace Requests tab. Expects $hold, $holdId, $__addons, $__changes. */ ?>
<div class="card"><div class="card__head"><span class="card__title">Requests</span></div>
<div class="card__body" style="padding:0">
  <table class="data-table">
    <thead><tr><th>Service</th><th>Details</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$__addons && !$__changes): ?>
        <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--muted)">No requests yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($__addons as $a):
        $bc = ['requested'=>'badge--orange','confirmed'=>'badge--blue','completed'=>'badge--green','declined'=>'badge--red','cancelled'=>'badge--grey'][$a['status']] ?? 'badge--grey';
      ?>
      <tr>
        <td style="text-transform:capitalize"><?= e($a['kind']) ?></td>
        <td><?= e(addon_label($a)) ?><?php if (!empty($a['scheduled_for'])): ?> <span class="text-muted" style="font-size:12px">· <?= e(date('j M, H:i', strtotime((string)$a['scheduled_for']))) ?></span><?php endif; ?></td>
        <td><span class="badge <?= $bc ?>"><?= e(addon_status_label($a['status'])) ?></span></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($a['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="confirmed" class="btn-primary btn-sm">Accept</button></form>
          <?php endif; ?>
          <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="completed" class="btn-outline btn-sm">Done</button></form>
          <?php endif; ?>
          <?php if ($a['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="declined" class="btn-danger btn-sm">Decline</button></form>
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
        <td style="text-align:right;white-space:nowrap">
          <?php if ($c['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="change"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="handled" class="btn-primary btn-sm">Handled</button></form>
          <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="change"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="return" value="workspace"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><button name="status" value="declined" class="btn-danger btn-sm">Decline</button></form>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
