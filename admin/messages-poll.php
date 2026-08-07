<?php
/**
 * Admin live-messaging endpoint (JSON) for admin/messages.php.
 *   GET  ?hold=&thread=general|<addon>&after=<id>  → new messages since <id>, marks them read by admin
 *   POST {hold_id, addon_id, body, csrf_token}      → send a staff reply, returns the created message
 * Session-authed, venue-scoped (staff_can_hold), and gated to the front-desk audience.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

header('Content-Type: application/json');

// Not-logged-in / wrong-role must not redirect (HTML) into a JSON fetch — answer with JSON.
if (!current_admin()) { http_response_code(401); exit(json_encode(['ok'=>false,'error'=>'Not signed in.'])); }
$job = admin_job();
if (is_staff() && (job_is_ops($job) || $job === 'security')) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Not available for your account.']));
}

/** Resolve a thread param ('general' | '<addon_id>') to an addon id or null, hold-scoped. */
$resolve_addon = function (int $holdId, $threadRaw): array {
    if ($threadRaw === '' || $threadRaw === null || $threadRaw === 'general') return [null, true];
    $aid = (int)$threadRaw;
    $own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$aid, ':h'=>$holdId])->fetchColumn();
    return [$aid, (bool)$own];
};

// ── GET: poll for new messages ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $holdId = (int)($_GET['hold'] ?? 0);
    if ($holdId <= 0 || (!is_owner() && !staff_can_hold($holdId))) {
        http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Not your property.']));
    }
    [$addonId, $own] = $resolve_addon($holdId, $_GET['thread'] ?? '');
    if (!$own) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown thread.'])); }
    $after = (int)($_GET['after'] ?? 0);
    $rows  = fetch_thread_messages_since($holdId, $addonId, $after);
    if ($rows) mark_thread_read_by_admin($holdId, $addonId);   // staff is viewing this thread
    $msgs = array_map('message_payload', $rows);
    $lastId = $msgs ? end($msgs)['id'] : $after;
    exit(json_encode(['ok'=>true, 'messages'=>$msgs, 'last_id'=>$lastId]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

// ── POST: send a staff reply ──
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF for the JSON body (verify_csrf() reads $_POST, which a JSON fetch does not populate).
$token = (string)($data['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Invalid session token. Please reload.']));
}

$holdId = (int)($data['hold_id'] ?? 0);
if ($holdId <= 0 || (!is_owner() && !staff_can_hold($holdId))) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Not your property.']));
}
[$addonId, $own] = $resolve_addon($holdId, ($data['addon_id'] ?? '') === '' ? 'general' : $data['addon_id']);
if (!$own) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown thread.'])); }

$body = trim((string)($data['body'] ?? ''));
if ($body === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please type a reply.'])); }
if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);

// Confirm the hold still exists (mirrors the PRG path in messages.php).
if (!db_query("SELECT 1 FROM holds WHERE id=:h", [':h'=>$holdId])->fetchColumn()) {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That conversation no longer exists.']));
}

try {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'admin', :b, FALSE, TRUE)",
        [':h'=>$holdId, ':a'=>$addonId, ':b'=>$body]
    );
    $id = (int)db()->lastInsertId();
    audit_log('booking_message.admin_reply', 'hold', $holdId, '');
    echo json_encode(['ok'=>true, 'message'=>message_payload([
        'id'=>$id, 'sender'=>'admin', 'body'=>$body, 'created_at'=>'now',
    ])]);
} catch (Throwable $e) {
    error_log('[messages-poll] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not send your reply. Please try again.']);
}
