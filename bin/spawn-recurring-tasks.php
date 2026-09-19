#!/usr/bin/env php
<?php
/**
 * Recurring-task spawner cron — turns due job-timetable / recurring-task rules
 * into real tasks on their cadence.
 *
 * Run daily via the in-container scheduler (docker/scheduler.sh):
 *   php bin/spawn-recurring-tasks.php
 *
 * Belt-and-suspenders: spawn_due_recurring_tasks() also runs inline on the
 * Tasks board (recurring_inline_spawn()), so due tasks appear even if this cron
 * is down. Idempotent — the unique (recurrence_id, due_date) index means a
 * re-run never double-creates an occurrence, so multiple ECS tasks are harmless.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/recurring-tasks.php';

if (!recurring_tasks_supported()) {
    echo '[' . date('Y-m-d H:i:s') . "] Recurring tasks not enabled (migration not run) — nothing to do.\n";
    exit(0);
}

$n = spawn_due_recurring_tasks();   // null scope = all venues, today = Nairobi-local (db.php sets tz)
echo '[' . date('Y-m-d H:i:s') . "] Spawned {$n} recurring task(s).\n";
exit(0);
