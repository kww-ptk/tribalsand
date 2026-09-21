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
 *
 * Dates/times are compared as STRINGS ($due vs $todayYmd, $time vs $nowHms),
 * which is only chronologically correct because both sides are zero-padded
 * (YYYY-MM-DD / HH:MM:SS). Same precondition as rates_window_ymd() in
 * includes/rates.php, where an unpadded date sorting wrongly was a real bug —
 * never feed this an unpadded value.
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

/** Default hour rows — a property working day, not 24 rows of empty grid. */
const TASK_GRID_MIN_HOUR = 6;
const TASK_GRID_MAX_HOUR = 20;

/**
 * Bucket week rows into the grid. PURE — no DB, no clock.
 *
 * Returns:
 *   days    => 7 Y-m-d dates, Monday first
 *   hours   => the hour rows to render, ascending
 *   anytime => [ymd => [task, …]]  tasks with no due_time
 *   cells   => [ymd => [hour => [task, …]]]
 *   counts  => ['total'=>n,'done'=>n,'overdue'=>n]
 *
 * Every ymd key is present in both `anytime` and `cells`, even for a day with
 * no tasks. Hour keys are SPARSE: an hour with nothing due has no key at all,
 * not an empty array — so index it as `$cells[$day][$hour] ?? []`, never bare.
 *
 * A row outside the week is dropped, so a caller that over-fetches cannot leak a
 * stray task into the grid or the counts.
 *
 * A task is untimed when due_time is null OR the key is absent — pre-migration
 * the column does not exist, so every task lands in the Anytime row and the grid
 * still renders.
 */
function task_week_grid(array $tasks, string $weekStart, string $todayYmd, string $nowHms): array {
    $days    = task_week_days($weekStart);
    $anytime = array_fill_keys($days, []);
    $cells   = array_fill_keys($days, []);
    $counts  = ['total' => 0, 'done' => 0, 'overdue' => 0];
    $lo = TASK_GRID_MIN_HOUR; $hi = TASK_GRID_MAX_HOUR;

    foreach ($tasks as $t) {
        $day = (string)($t['due_date'] ?? '');
        if ($day === '' || !array_key_exists($day, $cells)) continue;   // outside the week

        $counts['total']++;
        if ((string)($t['status'] ?? '') === 'done') $counts['done']++;
        if (task_is_overdue($t, $todayYmd, $nowHms)) $counts['overdue']++;

        $time = (string)($t['due_time'] ?? '');
        if ($time === '') { $anytime[$day][] = $t; continue; }

        $hour = max(0, min(23, (int)substr($time, 0, 2)));
        $cells[$day][$hour][] = $t;
        if ($hour < $lo) $lo = $hour;
        if ($hour > $hi) $hi = $hour;
    }

    // Keep each cell in time order; the SQL orders too, but the pure function
    // must not depend on its caller having done so.
    foreach ($cells as $day => $byHour) {
        foreach ($byHour as $hour => $list) {
            usort($list, fn($a, $b) => strcmp((string)($a['due_time'] ?? ''), (string)($b['due_time'] ?? '')));
            $cells[$day][$hour] = $list;
        }
        ksort($cells[$day]);
    }

    return [
        'days'    => $days,
        'hours'   => range($lo, $hi),
        'anytime' => $anytime,
        'cells'   => $cells,
        'counts'  => $counts,
    ];
}

/** True if task_recurrences.procedure exists (memoised). False pre-migration. */
function task_procedures_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    if (!recurring_tasks_supported()) return $c = false;
    try {
        $st = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'task_recurrences' AND column_name = 'procedure'"
        );
        return $c = (bool) $st->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

/**
 * The optional-column SQL fragments both fetchers need, resolved once.
 * Returns [$timeSel, $timeOrd, $procSel, $procJoin].
 *
 * due_time ships with add_recurring_tasks.sql (see admin/tasks.php:373), and
 * the procedure with add_task_procedures.sql. Each is selected only when
 * present.
 *
 * SELECT and ORDER BY need DIFFERENT time expressions: the select list carries
 * the "AS due_time" alias, and an alias is a syntax error inside ORDER BY.
 * Collapsing them throws 42601, which the callers' catch would turn into an
 * empty grid — a silently blank page on exactly the pre-migration deploy these
 * guards exist to protect.
 */
