<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

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
elseif ($rk === 'workspace')  { $returnTo = '/admin/booking.php?hold=' . (int)($_POST['hold_id'] ?? 0) . '&tab=requests'; }
else { $returnTo = '/admin/holds.php'; }

$type   = $_POST['type']   ?? '';   // 'addon' | 'change'
$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

$ok = false;
if ($type === 'addon' && in_array($status, ['confirmed', 'declined', 'cancelled', 'completed'], true) && $id) {
    $cur = db_query("SELECT status FROM booking_addons WHERE id=:id", [':id' => $id])->fetch();
    if (!$cur) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Add-on request #{$id} not found."];
        header('Location: ' . $returnTo);
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
    db_query(
        "UPDATE booking_addons SET status=:s WHERE id=:id AND status IN (" . implode(',', $fromNames) . ")",
        [':s' => $status, ':id' => $id] + $fromParams
    );
    audit_log('booking_addon.' . $status, 'booking_addon', $id, 'admin action');
    $ok = true;
} elseif ($type === 'change' && in_array($status, ['handled', 'declined'], true) && $id) {
    $cur = db_query("SELECT status FROM booking_change_requests WHERE id=:id", [':id' => $id])->fetch();
    if (!$cur) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Change request #{$id} not found."];
        header('Location: ' . $returnTo);
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
}

$_SESSION['hold_flash'] = $ok
    ? ['type' => 'success', 'msg' => ucfirst($type) . " request marked {$status}."]
    : ['type' => 'error',   'msg' => 'Invalid request action.'];

header('Location: ' . $returnTo);
exit;
