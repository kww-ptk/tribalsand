<?php
declare(strict_types=1);
/**
 * Backfill / shadow export (§7) for the entities Tribalsand OWNS (menu today;
 * tables + hours once those models exist). Reads the live menu through direct
 * SELECTs of the synced columns and prints the exact §3 envelopes we would send.
 *
 * It is READ-ONLY: it writes nothing to the outbox and sends nothing to the peer.
 * That makes it the Stage-1 (Shadow) artifact — "here is what we would send" — and
 * simultaneously the JSON dataset Zuri's backfill matcher reads to pair existing
 * rows before any live sync (§7).
 *
 *   php bin/sync-export.php            # {"events":[…]} to stdout
 *   php bin/sync-export.php > menu.json
 *
 * Emitted oldest-parent-first (menus → categories → items) so a backfill applies
 * in dependency order: a category's menu_uuid and an item's category_uuid always
 * resolve before the child arrives.
 */
require_once __DIR__ . '/../includes/sync-mappers.php';

if (!sync_supported()) {
    fwrite(STDERR, "sync not migrated (run add_restaurant_sync.sql) — nothing to export\n");
    exit(1);
}

$events = [];

// menus
foreach (db_query(
    "SELECT id, sync_uuid, sync_version, slug, title, subtitle, currency_label, is_published, sort_order
       FROM menus WHERE is_deleted = FALSE ORDER BY id"
)->fetchAll() as $m) {
    $events[] = sync_make_event('menu', 'create', (string) $m['sync_uuid'], (int) $m['sync_version'], sync_map_menu($m));
}

// categories (carry the parent menu's sync_uuid)
foreach (db_query(
    "SELECT c.id, c.sync_uuid, c.sync_version, c.section, c.name, c.tag, c.icon, c.sort_order, c.is_visible,
            m.sync_uuid AS menu_sync_uuid
       FROM menu_categories c
       JOIN menus m ON m.id = c.menu_id
      WHERE c.is_deleted = FALSE AND m.is_deleted = FALSE
      ORDER BY c.id"
)->fetchAll() as $c) {
    $events[] = sync_make_event('menu_category', 'create', (string) $c['sync_uuid'], (int) $c['sync_version'], sync_map_menu_category($c));
}

// items (carry the parent category's sync_uuid)
foreach (db_query(
    "SELECT i.id, i.sync_uuid, i.sync_version, i.name, i.description, i.price,
            i.is_veg, i.is_vegan, i.is_spicy, i.has_nuts, i.has_gluten, i.is_gf,
            i.is_signature, i.is_available, i.sort_order,
            c.sync_uuid AS category_sync_uuid
       FROM menu_items i
       JOIN menu_categories c ON c.id = i.category_id
      WHERE i.is_deleted = FALSE AND c.is_deleted = FALSE
      ORDER BY i.id"
)->fetchAll() as $i) {
    $events[] = sync_make_event('menu_item', 'create', (string) $i['sync_uuid'], (int) $i['sync_version'], sync_map_menu_item($i));
}

fwrite(STDERR, sprintf("exported %d event(s)\n", count($events)));
echo json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
