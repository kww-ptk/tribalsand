-- Shared booking portal (C-2): attribute manual bill items + message senders to a guest.
-- Idempotent. Both reference the checkin_guests roster.
ALTER TABLE bill_items       ADD COLUMN IF NOT EXISTS guest_id        INT REFERENCES checkin_guests(id) ON DELETE SET NULL;
ALTER TABLE booking_messages ADD COLUMN IF NOT EXISTS sender_guest_id INT REFERENCES checkin_guests(id) ON DELETE SET NULL;
