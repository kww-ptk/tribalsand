<?php
declare(strict_types=1);
/**
 * Admin: one Job timetable — its settings + its recurring task lines.
 *
 * The list of timetables lives on admin/task-schedules.php; this is the detail
 * editor for a single one (?id=N), mirroring menus.php → menu-edit.php. Owner +
 * manager, scoped to their venues. Add recurring lines with a frequency + time;
 * the spawner turns each into real tasks on its cadence.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';           // team helpers
require_once __DIR__ . '/../includes/recurring-tasks.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_manager();

$pageTitle  = 'Job timetable';
$activeMenu = 'task_schedules';

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();               // null = owner (all)
$JOBS     = team_job_types();

if (!recurring_tasks_supported()) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Recurring tasks aren’t enabled yet.'];
    header('Location: /admin/task-schedules.php'); exit;
}

$scopedVenueIds = null;   // built below for scope checks
if ($venueIds === null) {
    $scopedVenueIds = null;  // owner: any venue
} else {
    $scopedVenueIds = array_map('intval', $venueIds);
}
$venueInScope = function (int $vid) use ($scopedVenueIds): bool {
    return $scopedVenueIds === null || in_array($vid, $scopedVenueIds, true);
};

$id = (int)($_GET['id'] ?? 0);
$schedule = fetch_task_schedule($id);
if (!$schedule || !$venueInScope((int)$schedule['venue_id'])) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'That timetable belongs to another property.'];
    header('Location: /admin/task-schedules.php'); exit;
}
$venueId = (int)$schedule['venue_id'];

// Candidate assignees: active managers + staff assigned to THIS timetable's venue.
$candidates = db_query(
    "SELECT DISTINCT a.id, a.name, a.role, a.job_type
       FROM admin_users a
       JOIN admin_user_venues av ON av.admin_user_id = a.id
      WHERE a.role IN ('manager','staff') AND a.is_active = TRUE AND av.venue_id = :v
      ORDER BY a.role DESC, a.name ASC",
    [':v' => $venueId]
)->fetchAll();

$FREQS = recurring_freq_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $flash  = ['type' => 'success', 'msg' => ''];
    // Re-load + re-scope on every write (defence in depth).
    $s = fetch_task_schedule($id);
    if (!$s || !$venueInScope((int)$s['venue_id'])) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Not your timetable.'];
        header('Location: /admin/task-schedules.php'); exit;
    }
    try {
        if ($action === 'update_schedule') {
            $name = trim((string)($_POST['name'] ?? ''));
            $job  = $_POST['job_type'] ?? '';
            $asg  = (int)($_POST['assigned_to'] ?? 0) ?: null;
            if ($name === '') {
                $flash = ['type'=>'error','msg'=>'Give the timetable a name.'];
            } elseif ($asg !== null && !team_can_take_venue($asg, $venueId)) {
                $flash = ['type'=>'error','msg'=>'That person isn’t assigned to this property.'];
            } else {
                if (!array_key_exists((string)$job, $JOBS)) $job = null;
                db_query("UPDATE task_schedules SET name = :n, job_type = :j, assigned_to = :a WHERE id = :id",
                    [':n'=>$name, ':j'=>$job, ':a'=>$asg, ':id'=>$id]);
                audit_log('task_schedule.update', 'task_schedule', $id, $name);
                $flash['msg'] = 'Timetable updated.';
            }
        } elseif ($action === 'add_item') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') { $flash = ['type'=>'error','msg'=>'Give the task a title.']; }
            else {
                $asg = (int)($_POST['assigned_to'] ?? 0) ?: ($s['assigned_to'] !== null ? (int)$s['assigned_to'] : null);
                if ($asg !== null && !team_can_take_venue($asg, $venueId)) $asg = null;
                $rid = create_task_recurrence([
                    'schedule_id'  => $id,
                    'venue_id'     => $venueId,
                    'assigned_to'  => $asg,
                    'job_type'     => array_key_exists((string)($_POST['job_type'] ?? ''), $JOBS) ? $_POST['job_type'] : ($s['job_type'] ?? null),
                    'title'        => $title,
                    'detail'       => $_POST['detail'] ?? '',
                    'frequency'    => $_POST['frequency'] ?? 'weekly',
                    'interval_days'=> $_POST['interval_days'] ?? null,
                    'time_of_day'  => $_POST['time_of_day'] ?? '',
                    'start_date'   => $_POST['start_date'] ?? '',
                    'created_by'   => $meId ?: null,
                ]);
                audit_log('task_recurrence.create', 'task_recurrence', $rid, $title);
                $flash['msg'] = 'Recurring task added.';
            }
        } elseif ($action === 'toggle_item') {
            $rid = (int)($_POST['id'] ?? 0); $r = fetch_task_recurrence($rid);
            if ($r && (int)$r['schedule_id'] === $id) { db_query("UPDATE task_recurrences SET is_active = NOT is_active WHERE id = :id", [':id'=>$rid]); $flash['msg']='Recurring task updated.'; }
            else $flash = ['type'=>'error','msg'=>'Not your task.'];
        } elseif ($action === 'delete_item') {
            $rid = (int)($_POST['id'] ?? 0); $r = fetch_task_recurrence($rid);
            if ($r && (int)$r['schedule_id'] === $id) { db_query("DELETE FROM task_recurrences WHERE id = :id", [':id'=>$rid]); audit_log('task_recurrence.delete','task_recurrence',$rid,''); $flash['msg']='Recurring task removed.'; }
            else $flash = ['type'=>'error','msg'=>'Not your task.'];
        } elseif ($action === 'toggle_schedule') {
            db_query("UPDATE task_schedules SET is_active = NOT is_active WHERE id = :id", [':id'=>$id]); $flash['msg']='Timetable updated.';
        } elseif ($action === 'delete_schedule') {
            db_query("DELETE FROM task_schedules WHERE id = :id", [':id'=>$id]);
            audit_log('task_schedule.delete','task_schedule',$id,'');
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Timetable removed (its already-created tasks are kept).'];
            header('Location: /admin/task-schedules.php'); exit;
        }
    } catch (Throwable $e) {
        $flash = ['type'=>'error','msg'=>'Could not complete that action.'];
    }
    $_SESSION['hold_flash'] = $flash;
    header('Location: /admin/task-schedule-edit.php?id=' . $id); exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// Re-read for display (settings may have changed above), with venue + assignee names.
$schedule = db_query(
    "SELECT s.*, v.name AS venue_name, a.name AS assignee_name
       FROM task_schedules s
       LEFT JOIN venues v ON v.id = s.venue_id
       LEFT JOIN admin_users a ON a.id = s.assigned_to
      WHERE s.id = :id",
    [':id' => $id]
)->fetch() ?: fetch_task_schedule($id);
$items    = fetch_task_recurrences($id, $venueIds);
$sActive  = !empty($schedule['is_active']) && $schedule['is_active'] !== 'f';
$jobLabel = !empty($schedule['job_type']) ? ($JOBS[$schedule['job_type']] ?? $schedule['job_type']) : null;

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
/** Render a frequency <select>. */
$freqSelect = function (string $name, string $current = 'weekly') use ($FREQS) {
    echo '<select name="' . e($name) . '" class="inp" style="width:100%">';
    foreach ($FREQS as $k => $l) echo '<option value="' . e($k) . '"' . ($k===$current?' selected':'') . '>' . e($l) . '</option>';
    echo '</select>';
};
?>
<div class="page-header">
  <div>
    <a href="/admin/task-schedules.php" class="text-muted" style="font-size:13px;display:inline-flex;align-items:center;gap:4px;text-decoration:none"><?= admin_icon('chevron-left', 15) ?> All job timetables</a>
    <h1 style="margin-top:4px"><?= e($schedule['name']) ?>
      <span class="badge <?= $sActive ? 'badge--green':'badge--grey' ?>" style="margin-left:6px;vertical-align:middle"><?= $sActive ? 'Active':'Paused' ?></span>
    </h1>
    <p class="text-muted" style="margin:4px 0 0;font-size:13px">
      <?= e($schedule['venue_name'] ?? '') ?><?php if ($jobLabel): ?> · <?= e($jobLabel) ?><?php endif; ?><?php if (!empty($schedule['assignee_name'])): ?> · default assignee <?= e($schedule['assignee_name']) ?><?php endif; ?>
    </p>
  </div>
  <div class="actions" style="display:flex;gap:8px">
    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_schedule"><button class="btn-sm btn-icon--outline" title="<?= $sActive?'Pause timetable':'Resume timetable' ?>"><?= admin_icon($sActive?'x':'check', 15) ?> <?= $sActive?'Pause':'Resume' ?></button></form>
    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_schedule"><button class="btn-sm btn-icon--danger" title="Delete timetable" data-confirm="Delete the timetable “<?= e($schedule['name']) ?>”? Its recurring tasks stop; already-created tasks are kept."><?= admin_icon('trash', 15) ?> Delete</button></form>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<!-- Settings -->
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Timetable details</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="/admin/task-schedule-edit.php?id=<?= $id ?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_schedule">
      <label style="grid-column:1/-1">Timetable name<input type="text" name="name" required value="<?= e($schedule['name']) ?>" class="inp" style="width:100%;margin-top:4px"></label>
      <label>Job position <span class="text-muted">(optional)</span>
        <select name="job_type" class="inp" style="width:100%;margin-top:4px"><option value="">—</option><?php foreach ($JOBS as $jk=>$jl): ?><option value="<?= e($jk) ?>"<?= ($schedule['job_type']??'')===$jk?' selected':'' ?>><?= e($jl) ?></option><?php endforeach; ?></select>
      </label>
      <label>Default assignee <span class="text-muted">(optional)</span><?php $asgSelect('assigned_to', $schedule['assigned_to'] !== null ? (int)$schedule['assigned_to'] : null); ?></label>
      <div><button type="submit" class="btn-primary">Save details</button></div>
    </form>
    <p class="text-muted" style="margin:10px 0 0;font-size:12px">Property is fixed at <strong><?= e($schedule['venue_name'] ?? '') ?></strong>. The default assignee is used for new recurring tasks that don’t name their own.</p>
  </div>
