<?php
declare(strict_types=1);
/**
 * Trade portal — an agent posts a message on ONE of their own requests.
 *
 * Agent-session-authed (never an admin guard), CSRF-checked, and ownership is
 * re-verified inside agent_post_message() with the same signed filter the rest of
 * the portal uses — the submission id from the form is NEVER trusted on its own.
 * It writes a `guest_reply` note (the blue bubble reservations already read in
 * admin/submission-view.php) and raises the Item-4 unread flag. It NEVER places a
 * hold. Plain form POST + PRG so it works with JS off; flashes via the session.
 */
require_once __DIR__ . '/../includes/agent.php';

agent_require_login();
$agent = agent_current();

$id = (int)($_POST['submission_id'] ?? 0);
$back = '/agent/request-view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /agent/requests.php'); exit; }
verify_csrf();

// Per-agent throttle — an authenticated surface has no Turnstile, so a scripted
// or leaked session must not be able to flood the inbox with messages.
if (($throttled = agent_request_throttled($agent)) !== null) {
    $_SESSION['agent_msg_flash'] = ['type' => 'error', 'msg' => $throttled];
    header('Location: ' . $back); exit;
}

$res = agent_post_message($agent, $id, (string)($_POST['body'] ?? ''));

$_SESSION['agent_msg_flash'] = $res['ok']
    ? ['type' => 'success', 'msg' => 'Message sent to reservations.']
    : ['type' => 'error', 'msg' => (string)($res['error'] ?? 'Could not send your message.')];

// A failed ownership check has no valid request to return to.
if (!$res['ok'] && ($res['code'] ?? 0) === 404) { header('Location: /agent/requests.php'); exit; }
header('Location: ' . $back);
exit;
