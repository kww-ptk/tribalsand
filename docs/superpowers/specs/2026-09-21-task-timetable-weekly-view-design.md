# Job timetables — weekly grid + a staff-first day view (design)

**Date:** 2026-09-21 · **Status:** approved for implementation

## 1. Problem

Recurring job timetables shipped in `7e9874a` as a list: `admin/task-schedules.php` names a
routine, `admin/task-schedule-edit.php` holds its rules, and `bin/spawn-recurring-tasks.php`
turns those rules into real `tasks` rows. Everything works, but nothing *shows the week*. A
manager who wants to know "is Thursday covered?" reads a list of frequency labels and does
the calendar arithmetic in their head.

The staff side is worse. `admin/mywork.php` renders tasks as a five-column table with two
icon-sized buttons per row, each posting a full page reload. That table is below the guest
requests and the turnover worklist, so the thing a gardener opens the app for is the third
block down. It is not a surface anyone would check daily by choice.

## 2. Goal

1. **A weekly grid** at `admin/timetable.php` — days across, clock hours down, one property
   at a time, showing the real tasks for a real week with live status.
2. **A staff day view** — `admin/mywork.php` restructured so today's tasks come first, as
   tall cards with an unmistakable **Mark done** button that does not reload the page.
3. **Written procedures** a staff member can open and read on any task that came from a
   recurrence.

**Non-goals (v1):** drag-to-reschedule on the grid; tickable sub-step checklists (a
procedure is read, not ticked); push notifications; offline support; creating or editing
recurrences from the grid (that stays in the timetable editor); a print stylesheet.

## 3. Decisions taken in brainstorming

| Question | Decision |
|---|---|
| How do ops staff reach their list? | Each has their own login account — the existing access-code staff model |
| Grid shows the pattern or the week? | **Actual tasks for a real week**, navigable, live status |
| Grid layout | **True hour grid** — a school timetable, hours as rows |
| Tasks with no `due_time` | An **"Anytime" row pinned above the hours**, one cell per day |
| Staff landing screen | **Today's list**; the week is one tap away |
| Marking done | An **explicit "Mark done" button** on each card |
| Grid scope | **One property**, with a person / job-type filter |
| "Procedures" | **A written how-to staff can read** — one text field, not a checklist |

The hour grid was chosen over part-of-day bands after seeing both. It costs vertical space
and needs the Anytime row; that is accepted, and §5.2 keeps the range tight.

## 4. Migration — `add_task_procedures.sql`

One column, after `add_recurring_tasks.sql`:

```sql
ALTER TABLE task_recurrences ADD COLUMN IF NOT EXISTS procedure TEXT;
```

Guarded by `task_procedures_supported()` (an `to_regclass`/`information_schema` probe, never
a failing `SELECT`, matching `recurring_tasks_supported()`).

`procedure` is a non-reserved keyword in PostgreSQL and works as an unquoted column name —
verified against Postgres 18 before writing this. No quoting is needed at any call site.

**The procedure lives only on the recurrence and is read live through `tasks.recurrence_id`.**
It is never copied onto the spawned task. Correcting a procedure therefore fixes it
everywhere at once, including tasks already spawned and not yet done. This is deliberately
the opposite of the signed-consent rule: a waiver must never change after signing, but a
procedure must always be the current one. Ad-hoc one-off tasks own no recurrence and simply
have no procedure — they use `detail`, as today.

## 5. The read model — `includes/task-calendar.php`

One scoped query per week, then pure bucketing. Both the grid and the staff day list read
the same functions, so they cannot drift.

### 5.1 Fetch

```php
task_week_fetch(?array $venueIds, int $venueId, string $weekStart, array $filters = []): array
```

`$venueIds` is `admin_venue_ids()` (null = owner/all) and scopes the query; `$venueId` is the
chosen property and must be inside that set or the call returns `[]`. `$filters` accepts
`assigned_to` (an id, or the string `unassigned`) and `job_type`.

**One query for the whole week**, not one per cell. A 7-day × 15-hour grid is 105 cells; a
per-cell query would be 105 round trips for one page view — the same mistake
`mi_villa_states_window()` exists to avoid. It `LEFT JOIN`s `task_recurrences` for the
procedure, selecting that column only when `task_procedures_supported()`.

### 5.2 Bucket (pure)

```php
task_week_grid(array $tasks, string $weekStart, string $todayYmd, string $nowHms): array
```

Returns:

```php
[
  'days'    => ['2026-09-21', …],          // 7 dates, Monday first
  'hours'   => [6, 7, …, 20],              // derived, see below
  'anytime' => ['2026-09-21' => [$task, …]],
  'cells'   => ['2026-09-21' => [7 => [$task, …]]],
  'counts'  => ['total' => n, 'done' => n, 'overdue' => n],
]
```

Pure — no DB, no clock. `$todayYmd` and `$nowHms` are passed in rather than read inside, so
overdue logic is testable at any instant.

