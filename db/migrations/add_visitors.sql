-- Migration: gate visitor register (walk-up visitors, in/out).
-- Run via /admin/migrate.php. Idempotent.
--
-- Phase 4 of the Staff / Team Roles extension. The gate signs a visitor in
-- (time_in defaults to now) and later signs them out (stamps time_out).
CREATE TABLE IF NOT EXISTS visitors (
    id           SERIAL PRIMARY KEY,
    venue_id     INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    visitor_name TEXT NOT NULL,
    visiting     TEXT,
    hold_id      INT REFERENCES holds(id) ON DELETE SET NULL,
    purpose      TEXT,
    vehicle      TEXT,
    time_in      TIMESTAMPTZ NOT NULL DEFAULT now(),
    time_out     TIMESTAMPTZ,
    logged_by    INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Fast lookup of who's still on site (time_out IS NULL) per venue.
CREATE INDEX IF NOT EXISTS idx_visitors_venue_open ON visitors (venue_id, time_out);
