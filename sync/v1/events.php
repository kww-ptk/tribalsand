<?php
declare(strict_types=1);
/**
 * POST /sync/v1/events — receive a batch of up to 100 events from the peer (§5).
 *
 * Auth: HMAC-SHA256 over the raw body (X-Sync-Timestamp / X-Sync-Signature),
 * peer IP allowlisted. De-dupes on event_id so a redelivery is applied once.
 * Responds 202 with accepted / duplicates / rejected arrays; application is
 * asynchronous (the applier drains the inbox out of band).
 *
 * Served at /sync/v1/events via the strip-.php rewrite (POST bodies pass through
 * the internal rewrite intact).
 */
require_once __DIR__ . '/../../includes/sync-api.php';

sync_api_begin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sync_api_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$raw = sync_api_authenticate();   // 401 / 403 / 503 and exits on failure

if (!sync_supported()) {
    sync_api_json(['ok' => false, 'error' => 'sync_not_ready'], 503);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    sync_api_json(['ok' => false, 'error' => 'invalid_payload'], 400);
}

// Accept either {"events":[…]} or a bare array of events.
$events = $body['events'] ?? $body;
if (!is_array($events) || $events === []) {
    sync_api_json(['ok' => false, 'error' => 'invalid_payload'], 400);
}
if (count($events) > 100) {
    sync_api_json(['ok' => false, 'error' => 'batch_too_large'], 400);
}

$accepted = [];
$duplicates = [];
$rejected = [];

foreach ($events as $ev) {
    if (!is_array($ev)) { $rejected[] = ['event_id' => null, 'error' => 'invalid_payload']; continue; }
    try {
        $r = sync_inbox_receive($ev);
    } catch (Throwable $e) {
        error_log('[sync/events] receive failed: ' . $e->getMessage());
        $rejected[] = ['event_id' => $ev['event_id'] ?? null, 'error' => 'server_error'];
        continue;
    }
    $eid = (string) ($ev['event_id'] ?? '');
    switch ($r['result']) {
        case 'accepted':   $accepted[]   = $eid; break;
        case 'duplicate':  $duplicates[] = $eid; break;
        default:           $rejected[]   = ['event_id' => $eid, 'error' => $r['error'] ?? 'rejected'];
    }
}

sync_api_json([
    'ok'         => true,
    'accepted'   => $accepted,
    'duplicates' => $duplicates,
    'rejected'   => $rejected,
], 202);
