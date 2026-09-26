<?php
declare(strict_types=1);
/**
 * POS till — session actions.
 *   GET                                  → heartbeat: {ok} while unlocked (keeps the PIN session alive), 401 when locked
 *   POST {action:'pin', user_id, pin}    → unlock a registered terminal
 *   POST {action:'lock'}                 → lock the till (the terminal stays registered)
 * POSTs carry csrf_token in the JSON body. PIN unlock needs a valid terminal cookie
 * and is rate-limited per user and per IP (pos_pin_login()).
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
session_init();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    pos_api_guard(false);
    exit(json_encode(['ok' => true]));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed'])); }

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$sess = (string)($_SESSION['csrf_token'] ?? '');
if ($sess === '' || !hash_equals($sess, (string)($body['csrf_token'] ?? ''))) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Session expired — reload the till.']));
}

$action = (string)($body['action'] ?? '');
if ($action === 'lock') {
    pos_lock();
    exit(json_encode(['ok' => true]));
}
if ($action === 'pin') {
    $term = pos_current_terminal();
    if (!$term) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'This tablet is not registered as a till.'])); }
    $pin = (string)($body['pin'] ?? '');
    if (!preg_match('/^\d{4,6}$/', $pin)) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Enter your 4–6 digit PIN.'])); }
    $r = pos_pin_login($term, (int)($body['user_id'] ?? 0), $pin, client_ip());
    if (!$r['ok']) { http_response_code(!empty($r['locked']) ? 429 : 401); exit(json_encode($r)); }
    exit(json_encode(['ok' => true, 'csrf_token' => csrf_token()]));
}
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
