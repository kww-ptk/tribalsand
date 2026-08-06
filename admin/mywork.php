<?php
/**
 * Admin: My Work — a team member's own queue. Shows the guest requests assigned
 * to the signed-in account with inline status actions. Ops staff (housekeeping,
 * maintenance, gardening, driver) land here after login.
 *
 * (Phase 3 adds a Tasks section below for internal work items.)
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$pageTitle  = 'My work';
$activeMenu = 'mywork';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$meId    = (int)($_SESSION['admin_id'] ?? 0);
$showDone = isset($_GET['done']) && $_GET['done'] === '1';
$asgOn   = addon_assigned_supported();
$tasksOn = tasks_supported();

$open = $asgOn ? mywork_requests($meId, ['requested','confirmed']) : [];
$done = ($asgOn && $showDone) ? mywork_requests($meId, ['completed']) : [];
$myTasks = $tasksOn ? mywork_tasks($meId, ['todo','in_progress']) : [];

/** Map an addon status to the admin badge colour class. */
$badgeClass = fn(string $s): string => [
    'requested'=>'badge--orange','confirmed'=>'badge--blue','completed'=>'badge--green',
    'declined'=>'badge--red','cancelled'=>'badge--grey',
][$s] ?? 'badge--grey';

/** Render one request row with status actions (return=mywork). */
function mywork_row(array $a, callable $badgeClass): void {
    $gname = $a['guest_name'] ?: 'the guest';
    $gkind = $a['kind'];
    ?>
    <tr>
      <td>
        <strong><?= e($a['guest_name'] ?: 'Guest') ?></strong><br>
        <span class="text-muted" style="font-size:12px"><?= e(trim(($a['venue_name'] ?? '') . ' · ' . ($a['room_name'] ?? ''), ' ·')) ?></span>
      </td>
      <td style="text-transform:capitalize"><?= e($a['kind']) ?></td>
      <td><?= e(addon_label($a)) ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?></td>
      <td><?= !empty($a['scheduled_for']) ? e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) : '<span class="text-muted">—</span>' ?></td>
      <td><span class="badge <?= $badgeClass($a['status']) ?>"><?= e(addon_status_label($a['status'])) ?></span></td>
      <td style="text-align:right;white-space:nowrap">
        <a href="/admin/messages.php?hold=<?= (int)$a['hold_id'] ?>&thread=<?= (int)$a['id'] ?>" class="btn-outline btn-sm">Message</a>
        <?php if ($a['status'] === 'requested'): ?>
        <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="mywork"><button name="status" value="confirmed" class="btn-primary btn-sm" aria-label="Accept <?= e($gkind) ?> request from <?= e($gname) ?>">Accept</button></form>
        <?php endif; ?>
        <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
        <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="mywork"><button name="status" value="completed" class="btn-outline btn-sm" aria-label="Mark <?= e($gkind) ?> request from <?= e($gname) ?> as done">Mark done</button></form>
        <?php endif; ?>
        <?php if ($a['status'] === 'requested'): ?>
        <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="mywork"><button name="status" value="declined" class="btn-danger btn-sm" aria-label="Decline <?= e($gkind) ?> request from <?= e($gname) ?>" data-confirm="Decline this <?= e($gkind) ?> request from <?= e($gname) ?>?">Decline</button></form>
        <?php endif; ?>
        <?php if (!in_array($a['status'], ['requested','confirmed'], true)): ?><span class="text-muted">—</span><?php endif; ?>
      </td>
    </tr>
    <?php
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>My work</h1>
  <?php if ($asgOn): ?>
  <a href="/admin/mywork.php<?= $showDone ? '' : '?done=1' ?>" class="btn-outline btn-sm"><?= $showDone ? '← Hide completed' : 'Show completed' ?></a>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? (is_string($flash) ? $flash : '')) ?></div><?php endif; ?>

<?php if (!$asgOn && !$tasksOn): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Your work queue isn’t enabled yet. Ask the owner to run the <code>add_addon_assignee.sql</code> and <code>add_tasks.sql</code> migrations.</p></div></div>
<?php endif; ?>

<?php if ($tasksOn): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">My tasks</span></div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Task</th><th>Property</th><th>Due</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$myTasks): ?>
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No tasks assigned to you.</td></tr>
        <?php else: foreach ($myTasks as $t): $tid=(int)$t['id']; $st=(string)$t['status']; ?>
        <tr>
          <td>
            <strong><?= e($t['title']) ?></strong>
            <?php if (!empty($t['detail'])): ?><br><span class="text-muted" style="font-size:12px"><?= e($t['detail']) ?></span><?php endif; ?>
          </td>
          <td><?= e($t['venue_name'] ?? '') ?></td>
          <td><?= !empty($t['due_date']) ? e(date('j M', strtotime((string)$t['due_date']))) : '<span class="text-muted">—</span>' ?></td>
          <td><span class="badge <?= task_badge_class($st) ?>"><?= e(task_status_label($st)) ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($st === 'todo'): ?>
            <form method="POST" action="/admin/task-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="return" value="mywork"><input type="hidden" name="id" value="<?= $tid ?>"><button name="status" value="in_progress" class="btn-outline btn-sm">Start</button></form>
            <?php endif; ?>
            <?php if (in_array($st, ['todo','in_progress'], true)): ?>
            <form method="POST" action="/admin/task-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="return" value="mywork"><input type="hidden" name="id" value="<?= $tid ?>"><button name="status" value="done" class="btn-primary btn-sm">Done</button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($asgOn): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Requests assigned to me</span></div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Guest</th><th>Service</th><th>Request</th><th>Preferred time</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$open): ?>
        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Nothing assigned to you right now. 🎉</td></tr>
        <?php else: foreach ($open as $a) mywork_row($a, $badgeClass); endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showDone): ?>
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Completed requests</span></div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Guest</th><th>Service</th><th>Request</th><th>Preferred time</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$done): ?>
        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No completed items.</td></tr>
        <?php else: foreach ($done as $a) mywork_row($a, $badgeClass); endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
