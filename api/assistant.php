<?php
declare(strict_types=1);
/**
 * AI availability & price assistant — admin endpoint (JSON).
 *   POST {message, history?[{role,text}], csrf_token} → {ok, answer, tool_calls, tool_result?}
 *
 * Session-authed (admin), CSRF-checked (token in the JSON body, since
 * verify_csrf() reads $_POST which a JSON fetch does not populate), and scoped
 * by admin_venue_ids() — the tools only ever see this account's properties.
 * Read-only end to end: the model can quote, never book (see assistant-tools.php).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-tools.php';

header('Content-Type: application/json');

// Not-logged-in must answer JSON, not redirect HTML into a fetch.
if (!current_admin()) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'Not signed in.'])); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

if (!ai_assistant_supported()) {
    http_response_code(503);
    exit(json_encode(['ok' => false, 'error' => 'The assistant is not configured on this environment.']));
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF (JSON body).
$token = (string)($data['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Invalid session token. Please reload.']));
}

$message = trim((string)($data['message'] ?? ''));
if ($message === '') { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Ask a question.'])); }
if (mb_strlen($message) > 1000) $message = mb_substr($message, 0, 1000);

// Rebuild a short, sanitised history (plain text turns only). Cap so a client
// can't grow the context unbounded; the newest turns matter most.
$history = [];
foreach ((array)($data['history'] ?? []) as $h) {
    $role = (($h['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
    $text = trim((string)($h['text'] ?? ''));
    if ($text === '') continue;
    if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);
    $history[] = ['role' => $role, 'text' => $text];
}
$history = array_slice($history, -10);           // last 10 turns
$messages = $history;
$messages[] = ['role' => 'user', 'text' => $message];

$scope  = admin_venue_ids();                     // null = owner (all); [] = none; [ids] = scoped
$withRag = rag_supported();                      // descriptive layer available? (pgvector + embeddings key)
$system = assistant_system_prompt($scope, $withRag);
$tools  = assistant_tool_definitions($withRag);

// The read-only tool runner, bound to this account's scope.
$runTool = function (string $name, array $args) use ($scope): array {
    return assistant_run_tool($name, $args, $scope);
};

$result = chat_with_tools($system, $messages, $tools, $runTool);

if (!($result['ok'] ?? false)) {
    // A model/service failure — 502 so the client can show a soft error, not a crash.
    http_response_code(502);
    exit(json_encode(['ok' => false, 'error' => $result['error'] ?? 'The assistant could not answer just now.']));
}

// Surface the last availability/quote result so the UI can render a structured
// card alongside the prose (FR6). tool_calls carries the full trail for debugging.
$primary = null;
foreach ($result['tool_calls'] ?? [] as $call) {
    if (in_array($call['name'] ?? '', ['check_availability', 'quote_stay'], true) && empty($call['result']['error'])) {
        $primary = ['tool' => $call['name'], 'result' => $call['result']];
    }
}

exit(json_encode([
    'ok'          => true,
    'answer'      => $result['answer'] ?? '',
    'tool_result' => $primary,
    'tool_calls'  => $result['tool_calls'] ?? [],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
