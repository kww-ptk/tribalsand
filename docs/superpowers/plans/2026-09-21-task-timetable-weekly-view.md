# Job Timetable Weekly View — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a weekly hour-grid timetable for managers and rework the staff day view so today's tasks come first with an unmistakable "Mark done" button.

**Architecture:** One new read-model file (`includes/task-calendar.php`) does a single scoped query per week and then buckets rows into `[day][hour]` cells with a **pure** function. Both the admin grid and the staff day list read that same model, so they cannot drift. One new column (`task_recurrences.procedure`) is read live through `tasks.recurrence_id`, never copied onto a task.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO prepared statements, vanilla JS and CSS (no build step, no npm). Tests are plain PHP scripts run with `php tests/<name>.php`.

**Spec:** `docs/superpowers/specs/2026-09-21-task-timetable-weekly-view-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| Create `db/migrations/add_task_procedures.sql` | One column: `task_recurrences.procedure` |
| Create `includes/task-calendar.php` | Support guard, week maths, scoped fetch, pure bucketing, overdue test |
| Create `tests/task_calendar_logic.php` | Pure assertions + DB round-trip in a rolled-back transaction |
| Create `admin/timetable.php` | The weekly grid page: picker, filters, week nav, grid, detail panel |
| Create `admin/assets/admin-timetable.js` | Detail panel open/close + instant status posting |
| Modify `admin/task-action.php` | Add a JSON response path; keep the PRG redirect as fallback |
| Modify `admin/mywork.php` | Today's tasks first, as cards with a Mark done button + procedure |
| Modify `admin/task-schedule-edit.php` | A procedure textarea per recurrence |
| Modify `admin/_layout.php` | A "Timetable" sidebar link |

**House conventions that apply to every task below:**
- Prepared statements only, via `db_query()`. Never interpolate a value into SQL.
- Every new DB read is pre-migration-safe behind a `*_supported()` guard that probes
  `to_regclass` / `information_schema` — **never** a failing `SELECT`.
- Escape everything user- or DB-supplied with `e()`.
- Nairobi-local dates. Use `frontdesk_today_ymd()` for "today", never `date('Y-m-d')` on an
  assumed UTC clock.
- Admin UI uses the house design system (`.eselect`, `.inp`, `.btn-icon`, `.badge`) — no
  native form chrome.

---

### Task 1: Migration — the procedure column

**Files:**
- Create: `db/migrations/add_task_procedures.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Migration: a written procedure on a recurring job.
-- Run via /admin/migrate.php. Idempotent. Depends on add_recurring_tasks.sql.
--
-- The procedure is the standing how-to for a routine ("how we clean a villa").
-- It lives ONLY here and is read live through tasks.recurrence_id — it is never
-- copied onto a spawned task. Correcting it therefore fixes every task that came
-- from this rule, including ones already spawned and not yet done. That is the
-- deliberate opposite of the signed-consent rule (waiver_*_snapshot), where a
-- record must never change after signing: a procedure must always be current.
--
-- `procedure` is a NON-RESERVED keyword in PostgreSQL and works unquoted as a
-- column name (verified on PG 18). No call site needs to quote it.
ALTER TABLE task_recurrences ADD COLUMN IF NOT EXISTS procedure TEXT;
```

- [ ] **Step 2: Apply it and verify the column exists**

Run:
```bash
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_task_procedures.sql")); echo "applied\n";'
```
Expected: `applied`

Run:
```bash
php -r 'require "includes/db.php"; $r=db()->prepare("SELECT 1 FROM information_schema.columns WHERE table_name=:t AND column_name=:c"); $r->execute([":t"=>"task_recurrences",":c"=>"procedure"]); var_dump((bool)$r->fetchColumn());'
```
Expected: `bool(true)`

- [ ] **Step 3: Re-run it to prove idempotence**

Run:
```bash
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_task_procedures.sql")); echo "re-applied clean\n";'
```
Expected: `re-applied clean` with no error.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_task_procedures.sql
git commit -m "feat(tasks): migration — a written procedure on a recurring job"
```

---

### Task 2: Week maths (pure)

Monday-start week boundaries, Nairobi-local. Written test-first.

**Files:**
- Create: `includes/task-calendar.php`
- Create: `tests/task_calendar_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/task_calendar_logic.php`:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/task_calendar_logic.php`
Expected: a fatal error — `Failed opening required '.../includes/task-calendar.php'`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/task-calendar.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/task_calendar_logic.php`
Expected: 10 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/task-calendar.php tests/task_calendar_logic.php
git commit -m "feat(tasks): week maths for the timetable grid (Monday-start, Nairobi-local)"
```

---

### Task 3: Overdue test (pure)

**Files:**
- Modify: `includes/task-calendar.php`
- Modify: `tests/task_calendar_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/task_calendar_logic.php`, insert before the closing `echo $failures ?` line:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/task_calendar_logic.php`
Expected: fatal — `Call to undefined function task_is_overdue()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/task-calendar.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/task_calendar_logic.php`
Expected: 24 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/task-calendar.php tests/task_calendar_logic.php
git commit -m "feat(tasks): overdue test, with the instant injected so it is testable"
```

---

### Task 4: Bucketing into grid cells (pure)

**Files:**
- Modify: `includes/task-calendar.php`
- Modify: `tests/task_calendar_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/task_calendar_logic.php`, insert before the closing `echo $failures ?` line:

```php
// ── Bucketing (pure) ────────────────────────────────────────────────────────
$rows = [
    ['id'=>1,'due_date'=>'2026-09-21','due_time'=>'07:00:00','status'=>'todo','title'=>'Water beds'],
    ['id'=>2,'due_date'=>'2026-09-21','due_time'=>'07:30:00','status'=>'todo','title'=>'Sweep deck'],
    ['id'=>3,'due_date'=>'2026-09-21','due_time'=>null,      'status'=>'todo','title'=>'Rake paths'],
    ['id'=>4,'due_date'=>'2026-09-24','due_time'=>'15:00:00','status'=>'done','title'=>'Pool check'],
    ['id'=>5,'due_date'=>'2026-09-20','due_time'=>'09:00:00','status'=>'todo','title'=>'Last week'],
];
$g = task_week_grid($rows, '2026-09-21', '2026-09-21', '10:00:00');

