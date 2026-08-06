<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

// Store intended URL so admin lands here after login if session expired
session_init();
if (empty($_SESSION['admin_id'])) {
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: /admin/login.php');
    exit;
}

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

verify_csrf();

$rk = $_POST['return'] ?? '';
if ($rk === 'concierge-desk') { $returnTo = '/admin/concierge-desk.php'; }
elseif ($rk === 'mywork')     { $returnTo = '/admin/mywork.php'; }
elseif ($rk === 'workspace')  { $returnTo = '/admin/booking.php?hold=' . (int)($_POST['hold_id'] ?? 0) . '&tab=requests'; }
else { $returnTo = '/admin/holds.php'; }

$type   = $_POST['type']   ?? '';   // 'addon' | 'change'
$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

$ok = false;
if ($type === 'addon' && in_array($status, ['confirmed', 'declined', 'cancelled', 'completed'], true) && $id) {
    $cur = db_query("SELECT status, hold_id FROM booking_addons WHERE id=:id", [':id' => $id])->fetch();
    if (!$cur) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Add-on request #{$id} not found."];
        header('Location: ' . $returnTo);
        exit;
    }
    if (!is_owner() && !staff_can_hold((int)$cur['hold_id'])) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Not your property.'];
        header('Location: /admin/concierge-desk.php');
        exit;
    }
    $allowedFrom = $status === 'completed' ? ['requested', 'confirmed'] : ['requested'];
    if (!in_array($cur['status'], $allowedFrom, true)) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Add-on request #{$id} is already {$cur['status']} — no action taken."];
        header('Location: ' . $returnTo);
        exit;
    }
    $fromParams = [];
    $fromNames  = [];
    foreach ($allowedFrom as $i => $s) { $n = ":from{$i}"; $fromNames[] = $n; $fromParams[$n] = $s; }
    $upd = db_query(
        "UPDATE booking_addons SET status=:s WHERE id=:id AND status IN (" . implode(',', $fromNames) . ")",
        [':s' => $status, ':id' => $id] + $fromParams
    );
    // Only fire side effects for the request that actually transitioned here — a
    // concurrent double-submit updates 0 rows on the loser, so it won't double
    // audit-log or double-post the status message.
    if ($upd->rowCount() === 1) {
        audit_log('booking_addon.' . $status, 'booking_addon', $id, 'admin action');
        $__statusMsg = request_status_message($status);
        if ($__statusMsg !== '') {
            try { post_admin_message((int)$cur['hold_id'], $id, $__statusMsg); }
            catch (Throwable $e) { error_log('[request-action] status message failed: ' . $e->getMessage()); }
        }
    }

    $ok = true;
} elseif ($type === 'change' && in_array($status, ['handled', 'declined'], true) && $id) {
    $cur = db_query("SELECT status, hold_id FROM booking_change_requests WHERE id=:id", [':id' => $id])->fetch();
    if (!$cur) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Change request #{$id} not found."];
        header('Location: ' . $returnTo);
        exit;
    }
    if (!is_owner() && !staff_can_hold((int)$cur['hold_id'])) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Not your property.'];
        header('Location: /admin/concierge-desk.php');
        exit;
    }
    if ($cur['status'] !== 'requested') {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Change request #{$id} is already {$cur['status']} — no action taken."];
        header('Location: ' . $returnTo);
        exit;
    }
    db_query("UPDATE booking_change_requests SET status=:s WHERE id=:id AND status='requested'", [':s' => $status, ':id' => $id]);
    audit_log('booking_change.' . $status, 'booking_change_request', $id, 'admin action');
    $ok = true;
} elseif ($type === 'assign' && $id) {
    // (Re)assign a request to a team member — owner/manager only.
    if (!is_owner() && !is_manager()) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Only managers can assign requests.'];
        header('Location: ' . $returnTo); exit;
    }
    if (!addon_assigned_supported()) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Assignment isn’t enabled yet — run the migration first.'];
        header('Location: ' . $returnTo); exit;
    }
    $cur = db_query("SELECT status, hold_id FROM booking_addons WHERE id=:id", [':id' => $id])->fetch();
    if (!$cur) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Request #{$id} not found."];
        header('Location: ' . $returnTo); exit;
    }
    if (!is_owner() && !staff_can_hold((int)$cur['hold_id'])) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Not your property.'];
        header('Location: /admin/concierge-desk.php'); exit;
    }
    $raw      = trim((string)($_POST['assigned_to'] ?? ''));
    $assignee = ($raw === '' || $raw === '0') ? null : (int)$raw;
    if ($assignee !== null && !team_can_be_assigned($assignee, (int)$cur['hold_id'])) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'That person can’t be assigned to this property.'];
        header('Location: ' . $returnTo); exit;
    }
    db_query("UPDATE booking_addons SET assigned_to=:a WHERE id=:id", [':a' => $assignee, ':id' => $id]);
    audit_log('booking_addon.assign', 'booking_addon', $id, 'assigned_to=' . ($assignee ?? 'none'));
    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => $assignee === null
        ? 'Request unassigned.'
        : 'Assigned to ' . (team_member_name($assignee) ?: 'team member') . '.'];
    header('Location: ' . $returnTo); exit;
}

$_SESSION['hold_flash'] = $ok
    ? ['type' => 'success', 'msg' => ucfirst($type) . " request marked {$status}."]
    : ['type' => 'error',   'msg' => 'Invalid request action.'];

header('Location: ' . $returnTo);
exit;
