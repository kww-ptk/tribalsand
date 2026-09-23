<?php
declare(strict_types=1);
/**
 * GET /sync/v1/changes?since=<cursor>&limit=100 — pull anything the peer missed
 * while offline (§5). The cursor is opaque to the peer (our sync_outbox id, start
 * at 0); we return our outbox events with a higher id, oldest first, and a
 * next_cursor to store. The peer applies them through the same de-dupe path as a
 * live push, so overlap is harmless.
 *
 * GET /sync/v1/changes?entity=<e>&sync_uuid=<uuid> — our current state of ONE
 * record as a single event (empty when we don't have it); the peer calls this
 * after a stale_version.
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

// One record's CURRENT state (Zuri handover §7) — used by the peer after a
// stale_version rejection. Only for entities we own; events is empty when we
// don't have it (or it isn't the synced venue's).
$entity = (string) ($_GET['entity'] ?? '');
$uuid   = (string) ($_GET['sync_uuid'] ?? '');
if ($entity !== '' || $uuid !== '') {
    require_once __DIR__ . '/../../includes/sync-mappers.php';
    $tables = ['menu_category' => 'menu_categories', 'menu_item' => 'menu_items', 'restaurant_table' => 'restaurant_tables'];
    if (!isset($tables[$entity]) || !preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
        sync_api_json(['ok' => false, 'error' => 'invalid_payload'], 400);
    }
    $events = [];
    $id = 0;
    if (db_query('SELECT to_regclass(:t)', [':t' => 'public.' . $tables[$entity]])->fetchColumn()) {
        $id = (int) (db_query("SELECT id FROM {$tables[$entity]} WHERE sync_uuid = :u", [':u' => $uuid])->fetchColumn() ?: 0);
    }
    $row = $id ? (sync_menu_rows($entity, $id)[0] ?? null) : null;
    if ($row) {
        $data = match ($entity) {
            'menu_category'    => sync_map_menu_category($row),
            'menu_item'        => sync_map_menu_item($row),
            'restaurant_table' => sync_map_restaurant_table($row),
        };
        $deleted = (bool) db_query("SELECT is_deleted FROM {$tables[$entity]} WHERE id = :id", [':id' => $id])->fetchColumn();
        $events[] = sync_make_event($entity, $deleted ? 'delete' : 'update', $uuid, (int) $row['sync_version'], $data, $deleted);
    }
    sync_api_json(['events' => $events, 'next_cursor' => null, 'has_more' => false]);
}

// Catch-up pull: everything we queued after the peer's opaque cursor (our
// outbox id, start at 0).
$since = max(0, (int) ($_GET['since'] ?? 0));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 100)));

$rows = db_query(
    "SELECT id, payload FROM sync_outbox
      WHERE id > :since
      ORDER BY id ASC
      LIMIT {$limit}",
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
    'events'      => $events,
    'next_cursor' => (string) $cursor,   // pass back as ?since= on the next pull
    'has_more'    => count($rows) === $limit,
]);