check('grid days are the week',    $g['days'][0] === '2026-09-21' && $g['days'][6] === '2026-09-27');
check('two tasks share the 7 cell',count($g['cells']['2026-09-21'][7]) === 2);
check('cell keeps time order',     $g['cells']['2026-09-21'][7][0]['id'] === 1);
check('untimed goes to anytime',   count($g['anytime']['2026-09-21']) === 1 && $g['anytime']['2026-09-21'][0]['id'] === 3);
check('untimed is NOT in a cell',  !isset($g['cells']['2026-09-21'][0]));
check('task outside week dropped', $g['counts']['total'] === 4);
check('done counted',              $g['counts']['done'] === 1);
check('hours default low bound',   $g['hours'][0] === 6);
check('hours default high bound',  end($g['hours']) === 20);
check('thursday cell placed',      count($g['cells']['2026-09-24'][15]) === 1);

// Range expansion: a 04:00 task pulls the low bound down, a 22:00 pushes it up.
$g2 = task_week_grid([
    ['id'=>9,'due_date'=>'2026-09-22','due_time'=>'04:00:00','status'=>'todo','title'=>'Early'],
    ['id'=>10,'due_date'=>'2026-09-22','due_time'=>'22:00:00','status'=>'todo','title'=>'Late'],
], '2026-09-21', '2026-09-21', '10:00:00');
check('range expands down',  $g2['hours'][0] === 4);
check('range expands up',    end($g2['hours']) === 22);

// Pre-migration shape: no due_time key at all → everything is an Anytime task.
$g3 = task_week_grid([
    ['id'=>11,'due_date'=>'2026-09-23','status'=>'todo','title'=>'No time column'],
], '2026-09-21', '2026-09-21', '10:00:00');
check('missing due_time → anytime', count($g3['anytime']['2026-09-23']) === 1);
check('missing due_time → no cells', $g3['cells']['2026-09-23'] === []);

