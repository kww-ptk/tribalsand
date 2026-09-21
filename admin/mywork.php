<?php
/**
 * Admin: My Work — a team member's own queue. Ops staff (housekeeping,
 * maintenance, gardening, driver) land here after login.
 *
 * Today's tasks are the dominant block, rendered as tall cards with a big
 * "Mark done" button — the one thing staff need to check daily. Below that:
 * the specialty worklist (derived from bookings) and assigned guest requests.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/frontdesk.php';   // frontdesk_today_ymd(), the live worklist source
require_once __DIR__ . '/../includes/task-calendar.php';   // today's tasks + procedures
require_login();

$pageTitle  = 'My work';
$activeMenu = 'mywork';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$meId    = (int)($_SESSION['admin_id'] ?? 0);
$showDone = isset($_GET['done']) && $_GET['done'] === '1';
$asgOn   = addon_assigned_supported();
$tasksOn = tasks_supported();

// Specialty worklist, derived from the live booking calendar and scoped to the
// staff member's own venues. Housekeeping/laundry/driver get a tailored "today"
// list; other jobs get [] and work from Tasks + assigned requests below.
$myJob    = admin_job();
$jobLabel = team_job_types()[$myJob] ?? 'My work';
$today    = frontdesk_today_ymd();
$worklist = staff_day_worklist(admin_venue_ids(), (string)$myJob, $today);

/** One turnover row: guest, property · room, and the stay dates. */
function worklist_row(array $r): void {
    $room = trim((string)($r['room_name'] ?? '') . (!empty($r['unit_name']) ? ' · ' . $r['unit_name'] : ''), ' ·');
    ?>
    <tr>
      <td><strong><?= e($r['guest_name'] ?: 'Guest') ?></strong></td>
      <td><?= e($r['venue_name'] ?? '') ?></td>
      <td><?= e($room) ?></td>
      <td><span class="text-muted" style="font-size:12px"><?= e(date('j M', strtotime((string)$r['check_in']))) ?> → <?= e(date('j M', strtotime((string)$r['check_out']))) ?></span></td>
    </tr>
    <?php
}

/**
 * One task card: title, when, property, procedure, and a big Mark done button.
 *
 * `data-late` freezes whether this task was overdue AT RENDER (task_is_overdue()
 * already returns false for a done/cancelled task, so this is "late while open").
 * The client only ever needs to CLEAR the overdue indicator when it patches a
 * card to done — it never has to compute lateness itself — so freezing it here is
 * enough; nothing recomputes it from a bare id + status.
 */
function mywork_task_card(array $t, string $today, string $nowHms): void {
    $done = (string)$t['status'] === 'done';
    $late = task_is_overdue($t, $today, $nowHms);
    $when = ($t['due_time'] ?? '') !== '' ? substr((string)$t['due_time'], 0, 5) : 'Anytime';
    ?>
    <div class="mw-task<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?>" data-task-card data-late="<?= $late ? '1' : '0' ?>">
      <div class="mw-task__when"><?= e($when) ?></div>
      <div class="mw-task__body">
        <div class="mw-task__title"><?= e($t['title']) ?></div>
        <div class="mw-task__meta"><?= e($t['venue_name'] ?? '') ?><span class="mw-task__overdue"<?= ($late && !$done) ? '' : ' hidden' ?>> · Overdue</span></div>
        <?php if (!empty($t['detail'])): ?>
        <div class="mw-task__detail"><?= e($t['detail']) ?></div>
        <?php endif; ?>
        <?php if (!empty($t['procedure_text'])): ?>
        <details class="mw-task__proc">
          <summary>Procedure</summary>
          <div><?= nl2br(e($t['procedure_text'])) ?></div>
        </details>
        <?php endif; ?>
      </div>
      <form method="POST" action="/admin/task-action.php" class="mw-task__act" data-task-form>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="mywork">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <?php if ($done): ?>
        <button type="submit" name="status" value="todo" class="mw-btn mw-btn--undo">Reopen</button>
        <?php else: ?>
        <button type="submit" name="status" value="done" class="mw-btn mw-btn--done">Mark done</button>
        <?php endif; ?>
        <div class="mw-task__msg" data-task-msg></div>
      </form>
    </div>
    <?php
}

