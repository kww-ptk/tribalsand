<?php
declare(strict_types=1);
/**
 * Recurring tasks & job timetables (migration: add_recurring_tasks.sql).
 *
 * A "job timetable" (task_schedules) groups several recurring lines
 * (task_recurrences) for a role/person at a property — e.g. a Gardener routine
 * with "Cut grass" weekly and "Check pipes" fortnightly. A recurrence can also
 * stand alone (schedule_id NULL) when created straight from the Tasks board.
 *
 * The spawner turns due recurrences into real `tasks` rows on their cadence,
 * tagging tasks.recurrence_id + a unique (recurrence_id, due_date) index so the
 * same occurrence is never created twice — safe to run repeatedly (scheduler +
 * inline on page load), exactly like expire_stale_holds().
 *
 * All reads are pre-migration-safe via recurring_tasks_supported().
 */
require_once __DIR__ . '/db.php';

/** True if the recurring-task tables exist (memoised). False pre-migration. */
function recurring_tasks_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.task_recurrences')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Frequency key => human label, in menu order. Single source for every UI. */
function recurring_freq_options(): array {
    return [
        'daily'     => 'Daily',
        'weekly'    => 'Weekly',
        'biweekly'  => 'Every 2 weeks',
        'monthly'   => 'Monthly',
        'quarterly' => 'Every 3 months',
        'custom'    => 'Custom (every N days)',
    ];
}

/** Human label for a frequency (custom shows its interval). */
function recurring_freq_label(string $freq, ?int $intervalDays = null): string {
    if ($freq === 'custom') {
        $n = max(1, (int)($intervalDays ?? 1));
        return $n === 1 ? 'Every day' : "Every {$n} days";
    }
    return recurring_freq_options()[$freq] ?? ucfirst($freq);
}

/** Add $n calendar months to a Y-m-d, clamping the day to the target month's length (pure). */
function recurrence_add_months(string $ymd, int $n): string {
    [$y, $m, $d] = array_map('intval', explode('-', substr($ymd, 0, 10)));
    $total = ($y * 12 + ($m - 1)) + $n;
    $ny = intdiv($total, 12);
    $nm = ($total % 12) + 1;
    $last = (int) date('t', mktime(0, 0, 0, $nm, 1, $ny));
    return sprintf('%04d-%02d-%02d', $ny, $nm, min(max(1, $d), $last));
}

/**
 * The next spawn date after $ymd for a frequency (pure, no DB).
 * daily/weekly/biweekly/custom add fixed days; monthly/quarterly add calendar
 * months (clamped). Always advances by at least one day.
 */
function recurrence_next_date(string $ymd, string $frequency, ?int $intervalDays = null): string {
    $fixed = ['daily' => 1, 'weekly' => 7, 'biweekly' => 14];
    if (isset($fixed[$frequency])) return date('Y-m-d', (int) strtotime($ymd . ' +' . $fixed[$frequency] . ' days'));
    if ($frequency === 'monthly')   return recurrence_add_months($ymd, 1);
    if ($frequency === 'quarterly') return recurrence_add_months($ymd, 3);
    $n = max(1, (int)($intervalDays ?? 1));   // custom
    return date('Y-m-d', (int) strtotime($ymd . ' +' . $n . ' days'));
}

/**
 * Spawn tasks for every due recurrence (next_run_date <= $todayYmd). Idempotent
 * via the unique (recurrence_id, due_date) index. Advances next_run_date past
 * today, creating a task for each missed occurrence (capped so a long-dormant
 * rule can't flood). $venueIds scopes the sweep (null = all). Returns #spawned.
 */
function spawn_due_recurring_tasks(?string $todayYmd = null, ?array $venueIds = null): int {
    if (!recurring_tasks_supported()) return 0;
    $today = $todayYmd ?: date('Y-m-d');

    $where  = ['r.is_active = TRUE', 'r.next_run_date <= :today'];
    $params = [':today' => $today];
    if ($venueIds !== null) {
        if (!$venueIds) return 0;
        $in = implode(',', array_map('intval', $venueIds));
        $where[] = "r.venue_id IN ($in)";
    }
    try {
        $rules = db_query(
            'SELECT r.* FROM task_recurrences r WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id',
            $params
        )->fetchAll();
    } catch (Throwable $e) { return 0; }

    $spawned = 0;
    foreach ($rules as $r) {
        $rid  = (int) $r['id'];
        $next = substr((string) $r['next_run_date'], 0, 10);
        $freq = (string) $r['frequency'];
        $iv   = $r['interval_days'] !== null ? (int) $r['interval_days'] : null;
        $guard = 0;

        while ($next !== '' && $next <= $today && $guard < 120) {
            try {
                $ins = db_query(
                    "INSERT INTO tasks (venue_id, assigned_to, job_type, title, detail, status, due_date, due_time, recurrence_id, created_by)
                     VALUES (:v, :a, :j, :t, :d, 'todo', :due, :tm, :rid, :cb)
                     ON CONFLICT (recurrence_id, due_date) WHERE recurrence_id IS NOT NULL DO NOTHING",
                    [
                        ':v'   => (int) $r['venue_id'],
                        ':a'   => $r['assigned_to'] !== null ? (int) $r['assigned_to'] : null,
                        ':j'   => $r['job_type'] !== null ? (string) $r['job_type'] : null,
                        ':t'   => (string) $r['title'],
                        ':d'   => $r['detail'] !== null ? (string) $r['detail'] : null,
                        ':due' => $next,
                        ':tm'  => $r['time_of_day'] !== null ? (string) $r['time_of_day'] : null,
                        ':rid' => $rid,
                        ':cb'  => $r['created_by'] !== null ? (int) $r['created_by'] : null,
                    ]
                );
                if ($ins->rowCount() > 0) $spawned++;
            } catch (Throwable $e) { break; }
            $prev = $next;
            $next = recurrence_next_date($next, $freq, $iv);
            if ($next <= $prev) break;   // safety: never loop on a non-advancing date
            $guard++;
        }

        try {
            db_query(
                'UPDATE task_recurrences SET next_run_date = :n, last_spawned_date = :l WHERE id = :id',
                [':n' => $next, ':l' => $today, ':id' => $rid]
            );
        } catch (Throwable $e) { /* non-fatal */ }
    }
    return $spawned;
}

