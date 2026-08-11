<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

header('Content-Type: application/json');

$str = fn($v) => is_scalar($v) ? trim((string)$v) : '';

/** Resolve a thread param ('general' | '<addon_id>' | '') to an addon id or null, hold-scoped. */
$resolve_addon = function (array $hold, $threadRaw) {
    if ($threadRaw === '' || $threadRaw === null || $threadRaw === 'general') return [null, true];
    $aid = (int)$threadRaw;
    $own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$aid, ':h'=>$hold['id']])->fetchColumn();
    return [$aid, (bool)$own];
};

// ── GET: live poll for new messages in a thread (guest-authed by ref) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = resolve_portal_actor($str($_GET['ref'] ?? $_GET['g'] ?? ''));
    $hold = $actor ? $actor['hold'] : false;
    if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
    [$addonId, $own] = $resolve_addon($hold, $_GET['thread'] ?? '');
    if (!$own) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown request.'])); }
    $after = (int)($_GET['after'] ?? 0);
    $rows  = fetch_thread_messages_since((int)$hold['id'], $addonId, $after);
    // The guest is looking at this thread — admin messages they now receive are read.
    if ($rows) mark_thread_read_by_guest((int)$hold['id'], $addonId);
    $msgs = array_map('message_payload', $rows);
    $lastId = $msgs ? end($msgs)['id'] : $after;
    exit(json_encode(['ok'=>true, 'messages'=>$msgs, 'last_id'=>$lastId]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$actor = resolve_portal_actor($str($data['ref'] ?? $data['g'] ?? ''));
$hold = $actor ? $actor['hold'] : false;
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }

$body = $str($data['body'] ?? '');
if ($body === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please type a message.'])); }
if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);

$addonId = null;
if (($data['addon_id'] ?? '') !== '' && $data['addon_id'] !== null) {
    $addonId = (int)$data['addon_id'];
    $own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$addonId, ':h'=>$hold['id']])->fetchColumn();
    if (!$own) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown request.'])); }
}

// Rate limit: max 20 messages per hold / 10 min
$cnt = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND created_at > NOW() - INTERVAL '10 minutes'", [':h'=>$hold['id']])->fetchColumn();
if ($cnt >= 20) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many messages. Please wait a few minutes.'])); }

try {
    if (message_sender_guest_supported()) {
        db_query(
            "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin, sender_guest_id)
             VALUES (:h, :a, 'guest', :b, TRUE, FALSE, :sg)",
            [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body, ':sg'=>(int)$actor['guest_id']]
        );
    } else {
        db_query(
            "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
             VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
            [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body]
        );
    }
    $id = (int)db()->lastInsertId();
    echo json_encode(['ok'=>true, 'message'=>message_payload([
        'id'=>$id, 'sender'=>'guest', 'body'=>$body, 'created_at'=>'now',
    ])]);
} catch (Throwable $e) {
    error_log('[booking-message] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not send your message. Please try again.']);
}
