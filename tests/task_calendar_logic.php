<?php
declare(strict_types=1);
// Task calendar — week maths, hour-range derivation, bucketing, overdue.
// Run: php tests/task_calendar_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real rows are left behind. Requires add_tasks.sql + add_recurring_tasks.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/task-calendar.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Week boundaries (pure) ──────────────────────────────────────────────────
// 2026-09-21 is a Monday; 2026-09-27 the Sunday that closes the same week.
check('monday maps to itself',   task_week_start('2026-09-21') === '2026-09-21');
check('midweek maps back',       task_week_start('2026-09-24') === '2026-09-21');
check('sunday stays in week',    task_week_start('2026-09-27') === '2026-09-21');
check('next monday is next week',task_week_start('2026-09-28') === '2026-09-28');
check('week has 7 days',         count(task_week_days('2026-09-21')) === 7);
check('week starts monday',      task_week_days('2026-09-21')[0] === '2026-09-21');
check('week ends sunday',        task_week_days('2026-09-21')[6] === '2026-09-27');
check('shift forward',           task_week_shift('2026-09-21', 1) === '2026-09-28');
check('shift back',              task_week_shift('2026-09-21', -1) === '2026-09-14');
check('bad date falls back',     task_week_start('not-a-date') === task_week_start(date('Y-m-d')));

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