// Overdue count agrees with the rows it came from.
$g4 = task_week_grid([
    ['id'=>12,'due_date'=>'2026-09-21','due_time'=>'08:00:00','status'=>'todo','title'=>'Missed'],
    ['id'=>13,'due_date'=>'2026-09-21','due_time'=>'12:00:00','status'=>'todo','title'=>'Coming'],
], '2026-09-21', '2026-09-21', '10:00:00');
check('overdue counted once', $g4['counts']['overdue'] === 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/task_calendar_logic.php`
Expected: fatal — `Call to undefined function task_week_grid()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/task-calendar.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/task_calendar_logic.php`
Expected: 39 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/task-calendar.php tests/task_calendar_logic.php
git commit -m "feat(tasks): pure bucketing of a week into [day][hour] grid cells"
```

---

### Task 5: The procedure guard and the scoped week query

**Files:**
- Modify: `includes/task-calendar.php`
- Modify: `tests/task_calendar_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/task_calendar_logic.php`, insert before the closing `echo $failures ?` line:

```php
// ── DB round-trip, inside a transaction we roll back ────────────────────────
$dbOk = true;
try { db(); } catch (Throwable $e) { $dbOk = false; }

if (!$dbOk || !tasks_supported()) {
    echo "\nSKIP  DB assertions (no database, or add_tasks.sql not applied)\n";
} else {
    db()->beginTransaction();
    try {
        $vid = (int) db_query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn();
        $other = (int) (db_query("SELECT id FROM venues WHERE id <> :v ORDER BY id LIMIT 1", [':v'=>$vid])->fetchColumn() ?: 0);
        $mon = task_week_start(date('Y-m-d'));
        $wed = date('Y-m-d', strtotime('+2 days', strtotime($mon)));

        db_query("INSERT INTO tasks (venue_id, title, status, due_date) VALUES (:v, 'Grid probe', 'todo', :d)", [':v'=>$vid, ':d'=>$wed]);

        $rows = task_week_fetch(null, $vid, $mon);
        $titles = array_column($rows, 'title');
        check('fetch finds the task in its week', in_array('Grid probe', $titles, true));

        $prev = task_week_fetch(null, $vid, task_week_shift($mon, -1));
        check('previous week does not see it', !in_array('Grid probe', array_column($prev, 'title'), true));

        // Scope: a venue outside the caller's set yields nothing, even when asked for.
        check('out-of-scope venue returns []', task_week_fetch([$other ?: -1], $vid, $mon) === []);
        check('in-scope venue returns rows',   count(task_week_fetch([$vid], $vid, $mon)) >= 1);

        // Assignee filter: nobody is assigned, so filtering to a real id finds none.
        check('assignee filter narrows', task_week_fetch(null, $vid, $mon, ['assigned_to' => 999999]) === []);
        check('unassigned filter finds it',
            in_array('Grid probe', array_column(task_week_fetch(null, $vid, $mon, ['assigned_to'=>'unassigned']), 'title'), true));

        check('procedure guard returns a bool', is_bool(task_procedures_supported()));

        // The staff day list must be ONE query, not one per property. Assign the
        // probe task and read it back without naming a venue at all.
        $anyAdmin = (int) db_query("SELECT id FROM admin_users ORDER BY id LIMIT 1")->fetchColumn();
        db_query("UPDATE tasks SET assigned_to = :a WHERE title = 'Grid probe'", [':a' => $anyAdmin]);
        $mine = task_user_day_fetch($anyAdmin, $wed);
        check('day fetch finds my task',  in_array('Grid probe', array_column($mine, 'title'), true));
        check('day fetch is that day only', $mine === [] || count(array_unique(array_column($mine, 'due_date'))) === 1);
        check('day fetch excludes others', task_user_day_fetch(999999, $wed) === []);
    } finally {
        db()->rollBack();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/task_calendar_logic.php`
Expected: fatal — `Call to undefined function task_week_fetch()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/task-calendar.php`:

```php
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

    // due_time ships with add_recurring_tasks.sql (see admin/tasks.php:373), and
    // the procedure with add_task_procedures.sql. Select each only when present.
    //
    // SELECT and ORDER BY need DIFFERENT expressions: the select list carries the
    // "AS due_time" alias, and an alias is a syntax error inside ORDER BY. Reusing
    // one string for both throws 42601, which this function's catch would turn
    // into an empty grid — a silent blank page on exactly the pre-migration
    // deploy the guard exists to protect.
    $has      = recurring_tasks_supported();
    $timeSel  = $has ? "t.due_time" : "NULL::time AS due_time";
    $timeOrd  = $has ? "t.due_time" : "NULL::time";
    $procSel  = task_procedures_supported() ? "r.procedure AS procedure_text" : "NULL::text AS procedure_text";
    $procJoin = $has ? "LEFT JOIN task_recurrences r ON r.id = t.recurrence_id" : "";

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
    } catch (Throwable $e) { return []; }
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

    // Same SELECT-vs-ORDER BY split as task_week_fetch(): an alias is a syntax
    // error in ORDER BY, and the catch below would hide it as an empty day.
    $has      = recurring_tasks_supported();
    $timeSel  = $has ? "t.due_time" : "NULL::time AS due_time";
    $timeOrd  = $has ? "t.due_time" : "NULL::time";
    $procSel  = task_procedures_supported() ? "r.procedure AS procedure_text" : "NULL::text AS procedure_text";
    $procJoin = $has ? "LEFT JOIN task_recurrences r ON r.id = t.recurrence_id" : "";

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
    } catch (Throwable $e) { return []; }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/task_calendar_logic.php`
Expected: 49 `PASS` lines then `ALL PASS`. (If the DB has fewer than two venues, the
out-of-scope assertion still passes — it falls back to `-1`, which no venue has.)

- [ ] **Step 5: Commit**

```bash
git add includes/task-calendar.php tests/task_calendar_logic.php
git commit -m "feat(tasks): scoped one-query week fetch + the procedure guard"
```

---

### Task 6: A JSON response path on task-action.php

The permission model is already correct and **must not change**. This adds a response
format, nothing else.

**Files:**
- Modify: `admin/task-action.php`

- [ ] **Step 1: Read the file end to end before editing**

Run: `cat admin/task-action.php`

Confirm the guard order you must preserve: method check → `verify_csrf()` → task lookup →
status whitelist → `$canManage` / `$isAssignee` → the manager-only cancel rule → UPDATE.

- [ ] **Step 2: Add the JSON flag next to the existing return target**

Find this line:

```php
$returnTo = ($_POST['return'] ?? '') === 'mywork' ? '/admin/mywork.php' : '/admin/tasks.php';
```

Replace it with:

```php
$returnTo = ($_POST['return'] ?? '') === 'mywork' ? '/admin/mywork.php' : '/admin/tasks.php';

// The button posts FormData (not a JSON body) precisely so verify_csrf() — which
// reads $_POST — keeps working unchanged, with no CSRF-in-body special case.
// A caller asks for JSON with format=json; everything else still gets the PRG
// redirect, which is the no-JS fallback.
$wantsJson = ($_POST['format'] ?? '') === 'json';

/** Answer in the format the caller asked for. Never returns. */
function task_action_respond(bool $ok, string $msg, array $extra = []): void {
    if ($GLOBALS['wantsJson']) {
        header('Content-Type: application/json');
        if (!$ok) http_response_code(400);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg] + $extra);
        exit;
    }
    $_SESSION['hold_flash'] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
    header('Location: ' . $GLOBALS['returnTo']);
    exit;
}
```

- [ ] **Step 3: Route every existing exit through the responder**

Replace each of the four failure blocks and the success block with a
`task_action_respond()` call, leaving the conditions untouched.

Task not found:
```php
if (!$task) { task_action_respond(false, 'Task not found.'); }
```

Unknown status:
```php
if (!in_array($status, ['todo','in_progress','done','cancelled'], true)) {
    task_action_respond(false, 'Unknown task status.');
}
```

Not yours:
```php
if (!$canManage && !$isAssignee) {
    task_action_respond(false, 'That task isn’t yours to update.');
}
```

Manager-only cancel:
```php
if ($status === 'cancelled' && !$canManage) {
    task_action_respond(false, 'Only a manager can cancel a task.');
}
```

Success — replace the final three lines (`db_query(...)`, `audit_log(...)`,
`$_SESSION['hold_flash'] = ...; header(...); exit;`) with:

```php
db_query("UPDATE tasks SET status = :s WHERE id = :id", [':s'=>$status, ':id'=>$id]);
audit_log('task.' . $status, 'task', $id, '');
task_action_respond(true, 'Task marked ' . task_status_label($status) . '.', [
    'id'     => $id,
    'status' => $status,
    'label'  => task_status_label($status),
    'badge'  => task_badge_class($status),
]);
```

- [ ] **Step 4: Verify the file still parses**

Run: `php -l admin/task-action.php`
Expected: `No syntax errors detected in admin/task-action.php`

- [ ] **Step 5: Verify the guards are all still present and in order**

Run:
```bash
grep -nE "verify_csrf|canManage|isAssignee|cancelled' && |task_action_respond" admin/task-action.php
```
Expected: `verify_csrf()` appears before any `task_action_respond`, and both the
`!$canManage && !$isAssignee` check and the manager-only cancel check are still there.

- [ ] **Step 6: Commit**

```bash
git add admin/task-action.php
git commit -m "feat(tasks): task-action answers JSON when asked, PRG otherwise"
```

---

### Task 7: The weekly grid page

**Files:**
- Create: `admin/timetable.php`

- [ ] **Step 1: Write the page**

Create `admin/timetable.php`:

```php
<?php
/**
 * Admin: the weekly job timetable — a school-timetable grid of real tasks.
 *
 * Days across, clock hours down, ONE property at a time, with an "Anytime" row
 * pinned above the hours for tasks that carry no due_time. Read-only over the
 * tasks table: creating and editing routines stays in admin/task-schedules.php.
 *
 * Scope: owner sees every property; a manager sees their own. A STAFF member's
 * person filter is LOCKED to themselves — the lock is derived from the session
 * role here, never from a request parameter, so there is no identity to forge.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/task-calendar.php';
require_login();

$pageTitle  = 'Timetable';
$activeMenu = 'timetable';

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();                 // null = owner (all)
$JOBS     = team_job_types();
$today    = frontdesk_today_ymd();
$nowHms   = date('H:i:s');

// Venues in scope (owner: all; manager/staff: assigned).
if ($venueIds === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($venueIds) {
    $ph = []; $p = [];
    foreach ($venueIds as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}

if (!tasks_supported()) {
    include __DIR__ . '/_layout.php';
    echo '<div class="page-header"><h1>Timetable</h1></div>';
    echo '<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Tasks aren’t enabled yet. Ask the owner to run the <code>add_tasks.sql</code> migration.</p></div></div>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

// Chosen property: a ?venue= outside the account's own list is ignored, never honoured.
$venueId = (int)($_GET['venue'] ?? 0);
$allowed = array_map(fn($v) => (int)$v['id'], $venues);
if (!in_array($venueId, $allowed, true)) $venueId = $allowed[0] ?? 0;

$weekStart = task_week_start((string)($_GET['week'] ?? $today));

// Person filter. Managers and the owner choose; staff are locked to themselves.
$canFilterPeople = is_owner() || is_manager();
$filters = [];
if ($canFilterPeople) {
    $who = (string)($_GET['who'] ?? '');
    if ($who === 'unassigned')  $filters['assigned_to'] = 'unassigned';
    elseif (ctype_digit($who))  $filters['assigned_to'] = (int)$who;
    $job = (string)($_GET['job'] ?? '');
    if ($job !== '' && isset($JOBS[$job])) $filters['job_type'] = $job;
} else {
    $filters['assigned_to'] = $meId;
}

$rows = $venueId ? task_week_fetch($venueIds, $venueId, $weekStart, $filters) : [];
$grid = task_week_grid($rows, $weekStart, $today, $nowHms);

// People who could hold a task at this property, for the filter menu.
$people = [];
if ($canFilterPeople && $venueId) {
    try {
        $people = db_query(
            "SELECT DISTINCT a.id, a.name FROM admin_users a
               JOIN admin_user_venues av ON av.admin_user_id = a.id
              WHERE a.is_active = TRUE AND av.venue_id = :v
              ORDER BY a.name ASC", [':v' => $venueId]
        )->fetchAll();
    } catch (Throwable $e) { $people = []; }
}

/** Keep the current view when changing one parameter. */
$url = function (array $over = []) use ($venueId, $weekStart): string {
    $q = array_merge([
        'venue' => $venueId,
        'week'  => $weekStart,
        'who'   => (string)($_GET['who'] ?? ''),
        'job'   => (string)($_GET['job'] ?? ''),
    ], $over);
    return '/admin/timetable.php?' . http_build_query(array_filter($q, fn($x) => $x !== '' && $x !== 0));
};

/** A chip colour per job type, so a glance reads "who does what". */
$jobClass = fn(?string $j): string => 'tt-chip--' . preg_replace('/[^a-z]/', '', strtolower((string)$j)) ?: 'tt-chip--none';

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Timetable</h1>
  <div class="text-muted" style="font-size:13px">
    <?= (int)$grid['counts']['total'] ?> tasks ·
    <?= (int)$grid['counts']['done'] ?> done<?php if ($grid['counts']['overdue'] > 0): ?> ·
    <span style="color:#b3261e"><?= (int)$grid['counts']['overdue'] ?> overdue</span><?php endif; ?>
  </div>
</div>

<?php if (!$venueId): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">No properties are assigned to your account.</p></div></div>
<?php else: ?>

<div class="card" style="margin-bottom:1rem">
  <div class="card__body" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <form method="GET" action="/admin/timetable.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="week" value="<?= e($weekStart) ?>">
      <select name="venue" class="eselect" onchange="this.form.submit()">
        <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === $venueId ? 'selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($canFilterPeople): ?>
      <select name="who" class="eselect" onchange="this.form.submit()">
        <option value="">Everyone</option>
        <option value="unassigned" <?= ($_GET['who'] ?? '') === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
        <?php foreach ($people as $pp): ?>
        <option value="<?= (int)$pp['id'] ?>" <?= (string)($_GET['who'] ?? '') === (string)$pp['id'] ? 'selected' : '' ?>><?= e($pp['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="job" class="eselect" onchange="this.form.submit()">
        <option value="">Any job</option>
        <?php foreach ($JOBS as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= (string)($_GET['job'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </form>

    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <a class="btn-icon btn-icon--outline" href="<?= e($url(['week' => task_week_shift($weekStart, -1)])) ?>" aria-label="Previous week">‹</a>
      <span style="font-size:13px;min-width:170px;text-align:center">
        <?= e(date('j M', strtotime($grid['days'][0]))) ?> – <?= e(date('j M Y', strtotime($grid['days'][6]))) ?>
      </span>
      <a class="btn-icon btn-icon--outline" href="<?= e($url(['week' => task_week_shift($weekStart, 1)])) ?>" aria-label="Next week">›</a>
      <a class="btn-icon btn-icon--outline" href="<?= e($url(['week' => task_week_start($today)])) ?>">Today</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__body" style="overflow-x:auto">
    <table class="tt-grid">
      <thead>
        <tr>
          <th class="tt-hourcol"></th>
          <?php foreach ($grid['days'] as $d): ?>
          <th class="<?= $d === $today ? 'is-today' : '' ?>">
            <?= e(date('D', strtotime($d))) ?><br><span class="text-muted" style="font-weight:400"><?= e(date('j M', strtotime($d))) ?></span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr class="tt-anytime">
          <th class="tt-hourcol">Anytime</th>
          <?php foreach ($grid['days'] as $d): ?>
          <td class="<?= $d === $today ? 'is-today' : '' ?>">
            <?php foreach ($grid['anytime'][$d] as $t) tt_chip($t, $today, $nowHms, $jobClass); ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php foreach ($grid['hours'] as $h): ?>
        <tr>
          <th class="tt-hourcol"><?= sprintf('%02d:00', $h) ?></th>
          <?php foreach ($grid['days'] as $d): ?>
          <td class="<?= $d === $today ? 'is-today' : '' ?>">
            <?php foreach (($grid['cells'][$d][$h] ?? []) as $t) tt_chip($t, $today, $nowHms, $jobClass); ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($grid['counts']['total'] === 0): ?>
    <p class="text-muted" style="text-align:center;padding:2rem 0;margin:0">
      Nothing scheduled at this property for <?= e(date('j M', strtotime($grid['days'][0]))) ?>–<?= e(date('j M', strtotime($grid['days'][6]))) ?>.
    </p>
    <?php endif; ?>
  </div>
</div>

<div id="ttPanel" class="tt-panel" hidden aria-live="polite"></div>

<?php endif; ?>

<?php
/** One task chip. Carries its own data so the panel needs no second request. */
function tt_chip(array $t, string $today, string $nowHms, callable $jobClass): void {
    $late = task_is_overdue($t, $today, $nowHms);
    $done = (string)$t['status'] === 'done';
    $cls  = 'tt-chip ' . $jobClass($t['job_type'] ?? null)
          . ($done ? ' is-done' : '') . ($late ? ' is-late' : '');
    ?>
    <button type="button" class="<?= e($cls) ?>" data-task='<?= e(json_encode([
        'id'        => (int)$t['id'],
        'title'     => (string)$t['title'],
        'detail'    => (string)($t['detail'] ?? ''),
        'procedure' => (string)($t['procedure_text'] ?? ''),
        'assignee'  => (string)($t['assignee_name'] ?? ''),
        'status'    => (string)$t['status'],
        'time'      => $t['due_time'] ? substr((string)$t['due_time'], 0, 5) : '',
    ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
      <span class="tt-chip__title"><?= e($t['title']) ?></span>
      <span class="tt-chip__who"><?= e($t['assignee_name'] ?: 'Unassigned') ?></span>
    </button>
    <?php
}
?>
<style>
.tt-grid{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed}
.tt-grid th,.tt-grid td{border:1px solid #e7e1d6;vertical-align:top;padding:3px}
.tt-grid thead th{padding:6px 3px;font-size:12px;text-align:center}
.tt-hourcol{width:62px;color:#8a8072;font-weight:400;text-align:right;padding-right:6px!important;white-space:nowrap}
.tt-grid td.is-today,.tt-grid th.is-today{background:#fdfaf4}
.tt-anytime td{background:#faf7f1}
.tt-chip{display:block;width:100%;text-align:left;border:1px solid transparent;border-radius:5px;padding:4px 5px;margin-bottom:3px;cursor:pointer;font:inherit;background:#eef2f7}
.tt-chip:last-child{margin-bottom:0}
.tt-chip__title{display:block;line-height:1.25}
.tt-chip__who{display:block;font-size:11px;opacity:.7}
.tt-chip.is-done{opacity:.5;text-decoration:line-through}
.tt-chip.is-late{border-color:#b3261e}
.tt-chip--housekeeping{background:#e8f1ec}
.tt-chip--laundry{background:#eef0f7}
.tt-chip--maintenance{background:#f7efe6}
.tt-chip--gardening{background:#eaf3e2}
.tt-chip--driver{background:#f2eef7}
.tt-chip--security{background:#f7eaea}
.tt-chip--frontdesk{background:#eaf0f7}
.tt-panel{position:fixed;right:0;top:0;bottom:0;width:340px;max-width:92vw;background:#fff;border-left:1px solid #e7e1d6;box-shadow:-8px 0 24px rgba(0,0,0,.08);padding:1.25rem;overflow:auto;z-index:60}
.tt-panel h3{margin:0 0 .35rem;font-size:16px}
.tt-proc{white-space:pre-wrap;background:#faf7f1;border-radius:6px;padding:10px;font-size:13px;line-height:1.55;margin-top:.5rem}
</style>
<script src="/admin/assets/admin-timetable.js?v=<?= @filemtime(__DIR__ . '/assets/admin-timetable.js') ?: time() ?>"></script>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Verify it parses**

Run: `php -l admin/timetable.php`
Expected: `No syntax errors detected in admin/timetable.php`

- [ ] **Step 3: Commit**

```bash
git add admin/timetable.php
git commit -m "feat(tasks): weekly timetable grid — hours down, days across, Anytime row"
```

---

### Task 8: The detail panel script

**Files:**
- Create: `admin/assets/admin-timetable.js`

- [ ] **Step 1: Write the script**

Create `admin/assets/admin-timetable.js`:

```js
/* Timetable grid — the task detail panel.
   The chip already carries its task as JSON (the week query fetched it), so
   opening the panel costs no request. Status buttons post FormData to the same
   endpoint the staff cards use, so one permission model serves both. */
(function () {
  'use strict';
  var panel = document.getElementById('ttPanel');
  if (!panel) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* The page hands us the token on #ttPanel's data-csrf — this page has no
     form to read one from, the way admin-assistant.js and admin-gallery.js
     read theirs off a data attribute. */
  function token() { return panel.getAttribute('data-csrf') || ''; }

  function close() { panel.hidden = true; panel.innerHTML = ''; }

  function open(t) {
    var proc = t.procedure
      ? '<div style="margin-top:1rem"><strong style="font-size:13px">Procedure</strong><div class="tt-proc">' + esc(t.procedure) + '</div></div>'
      : '';
    var detail = t.detail ? '<p style="font-size:13px;color:#6b6256">' + esc(t.detail) + '</p>' : '';
    var when = t.time ? esc(t.time) : 'Anytime';
    panel.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:start;gap:8px">' +
        '<h3>' + esc(t.title) + '</h3>' +
        '<button type="button" class="btn-icon btn-icon--outline" data-tt-close aria-label="Close">&times;</button>' +
      '</div>' +
      '<p class="text-muted" style="font-size:13px;margin:.1rem 0 .75rem">' + when + ' · ' + esc(t.assignee || 'Unassigned') + '</p>' +
      detail + proc +
      '<div style="margin-top:1.25rem;display:flex;gap:8px;flex-wrap:wrap">' +
        (t.status === 'done'
          ? '<button type="button" class="btn-icon btn-icon--outline" data-tt-set="todo" data-tt-id="' + t.id + '">Reopen</button>'
          : '<button type="button" class="btn-icon btn-icon--primary" data-tt-set="done" data-tt-id="' + t.id + '">Mark done</button>') +
      '</div>' +
      '<p data-tt-msg style="font-size:12px;color:#b3261e;margin-top:.6rem"></p>';
    panel.hidden = false;
  }

  document.addEventListener('click', function (ev) {
    var chip = ev.target.closest ? ev.target.closest('.tt-chip') : null;
    if (chip) {
      try { open(JSON.parse(chip.getAttribute('data-task'))); } catch (e) { /* malformed payload: leave the panel shut */ }
      return;
    }
    if (ev.target.closest && ev.target.closest('[data-tt-close]')) { close(); return; }

    var btn = ev.target.closest ? ev.target.closest('[data-tt-set]') : null;
    if (!btn) return;

    var body = new FormData();
    body.append('csrf_token', token());
    body.append('id', btn.getAttribute('data-tt-id'));
    body.append('status', btn.getAttribute('data-tt-set'));
    body.append('format', 'json');
    btn.disabled = true;

    /* verify_csrf() answers an expired session with a 403 and a PLAIN TEXT body,
       before task-action.php's JSON flag exists — so check the status before
       parsing, or .json() throws and we blame the network for a dead session. */
    fetch('/admin/task-action.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) return { ok: false, error: 'Your session expired. Reload the page and sign in again.' };
        return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Reload and try again.' }; });
      })
      .then(function (d) {
        if (d && d.ok) { window.location.reload(); return; }
        btn.disabled = false;
        var m = panel.querySelector('[data-tt-msg]');
        if (m) m.textContent = (d && d.error) || 'That didn’t save. Try again.';
      })
      .catch(function () {
        btn.disabled = false;
        var m = panel.querySelector('[data-tt-msg]');
        if (m) m.textContent = 'Network problem. Try again.';
      });
  });

  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
})();
```

- [ ] **Step 2: Confirm the page really hands over a token**

Task 7 puts it on the panel container: `<div id="ttPanel" … data-csrf="…">`.

Run: `grep -n 'data-csrf' admin/timetable.php`
Expected: one match, on `#ttPanel`.

