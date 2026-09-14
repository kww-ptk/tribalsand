-- Migration: staff attendance records (the HR/attendance module).
-- Run via /admin/migrate.php. Idempotent. Depends on add_hr_staff.
--
-- One row per staff member per day. Times are stored as minutes from midnight
-- of work_date; an out-time that crosses midnight is >= 1440 (e.g. a 19:00→07:00
-- security night shift stores out1 = 1860). Hours are derived from the pairs, so
-- the crossing-midnight case computes correctly without a separate flag.
--
-- status is one of: P (present — implied by worked hours), OFF, REC, PH, SK,
-- ABS, LV (leave), REC_L (recovery + leave). A row with worked hours reads as P.
CREATE TABLE IF NOT EXISTS attendance (
    id          SERIAL PRIMARY KEY,
    hr_staff_id INT NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    work_date   DATE NOT NULL,
    status      TEXT,                 -- NULL/'' + hours ⇒ P; else a status code
    in1         SMALLINT,             -- minutes from midnight (nullable)
    out1        SMALLINT,             -- >= 1440 when it crosses midnight
    in2         SMALLINT,
    out2        SMALLINT,
    note        TEXT,
    logged_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (hr_staff_id, work_date)
);
-- Daily view (all staff on one date) and per-person month reads.
CREATE INDEX IF NOT EXISTS idx_attendance_date  ON attendance (work_date);
CREATE INDEX IF NOT EXISTS idx_attendance_staff ON attendance (hr_staff_id, work_date);
