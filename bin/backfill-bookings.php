#!/usr/bin/env php
<?php
/**
 * One-off backfill — populate the bookings ledger from holds that were confirmed
 * BEFORE the finance subsystem existed. Website revenue is snapshotted at confirm
 * time; holds confirmed before that code shipped never got a ledger row, so the
 * reports page would show them as missing until this runs once.
 *
 * Run once, after applying add_bookings_finance.sql, on each database:
 *   php bin/backfill-bookings.php            # apply
 *   php bin/backfill-bookings.php --dry-run  # count only, write nothing
 *
 * Idempotent: bookings_sync_hold() upserts on hold_id, so re-running is safe and
 * never duplicates. Gross is derived the same way a live confirm derives it
 * (rate map × nights), so a backfilled figure matches what a re-confirm would write.
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/bookings.php';

$dryRun = in_array('--dry-run', $argv, true);

if (!bookings_supported()) {
    fwrite(STDERR, "The bookings table is missing — run db/migrations/add_bookings_finance.sql first.\n");
    exit(1);
}

// Confirmed holds are the revenue-bearing ones; cancelled/expired/pending are not
// backfilled (a later confirm will snapshot them live).
$ids = db_query("SELECT id FROM holds WHERE status = 'confirmed' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$total = count($ids);
echo '[' . date('Y-m-d H:i:s') . "] {$total} confirmed hold(s) to reconcile"
   . ($dryRun ? " (dry run — no writes).\n" : ".\n");

if ($dryRun || $total === 0) {
    // Report how many already have a ledger row vs. would be created.
    $have = (int) db_query("SELECT COUNT(*) FROM bookings WHERE hold_id IS NOT NULL")->fetchColumn();
    echo "  Ledger rows already linked to a hold: {$have}.\n";
    exit(0);
}

$done = 0;
foreach ($ids as $id) {
    bookings_sync_hold((int)$id);
    $done++;
}
$rows = (int) db_query("SELECT COUNT(*) FROM bookings WHERE hold_id IS NOT NULL")->fetchColumn();
echo '[' . date('Y-m-d H:i:s') . "] Reconciled {$done} hold(s). Ledger now holds {$rows} website row(s).\n";
exit(0);
