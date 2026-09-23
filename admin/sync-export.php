<?php
declare(strict_types=1);
/**
 * Owner-only download of the restaurant-sync backfill/shadow export (§7).
 *
 * Because prod RDS is private (not reachable from a laptop), this is the browser
 * equivalent of `php bin/sync-export.php > menu.json`: it runs the SAME
 * read-only export inside the app (which does have DB access) and streams it as
 * a download. Give the backfill file to Bhumika for her natural-key matcher.
 *
 *   /admin/sync-export.php                  → backfill.json (the shape Zuri's matcher reads,
 *                                             handover §9: uuid + match keys per entity)
 *   /admin/sync-export.php?format=events    → events.json (the exact §3 envelopes we'd send)
 *   add &view=1 (or ?view=1) to show it inline in the browser
 *
 * READ-ONLY: writes nothing, sends nothing to the peer.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sync-mappers.php';
require_login();
require_owner();

if (!sync_supported()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Sync is not migrated on this database. Run add_restaurant_sync.sql first.";
    exit;
}

$asEvents = ($_GET['format'] ?? '') === 'events';
if ($asEvents) {
    $payload  = ['events' => sync_export_events()];
    $count    = count($payload['events']);
    $filename = 'events.json';
} else {
    $payload  = sync_backfill_export();
    $count    = count($payload['menu_category']) + count($payload['menu_item']) + count($payload['restaurant_table']);
    $filename = 'backfill.json';
}
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$inline = !empty($_GET['view']);
header('Content-Type: application/json; charset=utf-8');
if (!$inline) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
header('X-Sync-Export-Count: ' . $count);   // quick sanity check in dev tools
echo $json;
