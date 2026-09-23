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
 *   php bin/sync-export.php > backfill.json          # Zuri's matcher format (handover §9)
 *   php bin/sync-export.php --events > events.json   # the exact §3 envelopes we'd send
 *
 * Emitted oldest-parent-first (categories → items) so a backfill applies
 * in dependency order: an item's category_uuid always resolves before the item.
 */
require_once __DIR__ . '/../includes/sync-mappers.php';

if (!sync_supported()) {
    fwrite(STDERR, "sync not migrated (run add_restaurant_sync.sql) — nothing to export\n");
    exit(1);
}

$events = sync_export_events();

fwrite(STDERR, sprintf("exported %d event(s)\n", count($events)));
echo json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
