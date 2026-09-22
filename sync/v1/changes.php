<?php
declare(strict_types=1);
/**
 * GET /sync/v1/changes?since=<cursor> — pull anything the peer missed while
 * offline (§5). The cursor is the last sync_outbox id the peer applied; we return
 * our outbox events with a higher id, oldest first, up to 100. The peer applies
 * them through the same de-dupe path as a live push, so overlap is harmless.
 *
 * Authenticated like every sync endpoint (HMAC over the empty body + IP allowlist).
 */
require_once __DIR__ . '/../../includes/sync-api.php';

sync_api_begin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    sync_api_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

sync_api_authenticate();

if (!sync_supported()) {
    sync_api_json(['ok' => false, 'error' => 'sync_not_ready'], 503);
}

$since = (int) ($_GET['since'] ?? 0);

$rows = db_query(
    "SELECT id, payload FROM sync_outbox
      WHERE id > :since
      ORDER BY id ASC
      LIMIT 100",
    [':since' => $since]
)->fetchAll();

$events = [];
$cursor = $since;
foreach ($rows as $r) {
    $ev = json_decode((string) $r['payload'], true);
    if (is_array($ev)) $events[] = $ev;
    $cursor = (int) $r['id'];
}

sync_api_json([
    'ok'      => true,
    'events'  => $events,
    'cursor'  => $cursor,           // pass back as ?since= on the next pull
    'has_more'=> count($rows) === 100,
]);
