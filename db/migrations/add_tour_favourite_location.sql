-- Activities: "Guest Favourite" flag + a coarse location filter for the
-- public activities page. Idempotent — safe to re-run.
--
-- `location` is an explicit field (not derived from tour_venues) because the
-- activities page offers a fixed option set incl. "All Locations", matching the
-- homepage property filter values. tour_venues is unaffected (it drives upsell
-- placement, not this filter).

ALTER TABLE tours ADD COLUMN IF NOT EXISTS is_guest_favourite BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE tours ADD COLUMN IF NOT EXISTS location VARCHAR(20) NOT NULL DEFAULT 'all';

ALTER TABLE tours DROP CONSTRAINT IF EXISTS tours_location_check;
ALTER TABLE tours ADD CONSTRAINT tours_location_check
  CHECK (location IN ('all','watamu','kilifi','vipingo'));

CREATE INDEX IF NOT EXISTS idx_tours_guest_fav ON tours(is_guest_favourite) WHERE is_guest_favourite;
