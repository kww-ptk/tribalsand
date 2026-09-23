<?php
declare(strict_types=1);
/**
 * Nightly sync reconciliation (S§9) — REPORT-ONLY. Changes nothing but the stored
 * report (settings.sync_reconcile_last, shown on Admin → Zuri sync).
 *
 * For each entity we own (categories, items, tables, the hours record) it counts
 * the synced venue's live rows, computes the shared checksum
 *   md5(string_agg(sync_uuid || '|' || sync_version, ',' ORDER BY sync_uuid))
 * and lists rows whose CURRENT version was never delivered to Zuri. When Zuri's
 * /health carries a matching `checksums` map the two are compared; until that
 * endpoint is agreed with Zuri the undelivered check is the drift signal.
 *
 *   php bin/reconcile.php            # scheduler: daily, only while SYNC_ENABLED
 *   php bin/reconcile.php --force    # run even with sync switched off
 */
require_once __DIR__ . '/../includes/sync-monitor.php';

$force = in_array('--force', $argv, true);
if (!$force && !sync_enabled()) exit(0);                 // quiet when sync is off
if (!sync_supported()) { fwrite(STDERR, "sync not migrated — nothing to reconcile\n"); exit(0); }

$peer = (sync_peer_base_url() !== '' && sync_shared_secret() !== '') ? sync_peer_health() : null;
$r = sync_reconcile_report($peer);

$ts = '[' . gmdate('Y-m-d H:i:s') . 'Z] reconcile';
foreach ($r['entities'] as $entity => $e) {
    $peerNote = $e['peer_match'] === null ? '' : ($e['peer_match'] ? ' · matches Zuri' : ' · MISMATCH with Zuri (' . ($e['peer_count'] ?? '?') . ' there)');
    fwrite(STDERR, "$ts $entity: {$e['count']} rows, checksum {$e['checksum']}, {$e['undelivered']} undelivered{$peerNote}\n");
}
if (!$r['ok']) fwrite(STDERR, "$ts ALERT drift found — see Admin → Zuri sync\n");
exit(0);
