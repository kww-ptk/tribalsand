-- Shared booking portal (C-1): per-booking share toggle + request attribution.
-- Idempotent. requested_by references the checkin_guests roster (A/B).
ALTER TABLE holds          ADD COLUMN IF NOT EXISTS share_reservation BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS requested_by INT REFERENCES checkin_guests(id) ON DELETE SET NULL;
