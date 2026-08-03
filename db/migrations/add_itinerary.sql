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
    created_by TEXT NOT NULL DEFAULT 'admin' CHECK (created_by IN ('admin','guest')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- created_by added post-initial-draft; ALTER covers installs that already have the table.
ALTER TABLE itinerary_items ADD COLUMN IF NOT EXISTS created_by TEXT NOT NULL DEFAULT 'admin';
ALTER TABLE itinerary_items DROP CONSTRAINT IF EXISTS itinerary_items_created_by_check;
ALTER TABLE itinerary_items ADD CONSTRAINT itinerary_items_created_by_check CHECK (created_by IN ('admin','guest'));
CREATE INDEX IF NOT EXISTS idx_itin_hold_day ON itinerary_items (hold_id, day, at_time);
