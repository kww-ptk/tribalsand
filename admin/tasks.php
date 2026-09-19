<?php
/**
 * Admin: Tasks — the manager's internal work board (owner + managers).
 * Create venue-scoped tasks, assign them to a team member, filter and track
 * status. Status transitions post to task-action.php (also used by My Work).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';   // team helpers
require_once __DIR__ . '/../includes/recurring-tasks.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_reception();

$pageTitle  = 'Tasks';
$activeMenu = 'tasks';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();                 // null = owner (all)
$JOBS     = team_job_types();

// Venues in scope (owner: all; manager: assigned).
if ($venueIds === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($venueIds) {
    $ph = []; $p = [];
    foreach ($venueIds as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}
$scopedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

if (!tasks_supported()) {
    include __DIR__ . '/_layout.php';
    echo '<div class="page-header"><h1>Tasks</h1></div>';
    echo '<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Tasks aren’t enabled yet. Ask the owner to run the <code>add_tasks.sql</code> migration.</p></div></div>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

// Candidate assignees across the scoped venues (active managers + staff).
if ($venueIds === null) {
    $candidates = db_query(
        "SELECT DISTINCT a.id, a.name, a.role, a.job_type FROM admin_users a
         WHERE a.role IN ('manager','staff') AND a.is_active = TRUE ORDER BY a.role DESC, a.name ASC"
    )->fetchAll();
} elseif ($scopedVenueIds) {
    $ph = []; $p = [];
    foreach ($scopedVenueIds as $i => $v) { $n = ":cv{$i}"; $ph[] = $n; $p[$n] = $v; }
    $candidates = db_query(
        "SELECT DISTINCT a.id, a.name, a.role, a.job_type FROM admin_users a
         JOIN admin_user_venues av ON av.admin_user_id = a.id
         WHERE a.role IN ('manager','staff') AND a.is_active = TRUE AND av.venue_id IN (" . implode(',', $ph) . ")
         ORDER BY a.role DESC, a.name ASC", $p
    )->fetchAll();
} else {
    $candidates = [];
}

/**
 * Which venues each candidate is scoped to, so a row's reassign menu can offer
 * only the people who may actually take THAT task's property. Mirrors
 * team_can_take_venue() (the server-side guard the reassign action re-applies);
 * without it every row listed the whole team and most choices bounced off
 * "That person isn't assigned to that property."
 */
