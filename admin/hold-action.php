<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/booking.php';

// Store intended URL so admin lands here after login if session expired
session_init();
if (empty($_SESSION['admin_id'])) {
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: /admin/login.php');
    exit;
}

require_login();

$id     = (int)($_GET['id']     ?? 0);
$action = trim($_GET['action']  ?? '');
$token  = trim($_GET['t']       ?? '');

// Validate inputs
if (!$id || !in_array($action, ['confirm', 'decline'], true) || !$token) {
    http_response_code(400);
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Invalid action link — missing required parameters.'];
    header('Location: /admin/holds.php');
    exit;
}

// Verify HMAC token
if (!verify_hold_token($id, $action, $token)) {
    http_response_code(403);
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Invalid or tampered action link. Please use the buttons in admin instead.'];
    header('Location: /admin/holds.php');
    exit;
}

// Fetch hold with room/unit names for email
$hold = db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name
     FROM holds h
     JOIN units u ON u.id = h.unit_id
     JOIN rooms r ON r.id = u.room_id
     WHERE h.id = :id",
    [':id' => $id]
)->fetch();

if (!$hold) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Hold #{$id} not found."];
    header('Location: /admin/holds.php');
    exit;
}

$status = $hold['status'];

if ($action === 'confirm') {
    if ($status !== 'pending') {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Hold #{$id} is already {$status} — no action taken."];
        header('Location: /admin/holds.php');
        exit;
    }
    db_query("UPDATE holds SET status='confirmed', confirmed_at=NOW() WHERE id=:id", [':id' => $id]);
    db_query("UPDATE availability_blocks SET block_type='booked' WHERE hold_id=:hid", [':hid' => $id]);
    if ($hold['guest_email']) send_hold_confirmed($hold);
    audit_log('hold.confirm', 'hold', $id, "via email link — {$hold['guest_name']} {$hold['check_in']}→{$hold['check_out']}");
    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => "Hold #{$id} confirmed — confirmation email sent to {$hold['guest_email']}."];

} elseif ($action === 'decline') {
    if (!in_array($status, ['pending', 'confirmed'], true)) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Hold #{$id} is already {$status} — no action taken."];
        header('Location: /admin/holds.php');
        exit;
    }
    db_query("UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id", [':id' => $id]);
    db_query("DELETE FROM availability_blocks WHERE hold_id=:hid", [':hid' => $id]);
    if ($hold['guest_email']) send_hold_cancelled($hold, 'cancelled');
    audit_log('hold.decline', 'hold', $id, "via email link — {$hold['guest_name']} {$hold['check_in']}→{$hold['check_out']}");
    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => "Hold #{$id} declined — dates freed and guest notified."];
}

header('Location: /admin/holds.php');
exit;
