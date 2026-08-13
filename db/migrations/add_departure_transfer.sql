-- Tribal Sand: the check-out transfer. Run via /admin/migrate.php. Idempotent.
--
-- departure_time is when the CAR LEAVES THE PROPERTY, not when a flight departs.
-- The guest is asked for a pickup time and a destination; working back from a
-- flight is the team's job.
--
-- A TIME, not a timestamp, for the same reason as property_arrival_time: the date
-- is already known (the check-out day) and a bare time is what the driver reads off.
--
-- needs_departure_transfer is a BOOLEAN. Bind 'TRUE'/'FALSE'/null, never a PHP
-- bool -- PDO::ATTR_EMULATE_PREPARES renders false as '' and Postgres rejects it.
ALTER TABLE booking_checkin
  ADD COLUMN IF NOT EXISTS needs_departure_transfer BOOLEAN,
  ADD COLUMN IF NOT EXISTS departure_destination    TEXT,
  ADD COLUMN IF NOT EXISTS departure_time           TIME;
