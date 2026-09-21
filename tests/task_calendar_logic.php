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

$thisWeek = task_week_start(date('Y-m-d'));
check('zero date falls back',     task_week_start('0000-00-00') === $thisWeek);
check('huge year falls back',     task_week_start('99999-01-01') === $thisWeek);
check('impossible day falls back',task_week_start('2026-02-31') === $thisWeek);
check('empty falls back',         task_week_start('') === $thisWeek);
check('valid date still works',   task_week_start('2026-09-24') === '2026-09-21');

// ── Overdue (pure; "now" is injected so this is testable at any instant) ─────
$t = fn(string $d, ?string $tm, string $st) => ['due_date'=>$d, 'due_time'=>$tm, 'status'=>$st];

check('yesterday open is overdue',   task_is_overdue($t('2026-09-20', null, 'todo'), '2026-09-21', '10:00:00') === true);
check('today untimed is not overdue',task_is_overdue($t('2026-09-21', null, 'todo'), '2026-09-21', '10:00:00') === false);
check('today earlier is overdue',    task_is_overdue($t('2026-09-21', '09:00:00', 'todo'), '2026-09-21', '10:00:00') === true);
check('today later is not overdue',  task_is_overdue($t('2026-09-21', '11:00:00', 'todo'), '2026-09-21', '10:00:00') === false);
check('tomorrow is not overdue',     task_is_overdue($t('2026-09-22', null, 'todo'), '2026-09-21', '10:00:00') === false);
check('done is never overdue',       task_is_overdue($t('2026-09-20', null, 'done'), '2026-09-21', '10:00:00') === false);
check('cancelled is never overdue',  task_is_overdue($t('2026-09-20', null, 'cancelled'), '2026-09-21', '10:00:00') === false);
check('in_progress can be overdue',  task_is_overdue($t('2026-09-20', null, 'in_progress'), '2026-09-21', '10:00:00') === true);
check('no due date is not overdue',  task_is_overdue($t('', null, 'todo'), '2026-09-21', '10:00:00') === false);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
