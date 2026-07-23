-- Migration: guest ↔ staff messages (per-request + general threads)
-- Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS booking_messages (
    id            SERIAL PRIMARY KEY,
    hold_id       INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    addon_id      INT REFERENCES booking_addons(id) ON DELETE CASCADE,  -- NULL = general thread
    sender        TEXT NOT NULL CHECK (sender IN ('guest','admin')),
    body          TEXT NOT NULL,
    read_by_guest BOOLEAN NOT NULL DEFAULT FALSE,
    read_by_admin BOOLEAN NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_bmsg_thread ON booking_messages (hold_id, addon_id, created_at);