If it is missing, STOP and report it rather than adding a `csrf_field()` to the
filter form — that form is a GET and a hidden token in it would be echoed into
the query string of every filter change.

- [ ] **Step 3: Commit**

```bash
git add admin/assets/admin-timetable.js
git commit -m "feat(tasks): timetable detail panel, no second request per chip"
```

---

### Task 9: The staff day view

> **The staff cards must patch in place, NOT reload.** The admin grid (Task 8)
> reloads on success, which is fine for a manager on a desktop acting
> occasionally. It is the wrong trade here: a staff member works down many tasks
> on a phone, often on a poor connection, and a full page reload per tap is slow,
> costly and loses their place in the list. `admin/task-action.php` returns
> `id`, `status`, `label` and `badge` precisely so the caller can patch. On
> success, update the card in place — strike the title, grey the card, swap the
> button to Reopen — and do not navigate. `admin/assets/admin-chat.js` is the
> house precedent for patching after a mutation.
>
> The same two client rules from Task 8 apply, for the same reasons: read the
> CSRF token from a `data-csrf` attribute the page emits (not a form input), and
> check `r.status === 403` BEFORE calling `.json()`, because `verify_csrf()`
> answers an expired session with a plain-text body. A staff phone left open all
> shift makes the expired session the LIKELY failure, not an edge case.


