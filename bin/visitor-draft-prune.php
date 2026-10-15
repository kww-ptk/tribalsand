#!/usr/bin/env php
<?php
/**
 * Abandoned visitor ID photos — an ID photo is stored privately the moment it is
 * uploaded (api/visitor-upload.php), before the visitor row exists. If the form
 * is never finished, nothing ever attaches it. This deletes every uploaded photo
 * older than 24 hours that no registered visitor uses, and clears its log row's
 * key so it is not looked at again.
 *
 * Run daily via the in-container scheduler (docker/scheduler.sh, Job 11):
 *   php bin/visitor-draft-prune.php [--dry-run] [--hours=24]
 * Idempotent; safe on several ECS tasks. Quiet no-op until add_visitor_registration.sql has run.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/visitor-store.php';

$dry = in_array('--dry-run', $argv, true);
$hours = 24;
foreach ($argv as $a) if (preg_match('~^--hours=(\d+)$~', $a, $m)) $hours = max(24, (int)$m[1]);

$stamp = '[' . date('Y-m-d H:i:s') . '] ';
if (!visitor_reg_supported()) {
    echo $stamp . "Visitor registration not enabled (add_visitor_registration.sql not run) — nothing to prune.\n";
    exit(0);
}
$r = visitor_prune_drafts($hours, $dry);
echo $stamp . ($dry ? '[dry run] would delete ' : 'Visitor ID photos: deleted ') . $r['deleted']
   . " abandoned photo(s) older than {$hours} h; {$r['kept']} attached photo(s) left alone.\n";
