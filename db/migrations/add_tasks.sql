-- Migration: internal work tasks (manager-created, staff-assigned).
-- Run via /admin/migrate.php. Idempotent.
--
-- Phase 3 of the Staff / Team Roles extension. Structurally a blend of
-- itinerary_items (per-hold rows) and the booking_addons status machine — but
-- these are internal ops items, always venue-scoped, optionally linked to a hold.
CREATE TABLE IF NOT EXISTS tasks (
    id          SERIAL PRIMARY KEY,
    venue_id    INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    hold_id     INT REFERENCES holds(id) ON DELETE SET NULL,
    assigned_to INT REFERENCES admin_users(id) ON DELETE SET NULL,
    job_type    TEXT,
    title       TEXT NOT NULL,
    detail      TEXT,
    status      TEXT NOT NULL DEFAULT 'todo'
                CHECK (status IN ('todo','in_progress','done','cancelled')),
    due_date    DATE,
    created_by  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_tasks_venue    ON tasks (venue_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_assignee ON tasks (assigned_to, status);