function task_calendar_sql_parts(): array {
    $has = recurring_tasks_supported();
    return [
        $has ? "t.due_time" : "NULL::time AS due_time",                                    // timeSel
        $has ? "t.due_time" : "NULL::time",                                                // timeOrd
        task_procedures_supported() ? "r.procedure AS procedure_text" : "NULL::text AS procedure_text", // procSel
        $has ? "LEFT JOIN task_recurrences r ON r.id = t.recurrence_id" : "",               // procJoin
    ];
}

/**
 * Every task for ONE property in ONE week, scoped. ONE query — never one per
 * cell: a 7-day x 15-hour grid is 105 cells, and a per-cell query would be 105
 * round trips for one page view.
 *
 * $venueIds is admin_venue_ids() (null = owner/all) and is the security
 * boundary; $venueId is the property being viewed and must sit inside it, or the
 * call returns [] rather than honouring a posted id.
 *
 * $filters: 'assigned_to' => int|'unassigned', 'job_type' => string.
 */
function task_week_fetch(?array $venueIds, int $venueId, string $weekStart, array $filters = []): array {
    if (!tasks_supported() || $venueId <= 0) return [];
    if ($venueIds !== null && !in_array($venueId, array_map('intval', $venueIds), true)) return [];

    $days  = task_week_days($weekStart);
    $p     = [':v' => $venueId, ':from' => $days[0], ':to' => $days[6]];
    $where = "t.venue_id = :v AND t.due_date BETWEEN :from AND :to";

    $asg = $filters['assigned_to'] ?? null;
    if ($asg === 'unassigned')      { $where .= " AND t.assigned_to IS NULL"; }
    elseif (is_numeric($asg))       { $where .= " AND t.assigned_to = :a"; $p[':a'] = (int)$asg; }

    if (!empty($filters['job_type'])) { $where .= " AND t.job_type = :j"; $p[':j'] = (string)$filters['job_type']; }

    [$timeSel, $timeOrd, $procSel, $procJoin] = task_calendar_sql_parts();

    try {
        return db_query(
            "SELECT t.id, t.venue_id, t.title, t.detail, t.status, t.due_date, t.job_type,
                    t.assigned_to, {$timeSel}, {$procSel},
                    v.name AS venue_name, a.name AS assignee_name
               FROM tasks t
               LEFT JOIN venues v ON v.id = t.venue_id
               LEFT JOIN admin_users a ON a.id = t.assigned_to
               {$procJoin}
              WHERE {$where}
              ORDER BY t.due_date ASC, {$timeOrd} ASC NULLS LAST, t.id ASC",
            $p
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[task-calendar] week fetch failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * One person's tasks for ONE day, across every property they work at — in ONE
 * query. The staff day view must never loop venues: a person scoped to four
 * properties would fire four queries for one screen, and an owner one per venue
 * in the whole estate. Assignment already implies the property, so no venue
 * filter is needed and none is applied.
 */
function task_user_day_fetch(int $adminId, string $ymd): array {
    if (!tasks_supported() || $adminId <= 0) return [];

    [$timeSel, $timeOrd, $procSel, $procJoin] = task_calendar_sql_parts();

    try {
        return db_query(
            "SELECT t.id, t.venue_id, t.title, t.detail, t.status, t.due_date, t.job_type,
                    t.assigned_to, {$timeSel}, {$procSel},
                    v.name AS venue_name
               FROM tasks t
               LEFT JOIN venues v ON v.id = t.venue_id
               {$procJoin}
              WHERE t.assigned_to = :a AND t.due_date = :d
              ORDER BY {$timeOrd} ASC NULLS LAST, t.id ASC",
            [':a' => $adminId, ':d' => $ymd]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[task-calendar] day fetch failed: ' . $e->getMessage());
        return [];
    }
}
