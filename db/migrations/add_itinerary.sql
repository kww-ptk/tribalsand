-- Migration: per-booking daily itinerary items (admin-authored)
-- Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS itinerary_items (
    id         SERIAL PRIMARY KEY,
    hold_id    INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    day        DATE NOT NULL,
    at_time    TIME,
    category   TEXT NOT NULL DEFAULT 'note'
               CHECK (category IN ('flight','transfer','tour','dining','activity','checkin','checkout','note')),
    title      TEXT NOT NULL,
    detail     TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_itin_hold_day ON itinerary_items (hold_id, day, at_time);
