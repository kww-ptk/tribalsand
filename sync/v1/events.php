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

// Rejections use Zuri's shape: {event_id, code, message}.
$reject = static function (?string $eid, string $code, string $message) use (&$rejected): void {
    $rejected[] = ['event_id' => $eid, 'code' => $code, 'message' => $message];
};

foreach ($events as $ev) {
    if (!is_array($ev)) { $reject(null, 'invalid_payload', 'event is not an object'); continue; }
    $eid = isset($ev['event_id']) ? (string) $ev['event_id'] : null;
    if (($ev['source'] ?? '') !== sync_peer_source()) {
        $reject($eid, 'invalid_payload', 'source must equal X-Sync-Source (' . sync_peer_source() . ')');
        continue;
    }
    try {
        $r = sync_inbox_receive($ev);
    } catch (Throwable $e) {
        error_log('[sync/events] receive failed: ' . $e->getMessage());
        $reject($eid, 'server_error', 'could not store the event');
        continue;
    }
    switch ($r['result']) {
        case 'accepted':   $accepted[]   = (string) $eid; break;
        case 'duplicate':  $duplicates[] = (string) $eid; break;
        default:
            $code = (string) ($r['error'] ?? 'rejected');
            $reject($eid, $code === 'not_owner' ? 'not_owner' : 'invalid_payload',
                $code === 'not_owner' ? 'tribalsand owns ' . ($ev['entity'] ?? '') : $code);
    }
}

sync_api_json([
    'accepted'   => $accepted,
    'duplicates' => $duplicates,
    'rejected'   => $rejected,
    'received'   => count($events),
    'applies'    => 'async',
], 202);
