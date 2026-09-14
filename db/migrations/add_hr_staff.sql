-- Migration: internal team / HR directory.
-- Run via /admin/migrate.php. Idempotent.
--
-- The workforce (73+ people across 11 property units) is a superset of the
-- login accounts in admin_users — most staff have no login. This directory
-- holds "who works where" (property, position, department, weekly off-day) and
-- is built to connect to the HR/attendance tool later. A row MAY link to a
-- login account (admin_user_id) but usually does not.
CREATE TABLE IF NOT EXISTS hr_staff (
    id            SERIAL PRIMARY KEY,
    full_name     TEXT NOT NULL,
    position      TEXT,                         -- raw job title, e.g. "HOUSE KEEPER"
    department    TEXT,                         -- derived bucket (see hr_department())
    venue_id      INT REFERENCES venues(id) ON DELETE SET NULL,  -- NULL = no matching venue (office, kite school, restaurant, plots)
    unit_label    TEXT,                         -- original roster unit label, kept even when venue_id is NULL
    off_day       TEXT,                         -- weekly off-day: MON..SUN or '' (Sunday-off by convention)
    phone         TEXT,
    email         TEXT,
    status        TEXT NOT NULL DEFAULT 'active',-- active | inactive
    admin_user_id INT REFERENCES admin_users(id) ON DELETE SET NULL, -- optional link to a login account
    sort_order    INT NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Directory is read grouped by property and filtered by department.
CREATE INDEX IF NOT EXISTS idx_hr_staff_venue      ON hr_staff (venue_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_hr_staff_department ON hr_staff (department);
CREATE INDEX IF NOT EXISTS idx_hr_staff_status     ON hr_staff (status);
