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

/** Monday of the week containing $ymd. Unparseable/malformed input falls back to today. */
function task_week_start(string $ymd): string {
    $ymd = trim($ymd);
    // Validate the shape before trusting strtotime(): it accepts '0000-00-00'
    // (year -1) and '99999-01-01' (silently 2008) rather than returning false,
    // so a === false check alone lets a crafted ?week= through. Same read-window
    // repair rule as rates_window_ymd().
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $ymd = date('Y-m-d');
    }
    $ts = strtotime($ymd);
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

/**
 * Is this task late? Open (not done/cancelled) AND either its due date is past,
 * or it is today and its due time has already gone by.
 *
 * "Now" is passed in rather than read from the clock so the grid, the day list
 * and the counts all judge one instant — and so this is testable.
 */
function task_is_overdue(array $t, string $todayYmd, string $nowHms): bool {
    $status = (string)($t['status'] ?? '');
    if ($status === 'done' || $status === 'cancelled') return false;
    $due = (string)($t['due_date'] ?? '');
    if ($due === '') return false;
    if ($due < $todayYmd) return true;
    if ($due > $todayYmd) return false;
    $time = (string)($t['due_time'] ?? '');
    if ($time === '') return false;          // untimed today is not late yet
    return substr($time, 0, 8) < substr($nowHms, 0, 8);
}
