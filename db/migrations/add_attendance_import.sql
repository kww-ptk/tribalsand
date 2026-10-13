-- Attendance import (Oct 2026): HR's Excel attendance workbook (Jan–Oct 2026)
-- goes into the system month by month, and from now on every attendance day
-- records WHERE it came from.
--
--  • attendance.source: 'kiosk' (clock card), 'manual' (typed in the daily
--    editor), 'import' (from HR's workbook), 'request' (an approved staff
--    request). NULL = a row written before this migration (treated as manual).
--    The import never overwrites a kiosk day, and a hand-typed day only when HR
--    asks it to.
--  • attendance_imports: one row per uploaded workbook — the parsed months are
--    kept here (not in the session) so Preview and Import work on any server,
--    and HR can see what was imported when.
--  • attendance_import_names: "this name in the workbook is this person",
--    remembered so next month's sheet matches by itself.
--
-- Run after add_attendance.sql and add_hr_staff_establishment.sql. Idempotent.

CREATE TABLE IF NOT EXISTS attendance_imports (
    id           SERIAL PRIMARY KEY,
    filename     TEXT        NOT NULL,
    is_1904      BOOLEAN     NOT NULL DEFAULT FALSE,
    months       JSONB       NOT NULL DEFAULT '{}'::jsonb,   -- "2026-10" => parsed month
    applied      JSONB       NOT NULL DEFAULT '{}'::jsonb,   -- "2026-10" => {at, by, written, kept}
    uploaded_by  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    uploaded_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE attendance ADD COLUMN IF NOT EXISTS source    TEXT;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS import_id INT REFERENCES attendance_imports(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS attendance_import_names (
    name_key    TEXT PRIMARY KEY,                               -- the name's words, sorted (order-free)
    hr_staff_id INT  NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
