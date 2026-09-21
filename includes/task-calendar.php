<?php
/**
 * Task calendar — the read model behind the weekly timetable grid and the staff
 * day list. ONE scoped query per week, then PURE bucketing into [day][hour].
 *
 * Both surfaces read these functions, so the grid and the day list cannot drift.
 * Every date here is Nairobi-local (includes/db.php sets the timezone on load and
 * on every PDO connect) — never assume UTC.
 *
 * All reads are pre-migration-safe: tasks_supported() gates the table,
 * recurring_tasks_supported() gates tasks.due_time (it ships in the same
 * migration — see admin/tasks.php:373), task_procedures_supported() gates the
 * procedure column.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking.php';           // team helpers: tasks_supported()
require_once __DIR__ . '/recurring-tasks.php';   // recurring_tasks_supported()

/** Monday of the week containing $ymd. Unparseable input falls back to today. */
function task_week_start(string $ymd): string {
    $ts = strtotime($ymd);
    if ($ts === false) $ts = strtotime(date('Y-m-d'));
    // 'N' is 1 (Mon) … 7 (Sun), so subtracting N-1 days always lands on Monday.
    $dow = (int)date('N', $ts);
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

/** The seven Y-m-d dates of the week starting at $weekStart, Monday first. */
function task_week_days(string $weekStart): array {
    $start = task_week_start($weekStart);
    $out = [];
    for ($i = 0; $i < 7; $i++) $out[] = date('Y-m-d', strtotime("+{$i} days", strtotime($start)));
    return $out;
}

/** Move a week start by $n whole weeks (negative = back). */
function task_week_shift(string $weekStart, int $n): string {
    return date('Y-m-d', strtotime(($n >= 0 ? '+' : '-') . abs($n) . ' weeks', strtotime(task_week_start($weekStart))));
}
