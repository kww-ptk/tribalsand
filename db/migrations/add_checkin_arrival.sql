-- Tribal Sand: arrival modes for pre-arrival check-in (flight / road / other).
-- Run via /admin/migrate.php. Idempotent — safe to re-run.
--
-- arrival_mode    'flight' | 'road' | 'other'; NULL = a row saved before this
--                 migration, which the app treats with the legacy flight rule.
-- arrival_vehicle road mode only: vehicle description / number plate.
-- arrival_note    other mode only: how the guest is arriving.
-- The "Other" airport free-text reuses the existing arrival_airport column.
ALTER TABLE booking_checkin
    ADD COLUMN IF NOT EXISTS arrival_mode    TEXT,
    ADD COLUMN IF NOT EXISTS arrival_vehicle TEXT,
    ADD COLUMN IF NOT EXISTS arrival_note    TEXT;