**Files:**
- Modify: `admin/mywork.php`

- [ ] **Step 1: Read the whole file first**

Run: `cat admin/mywork.php`

You are moving the task block above the guest-request and worklist blocks and changing how
each task renders. The request and worklist blocks keep working, unchanged, below.

- [ ] **Step 2: Load the calendar helper**

After the existing `require_once __DIR__ . '/../includes/frontdesk.php';` line, add:

```php
require_once __DIR__ . '/../includes/task-calendar.php';   // today's tasks + procedures
```

- [ ] **Step 3: Fetch today's tasks through the shared model**

Find:

```php
$myTasks = $tasksOn ? mywork_tasks($meId, ['todo','in_progress']) : [];
```

Replace it with:

```php
// Today's tasks come from the SAME read model as the grid, so the two cannot
// disagree about what is due or what counts as late. ONE query, whatever the
// person's venue scope — task_user_day_fetch() keys on assignment, which already
// implies the property, so there is no venue loop to fire a query per property.
//
// Completed tasks stay in the list for the rest of the day: a staff member should
// see what they finished, and be able to reopen a mis-tap.
$nowHms = date('H:i:s');
$dayAll = $tasksOn ? task_user_day_fetch($meId, $today) : [];

// Timed work first, in clock order; untimed work under its own heading.
$myToday = array_values(array_filter($dayAll, fn($r) => ($r['due_time'] ?? '') !== ''));
$myLater = array_values(array_filter($dayAll, fn($r) => ($r['due_time'] ?? '') === ''));

// Anything assigned and still open from before today, so nothing is silently lost.
$myOverdue = $tasksOn
    ? array_values(array_filter(mywork_tasks($meId, ['todo','in_progress']), fn($r) => (string)($r['due_date'] ?? '') !== '' && (string)$r['due_date'] < $today))
    : [];
```

