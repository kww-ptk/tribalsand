-- Migration: a team member can be assigned to more than one property (Item 5).
-- Run via /admin/migrate.php AFTER add_hr_staff.sql (the FK target). Idempotent.
--
-- hr_staff.venue_id stays the person's HOME property — it drives grouping (the
-- "who works where" cards), the attendance day/month grids (one card per person)
-- and the dashboard (a multi-venue person is counted once, on their home card).
-- This join table adds ADDITIONAL properties that only widen visibility/scope: a
-- manager of any of a person's venues can see and manage them. Every read is
-- guarded by hr_staff_venues_supported() so a pre-migration deploy behaves exactly
-- as today (home venue only).
CREATE TABLE IF NOT EXISTS hr_staff_venues (
    hr_staff_id INTEGER NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    venue_id    INTEGER NOT NULL REFERENCES venues(id)   ON DELETE CASCADE,
    PRIMARY KEY (hr_staff_id, venue_id)
);

-- Scope lookups walk this by venue ("who else can this manager see").
CREATE INDEX IF NOT EXISTS idx_hr_staff_venues_venue ON hr_staff_venues (venue_id);