$open = $asgOn ? mywork_requests($meId, ['requested','confirmed']) : [];
$done = ($asgOn && $showDone) ? mywork_requests($meId, ['completed']) : [];

// Today's tasks come from the SAME read model as the grid, so the two cannot
// disagree about what is due or what counts as late. ONE query, whatever the
// person's venue scope — task_user_day_fetch() keys on assignment, which already
// implies the property, so there is no venue loop to fire a query per property.
//
// Completed tasks stay in the list for the rest of the day: a staff member should
// see what they finished, and be able to reopen a mis-tap.
$nowHms = date('H:i:s');
$dayAll = $tasksOn ? task_user_day_fetch($meId, $today) : [];

// Timed work first, in clock order; untimed work under its own heading.
$myToday = array_values(array_filter($dayAll, fn($r) => ($r['due_time'] ?? '') !== ''));
$myLater = array_values(array_filter($dayAll, fn($r) => ($r['due_time'] ?? '') === ''));

// Anything assigned and still open from before today, so nothing is silently
// lost — via the SAME one-query/one-shape read model as today's tasks, so it
// carries the procedure too (an overdue task is the one most likely to need it).
$myOverdue = $tasksOn ? task_user_overdue_fetch($meId, $today) : [];

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
      <td>
        <div class="row-actions">
        <?php if ($a['status'] === 'requested'): ?>
        <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="mywork"><button name="status" value="confirmed" class="btn-icon btn-icon--primary" title="Accept request" aria-label="Accept <?= e($gkind) ?> request from <?= e($gname) ?>"><?= admin_icon('check') ?></button></form>
        <?php endif; ?>
        <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
        <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="mywork"><button name="status" value="completed" class="btn-icon btn-icon--outline" title="Mark done" aria-label="Mark <?= e($gkind) ?> request from <?= e($gname) ?> as done"><?= admin_icon('check-check') ?></button></form>
        <?php endif; ?>
        <?php if ($a['status'] === 'requested'): ?>
        <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="mywork"><button name="status" value="declined" class="btn-icon btn-icon--danger" title="Decline request" aria-label="Decline <?= e($gkind) ?> request from <?= e($gname) ?>" data-confirm="Decline this <?= e($gkind) ?> request from <?= e($gname) ?>?"><?= admin_icon('x') ?></button></form>
        <?php endif; ?>
        <?php if (!in_array($a['status'], ['requested','confirmed'], true)): ?><span class="text-muted">—</span><?php endif; ?>
        </div>
      </td>
    </tr>
    <?php
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1><?= e($jobLabel) ?> — my work</h1>
  <?php if ($asgOn): ?>
  <a href="/admin/mywork.php<?= $showDone ? '' : '?done=1' ?>" class="btn-outline btn-sm"><?= $showDone ? admin_icon('arrow-left', 15) . ' Hide completed' : 'Show completed' ?></a>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? (is_string($flash) ? $flash : '')) ?></div><?php endif; ?>