- [ ] **Step 4: Render the card**

Add this function near the existing `worklist_row()` definition:

```php
/** One task card: title, when, property, procedure, and a Mark done button. */
function mywork_task_card(array $t, string $today, string $nowHms): void {
    $done = (string)$t['status'] === 'done';
    $late = task_is_overdue($t, $today, $nowHms);
    $when = ($t['due_time'] ?? '') !== '' ? substr((string)$t['due_time'], 0, 5) : 'Anytime';
    ?>
    <div class="mw-task<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?>" data-task-card>
      <div class="mw-task__when"><?= e($when) ?></div>
      <div class="mw-task__body">
        <div class="mw-task__title"><?= e($t['title']) ?></div>
        <div class="mw-task__meta"><?= e($t['venue_name'] ?? '') ?><?= $late && !$done ? ' · <span style="color:#b3261e">Overdue</span>' : '' ?></div>
        <?php if (!empty($t['detail'])): ?>
        <div class="mw-task__detail"><?= e($t['detail']) ?></div>
        <?php endif; ?>
        <?php if (!empty($t['procedure_text'])): ?>
        <details class="mw-task__proc">
          <summary>Procedure</summary>
          <div><?= nl2br(e($t['procedure_text'])) ?></div>
        </details>
        <?php endif; ?>
      </div>
      <form method="POST" action="/admin/task-action.php" class="mw-task__act" data-task-form>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="mywork">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <?php if ($done): ?>
        <button name="status" value="todo" class="mw-btn mw-btn--undo">Reopen</button>
        <?php else: ?>
        <button name="status" value="done" class="mw-btn mw-btn--done">Mark done</button>
        <?php endif; ?>
      </form>
    </div>
    <?php
}
```

