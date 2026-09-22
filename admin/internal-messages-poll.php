<?php
/**
 * Internal team-chat live endpoint (JSON) for admin/internal-messages.php.
 *   GET  ?channel=all|<venue_id>&after=<id>  → new messages since <id>, marks the channel read
 *   POST {channel, body, csrf_token}         → post a message, returns the created row
 * Session-authed and channel-scoped. Broader audience than guest messaging:
 * any signed-in account (incl. ops & gate staff) may use the all-team channel;
 * venue channels are limited to the accounts assigned to that venue.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/internal-messages.php';

header('Content-Type: application/json');

$admin = current_admin();
if (!$admin) { http_response_code(401); exit(json_encode(['ok'=>false,'error'=>'Not signed in.'])); }
if (!internal_messages_supported()) { http_response_code(503); exit(json_encode(['ok'=>false,'error'=>'Team chat unavailable.'])); }

$meId = (int)$admin['id'];

/** Resolve a channel param ('all' | '<venue_id>' | 'g<group_id>') → [venueId, groupId, ok]. */
$resolve = function ($raw) use ($meId): array {
    [$venueId, $groupId] = internal_parse_channel((string)$raw);
    if ($groupId)       return [null, $groupId, internal_can_access_group($groupId, $meId)];
    if ($venueId)       return [$venueId, null, internal_can_access_channel($venueId)];
    return [null, null, true]; // all-team
};

// ── GET: poll for new messages ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    [$venueId, $groupId, $ok] = $resolve($_GET['channel'] ?? 'all');
    if (!$ok) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Not your channel.'])); }
    $after = (int)($_GET['after'] ?? 0);
    $rows  = fetch_internal_messages_since($venueId, $after, 200, $groupId);
    $msgs  = array_map(fn($r) => internal_message_payload($r, $meId), $rows);
    $lastId = $msgs ? end($msgs)['id'] : $after;
    if ($lastId > $after) internal_mark_channel_read($meId, $venueId, $lastId, $groupId);
    exit(json_encode(['ok'=>true, 'messages'=>$msgs, 'last_id'=>$lastId]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

// ── POST: send a message ──
$data  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = (string)($data['csrf_token'] ?? '');
if (($_SESSION['csrf_token'] ?? '') === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Invalid session token. Please reload.']));
}

[$venueId, $groupId, $ok] = $resolve($data['channel'] ?? 'all');
if (!$ok) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Not your channel.'])); }

$body = trim((string)($data['body'] ?? ''));
if ($body === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Type a message.'])); }

try {
    $id = post_internal_message($venueId, $meId, $body, $groupId);
    internal_mark_channel_read($meId, $venueId, $id, $groupId); // sender has "read" their own message
    audit_log('internal_message.post', $groupId ? 'internal_channel' : 'venue', ($groupId ?: $venueId) ?? 0, '');
    echo json_encode(['ok'=>true, 'message'=>internal_message_payload([
        'id'=>$id, 'sender_admin_id'=>$meId, 'sender_name'=>$admin['name'] ?? 'Team',
        'body'=>$body, 'created_at'=>'now',
    ], $meId)]);
} catch (Throwable $e) {
    error_log('[internal-messages-poll] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not send. Please try again.']);
}
