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

$type   = $_POST['type']   ?? '';   // 'addon' | 'change'
$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

$ok = false;
if ($type === 'addon' && $id && in_array($status, ['confirmed', 'declined', 'cancelled'], true)) {
    db_query("UPDATE booking_addons SET status=:s WHERE id=:id", [':s' => $status, ':id' => $id]);
    audit_log('booking_addon.' . $status, 'booking_addon', $id, 'admin action');
    $ok = true;
} elseif ($type === 'change' && $id && in_array($status, ['handled', 'declined'], true)) {
    db_query("UPDATE booking_change_requests SET status=:s WHERE id=:id", [':s' => $status, ':id' => $id]);
    audit_log('booking_change.' . $status, 'booking_change_request', $id, 'admin action');
    $ok = true;
}

$_SESSION['hold_flash'] = $ok
    ? ['type' => 'success', 'msg' => ucfirst($type) . " request marked {$status}."]
    : ['type' => 'error',   'msg' => 'Invalid request action.'];

header('Location: /admin/holds.php');
exit;
