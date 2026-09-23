<?php
declare(strict_types=1);
/**
 * Sync applier — drains sync_inbox (events Zuri pushed to /sync/v1/events) into
 * our tables. Logic lives in includes/sync-apply.php; this is the CLI worker.
 *
 *   php bin/sync-apply.php            # one drain pass, then exit (scheduler-driven)
 *   php bin/sync-apply.php --quiet    # no "idle" lines (the scheduler runs it every 10s)
 *
 * Kill switch (S§10): does nothing unless SYNC_ENABLED and SYNC_ZURI_TO_TS are
 * on. While off, inbound events stay pending in the inbox and apply in order
 * once switched on. Only one applier runs at a time across ECS tasks
 * (sync_worker_lock) — events must apply in order.
 *
 * Local Windows dev: the pull-on-unknown path makes an HTTPS call to Zuri, so run
 * with -d curl.cainfo="C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt".
 */
require_once __DIR__ . '/../includes/sync-apply.php';

function apply_log(string $msg): void {
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . "\n");
}

$quiet = in_array('--quiet', $argv, true);
// Env switch first: an idle pass (the scheduler's every-10s default) never touches the DB.
if (!sync_zuri_to_ts_enabled()) { if (!$quiet) apply_log('Zuri→TS disabled (kill switch) — idle'); exit(0); }
if (!sync_applier_supported())  { if (!$quiet) apply_log('add_sync_applier.sql not applied — nothing to do'); exit(0); }
if (!sync_worker_lock('apply')) { if (!$quiet) apply_log('another applier is running — skipping'); exit(0); }

$total = ['applied' => 0, 'rejected' => 0, 'waiting' => 0, 'held' => 0];
for ($pass = 0; $pass < 50; $pass++) {           // bounded: a burst drains, a runaway can't spin
    $s = sync_apply_pending();
    foreach ($s as $k => $n) $total[$k] += $n;
    if ($s['applied'] + $s['rejected'] === 0) break;   // only waiting/held (or nothing) left
}
if (!$quiet || $total['applied'] + $total['rejected'] + $total['waiting'] > 0) {
    apply_log("applied {$total['applied']}, rejected {$total['rejected']}, waiting {$total['waiting']}, held {$total['held']}");
}
exit(0);
