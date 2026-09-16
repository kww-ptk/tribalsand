<?php
declare(strict_types=1);
/**
 * Live-messaging endpoint (JSON) for the submission_notes thread — shared by two
 * audiences so both the admin submission view and the agent request view update
 * without a page refresh (short-polling, mirroring admin/messages-poll.php):
 *
 *   GET  ?id=<submission>&after=<note id>  → notes newer than <after>
 *   POST {id, body, csrf_token[, kind]}    → add a note, returns the created note
 *
 * Authorisation is per audience and re-checked on every call:
 *   • Admin session  → current_admin() + submission_in_scope(); sees ALL notes;
 *                      may post an internal note or a reply (no email here — the
 *                      emailed-reply path stays on the submission-view PRG form).
 *   • Agent session  → agent_fetch_request() ownership; sees ONLY reply /
 *                      guest_reply; a post is a guest_reply (via agent_post_message).
 * Never mixes the two: an admin guard is never satisfied by an agent session.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/submission-notes.php';

header('Content-Type: application/json');
$out = function (int $code, array $p): never { http_response_code($code); echo json_encode($p); exit; };

// Resolve the viewer: admin first, then agent. Exactly one.
$admin = current_admin();
$agent = $admin ? false : agent_current();
$role  = $admin ? 'admin' : ($agent ? 'agent' : null);
if ($role === null) $out(401, ['ok' => false, 'error' => 'Not signed in.']);

/** True if this viewer may see/act on submission $id. */
$authorize = function (int $id) use ($role, $agent): bool {
    if ($id <= 0) return false;
    if ($role === 'admin') return submission_in_scope($id);
    return agent_fetch_request($agent, $id) !== null;   // ownership re-check
};

// ── GET: poll for new notes ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id    = (int)($_GET['id'] ?? 0);
    $after = max(0, (int)($_GET['after'] ?? 0));
    if (!$authorize($id)) $out(403, ['ok' => false, 'error' => 'Not available.']);

    $rows = $role === 'admin'
        ? fetch_submission_notes_since($id, $after)
        : fetch_agent_visible_thread($id, $after);
    // Staff viewing the thread clears the unread marker (Item 4).
    if ($role === 'admin' && $rows) submission_mark_reply_seen($id);

    $notes  = array_map(fn($n) => submission_thread_payload($n, $role), $rows);
    $lastId = $notes ? (int) end($notes)['id'] : $after;
    $out(200, ['ok' => true, 'notes' => $notes, 'last_id' => $lastId]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'error' => 'Method not allowed.']);

// ── POST: add a note (JSON body) ─────────────────────────────────────────────
$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) $data = [];

// CSRF token in the body (verify_csrf reads $_POST, which a JSON fetch doesn't fill).
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($data['csrf_token'] ?? ''))) {
    $out(403, ['ok' => false, 'error' => 'Invalid session token. Please reload.']);
}

$id   = (int)($data['id'] ?? 0);
$body = trim((string)($data['body'] ?? ''));
if (!$authorize($id)) $out(403, ['ok' => false, 'error' => 'Not available.']);
if ($body === '')     $out(422, ['ok' => false, 'error' => 'Please type a message.']);
$body = mb_substr($body, 0, 4000);

if (!submission_notes_supported()) $out(503, ['ok' => false, 'error' => 'Messaging is unavailable right now.']);

try {
    if ($role === 'agent') {
        $res = agent_post_message($agent, $id, $body);
        if (!$res['ok']) $out((int)($res['code'] ?? 422), ['ok' => false, 'error' => $res['error']]);
        $noteId = (int)$res['note_id'];
    } else {
        // Admin: an internal note, or a reply logged to the thread (no email).
        $kind = ($data['kind'] ?? 'note') === 'reply' ? 'reply' : 'note';
        $me   = current_admin();
        $name = $me ? (trim((string)($me['name'] ?? '')) ?: (string)($me['email'] ?? '')) : 'Admin';
        $noteId = add_submission_note($id, (int)($_SESSION['admin_id'] ?? 0) ?: null, $body, $kind, $name);
        if (!$noteId) $out(500, ['ok' => false, 'error' => 'Could not save to the thread.']);
    }
} catch (Throwable $e) {
    error_log('[submission-thread] post failed: ' . $e->getMessage());
    $out(500, ['ok' => false, 'error' => 'Could not send your message. Please try again.']);
}

// Return the created note in the same shape the poller renders (kind-safe select).
$kindSel = submission_notes_kind_supported()
    ? "n.kind, NULLIF(n.author_name,'') AS frozen_author,"
    : "'note'::text AS kind, NULL::text AS frozen_author,";
$row = db_query(
    "SELECT n.id, n.body, n.created_at, {$kindSel}
            a.name AS author_name, a.email AS author_email
       FROM submission_notes n LEFT JOIN admin_users a ON a.id = n.admin_id
      WHERE n.id = :id",
    [':id' => $noteId]
)->fetch();
$out(200, ['ok' => true, 'note' => $row ? submission_thread_payload($row, $role) : null]);
