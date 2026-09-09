<?php
declare(strict_types=1);
/**
 * AI enquiry-reply drafter — admin endpoint (JSON), Phase 4.
 *   POST {submission_id, csrf_token} → {ok, draft}
 *
 * From an enquiry thread (admin/submission-view.php), staff click "Draft options
 * with AI" and get a warm, ready-to-send reply — availability, best-fit options
 * / combinations, and prices — dropped into the reply box to EDIT and send. It
 * never sends: staff review, then use the existing add_note + send_admin_reply
 * path. Everything the draft asserts about price/availability comes from the same
 * READ-ONLY tools (ONE pricing path) the assistant uses; the model only phrases.
 *
 * Guards mirror api/assistant.php: session-authed, require_frontdesk() audience,
 * CSRF token in the JSON body, admin_venue_ids() scope. The submission itself is
 * re-checked with submission_in_scope() so a scoped account can't draft against
 * another property's enquiry by posting a foreign id.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-tools.php';

header('Content-Type: application/json');

if (!current_admin()) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'Not signed in.'])); }

// Audience gate — owner/manager/reception/front-desk staff, like the assistant.
// Ops & gate-security are bounced; answer JSON rather than redirecting HTML.
$job = admin_job();
if (is_staff() && (job_is_ops($job) || $job === 'security')) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Not available for your account.']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

if (!ai_assistant_supported()) {
    http_response_code(503); exit(json_encode(['ok' => false, 'error' => 'The assistant is not configured on this environment.']));
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF (JSON body).
$token = (string)($data['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Invalid session token. Please reload.']));
}

$sid = (int)($data['submission_id'] ?? 0);
if ($sid <= 0) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Missing enquiry id.'])); }
if (!submission_in_scope($sid)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'That enquiry belongs to a property this account cannot see.']));
}

$sub = db_query(
    "SELECT s.*, r.name AS room_name, r.slug AS room_slug, v.name AS venue_name, v.slug AS venue_slug
       FROM submissions s
       LEFT JOIN rooms  r ON r.id = s.room_id
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE s.id = :id",
    [':id' => $sid]
)->fetch();
if (!$sub) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Enquiry not found.'])); }

// Build the drafting brief from the enquiry. Kept factual — the model resolves
// availability/prices from the tools, never from anything asserted here.
$brief = assistant_build_draft_brief($sub);

$scope   = admin_venue_ids();                 // null = owner (all); [ids] = scoped
$withRag = rag_supported();
$system  = assistant_system_prompt($scope, $withRag, 'staff');
$tools   = assistant_tool_definitions($withRag);

$runTool = function (string $name, array $args) use ($scope): array {
    return assistant_run_tool($name, $args, $scope);
};

$result = chat_with_tools($system, [['role' => 'user', 'text' => $brief]], $tools, $runTool);

if (!($result['ok'] ?? false)) {
    http_response_code(502);
    exit(json_encode(['ok' => false, 'error' => $result['error'] ?? 'The assistant could not draft a reply just now.']));
}

$draft = trim((string)($result['answer'] ?? ''));
if ($draft === '') {
    http_response_code(502);
    exit(json_encode(['ok' => false, 'error' => 'The assistant returned an empty draft. Try again.']));
}

audit_log('assistant.draft', 'submission', $sid);

exit(json_encode(['ok' => true, 'draft' => $draft], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
