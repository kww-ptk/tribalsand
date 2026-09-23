<?php
declare(strict_types=1);
/**
 * Re-queue the full Tribalsand-owned dataset for Zuri (spec S§7 step 6, go-live).
 *
 * Shadow mode (SYNC_SHADOW=true) marks outbox rows `sent` after only logging
 * them, so before the first real TS → Zuri push the complete current dataset
 * must be queued again. This queues a `create` for every live synced row of the
 * synced venue (SYNC_VENUE_SLUG) in dependency order: categories → items →
 * tables → the opening-hours record. The envelopes come from
 * sync_export_events() — the SAME mappers the live emit path and the backfill
 * export use, so nothing can drift. Unpriced items are left out (price is
 * required by the contract; their first priced edit sends them).
 *
 *   php bin/sync-requeue.php --dry-run   # count only, write nothing
 *   php bin/sync-requeue.php             # queue
 *
 * IDEMPOTENT: a row that already has a PENDING outbox event at the same or a
 * newer version is skipped, so running it twice queues nothing the second time.
 * Rows already delivered are queued again on purpose — that is the point after
 * shadow mode; Zuri acknowledges a create it already holds as a duplicate/no-op.
 */
require_once __DIR__ . '/../includes/sync-mappers.php';

$dry = in_array('--dry-run', $argv, true);
if (!sync_supported()) { fwrite(STDERR, "sync not migrated — nothing to do\n"); exit(0); }

$counts = [];
$queued = 0;
$skipped = 0;

$run = function () use (&$counts, &$queued, &$skipped, $dry) {
    foreach (sync_export_events() as $ev) {
        $ent = (string) $ev['entity'];
        $counts[$ent] = ($counts[$ent] ?? 0) + 1;
        $pending = (bool) db_query(
            "SELECT 1 FROM sync_outbox WHERE sync_uuid = :u AND status = 'pending' AND version >= :v LIMIT 1",
            [':u' => $ev['sync_uuid'], ':v' => (int) $ev['version']]
        )->fetchColumn();
        if ($pending) { $skipped++; continue; }
        if (!$dry) {
            sync_outbox_push($ent, (string) $ev['sync_uuid'], 'create', (array) $ev['data'], (int) $ev['version']);
        }
        $queued++;
    }
};

if ($dry) {
    $run();
} else {
    db()->beginTransaction();   // all or nothing — a half-queued dataset would push out of order
    try { $run(); db()->commit(); }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n"); exit(1); }
}

foreach ($counts as $ent => $n) echo str_pad($ent, 18) . " {$n}\n";
echo ($dry ? 'DRY RUN — would queue ' : 'queued ') . "{$queued}, skipped {$skipped} already pending (venue " . sync_venue_slug() . ")\n";
exit(0);
