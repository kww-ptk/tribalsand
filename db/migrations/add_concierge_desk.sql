-- Migration: laundry concierge kind + optional scheduled_for on booking_addons
-- Run via /admin/migrate.php. Idempotent — safe to re-run.

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other',
                    'housekeeping','amenities','maintenance','restaurant','laundry'));

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS scheduled_for TIMESTAMP;
