-- Migration: recurring job timetables + recurring tasks.
-- Run via /admin/migrate.php. Idempotent. Depends on add_tasks.sql.
--
-- Two levels, both optional:
--   task_schedules   — a named "job timetable" for a role/person at a property
--                      (e.g. "Gardener routine"). Groups several recurring lines.
--   task_recurrences — one recurring rule. schedule_id links it to a timetable;
--                      NULL = a standalone recurring task (created straight from
--                      the Tasks board). Each rule spawns real `tasks` rows on a
--                      cadence; next_run_date is the next date to spawn one.
--
-- The spawner (bin/spawn-recurring-tasks.php / inline on the Tasks page) reads
-- these and INSERTs into tasks, tagging tasks.recurrence_id so the same
-- occurrence is never created twice.

CREATE TABLE IF NOT EXISTS task_schedules (
    id          SERIAL PRIMARY KEY,
    venue_id    INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    name        TEXT NOT NULL,
    job_type    TEXT,                                  -- role this timetable is for (gardening, housekeeping, …)
    assigned_to INT REFERENCES admin_users(id) ON DELETE SET NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_by  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_task_schedules_venue ON task_schedules (venue_id, is_active);

CREATE TABLE IF NOT EXISTS task_recurrences (
    id            SERIAL PRIMARY KEY,
    schedule_id   INT REFERENCES task_schedules(id) ON DELETE CASCADE,
    venue_id      INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    assigned_to   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    job_type      TEXT,
    title         TEXT NOT NULL,
    detail        TEXT,
    frequency     TEXT NOT NULL DEFAULT 'weekly'
                  CHECK (frequency IN ('daily','weekly','biweekly','monthly','quarterly','custom')),
    interval_days INT,                                 -- used when frequency = 'custom' (also a fallback)
    time_of_day   TIME,                                -- optional "set the time"
    next_run_date DATE NOT NULL DEFAULT CURRENT_DATE,  -- next date a task is spawned
    last_spawned_date DATE,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_by    INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_task_recurrences_due   ON task_recurrences (is_active, next_run_date);
CREATE INDEX IF NOT EXISTS idx_task_recurrences_sched ON task_recurrences (schedule_id);
CREATE INDEX IF NOT EXISTS idx_task_recurrences_venue ON task_recurrences (venue_id);

-- Link a spawned task back to its rule (+ optional clock time). ON DELETE SET
-- NULL so deleting a rule leaves its already-spawned tasks intact.
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS recurrence_id INT REFERENCES task_recurrences(id) ON DELETE SET NULL;
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS due_time TIME;

-- One task per (rule, due date) — the spawner's idempotency guard.
CREATE UNIQUE INDEX IF NOT EXISTS uq_tasks_recurrence_due
    ON tasks (recurrence_id, due_date) WHERE recurrence_id IS NOT NULL;
