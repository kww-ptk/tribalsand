-- Tribal Sand: booking-flow add-ons ("upsells").
-- Run via /admin/migrate.php. Idempotent. Order: after add_activity_booking.sql.
--
-- No new tables: an upsell IS a published activity (tours). Property assignment
-- already comes from tour_venues, pricing from tours.price_amount /
-- price_per_person, and the link to a booking from booking_addons (kind='tour',
-- which the existing CHECK already allows). This migration only adds the two
-- switches that decide WHERE an activity is offered.

-- Per-property master switch. Off by default: turning the feature on for a
-- property is a deliberate act, so applying this migration changes nothing
-- visible until an owner ticks a box.
ALTER TABLE venues ADD COLUMN IF NOT EXISTS upsell_enabled BOOLEAN NOT NULL DEFAULT FALSE;

-- Per-activity placement. 'none' by default for the same reason — no existing
-- activity silently starts appearing inside the enquiry form.
ALTER TABLE tours ADD COLUMN IF NOT EXISTS upsell_placement VARCHAR(10) NOT NULL DEFAULT 'none';
ALTER TABLE tours DROP CONSTRAINT IF EXISTS tours_upsell_placement_check;
ALTER TABLE tours ADD CONSTRAINT tours_upsell_placement_check
    CHECK (upsell_placement IN ('none','enquiry','checkin','both'));

CREATE INDEX IF NOT EXISTS idx_tours_upsell_placement ON tours (upsell_placement)
    WHERE upsell_placement <> 'none';
