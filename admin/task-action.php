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

// The button posts FormData (not a JSON body) precisely so verify_csrf() — which
// reads $_POST — keeps working unchanged, with no CSRF-in-body special case.
// A caller asks for JSON with format=json; everything else still gets the PRG
// redirect, which is the no-JS fallback.
$wantsJson = ($_POST['format'] ?? '') === 'json';

/** Answer in the format the caller asked for. Never returns. */
function task_action_respond(bool $ok, string $msg, array $extra = []): void {
    if ($GLOBALS['wantsJson']) {
        header('Content-Type: application/json');
        if (!$ok) http_response_code(400);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg] + $extra);
        exit;
    }
    $_SESSION['hold_flash'] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
    header('Location: ' . $GLOBALS['returnTo']);
    exit;
}

$id     = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$task   = $id ? fetch_task($id) : false;

if (!$task) {
    task_action_respond(false, 'Task not found.');
}
if (!in_array($status, ['todo','in_progress','done','cancelled'], true)) {
    task_action_respond(false, 'Unknown task status.');
}

$meId       = (int)($_SESSION['admin_id'] ?? 0);
$venueIds   = admin_venue_ids();                       // null = owner (all)
$inScope    = $venueIds === null || in_array((int)$task['venue_id'], $venueIds, true);
$canManage  = is_owner() || ((is_manager() || is_reception()) && $inScope);
$isAssignee = (int)($task['assigned_to'] ?? 0) === $meId && $meId > 0;

if (!$canManage && !$isAssignee) {
    task_action_respond(false, 'That task isn’t yours to update.');
}
// Only managers/owner may cancel a task; the assignee can move it through the queue.
if ($status === 'cancelled' && !$canManage) {
    task_action_respond(false, 'Only a manager can cancel a task.');
}

db_query("UPDATE tasks SET status = :s WHERE id = :id", [':s'=>$status, ':id'=>$id]);
audit_log('task.' . $status, 'task', $id, '');
task_action_respond(true, 'Task marked ' . task_status_label($status) . '.', [
    'id'     => $id,
    'status' => $status,
    'label'  => task_status_label($status),
    'badge'  => task_badge_class($status),
]);
