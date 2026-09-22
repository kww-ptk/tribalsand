-- Migration: self-service clock in/out (QR card kiosk).
-- Run via /admin/migrate.php. Idempotent. Depends on add_attendance.sql.
--
-- Three additions. The existing `attendance` table is NOT changed: punches
-- update it, and it stays the daily summary every report already reads.

-- 1. The secret a printed QR card encodes. NEVER hr_staff.id — a card encoding
--    an id is forged by printing a different number. 32 random hex chars,
--    minted on first card print, regenerated to kill a lost card.
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS punch_token TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_hr_staff_punch_token
    ON hr_staff (punch_token) WHERE punch_token IS NOT NULL;

-- 2. A registered kiosk tablet. Only the token HASH is stored, like a password:
--    a leaked row must not yield a working kiosk.
CREATE TABLE IF NOT EXISTS attendance_devices (
    id           SERIAL PRIMARY KEY,
    name         TEXT NOT NULL,
    venue_id     INT REFERENCES venues(id) ON DELETE SET NULL,
    token_hash   TEXT NOT NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    last_seen_at TIMESTAMPTZ,
    created_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 3. The event log — one row per tap. A day has up to four punches but one
--    `attendance` row, and each punch carries its own photo and device. This is
--    also the audit trail: `attendance` is editable by any manager, so without
--    this there is no record of what the person actually did.
CREATE TABLE IF NOT EXISTS attendance_punches (
    id          SERIAL PRIMARY KEY,
    hr_staff_id INT NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    device_id   INT REFERENCES attendance_devices(id) ON DELETE SET NULL,
    venue_id    INT REFERENCES venues(id) ON DELETE SET NULL,
    punched_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    kind        TEXT NOT NULL CHECK (kind IN ('in','out')),
    work_date   DATE NOT NULL,              -- the attendance row this filled
    slot        TEXT NOT NULL CHECK (slot IN ('in1','out1','in2','out2')),
    photo_key   TEXT,                       -- NULL = photo failed; time still stands
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_punches_staff_date ON attendance_punches (hr_staff_id, work_date);
CREATE INDEX IF NOT EXISTS idx_punches_date       ON attendance_punches (work_date);
