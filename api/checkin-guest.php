<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

// Managing the party roster is a LEAD action — requires the hold magic link (no ?g=).
$ref    = trim((string)($_POST['ref'] ?? ''));
$holdId = verify_guest_ref($ref);
if (!$holdId) { http_response_code(403); echo json_encode(['error' => 'bad ref']); exit; }
verify_csrf();
$hold = fetch_hold_for_guest($holdId);
if (!$hold || !checkin_required($hold)) { http_response_code(403); echo json_encode(['error' => 'not applicable']); exit; }

$action = $_POST['action'] ?? '';
$s = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;

if ($action === 'add_adult') {
    $need = max(1, (int)($hold['guest_count'] ?? 1));
    $have = (int)db_query('SELECT COUNT(*) FROM checkin_guests WHERE hold_id = :h AND is_child = FALSE', [':h' => $holdId])->fetchColumn();
    if ($have >= $need) { http_response_code(409); echo json_encode(['error' => 'party full']); exit; }
    db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, passport_name) VALUES (:h, FALSE, FALSE, :n)',
        [':h' => $holdId, ':n' => $s('passport_name')]);
    $gid = (int)db()->lastInsertId();
    audit_log('checkin.guest_add', 'hold', $holdId, 'adult ' . $gid);
    echo json_encode(['ok' => true, 'guest_id' => $gid, 'name' => (string)($_POST['passport_name'] ?? ''), 'link' => make_guest_pass_url($holdId, $gid)]);
    exit;
}

if ($action === 'add_child') {
    $parent = (int)($_POST['parent_guest_id'] ?? 0);
    if ($parent > 0) {
        $ok = db_query('SELECT 1 FROM checkin_guests WHERE id = :p AND hold_id = :h AND is_child = FALSE', [':p' => $parent, ':h' => $holdId])->fetchColumn();
        if (!$ok) { http_response_code(400); echo json_encode(['error' => 'bad parent']); exit; }
    } else {
        $parent = (int)db_query('SELECT id FROM checkin_guests WHERE hold_id = :h AND is_lead', [':h' => $holdId])->fetchColumn() ?: null;
    }
    $dob = ($_POST['date_of_birth'] ?? '') !== '' ? (string)$_POST['date_of_birth'] : null;
    db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, parent_guest_id, passport_name, date_of_birth) VALUES (:h, FALSE, TRUE, :p, :n, :dob)',
        [':h' => $holdId, ':p' => $parent, ':n' => $s('passport_name'), ':dob' => $dob]);
    $gid = (int)db()->lastInsertId();
    echo json_encode(['ok' => true, 'guest_id' => $gid, 'name' => (string)($_POST['passport_name'] ?? '')]);
    exit;
}

if ($action === 'remove') {
    $gid = (int)($_POST['guest_id'] ?? 0);
    $row = db_query('SELECT is_lead FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $gid, ':h' => $holdId])->fetch();
    if (!$row || !empty($row['is_lead'])) { http_response_code(400); echo json_encode(['error' => 'cannot remove']); exit; }
    // Gather this row's + its children's file keys before the cascade delete removes them.
    $files = db_query('SELECT passport_file_key FROM checkin_guests WHERE (id = :g OR parent_guest_id = :g) AND hold_id = :h', [':g' => $gid, ':h' => $holdId])->fetchAll(PDO::FETCH_COLUMN);
    db_query('DELETE FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $gid, ':h' => $holdId]);   // children cascade via FK
    foreach ($files as $fk) { if (!empty($fk)) { try { storage_delete_private($fk); } catch (Throwable $e) {} } }
    checkin_recompute_completion($holdId);
    http_response_code(204); exit;
}

http_response_code(400); echo json_encode(['error' => 'unknown action']);
