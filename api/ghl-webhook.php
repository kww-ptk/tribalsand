<?php
/**
 * GHL → us: a WhatsApp (or other channel) reply, posted by a GHL Workflow
 * ("Customer Replied" → Webhook). Authenticated FIRST (Ed25519 X-GHL-Signature
 * or the shared secret header), then threaded into the matching lead as a guest
 * reply, or opened as a new lead. Logic + auth rules: includes/ghl-webhook.php.
 * Setup: docs/ghl-whatsapp-webhook.md.
 *
 * Responses: 401 when authentication fails; 400 for a body that isn't JSON;
 * otherwise 200 (including "ignored"/"duplicate"), so GHL stops retrying.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ghl-webhook.php';

header('Content-Type: application/json');

function ghl_webhook_out(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ghl_webhook_out(405, ['ok' => false, 'error' => 'POST only']);

// Read the RAW bytes — the signature covers exactly these; re-encoding JSON breaks it.
$raw = (string) file_get_contents('php://input', false, null, 0, GHL_WEBHOOK_MAX_BYTES + 1);
if ($raw === '' || strlen($raw) > GHL_WEBHOOK_MAX_BYTES) ghl_webhook_out(400, ['ok' => false, 'error' => 'Empty or oversized body']);

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
}

if (!ghl_webhook_authenticate($raw, $headers)) {
    error_log('[ghl-webhook] rejected unauthenticated post from ' . client_ip());
    ghl_webhook_out(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) ghl_webhook_out(400, ['ok' => false, 'error' => 'Body must be JSON']);

$parsed = ghl_webhook_parse($payload);
$key    = $parsed['message_id'] !== '' ? 'msg:' . $parsed['message_id'] : 'sha256:' . hash('sha256', $raw);

try {
    $r = ghl_webhook_handle($parsed, $key);
} catch (Throwable $e) {
    error_log('[ghl-webhook] handle failed: ' . $e->getMessage());
    ghl_webhook_out(500, ['ok' => false, 'error' => 'Could not record the message']);
}

ghl_webhook_out(200, ['ok' => true] + $r);