<?php if ($tasksOn): ?>
<div class="card" style="margin-bottom:1rem" id="mwTaskCard" data-csrf="<?= e(csrf_token()) ?>">
  <div class="card__header mw-head-row">
    <h2 style="margin:0;font-size:17px">Today · <?= e(date('D j M', strtotime($today))) ?></h2>
    <a class="btn-outline btn-sm" href="/admin/timetable.php">This week</a>
  </div>
  <div class="card__body">
    <?php if (!$myToday && !$myLater && !$myOverdue): ?>
    <p class="text-muted" style="text-align:center;padding:1.5rem 0;margin:0">Nothing on your list today.</p>
    <?php endif; ?>

    <?php if ($myOverdue): ?>
    <h3 class="mw-head">Still open from before</h3>
    <?php foreach ($myOverdue as $t) mywork_task_card($t, $today, $nowHms); ?>
    <?php endif; ?>

    <?php foreach ($myToday as $t) mywork_task_card($t, $today, $nowHms); ?>

    <?php if ($myLater): ?>
    <h3 class="mw-head">Anytime today</h3>
    <?php foreach ($myLater as $t) mywork_task_card($t, $today, $nowHms); ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (in_array($myJob, ['housekeeping','laundry','driver'], true)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Today · <?= e(date('D j M', strtotime($today))) ?></span></div>
  <div class="card__body" style="padding:0">
    <?php if (!$worklist): ?>
      <p class="text-muted" style="margin:0;padding:2rem;text-align:center">Nothing on the calendar for your properties today. 🎉</p>
    <?php else: foreach ($worklist as $sec): ?>
      <div style="padding:12px 16px 4px"><strong><?= e($sec['title']) ?></strong> <span class="text-muted" style="font-size:12px">· <?= e($sec['note']) ?></span></div>
      <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Guest</th><th>Property</th><th>Room</th><th>Stay</th></tr></thead>
        <tbody><?php foreach ($sec['rows'] as $r) worklist_row($r); ?></tbody>
      </table>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$asgOn && !$tasksOn): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Your work queue isn’t enabled yet. Ask the owner to run the <code>add_addon_assignee.sql</code> and <code>add_tasks.sql</code> migrations.</p></div></div>
<?php endif; ?>

<?php if ($asgOn): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Requests assigned to me</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Guest</th><th>Service</th><th>Request</th><th>Preferred time</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (!$open): ?>
        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Nothing assigned to you right now. 🎉</td></tr>
        <?php else: foreach ($open as $a) mywork_row($a, $badgeClass); endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php if ($showDone): ?>
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Completed requests</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Guest</th><th>Service</th><th>Request</th><th>Preferred time</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (!$done): ?>
        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No completed items.</td></tr>
        <?php else: foreach ($done as $a) mywork_row($a, $badgeClass); endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.mw-head-row{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.mw-head-row h2{flex:1 1 auto;min-width:0}
.mw-head-row > a{flex:0 0 auto}
.mw-head{font-size:13px;color:#8a8072;margin:1rem 0 .5rem;font-weight:500}
.mw-head:first-child{margin-top:0}
.mw-task{display:flex;gap:12px;align-items:flex-start;border:1px solid #e7e1d6;border-radius:10px;padding:12px;margin-bottom:10px;background:#fff}
.mw-task.is-late{border-color:#e8b4ae}
.mw-task.is-done{opacity:.55}
.mw-task.is-done .mw-task__title{text-decoration:line-through}
.mw-task__when{min-width:52px;font-size:13px;color:#8a8072;padding-top:2px}
.mw-task__body{flex:1;min-width:0}
.mw-task__title{font-size:16px;line-height:1.3}
.mw-task__meta{font-size:12px;color:#8a8072;margin-top:2px}
.mw-task__overdue{color:#b3261e}
.mw-task__detail{font-size:13px;color:#6b6256;margin-top:6px}
.mw-task__proc{margin-top:8px;font-size:13px}
.mw-task__proc summary{cursor:pointer;color:#6b6256}
.mw-task__proc div{white-space:pre-wrap;background:#faf7f1;border-radius:6px;padding:10px;margin-top:6px;line-height:1.55}
.mw-btn{border:0;border-radius:8px;padding:12px 16px;font:inherit;font-size:14px;cursor:pointer;min-height:44px;white-space:nowrap}
.mw-btn--done{background:#2f6f4f;color:#fff}
.mw-btn--undo{background:#f1ece2;color:#6b6256}
.mw-task__msg{font-size:12px;color:#b3261e;margin-top:6px}
.mw-task__msg:empty{margin-top:0}
@media (max-width:560px){
  .mw-task{flex-wrap:wrap}
  .mw-task__act{width:100%}
  .mw-btn{width:100%}
}
</style>
<script src="/admin/assets/admin-mywork.js?v=<?= @filemtime(__DIR__ . '/assets/admin-mywork.js') ?: time() ?>"></script>

<?php include __DIR__ . '/_layout_end.php'; ?>
