<?php
declare(strict_types=1);
/**
 * Public guest concierge — JSON endpoint (Phase 3).
 *   POST {message, history?[{role,text}], csrf_token, turnstile?} → {ok, answer, tool_result?, tool_calls}
 *
 * Same read-only tool+RAG engine as the admin assistant, but PUBLIC, so it wears
 * the standard public-form guardrails (NFR6):
 *   · CSRF — token in the JSON body (verify_csrf() reads $_POST, which a JSON
 *     fetch does not populate), compared with hash_equals.
 *   · Turnstile (fail-closed) — required on the FIRST message of a session, then
 *     trusted for a short TTL so a multi-turn chat isn't a challenge every line.
 *   · IP rate limit — capped answered turns per client_ip / window (concierge_log).
 *   · Honeypot — a hidden field bots fill; we accept-and-ignore without spending
 *     a model call.
 * Scope is null = every PUBLISHED property (guests see the whole catalogue). The
 * tools are read-only: the concierge quotes and describes, never books.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';         // session_init(), csrf_token()
require_once __DIR__ . '/../includes/turnstile.php';    // verify_captcha()
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-tools.php';
require_once __DIR__ . '/../includes/assistant-rag.php';
require_once __DIR__ . '/../includes/concierge.php';

session_init();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}
if (!concierge_supported()) {
    http_response_code(503); exit(json_encode(['ok' => false, 'error' => 'The concierge is unavailable right now.']));
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$ip   = client_ip();

// CSRF (JSON body). The session token must be present AND match — a cold request
// with no session yet has an empty token, and hash_equals('','') is TRUE, so an
// empty session token would otherwise let a cookie-less bot through. Requiring a
// non-empty session token means only a real page load (which mints the token)
// can talk to this endpoint.
$sessTok = (string)($_SESSION['csrf_token'] ?? '');
if ($sessTok === '' || !hash_equals($sessTok, (string)($data['csrf_token'] ?? ''))) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Your session expired. Please reload the page.']));
}

// Honeypot: a real guest never fills this. Accept-and-ignore (don't tip the bot,
// don't spend a model call).
if (trim((string)($data['website'] ?? '')) !== '') {
    exit(json_encode(['ok' => true, 'answer' => 'How can I help you plan your stay?', 'tool_result' => null, 'tool_calls' => []]));
}

// Turnstile — required once per session (fail-closed), then trusted for a TTL.
if (!concierge_session_verified()) {
    if (!verify_captcha((string)($data['turnstile'] ?? ''), $ip)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'need' => 'captcha', 'error' => 'Please complete the verification, then send your message again.']));
    }
    concierge_mark_verified();
}

// IP rate limit.
if (concierge_rate_limited($ip)) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'You’ve sent a lot of messages in a short time. Please pause a moment and try again shortly.']));
}

$message = trim((string)($data['message'] ?? ''));
if ($message === '') { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Type a question to get started.'])); }
if (mb_strlen($message) > 1000) $message = mb_substr($message, 0, 1000);

// Sanitised, capped history (plain-text turns only) — same shape as the admin endpoint.
$history = [];
foreach ((array)($data['history'] ?? []) as $h) {
    $role = (($h['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
    $text = trim((string)($h['text'] ?? ''));
    if ($text === '') continue;
    if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);
    $history[] = ['role' => $role, 'text' => $text];
}
$history   = array_slice($history, -10);
$messages  = $history;
$messages[] = ['role' => 'user', 'text' => $message];

$withRag = rag_supported();
$system  = assistant_system_prompt(null, $withRag, 'guest');   // null scope = all published venues
$tools   = assistant_tool_definitions($withRag);
$runTool = fn(string $name, array $args): array => assistant_run_tool($name, $args, null);

$result = chat_with_tools($system, $messages, $tools, $runTool);

// Log the turn (best-effort; also feeds the rate limiter).
concierge_log_turn($ip, session_id(), $message, (string)($result['answer'] ?? ($result['error'] ?? '')),
                   $result['tool_calls'] ?? [], (bool)($result['ok'] ?? false));

if (!($result['ok'] ?? false)) {
    http_response_code(502);
    exit(json_encode(['ok' => false, 'error' => $result['error'] ?? 'The concierge could not answer just now.']));
}

// Surface the last availability/quote result so the UI can render a card + a
// "Request to Book" hand-off into the existing flow (FR6).
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
