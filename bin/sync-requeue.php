<?php
declare(strict_types=1);
/**
 * Re-queue the full Tribalsand-owned dataset for Zuri (spec S§7 step 6, go-live).
 * Same as Admin → Zuri sync → "Send everything to Zuri" — both call
 * sync_requeue_all() (includes/sync-monitor.php), which explains the rules:
 * categories → items → tables → hours, unpriced items left out, idempotent,
 * one transaction.
 *
 *   php bin/sync-requeue.php --dry-run   # count only, write nothing
 *   php bin/sync-requeue.php             # queue
 */
require_once __DIR__ . '/../includes/sync-monitor.php';

$dry = in_array('--dry-run', $argv, true);
if (!sync_supported()) { fwrite(STDERR, "sync not migrated — nothing to do\n"); exit(0); }
try { $r = sync_requeue_all($dry); }
catch (Throwable $e) { fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n"); exit(1); }

foreach ($r['counts'] as $ent => $n) echo str_pad($ent, 18) . " {$n}\n";
echo ($dry ? 'DRY RUN — would queue ' : 'queued ') . "{$r['queued']}, skipped {$r['skipped']} already pending (venue " . sync_venue_slug() . ")\n";
exit(0);