$candidateVenues = [];
if ($candidates) {
    $ids = implode(',', array_map(fn($c) => (int)$c['id'], $candidates));
    try {
        foreach (db_query("SELECT admin_user_id, venue_id FROM admin_user_venues WHERE admin_user_id IN ({$ids})")->fetchAll() as $r) {
            $candidateVenues[(int)$r['admin_user_id']][] = (int)$r['venue_id'];
        }
    } catch (Throwable $e) { $candidateVenues = []; }
}
/** Candidates assignable to one venue (empty map = pre-migration, offer everyone). */
$candidatesFor = function (int $venueId) use ($candidates, $candidateVenues): array {
    if (!$candidateVenues) return $candidates;
    return array_values(array_filter(
        $candidates,
        fn($c) => in_array($venueId, $candidateVenues[(int)$c['id']] ?? [], true)
    ));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $venue = (int)($_POST['venue_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $detail = trim((string)($_POST['detail'] ?? ''));
        $job    = $_POST['job_type'] ?? '';
        $due    = trim((string)($_POST['due_date'] ?? ''));
        $asg    = (int)($_POST['assigned_to'] ?? 0) ?: null;

        if (!in_array($venue, $scopedVenueIds, true)) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Choose a property you manage.'];
            header('Location: /admin/tasks.php'); exit;
        }
        if ($title === '') {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Give the task a title.'];
            header('Location: /admin/tasks.php'); exit;
        }
        if (!array_key_exists($job, $JOBS)) $job = null;
        if ($asg !== null && !team_can_take_venue($asg, $venue)) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That person isn’t assigned to that property.'];
            header('Location: /admin/tasks.php'); exit;
        }
        $dueSql = null;
        if ($due !== '') { $ts = strtotime($due); if ($ts !== false) $dueSql = date('Y-m-d', $ts); }
        $timeSql = trim((string)($_POST['due_time'] ?? '')) ?: null;

        // Recurring path: create a recurrence rule (optionally attached to a
        // timetable) and let the spawner create the actual task(s). One-time path
        // is the original single INSERT.
        $isRecurring = recurring_tasks_supported() && ($_POST['recurring'] ?? '') === '1';
        if ($isRecurring) {
            $freq = (string)($_POST['frequency'] ?? 'weekly');
            $sid  = (int)($_POST['schedule_id'] ?? 0) ?: null;
            // A chosen timetable must be one of this account's own.
            if ($sid !== null) {
                $s = fetch_task_schedule($sid);
                if (!$s || !in_array((int)$s['venue_id'], $scopedVenueIds, true)) $sid = null;
            }
            $rid = create_task_recurrence([
                'schedule_id'  => $sid,
                'venue_id'     => $venue,
                'assigned_to'  => $asg,
                'job_type'     => $job,
                'title'        => $title,
                'detail'       => $detail,
                'frequency'    => $freq,
                'interval_days'=> $_POST['interval_days'] ?? null,
                'time_of_day'  => $_POST['due_time'] ?? '',
                'start_date'   => $dueSql ?? date('Y-m-d'),   // first occurrence = the due date, or today
                'created_by'   => $meId ?: null,
            ]);
            audit_log('task_recurrence.create', 'task_recurrence', $rid, $title);
            spawn_due_recurring_tasks(null, $venueIds);   // create the first occurrence immediately
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Recurring task created — it will repeat ' . strtolower(recurring_freq_label($freq, (int)($_POST['interval_days'] ?? 0) ?: null)) . '.'];
            header('Location: /admin/tasks.php'); exit;
        }

        db_query(
            "INSERT INTO tasks (venue_id, assigned_to, job_type, title, detail, due_date, due_time, created_by)
             VALUES (:v, :a, :j, :t, :d, :due, :tm, :cb)",
            [':v'=>$venue, ':a'=>$asg, ':j'=>$job, ':t'=>$title, ':d'=>($detail !== '' ? $detail : null),
             ':due'=>$dueSql, ':tm'=>$timeSql, ':cb'=>$meId ?: null]
        );
        audit_log('task.create', 'task', (int)db()->lastInsertId(), $title);
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Task created.'];
        header('Location: /admin/tasks.php'); exit;
    }

    if ($action === 'reassign') {
        // Owner/manager only (reception can create/track but not reassign ownership).
        if (!is_owner() && !is_manager()) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Only a manager can reassign a task.'];
            header('Location: /admin/tasks.php'); exit;
        }
        $id  = (int)($_POST['id'] ?? 0);
        $t   = $id ? fetch_task($id) : false;
        $ok  = $t && ($venueIds === null || in_array((int)$t['venue_id'], $venueIds, true));
        if (!$ok) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your task.'];
            header('Location: /admin/tasks.php'); exit;
        }
        $to = (int)($_POST['assigned_to'] ?? 0) ?: null;
        if ($to !== null && !team_can_take_venue($to, (int)$t['venue_id'])) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That person isn’t assigned to that property.'];
            header('Location: /admin/tasks.php'); exit;
        }
        db_query("UPDATE tasks SET assigned_to = :a WHERE id = :id", [':a'=>$to, ':id'=>$id]);
        audit_log('task.reassign', 'task', $id, $to !== null ? ('→ ' . team_member_name($to)) : 'unassigned');
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>$to !== null ? ('Task reassigned to ' . team_member_name($to) . '.') : 'Task unassigned.'];
        header('Location: /admin/tasks.php'); exit;
    }

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $t   = $id ? fetch_task($id) : false;
        $ok  = $t && ($venueIds === null || in_array((int)$t['venue_id'], $venueIds, true));
        if ($ok) {
            db_query("DELETE FROM tasks WHERE id = :id", [':id'=>$id]);
            audit_log('task.delete', 'task', $id, '');
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Task deleted.'];
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your task.'];
        }
        header('Location: /admin/tasks.php'); exit;
    }
}