**Hour range** defaults to 06:00–20:00 and expands to cover any timed task falling outside
it, clamped to 0–23. A week whose only timed task is at 04:00 renders rows 4–20, not 0–23.

**Untimed tasks** (`due_time IS NULL`) route to `anytime[$day]`, never to an hour cell. A
task is untimed whenever `due_time` is null *or* `recurring_tasks_supported()` is false —
pre-migration the column does not exist, so every task is an Anytime task and the grid still
renders.

### 5.3 Overdue

```php
task_is_overdue(array $t, string $todayYmd, string $nowHms): bool
```

True when the task is neither done nor cancelled, and either its `due_date` is before today,
or it is today with a `due_time` already passed. Kept as its own named function because the
grid, the day list and the counts must all agree on it.

## 6. The weekly grid — `admin/timetable.php`

`require_login()`. Columns Mon–Sun, Anytime row pinned above the hour rows, prev/next week
navigation, all Nairobi-local (`frontdesk_today_ymd()`), today's column emphasised.

A cell holds every task in that hour, stacked as chips. A chip carries the title, the
assignee (or "Unassigned"), and a colour from its `job_type` with a legend above the grid.
Done chips are struck through and muted; overdue chips take a red accent.

**Clicking a chip opens a detail panel on the timetable page itself** — title, detail,
assignee, status, and the procedure — rendered from the week query's own rows. No second
request: §5.1 already fetched everything the panel shows. The panel carries the same
status buttons as the staff card, posting to the same endpoint.

**Scoping.** The property picker is built from `admin_venue_ids()` and a posted `venue_id`
outside that set is ignored, not honoured — the same rule as the booking importer. Owner and
manager get the person and job-type filters. **A staff member's filter is locked to
themselves** and the control is disabled, so the grid is their own week.

Empty week → an empty state naming the property and week, never a blank grid.

## 7. The staff day — `admin/mywork.php`

Today's tasks move to the top and become the dominant block: tall cards in time order,
carrying title, time, property, and a **Mark done** button. Untimed tasks follow under an
"Anytime today" heading. When the task came from a recurrence and a procedure is set, the
card has a **Procedure** disclosure holding it.

A "This week" link goes to `admin/timetable.php` carrying no person parameter — the page
derives the lock from the signed-in account's role (§6), so there is no client-supplied
identity to forge. When a staff member is scoped to more than one property the timetable
opens on the first of their venues, with the property picker still available to them.

The existing assigned-request and turnover blocks keep working, below the tasks.

Completed tasks stay visible, struck through, for the rest of the day — a staff member
should be able to see what they have finished, and re-open a mis-tap.

## 8. Marking done — `admin/task-action.php`

The endpoint already carries the correct permission model (the assignee may move their own
task through todo / in progress / done; owner and in-scope manager may set any status; only
a manager may cancel). **None of that changes.**

It gains a JSON response path: when the request asks for JSON, it returns
`{ok, id, status, label, badge}` instead of redirecting. The button posts `FormData`, not a
JSON body, so `verify_csrf()` — which reads `$_POST` — keeps working unchanged and needs no
CSRF-in-body special case.

The existing PRG redirect stays as the no-JS fallback, and the card is a real `<form>`, so
the page works with JavaScript off.

## 9. Pre-migration safety

Every read degrades to something that renders:

- `tasks_supported()` false → both surfaces hide their task blocks, as today.
- `recurring_tasks_supported()` false → no `due_time` column; every task is an Anytime task
  and the grid renders with the Anytime row only. This guard already doubles as the
  `tasks.due_time` guard (`admin/tasks.php:373` sets that convention) — no second probe.
- `task_procedures_supported()` false → no Procedure disclosure; nothing else differs.

## 10. Testing — `tests/task_calendar_logic.php`

Pure assertions always; DB work inside a rolled-back transaction, matching the house pattern.

- Monday-start week boundaries, including a Sunday input landing in the right week
- Hour-range derivation: the 06–20 default, expansion for an early or late task, the 0–23 clamp
- Untimed routing to the Anytime row, including the pre-migration all-untimed case
- Several tasks in one cell preserved in time order
- `task_is_overdue()` across yesterday / earlier today / later today / done / cancelled
- Counts agree with the cells they were derived from
- Scoping: a `venue_id` outside `admin_venue_ids()` returns no rows
- A staff filter locked to self cannot read another person's tasks

## 11. Decisions made without confirmation

1. **Mark-done posts `FormData`, not JSON**, so `verify_csrf()` is untouched. The alternative
   (a JSON body plus a CSRF-in-body branch, as `api/assistant.php` does) adds a code path for
   no gain here, because this endpoint has no non-form callers.
2. **Hour range 06:00–20:00 by default.** Chosen to cover a property working day without 24
   rows of empty grid; it expands automatically rather than hiding a task.
3. **Completed tasks stay on the staff day view** until the day rolls over, rather than
   disappearing on completion.
