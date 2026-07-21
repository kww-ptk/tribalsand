-- Migration: concierge request types + completed status on booking_addons
-- Run: php bin/migrate.php db/migrations/add_concierge.sql
-- Idempotent — safe to re-run.

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other',
                    'housekeeping','amenities','maintenance','restaurant'));

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_status_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_status_check
    CHECK (status IN ('requested','confirmed','declined','cancelled','completed'));
