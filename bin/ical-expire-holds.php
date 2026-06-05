#!/usr/bin/env php
<?php
/**
 * Hold expiry cron — expires pending holds past their 24h TTL.
 *
 * Run every 5 minutes via Render Cron:
 *   php bin/ical-expire-holds.php
 *
 * What it does:
 *   1. Sets expired holds to status='expired'
 *   2. Frees their availability_blocks
 *   3. Emails each guest to let them know
 *
 * This is belt-and-suspenders — expire_stale_holds() in db.php also runs
 * lazily on availability checks and admin page loads. The cron ensures guest
 * emails go out even when no admin is active.
 */
declare(strict_types=1);

// Allow running from any working directory
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';

$before = (int)db_query("SELECT COUNT(*) FROM holds WHERE status='pending' AND expires_at < NOW()")->fetchColumn();

if ($before === 0) {
    echo '[' . date('Y-m-d H:i:s') . "] No holds to expire.\n";
    exit(0);
}

// expire_stale_holds() handles UPDATE + DELETE blocks + guest emails
expire_stale_holds();

echo '[' . date('Y-m-d H:i:s') . "] Expired {$before} hold(s) and notified guest(s).\n";
exit(0);