- [ ] **Step 5: Put the task block first**

Move the whole tasks `<div class="card">` block so it sits immediately after the flash
message and before the worklist and assigned-request blocks, and replace its table body
with the cards:

```php
<?php if ($tasksOn): ?>
<div class="card" style="margin-bottom:1rem">
  <div class="card__header" style="display:flex;justify-content:space-between;align-items:center">
    <h2 style="margin:0;font-size:17px">Today · <?= e(date('D j M', strtotime($today))) ?></h2>
    <a class="btn-icon btn-icon--outline" href="/admin/timetable.php">This week</a>
  </div>
  <div class="card__body">
    <?php if (!$myToday && !$myLater && !$myOverdue): ?>
    <p class="text-muted" style="text-align:center;padding:1.5rem 0;margin:0">Nothing on your list today.</p>
    <?php endif; ?>

    <?php if ($myOverdue): ?>
    <h3 class="mw-head">Still open from before</h3>
    <?php foreach ($myOverdue as $t) mywork_task_card($t, $today, $nowHms); ?>
    <?php endif; ?>

    <?php foreach ($myToday as $t) mywork_task_card($t, $today, $nowHms); ?>

    <?php if ($myLater): ?>
    <h3 class="mw-head">Anytime today</h3>
    <?php foreach ($myLater as $t) mywork_task_card($t, $today, $nowHms); ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
```

- [ ] **Step 6: Add the card styles**

Add before the `_layout_end.php` include:

```php
<style>
.mw-head{font-size:13px;color:#8a8072;margin:1rem 0 .5rem;font-weight:500}
.mw-head:first-child{margin-top:0}
.mw-task{display:flex;gap:12px;align-items:flex-start;border:1px solid #e7e1d6;border-radius:10px;padding:12px;margin-bottom:10px;background:#fff}
.mw-task.is-late{border-color:#e8b4ae}
.mw-task.is-done{opacity:.55}
.mw-task.is-done .mw-task__title{text-decoration:line-through}
.mw-task__when{min-width:52px;font-size:13px;color:#8a8072;padding-top:2px}
.mw-task__body{flex:1;min-width:0}
.mw-task__title{font-size:16px;line-height:1.3}
.mw-task__meta{font-size:12px;color:#8a8072;margin-top:2px}
.mw-task__detail{font-size:13px;color:#6b6256;margin-top:6px}
.mw-task__proc{margin-top:8px;font-size:13px}
.mw-task__proc summary{cursor:pointer;color:#6b6256}
.mw-task__proc div{white-space:pre-wrap;background:#faf7f1;border-radius:6px;padding:10px;margin-top:6px;line-height:1.55}
.mw-btn{border:0;border-radius:8px;padding:12px 16px;font:inherit;font-size:14px;cursor:pointer;min-height:44px;white-space:nowrap}
.mw-btn--done{background:#2f6f4f;color:#fff}
.mw-btn--undo{background:#f1ece2;color:#6b6256}
@media (max-width:560px){
  .mw-task{flex-wrap:wrap}
  .mw-task__act{width:100%}
  .mw-btn{width:100%}
}
</style>
```

- [ ] **Step 7: Verify it parses**

Run: `php -l admin/mywork.php`
Expected: `No syntax errors detected in admin/mywork.php`

- [ ] **Step 8: Commit**

```bash
git add admin/mywork.php
git commit -m "feat(tasks): staff day view — today first, big Mark done, procedures inline"
```

---

### Task 10: Editing the procedure

**Files:**
- Modify: `admin/task-schedule-edit.php`

- [ ] **Step 1: Read the file and find the recurrence form**

Run: `grep -n "detail\|textarea\|name=\"title\"" admin/task-schedule-edit.php | head -20`

Locate the form that creates or edits one recurrence, and the POST branch that writes it.

- [ ] **Step 2: Add the textarea to the form**

