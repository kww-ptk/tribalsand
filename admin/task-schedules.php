<?php
declare(strict_types=1);
/**
 * Admin: Job timetables — the LIST of predefined recurring task lists, one per
 * role/person at a property (e.g. a Gardener routine, a Reception checklist).
 * Owner + manager (scoped to their venues). This page creates timetables and
 * lists every one; open a timetable (admin/task-schedule-edit.php) to manage its
 * recurring task lines. The spawner turns each line into real tasks on its
 * cadence. Standalone recurring tasks (created from the Tasks board) are listed
 * here too under "Other recurring tasks".
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';           // team helpers
require_once __DIR__ . '/../includes/recurring-tasks.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_manager();

$pageTitle  = 'Job timetables';
$activeMenu = 'task_schedules';

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();               // null = owner (all)
$JOBS     = team_job_types();

// Venues in scope.
if ($venueIds === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($venueIds) {
    $ph = []; $p = [];
    foreach ($venueIds as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else { $venues = []; }
$scopedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

// Candidate assignees (active managers + staff in scope) — for the "default assignee" picker.
if ($venueIds === null) {
    $candidates = db_query("SELECT DISTINCT a.id, a.name, a.role, a.job_type FROM admin_users a WHERE a.role IN ('manager','staff') AND a.is_active = TRUE ORDER BY a.role DESC, a.name ASC")->fetchAll();
} elseif ($scopedVenueIds) {
    $ph = []; $p = [];
    foreach ($scopedVenueIds as $i => $v) { $n = ":cv{$i}"; $ph[] = $n; $p[$n] = $v; }
    $candidates = db_query("SELECT DISTINCT a.id, a.name, a.role, a.job_type FROM admin_users a JOIN admin_user_venues av ON av.admin_user_id = a.id WHERE a.role IN ('manager','staff') AND a.is_active = TRUE AND av.venue_id IN (" . implode(',', $ph) . ") ORDER BY a.role DESC, a.name ASC", $p)->fetchAll();
} else { $candidates = []; }

$supported = recurring_tasks_supported();

// Scope guard helpers.
$venueInScope = fn(int $vid) => in_array($vid, $scopedVenueIds, true);
$scheduleInScope = function (?array $s) use ($venueInScope) { return $s && $venueInScope((int)$s['venue_id']); };
$recurrenceInScope = function (?array $r) use ($venueInScope) { return $r && $venueInScope((int)$r['venue_id']); };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $flash  = ['type' => 'success', 'msg' => ''];
    $redirect = '/admin/task-schedules.php';
    try {
        if ($action === 'create_schedule') {
            $venue = (int)($_POST['venue_id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $job   = $_POST['job_type'] ?? '';
            $asg   = (int)($_POST['assigned_to'] ?? 0) ?: null;
            if (!$venueInScope($venue))       $flash = ['type'=>'error','msg'=>'Choose a property you manage.'];
            elseif ($name === '')             $flash = ['type'=>'error','msg'=>'Give the timetable a name.'];
            elseif ($asg !== null && !team_can_take_venue($asg, $venue)) $flash = ['type'=>'error','msg'=>'That person isn’t assigned to that property.'];
            else {
                if (!array_key_exists((string)$job, $JOBS)) $job = null;
                db_query("INSERT INTO task_schedules (venue_id, name, job_type, assigned_to, created_by) VALUES (:v,:n,:j,:a,:cb)",
                    [':v'=>$venue, ':n'=>$name, ':j'=>$job, ':a'=>$asg, ':cb'=>$meId ?: null]);
                $newId = (int)db()->lastInsertId();
                audit_log('task_schedule.create', 'task_schedule', $newId, $name);
                // Straight into the new timetable so the user can add its tasks.
                $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Timetable created — add its recurring tasks below.'];
                header('Location: /admin/task-schedule-edit.php?id=' . $newId); exit;
            }
        } elseif ($action === 'toggle_schedule') {
            $sid = (int)($_POST['id'] ?? 0); $s = fetch_task_schedule($sid);
            if ($scheduleInScope($s)) { db_query("UPDATE task_schedules SET is_active = NOT is_active WHERE id = :id", [':id'=>$sid]); $flash['msg']='Timetable updated.'; }
            else $flash = ['type'=>'error','msg'=>'Not your timetable.'];
        } elseif ($action === 'delete_schedule') {
            $sid = (int)($_POST['id'] ?? 0); $s = fetch_task_schedule($sid);
            if ($scheduleInScope($s)) { db_query("DELETE FROM task_schedules WHERE id = :id", [':id'=>$sid]); audit_log('task_schedule.delete','task_schedule',$sid,''); $flash['msg']='Timetable removed (its already-created tasks are kept).'; }
            else $flash = ['type'=>'error','msg'=>'Not your timetable.'];
        } elseif ($action === 'toggle_item') {   // standalone recurrences only
            $rid = (int)($_POST['id'] ?? 0); $r = fetch_task_recurrence($rid);
            if ($recurrenceInScope($r)) { db_query("UPDATE task_recurrences SET is_active = NOT is_active WHERE id = :id", [':id'=>$rid]); $flash['msg']='Recurring task updated.'; }
            else $flash = ['type'=>'error','msg'=>'Not your task.'];
        } elseif ($action === 'delete_item') {    // standalone recurrences only
            $rid = (int)($_POST['id'] ?? 0); $r = fetch_task_recurrence($rid);
            if ($recurrenceInScope($r)) { db_query("DELETE FROM task_recurrences WHERE id = :id", [':id'=>$rid]); audit_log('task_recurrence.delete','task_recurrence',$rid,''); $flash['msg']='Recurring task removed.'; }
            else $flash = ['type'=>'error','msg'=>'Not your task.'];
        } elseif ($action === 'spawn_now') {
            $n = spawn_due_recurring_tasks(null, $venueIds);
            $flash['msg'] = $n > 0 ? "Created {$n} due task(s)." : 'No tasks were due right now.';
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'error','msg'=>'Could not complete that action.'];
    }
    $_SESSION['hold_flash'] = $flash;
    header('Location: ' . $redirect); exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// Spawn any due tasks on load (idempotent) so the board stays current.
if ($supported) recurring_inline_spawn($venueIds);

$schedules  = $supported ? fetch_task_schedules($venueIds) : [];
$standalone = $supported ? fetch_task_recurrences(null, $venueIds) : [];

include __DIR__ . '/_layout.php';

/** Render an assignee <select>. */
$asgSelect = function (string $name, ?int $current) use ($candidates) {
    echo '<select name="' . e($name) . '" class="inp" style="width:100%"><option value="">— Unassigned —</option>';
    foreach ($candidates as $c) {
        $sel = ($current !== null && (int)$c['id'] === $current) ? ' selected' : '';
        $suffix = ($c['role'] ?? '')==='manager' ? ' (mgr)' : (!empty($c['job_type']) ? ' · '.$c['job_type'] : '');
        echo '<option value="' . (int)$c['id'] . '"' . $sel . '>' . e($c['name'] . $suffix) . '</option>';
    }
    echo '</select>';
};
?>
<div class="page-header">
  <div>
    <h1>Job timetables</h1>
    <p class="text-muted" style="margin:4px 0 0;font-size:13px">A recurring task list for a job position — e.g. a <strong>Gardener</strong> routine or a <strong>Reception</strong> checklist. The system creates the tasks automatically on each cadence; open one to manage its tasks. They appear on the <a href="/admin/tasks.php">Tasks board</a>.</p>
  </div>
  <?php if ($supported && $venues): ?>
  <div class="actions" style="display:flex;gap:8px">
    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="spawn_now"><button class="btn-sm btn-icon--outline" title="Create any tasks due now"><?= admin_icon('rotate', 15) ?> Run now</button></form>
    <button type="button" class="btn-primary btn-sm" id="schedCreateToggle"><?= admin_icon('plus', 15) ?> New timetable</button>
  </div>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<?php if (!$supported): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Recurring tasks aren’t enabled yet. Run the <code>add_recurring_tasks.sql</code> migration.</p></div></div>