</div>

<!-- Recurring tasks -->
<div class="card">
  <div class="card__head"><span class="card__title">Recurring tasks</span></div>
  <div class="card__body card__body--pad">
    <?php
    if (!$items) { echo '<p class="text-muted" style="margin:0 0 4px;font-size:13px">No recurring tasks yet — add the first one below.</p>'; }
    else {
        echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>Task</th><th>Frequency</th><th>Time</th><th>Assignee</th><th>Next</th><th>Status</th><th style="width:1%;text-align:right">Actions</th></tr></thead><tbody>';
        foreach ($items as $r) {
            $rid = (int)$r['id'];
            $freq = recurring_freq_label((string)$r['frequency'], $r['interval_days'] !== null ? (int)$r['interval_days'] : null);
            $time = $r['time_of_day'] ? date('g:i A', strtotime((string)$r['time_of_day'])) : '—';
            $asg  = !empty($r['assignee_name']) ? e($r['assignee_name']) : '<span class="text-muted">Unassigned</span>';
            $next = $r['next_run_date'] ? e(date('j M Y', strtotime((string)$r['next_run_date']))) : '—';
            $active = !empty($r['is_active']) && $r['is_active'] !== 'f';
            echo '<tr><td><strong>' . e($r['title']) . '</strong>';
            if (!empty($r['detail'])) echo '<br><span class="text-muted" style="font-size:12px">' . e($r['detail']) . '</span>';
            echo '</td><td>' . e($freq) . '</td><td>' . e($time) . '</td><td>' . $asg . '</td><td>' . $next . '</td>';
            echo '<td><span class="badge ' . ($active ? 'badge--green' : 'badge--grey') . '">' . ($active ? 'Active' : 'Paused') . '</span></td>';
            echo '<td style="text-align:right"><span class="dt-actions">'
               . '<form method="POST" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="' . $rid . '"><button class="btn-icon btn-icon--outline" title="' . ($active?'Pause':'Resume') . '" aria-label="Toggle">' . admin_icon($active?'x':'check') . '</button></form>'
               . '<form method="POST" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="' . $rid . '"><button class="btn-icon btn-icon--danger" title="Delete" aria-label="Delete" data-confirm="Delete this recurring task? Already-created tasks are kept.">' . admin_icon('trash') . '</button></form>'
               . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    ?>

    <form method="POST" action="/admin/task-schedule-edit.php?id=<?= $id ?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;margin-top:14px;padding-top:14px;border-top:1px dashed #e2dbcd">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_item">
      <label style="grid-column:1/-1;font-size:12px;color:var(--muted)">New recurring task
        <input type="text" name="title" required placeholder="e.g. Cut grass" class="inp" style="width:100%;margin-top:4px">
      </label>
      <label style="font-size:12px;color:var(--muted)">Frequency<?php $freqSelect("frequency"); ?></label>
      <label style="font-size:12px;color:var(--muted)">Every N days <span style="opacity:.6">(custom)</span><input type="number" name="interval_days" min="1" placeholder="e.g. 10" class="inp" style="width:100%"></label>
      <label style="font-size:12px;color:var(--muted)">Time <span style="opacity:.6">(optional)</span><input type="time" name="time_of_day" class="inp" style="width:100%"></label>
      <label style="font-size:12px;color:var(--muted)">Assign to<?php $asgSelect("assigned_to", null); ?></label>
      <label style="font-size:12px;color:var(--muted)">Start date <span style="opacity:.6">(optional)</span>
        <button type="button" class="dp-btn" data-dp-target="start_item" data-dp-placeholder="Today" style="width:100%;margin-top:4px">Today</button>
        <input type="hidden" id="start_item" name="start_date">
      </label>
      <div><button type="submit" class="btn-primary btn-sm">Add</button></div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
