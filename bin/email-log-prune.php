#!/usr/bin/env php
<?php
/**
 * Email log retention — clears the rendered body (html/text snapshot) of emails
 * older than 180 days. The log row itself (who, when, which email, status)
 * stays, so "was this guest ever sent a confirmation?" still has an answer.
 *
 * Run daily via the in-container scheduler (docker/scheduler.sh):
 *   php bin/email-log-prune.php [--days=180]
 * Idempotent: only rows that still carry a body are touched.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail-log.php';

$days = 180;
foreach ($argv as $a) if (preg_match('~^--days=(\d+)$~', $a, $m)) $days = max(30, (int)$m[1]);

if (!email_log_supported()) {
    echo '[' . date('Y-m-d H:i:s') . "] Email log not enabled (add_email_log.sql not run) — nothing to prune.\n";
    exit(0);
}
$n = email_log_prune($days);
echo '[' . date('Y-m-d H:i:s') . "] Email log: cleared the body of {$n} email(s) older than {$days} days.\n";
