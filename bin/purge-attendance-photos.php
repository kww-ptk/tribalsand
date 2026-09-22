#!/usr/bin/env php
<?php
/**
 * Clock-in photo retention cron — deletes stored punch photos older than
 * CLOCK_PHOTO_RETENTION_DAYS (30), keeping the punch records themselves.
 *
 * Run daily via the in-container scheduler (docker/scheduler.sh):
 *   php bin/purge-attendance-photos.php
 *   php bin/purge-attendance-photos.php --dry-run    # count only, delete nothing
 *
 * Why this exists: the photo is evidence for a disputed shift, and that gets
 * questioned within days or weeks. Holding images of staff faces indefinitely
 * serves no purpose the feature was built for, and at two punches a person a
 * day it grows without bound. The punch row — who, when, which tablet — is
 * small and is kept permanently; only the image is removed.
 *
 * Idempotent: only rows that still carry a photo_key are selected, so a re-run
 * (or several ECS tasks each running the scheduler) is harmless.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/attendance-clock.php';

$dry = in_array('--dry-run', $argv, true);
$r   = clock_purge_old_photos($dry);

printf(
    "[%s] attendance photo purge%s: %d older than %d days, %d deleted, %d failed\n",
    date('Y-m-d H:i:s'),
    $dry ? ' (dry run)' : '',
    $r['checked'], CLOCK_PHOTO_RETENTION_DAYS, $r['deleted'], $r['failed']
);

exit($r['failed'] > 0 ? 1 : 0);
