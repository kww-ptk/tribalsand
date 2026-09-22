<?php
declare(strict_types=1);
/**
 * Owner-only download of the restaurant-sync backfill/shadow export (§7).
 *
 * Because prod RDS is private (not reachable from a laptop), this is the browser
 * equivalent of `php bin/sync-export.php > menu.json`: it runs the SAME
 * read-only export inside the app (which does have DB access) and streams it as
 * a menu.json download. Give the file to Bhumika for the field-map sign-off and
 * her backfill matcher.
 *
 *   /admin/sync-export.php           → downloads menu.json
 *   /admin/sync-export.php?view=1    → shows the JSON inline in the browser
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

$events = sync_export_events();
$json   = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$inline = !empty($_GET['view']);
header('Content-Type: application/json; charset=utf-8');
if (!$inline) {
    header('Content-Disposition: attachment; filename="menu.json"');
}
header('X-Sync-Export-Count: ' . count($events));   // quick sanity check in dev tools
echo $json;
