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
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_manager();

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

        db_query(
            "INSERT INTO tasks (venue_id, assigned_to, job_type, title, detail, due_date, created_by)
             VALUES (:v, :a, :j, :t, :d, :due, :cb)",
            [':v'=>$venue, ':a'=>$asg, ':j'=>$job, ':t'=>$title, ':d'=>($detail !== '' ? $detail : null),
             ':due'=>$dueSql, ':cb'=>$meId ?: null]
        );
        audit_log('task.create', 'task', (int)db()->lastInsertId(), $title);
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Task created.'];
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
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)"><?= $pg['q'] !== '' ? 'No tasks match your search.' : 'Nothing to show yet.' ?></td></tr>
        <?php else: foreach ($tasks as $t):
          $tid = (int)$t['id']; $st = (string)$t['status'];
        ?>
        <tr>
          <td>
            <strong><?= e($t['title']) ?></strong>
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

<div class="page-header">
  <h1>Tasks</h1>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px">
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
      <label>Due date <span class="text-muted">(optional)</span>
        <button type="button" class="dp-btn" data-dp-target="taskDueDate" data-dp-placeholder="Select due date" style="margin-top:4px">Select due date</button>
        <input type="hidden" id="taskDueDate" name="due_date">
      </label>
      <div><button type="submit" class="btn-primary">Create task</button></div>
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


<?php include __DIR__ . '/_layout_end.php'; ?>
