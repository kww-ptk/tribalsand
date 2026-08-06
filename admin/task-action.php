<?php
/**
 * Admin: task status transitions. Posted from the manager Tasks board and from a
 * staff member's My Work. Owner/manager (in scope) may set any status; the
 * assignee may move their own task between todo / in progress / done.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';   // team helpers
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
verify_csrf();

$returnTo = ($_POST['return'] ?? '') === 'mywork' ? '/admin/mywork.php' : '/admin/tasks.php';

$id     = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$task   = $id ? fetch_task($id) : false;

if (!$task) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Task not found.'];
    header('Location: ' . $returnTo); exit;
}
if (!in_array($status, ['todo','in_progress','done','cancelled'], true)) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Unknown task status.'];
    header('Location: ' . $returnTo); exit;
}

$meId       = (int)($_SESSION['admin_id'] ?? 0);
$venueIds   = admin_venue_ids();                       // null = owner (all)
$inScope    = $venueIds === null || in_array((int)$task['venue_id'], $venueIds, true);
$canManage  = is_owner() || (is_manager() && $inScope);
$isAssignee = (int)($task['assigned_to'] ?? 0) === $meId && $meId > 0;

if (!$canManage && !$isAssignee) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That task isn’t yours to update.'];
    header('Location: ' . $returnTo); exit;
}
// Only managers/owner may cancel a task; the assignee can move it through the queue.
if ($status === 'cancelled' && !$canManage) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Only a manager can cancel a task.'];
    header('Location: ' . $returnTo); exit;
}

db_query("UPDATE tasks SET status = :s WHERE id = :id", [':s'=>$status, ':id'=>$id]);
audit_log('task.' . $status, 'task', $id, '');
$_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Task marked ' . task_status_label($status) . '.'];
header('Location: ' . $returnTo); exit;