Directly after the recurrence's `detail` field, add:

```php
<?php if (task_procedures_supported()): ?>
<label style="display:block;margin-top:10px">
  <span style="font-size:13px">Procedure <span class="text-muted">(how the job is done — staff read this on the task)</span></span>
  <textarea name="procedure" rows="6" class="inp" style="width:100%;margin-top:4px"><?= e($r['procedure'] ?? '') ?></textarea>
</label>
<?php endif; ?>
```

Add the require at the top of the file if it is not already present:

```php
require_once __DIR__ . '/../includes/task-calendar.php';   // task_procedures_supported()
```

- [ ] **Step 3: Persist it**

In the POST branch that writes a recurrence, add the column to the write **only when
supported**, so a pre-migration deploy saves everything else instead of failing:

```php
if (task_procedures_supported()) {
    db_query("UPDATE task_recurrences SET procedure = :p WHERE id = :id",
        [':p' => (trim((string)($_POST['procedure'] ?? '')) ?: null), ':id' => $recurrenceId]);
}
```

- [ ] **Step 4: Verify it parses**

Run: `php -l admin/task-schedule-edit.php`
Expected: `No syntax errors detected in admin/task-schedule-edit.php`

- [ ] **Step 5: Round-trip the value through the DB**

Run:
```bash
php -r 'require "includes/task-calendar.php"; require "includes/db.php";
db()->beginTransaction();
$v=(int)db_query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn();
db_query("INSERT INTO task_schedules (venue_id,name) VALUES (:v,:n)",[":v"=>$v,":n"=>"probe"]);
$s=(int)db()->lastInsertId("task_schedules_id_seq");
db_query("INSERT INTO task_recurrences (schedule_id,venue_id,title,procedure) VALUES (:s,:v,:t,:p)",[":s"=>$s,":v"=>$v,":t"=>"probe",":p"=>"step one\nstep two"]);
echo db_query("SELECT procedure FROM task_recurrences WHERE schedule_id=:s",[":s"=>$s])->fetchColumn(), "\n";
db()->rollBack();'
```
Expected:
```
step one
step two
```

- [ ] **Step 6: Commit**

```bash
git add admin/task-schedule-edit.php
git commit -m "feat(tasks): edit a routine's written procedure"
```

---

### Task 11: The nav link

**Files:**
- Modify: `admin/_layout.php`

- [ ] **Step 1: Add the link**

In `admin/_layout.php`, immediately after the "Job timetables" link block (the
`<?php if ($__isOwner || $__isManager): ?>` block ending before the Gate entry), add:

```php
<?php if ($__navTasks): ?>
<a href="/admin/timetable.php"    class="sidebar__link <?= ($activeMenu??'')==='timetable'    ? 'is-active':'' ?>">
  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="12" y1="14" x2="12" y2="18"/></svg>
  Timetable
</a>
<?php endif; ?>
```

`$__navTasks` is the right audience: anyone who can hold a task can see their own week,
and §6 of the spec locks a staff member's filter to themselves inside the page.

- [ ] **Step 2: Verify it parses**

Run: `php -l admin/_layout.php`
Expected: `No syntax errors detected in admin/_layout.php`

- [ ] **Step 3: Commit**

```bash
git add admin/_layout.php
git commit -m "feat(tasks): Timetable sidebar link"
```

---

### Task 12: Full verification

**Files:** none — this task only runs things.

- [ ] **Step 1: Run the new suite**

Run: `php tests/task_calendar_logic.php`
Expected: `ALL PASS`, exit status 0.

- [ ] **Step 2: Run every neighbouring suite for regressions**

Run:
```bash
for t in recurring_tasks_logic team_logic hr_logic; do echo "=== $t ==="; php tests/$t.php 2>&1 | tail -3; done
```
Expected: `recurring_tasks_logic` and `team_logic` end `ALL PASS`. `hr_logic` has **one
known failure** — "scope: bare column when no alias", an over-broad assertion in a test
that only passes pre-migration. It is unrelated to this work; do not chase it, and do not
"fix" it by weakening `hr_scope_sql()`.

- [ ] **Step 3: Lint every file this plan touched**

Run:
```bash
for f in includes/task-calendar.php admin/timetable.php admin/mywork.php admin/task-action.php admin/task-schedule-edit.php admin/_layout.php; do php -l $f; done
```
Expected: `No syntax errors detected` six times.

- [ ] **Step 4: Prove the pre-migration path still renders**

The grid must survive a database without `add_recurring_tasks.sql`. Simulate the guard
returning false and confirm bucketing still produces a usable grid:

```bash
php -r 'require "includes/task-calendar.php";
$g = task_week_grid([["id"=>1,"due_date"=>"2026-09-23","status"=>"todo","title"=>"No due_time column"]], "2026-09-21", "2026-09-21", "10:00:00");
echo count($g["anytime"]["2026-09-23"]) === 1 ? "pre-migration OK: task landed in Anytime\n" : "BROKEN\n";'
```
Expected: `pre-migration OK: task landed in Anytime`

- [ ] **Step 5: Walk the pages in a browser**

Start the dev server and check each surface by hand:

```bash
php -S localhost:8765
```

- `/admin/timetable.php` — the grid renders, week nav moves, the property picker switches,
  a chip opens the panel, "Mark done" updates the chip after the reload.
- `/admin/mywork.php` — today's tasks are the first block, the button is large enough to
  hit on a phone, a procedure opens, a completed task stays visible and can be reopened.
- `/admin/task-schedules.php` → a routine → the procedure textarea saves and comes back.

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "test(tasks): verify the timetable grid and staff day view end to end"
```

---

## Deployment

`add_task_procedures.sql` must be applied to **production RDS separately** — the local
`.env` points at a local Postgres, not prod. Apply it through `/admin/migrate.php` after
the deploy. Until it runs, `task_procedures_supported()` is false: the grid and the day
view work exactly as they do now, minus the Procedure disclosure.