<?php else: ?>

<?php if (!$venues): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">You have no properties assigned yet.</p></div></div>
<?php else: ?>

<!-- Create panel -->
<div class="card" id="schedCreateCard" style="margin-bottom:20px;display:none">
  <div class="card__head"><span class="card__title">New job timetable</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="/admin/task-schedules.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_schedule">
      <label style="grid-column:1/-1">Timetable name<input type="text" name="name" required placeholder="e.g. Gardener routine, Reception opening checklist" class="inp" style="width:100%;margin-top:4px"></label>
      <label>Property
        <select name="venue_id" required class="inp" style="width:100%;margin-top:4px"><?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select>
      </label>
      <label>Job position <span class="text-muted">(optional)</span>
        <select name="job_type" class="inp" style="width:100%;margin-top:4px"><option value="">—</option><?php foreach ($JOBS as $jk=>$jl): ?><option value="<?= e($jk) ?>"><?= e($jl) ?></option><?php endforeach; ?></select>
      </label>
      <label>Default assignee <span class="text-muted">(optional)</span><?php $asgSelect('assigned_to', null); ?></label>
      <div><button type="submit" class="btn-primary">Create &amp; add tasks</button></div>
    </form>
    <p class="text-muted" style="margin:10px 0 0;font-size:12px">You’ll add the recurring tasks (e.g. “Cut grass” weekly, “Check pipes” fortnightly) on the next screen.</p>
  </div>
</div>