/**
 * Run the spawner at most once per request (belt-and-suspenders for the
 * scheduler). Call from the Tasks board so due tasks appear without waiting for
 * the cron. $venueIds scopes it to what the caller can see.
 */
function recurring_inline_spawn(?array $venueIds = null): int {
    static $done = false;
    if ($done) return 0;
    $done = true;
    return spawn_due_recurring_tasks(null, $venueIds);
}

// ── Read helpers ────────────────────────────────────────────────────────────

/** WHERE fragment + params for venue scope on a table alias. */
function _rt_scope(string $alias, ?array $venueIds, array &$params): string {
    if ($venueIds === null) return '';
    if (!$venueIds) return " AND {$alias}.venue_id = -1";
    $in = implode(',', array_map('intval', $venueIds));
    return " AND {$alias}.venue_id IN ($in)";
}

/** Timetables (schedules) in scope, each with a recurrence count. */
function fetch_task_schedules(?array $venueIds): array {
    if (!recurring_tasks_supported()) return [];
    $params = [];
    $scope = _rt_scope('s', $venueIds, $params);
    try {
        return db_query(
            "SELECT s.*, v.name AS venue_name, a.name AS assignee_name,
                    (SELECT COUNT(*) FROM task_recurrences r WHERE r.schedule_id = s.id) AS item_count
               FROM task_schedules s
               LEFT JOIN venues v ON v.id = s.venue_id
               LEFT JOIN admin_users a ON a.id = s.assigned_to
              WHERE 1=1 {$scope}
              ORDER BY s.is_active DESC, s.name",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** One schedule by id (with venue for scope checks), or null. */
function fetch_task_schedule(int $id): ?array {
    if (!recurring_tasks_supported() || $id <= 0) return null;
    $r = db_query("SELECT * FROM task_schedules WHERE id = :id", [':id' => $id])->fetch();
    return $r ?: null;
}

/** Recurrences belonging to a schedule, or standalone ones in scope when $scheduleId is null. */
function fetch_task_recurrences(?int $scheduleId, ?array $venueIds): array {
    if (!recurring_tasks_supported()) return [];
    $params = [];
    if ($scheduleId !== null) {
        $params[':sid'] = $scheduleId;
        $where = 'r.schedule_id = :sid';
    } else {
        $where = 'r.schedule_id IS NULL';
    }
    $where .= _rt_scope('r', $venueIds, $params);
    try {
        return db_query(
            "SELECT r.*, v.name AS venue_name, a.name AS assignee_name
               FROM task_recurrences r
               LEFT JOIN venues v ON v.id = r.venue_id
               LEFT JOIN admin_users a ON a.id = r.assigned_to
              WHERE {$where}
              ORDER BY r.is_active DESC, r.next_run_date, r.id",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** One recurrence by id (for scope checks), or null. */
function fetch_task_recurrence(int $id): ?array {
    if (!recurring_tasks_supported() || $id <= 0) return null;
    $r = db_query("SELECT * FROM task_recurrences WHERE id = :id", [':id' => $id])->fetch();
    return $r ?: null;
}

/**
 * Insert a recurrence. $data: venue_id, title (required), schedule_id, assigned_to,
 * job_type, detail, frequency, interval_days, time_of_day, start_date, created_by.
 * next_run_date starts at start_date (or today). Returns the new id (0 on failure).
 */
function create_task_recurrence(array $data): int {
    if (!recurring_tasks_supported()) return 0;
    $freq = (string)($data['frequency'] ?? 'weekly');
    if (!array_key_exists($freq, recurring_freq_options())) $freq = 'weekly';
    $iv = ($freq === 'custom') ? max(1, (int)($data['interval_days'] ?? 1)) : null;
    $start = (string)($data['start_date'] ?? '') ?: date('Y-m-d');
    $ts = strtotime($start); $start = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    $time = trim((string)($data['time_of_day'] ?? '')) ?: null;

    $stmt = db()->prepare(
        "INSERT INTO task_recurrences
            (schedule_id, venue_id, assigned_to, job_type, title, detail, frequency, interval_days, time_of_day, next_run_date, created_by)
         VALUES (:sid, :v, :a, :j, :t, :d, :f, :iv, :tm, :nr, :cb) RETURNING id"
    );
    $stmt->execute([
        ':sid' => !empty($data['schedule_id']) ? (int)$data['schedule_id'] : null,
        ':v'   => (int)($data['venue_id'] ?? 0),
        ':a'   => !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null,
        ':j'   => !empty($data['job_type']) ? (string)$data['job_type'] : null,
        ':t'   => trim((string)($data['title'] ?? '')),
        ':d'   => trim((string)($data['detail'] ?? '')) ?: null,
        ':f'   => $freq,
        ':iv'  => $iv,
        ':tm'  => $time,
        ':nr'  => $start,
        ':cb'  => !empty($data['created_by']) ? (int)$data['created_by'] : null,
    ]);
    return (int)$stmt->fetchColumn();
}