// Spawn any due recurring tasks on load (idempotent) so the board is current.
if (recurring_tasks_supported()) recurring_inline_spawn($venueIds);

// Active timetables in scope, for the "Add to timetable" picker on the create form.
$schedulesForPicker = (recurring_tasks_supported())
    ? array_values(array_filter(fetch_task_schedules($venueIds), fn($s) => !empty($s['is_active']) && $s['is_active'] !== 'f'))
    : [];
// Owner/manager may reassign; reception may not.
$canReassign = is_owner() || is_manager();

// Filters.
$statusKey = $_GET['status'] ?? 'open';
if (!in_array($statusKey, ['open','todo','in_progress','done','cancelled','all'], true)) $statusKey = 'open';
$asgKey = $_GET['assignee'] ?? 'all';
$filters = ['status' => $statusKey];
if ($asgKey === 'me')          { $filters['assignee'] = 'me'; $filters['me'] = $meId; }
elseif ($asgKey === 'unassigned') { $filters['assignee'] = 'unassigned'; }
else { $asgKey = 'all'; }

$tasks = fetch_tasks($venueIds, $filters);

// Search + pagination (this list comes from an aggregate helper, so filter/slice
// the result set in PHP — same toolkit UX as the DB-paginated pages).
$pg = paginate_params(25);
if ($pg['q'] !== '') {
    $needle = mb_strtolower($pg['q']);
    $tasks = array_values(array_filter($tasks, function ($t) use ($needle) {
        $hay = mb_strtolower(($t['title'] ?? '') . ' ' . ($t['detail'] ?? '') . ' ' . ($t['venue_name'] ?? '') . ' ' . ($t['assignee_name'] ?? '') . ' ' . ($t['hold_guest'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    }));
}
$total = count($tasks);
$meta  = paginate_meta($total, $pg['page'], $pg['per']);
$tasks = array_slice($tasks, $meta['offset'], $meta['per']);

// ── Swappable body (list + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Task</th><th>Property</th><th>Assignee</th><th>Job</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (!$tasks): ?>
        <tr><td colspan="7" style="padding:0"><?php dt_empty($pg['q'] !== '' ? 'No tasks match your search.' : 'No tasks yet.'); ?></td></tr>
        <?php else: foreach ($tasks as $t):
          $tid = (int)$t['id']; $st = (string)$t['status'];
        ?>
        <tr>
          <td>
            <strong><?= e($t['title']) ?></strong>
            <?php if (!empty($t['recurrence_id'])): ?> <span class="badge badge--blue" style="font-size:10px" title="Created by a recurring schedule">↻ Recurring</span><?php endif; ?>
            <?php if (!empty($t['detail'])): ?><br><span class="text-muted" style="font-size:12px"><?= e($t['detail']) ?></span><?php endif; ?>
            <?php if (!empty($t['hold_guest'])): ?><br><span class="text-muted" style="font-size:12px">Booking: <?= e($t['hold_guest']) ?></span><?php endif; ?>
          </td>
          <td><?= e($t['venue_name'] ?? '') ?></td>
          <td><?= !empty($t['assignee_name']) ? e($t['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
          <td><?= !empty($t['job_type']) ? e($JOBS[$t['job_type']] ?? $t['job_type']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($t['due_date']) ? e(date('j M', strtotime((string)$t['due_date']))) : '<span class="text-muted">—</span>' ?></td>
          <td><span class="badge <?= task_badge_class($st) ?>"><?= e(task_status_label($st)) ?></span></td>
          <td>
            <div class="row-actions">
            <?php
              $btn = function(string $to, string $title, string $icon, string $variant) use ($tid) {
                echo '<form method="POST" action="/admin/task-action.php" style="display:inline">' . csrf_field()
                   . '<input type="hidden" name="return" value="tasks"><input type="hidden" name="id" value="' . $tid . '">'
                   . '<button name="status" value="' . e($to) . '" class="btn-icon ' . $variant . '" title="' . e($title) . '" aria-label="' . e($title) . '">' . admin_icon($icon) . '</button></form>';
              };
              if ($st === 'todo')        $btn('in_progress', 'Start task', 'play', 'btn-icon--outline');
              if (in_array($st, ['todo','in_progress'], true)) $btn('done', 'Mark done', 'check', 'btn-icon--primary');
              if (in_array($st, ['done','cancelled'], true))   $btn('todo', 'Reopen task', 'rotate', 'btn-icon--outline');
              if (in_array($st, ['todo','in_progress'], true)) $btn('cancelled', 'Cancel task', 'ban', 'btn-icon--outline');
            ?>
            <?php $rowCandidates = $canReassign ? $candidatesFor((int)$t['venue_id']) : []; ?>
            <?php if ($canReassign && $rowCandidates && in_array($st, ['todo','in_progress'], true)): ?>
            <form method="POST" style="display:inline-flex;align-items:center;gap:4px">
              <?= csrf_field() ?><input type="hidden" name="action" value="reassign"><input type="hidden" name="id" value="<?= $tid ?>">
              <select name="assigned_to" class="filter-select" title="Reassign task" aria-label="Reassign task" style="max-width:150px" onchange="this.form.submit()">
                <option value="" disabled>Reassign…</option>
                <option value="0" <?= empty($t['assigned_to']) ? 'selected' : '' ?>>— Unassigned —</option>
                <?php foreach ($rowCandidates as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)($t['assigned_to'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php endif; ?>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $tid ?>"><button class="btn-icon btn-icon--danger" title="Delete task" aria-label="Delete task" data-confirm="Delete this task?"><?= admin_icon('trash') ?></button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<?php
  // Reveal the create form on load if a create attempt just failed (so the user
  // sees their form + the error) — otherwise it stays collapsed behind the button.
  $formOpen = $flash && ($flash['type'] ?? '') === 'error';
?>
<div class="page-header">
  <h1>Tasks</h1>
  <?php if ($venues): ?>
  <div class="actions">
    <button type="button" class="btn-primary btn-sm" id="taskCreateToggle" aria-expanded="<?= $formOpen ? 'true' : 'false' ?>" aria-controls="taskCreateCard"><?= admin_icon('plus', 15) ?> Create task</button>
  </div>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<div class="card" id="taskCreateCard" style="margin-bottom:20px<?= $formOpen ? '' : ';display:none' ?>">
  <div class="card__head"><span class="card__title">New task</span></div>
  <div class="card__body" style="padding:20px 24px">
    <?php if (!$venues): ?>
      <p class="text-muted" style="margin:0">You have no properties assigned yet.</p>
    <?php else: ?>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label style="grid-column:1/-1">Title
        <input type="text" name="title" required placeholder="Enter task title" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="grid-column:1/-1">Details <span class="text-muted">(optional)</span>
        <textarea name="detail" rows="2" placeholder="Enter details (optional)" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px"></textarea>
      </label>
      <label>Property
        <select name="venue_id" required style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Assign to <span class="text-muted">(optional)</span>
        <select name="assigned_to" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
          <option value="">— Unassigned —</option>
          <?php foreach ($candidates as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= ($c['role'] ?? '')==='manager' ? ' (mgr)' : (!empty($c['job_type']) ? ' · '.e($c['job_type']) : '') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Job type <span class="text-muted">(optional)</span>
        <select name="job_type" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
          <option value="">—</option>
          <?php foreach ($JOBS as $jk=>$jl): ?><option value="<?= e($jk) ?>"><?= e($jl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><?php /* label doubles as "first occurrence" when recurring */ ?><span id="dueDateLabel">Due date</span> <span class="text-muted">(optional)</span>
        <button type="button" class="dp-btn" data-dp-target="taskDueDate" data-dp-placeholder="Select date" style="margin-top:4px">Select date</button>
        <input type="hidden" id="taskDueDate" name="due_date">
      </label>
      <label>Time <span class="text-muted">(optional)</span>
        <input type="time" name="due_time" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>

      <?php if (recurring_tasks_supported()): ?>
      <label style="grid-column:1/-1;display:flex;align-items:center;gap:8px;font-weight:500;margin-top:4px">
        <input type="checkbox" name="recurring" value="1" id="taskRecurring" style="width:auto"> Make this a recurring task
      </label>
      <div id="taskRecurringFields" style="grid-column:1/-1;display:none;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;align-items:end;padding:14px;background:#f7f3ea;border:1px solid #e2dbcd;border-radius:8px">
        <label>Frequency
          <select name="frequency" id="taskFreq" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
            <?php foreach (recurring_freq_options() as $fk=>$fl): ?><option value="<?= e($fk) ?>"<?= $fk==='weekly'?' selected':'' ?>><?= e($fl) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label id="taskIntervalWrap" style="display:none">Every N days
          <input type="number" name="interval_days" min="1" placeholder="e.g. 10" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
        </label>
        <?php if ($schedulesForPicker): ?>
        <label>Add to timetable <span class="text-muted">(optional)</span>
          <select name="schedule_id" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
            <option value="">— None —</option>
            <?php foreach ($schedulesForPicker as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
        <p class="text-muted" style="grid-column:1/-1;margin:0;font-size:12.5px">The date above is the first occurrence (defaults to today). Tasks repeat automatically on the chosen frequency. Manage all recurring tasks under <a href="/admin/task-schedules.php">Job timetables</a>.</p>
      </div>
      <?php endif; ?>

      <div style="grid-column:1/-1"><button type="submit" class="btn-primary">Create task</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="dt" data-dt>
  <div class="dt-controls">
<form method="GET" action="/admin/tasks.php" class="filters">
  <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
  <input type="hidden" name="per" value="<?= (int)$meta['per'] ?>">
  <label class="filter-field">Status
    <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
      <?php foreach (['open'=>'Open','todo'=>'To do','in_progress'=>'In progress','done'=>'Done','cancelled'=>'Cancelled','all'=>'All'] as $sk=>$sl): ?>
      <option value="<?= e($sk) ?>" <?= $statusKey===$sk?'selected':'' ?>><?= e($sl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="filter-field">Assignee
    <select name="assignee" class="filter-select" aria-label="Filter by assignee" onchange="this.form.submit()">
      <?php foreach (['all'=>'All','me'=>'Assigned to me','unassigned'=>'Unassigned'] as $ak=>$al): ?>
      <option value="<?= e($ak) ?>" <?= $asgKey===$ak?'selected':'' ?>><?= e($al) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search task, property, assignee or guest…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<script>
(function () {
  var btn  = document.getElementById('taskCreateToggle');
  var card = document.getElementById('taskCreateCard');
  if (!btn || !card) return;
  btn.addEventListener('click', function () {
    var open = card.style.display !== 'none';
    card.style.display = open ? 'none' : '';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    if (!open) { var f = card.querySelector('input[name="title"]'); if (f) f.focus(); }
  });
})();
// Recurring toggle: reveal the frequency block; show interval only for "custom";
// relabel the date field as "First occurrence" when recurring.
(function () {
  var chk   = document.getElementById('taskRecurring');
  var block = document.getElementById('taskRecurringFields');
  var freq  = document.getElementById('taskFreq');
  var ivWrap= document.getElementById('taskIntervalWrap');
  var lbl   = document.getElementById('dueDateLabel');
  if (!chk || !block) return;
  function sync() {
    block.style.display = chk.checked ? 'grid' : 'none';
    if (lbl) lbl.textContent = chk.checked ? 'First occurrence' : 'Due date';
    if (ivWrap && freq) ivWrap.style.display = (chk.checked && freq.value === 'custom') ? '' : 'none';
  }
  chk.addEventListener('change', sync);
  if (freq) freq.addEventListener('change', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
