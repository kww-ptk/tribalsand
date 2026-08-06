-- Migration: guest Pre-Check-in. Run via /admin/migrate.php. Idempotent.
-- Adds a per-booking require flag + completion stamp, one booking-level
-- check-in row, and a per-guest identity table (lead-only UI in v1).

ALTER TABLE holds ADD COLUMN IF NOT EXISTS require_checkin      BOOLEAN     NOT NULL DEFAULT FALSE;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS checkin_completed_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS booking_checkin (
    hold_id            INT PRIMARY KEY REFERENCES holds(id) ON DELETE CASCADE,
    arrival_airport    TEXT,
    flight_number      TEXT,
    arrival_at         TIMESTAMPTZ,
    needs_transfer     BOOLEAN,
    transfer_details   TEXT,
    dietary            TEXT,
    special_requests   TEXT,
    waiver_signed_name TEXT,
    waiver_signed_at   TIMESTAMPTZ,
    waiver_signed_ip   TEXT,
    waiver_version     TEXT,
    steps_saved        JSONB       NOT NULL DEFAULT '{}'::jsonb,
    submitted_at       TIMESTAMPTZ,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS checkin_guests (
    id                SERIAL PRIMARY KEY,
    hold_id           INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    is_lead           BOOLEAN NOT NULL DEFAULT TRUE,
    passport_name     TEXT,
    passport_number   TEXT,
    nationality       TEXT,
    passport_expiry   DATE,
    passport_file_key TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_checkin_guests_hold ON checkin_guests (hold_id);
