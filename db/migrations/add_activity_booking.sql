-- Tribal Sand: per-property activities + structured booking price/capacity, and
-- a party-size snapshot on requests. Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS tour_venues (
    tour_id  INT NOT NULL REFERENCES tours(id)  ON DELETE CASCADE,
    venue_id INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    PRIMARY KEY (tour_id, venue_id)
);

ALTER TABLE tours
  ADD COLUMN IF NOT EXISTS price_amount     NUMERIC(10,2),
  ADD COLUMN IF NOT EXISTS price_per_person BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS max_pax          INT,
  ADD COLUMN IF NOT EXISTS whats_included   TEXT;

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS pax INT;