<!-- Timetables list -->
<?php if (!$schedules): ?>
<div class="card"><div class="card__body">
  <p class="text-muted" style="margin:0 0 10px">No timetables yet. Create one for a job position — for example a <strong>Gardener</strong> routine at a property, then add “Cut grass” weekly and “Check pipes” fortnightly.</p>
  <button type="button" class="btn-primary btn-sm" id="schedCreateToggle2"><?= admin_icon('plus', 15) ?> New timetable</button>
</div></div>
<?php else: ?>
<div class="card">
  <div class="card__body">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Timetable</th><th>Job position</th><th>Property</th><th>Default assignee</th><th>Tasks</th><th>Status</th><th style="width:1%;text-align:right">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($schedules as $s): $sid = (int)$s['id']; $sActive = !empty($s['is_active']) && $s['is_active'] !== 'f'; ?>
          <tr>
            <td><a href="/admin/task-schedule-edit.php?id=<?= $sid ?>" style="font-weight:600;text-decoration:none"><?= e($s['name']) ?></a></td>
            <td class="text-muted"><?= !empty($s['job_type']) ? e($JOBS[$s['job_type']] ?? $s['job_type']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-muted"><?= e($s['venue_name'] ?? '') ?></td>
            <td class="text-muted"><?= !empty($s['assignee_name']) ? e($s['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
            <td><?= (int)($s['item_count'] ?? 0) ?></td>
            <td><span class="badge <?= $sActive ? 'badge--green':'badge--grey' ?>"><?= $sActive ? 'Active':'Paused' ?></span></td>
            <td style="text-align:right"><span class="dt-actions">
              <a href="/admin/task-schedule-edit.php?id=<?= $sid ?>" class="btn-icon btn-icon--outline" title="Open" aria-label="Open"><?= admin_icon('edit') ?></a>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_schedule"><input type="hidden" name="id" value="<?= $sid ?>"><button class="btn-icon btn-icon--outline" title="<?= $sActive?'Pause':'Resume' ?>" aria-label="Toggle"><?= admin_icon($sActive?'x':'check') ?></button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="id" value="<?= $sid ?>"><button class="btn-icon btn-icon--danger" title="Delete" aria-label="Delete" data-confirm="Delete the timetable “<?= e($s['name']) ?>”? Its recurring tasks stop; already-created tasks are kept."><?= admin_icon('trash') ?></button></form>
            </span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Standalone recurring tasks (from the Tasks board) -->
<?php if ($standalone): ?>
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">Other recurring tasks</span></div>
  <div class="card__body card__body--pad">
    <p class="text-muted" style="margin:0 0 8px;font-size:13px">Recurring tasks created directly from the Tasks board (not part of a timetable).</p>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Task</th><th>Frequency</th><th>Time</th><th>Assignee</th><th>Property</th><th>Next</th><th>Status</th><th style="width:1%;text-align:right">Actions</th></tr></thead><tbody>
      <?php foreach ($standalone as $r): $rid=(int)$r['id'];
        $freq = recurring_freq_label((string)$r['frequency'], $r['interval_days'] !== null ? (int)$r['interval_days'] : null);
        $time = $r['time_of_day'] ? date('g:i A', strtotime((string)$r['time_of_day'])) : '—';
        $next = $r['next_run_date'] ? date('j M Y', strtotime((string)$r['next_run_date'])) : '—';
        $active = !empty($r['is_active']) && $r['is_active'] !== 'f'; ?>
      <tr>
        <td><strong><?= e($r['title']) ?></strong><?php if (!empty($r['detail'])): ?><br><span class="text-muted" style="font-size:12px"><?= e($r['detail']) ?></span><?php endif; ?></td>
        <td><?= e($freq) ?></td><td><?= e($time) ?></td>
        <td><?= !empty($r['assignee_name']) ? e($r['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
        <td class="text-muted"><?= e($r['venue_name'] ?? '') ?></td>
        <td><?= e($next) ?></td>
        <td><span class="badge <?= $active?'badge--green':'badge--grey' ?>"><?= $active?'Active':'Paused' ?></span></td>
        <td style="text-align:right"><span class="dt-actions">
          <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn-icon btn-icon--outline" title="<?= $active?'Pause':'Resume' ?>" aria-label="Toggle"><?= admin_icon($active?'x':'check') ?></button></form>
          <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn-icon btn-icon--danger" title="Delete" aria-label="Delete" data-confirm="Delete this recurring task? Already-created tasks are kept."><?= admin_icon('trash') ?></button></form>
        </span></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php endif; ?>

<?php endif; /* venues */ ?>
<?php endif; /* supported */ ?>

<script>
(function(){
  var card=document.getElementById('schedCreateCard');
  function toggle(){ if(card) card.style.display = (card.style.display==='none'||!card.style.display) ? '' : 'none'; if(card && card.style.display===''){ var f=card.querySelector('input[name=name]'); if(f) f.focus(); } }
  ['schedCreateToggle','schedCreateToggle2'].forEach(function(id){ var b=document.getElementById(id); if(b) b.addEventListener('click',toggle); });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
