-- Marks a room as the whole-property ("Entire place") booking option,
-- so the front-end Rooms & Rates toggle can separate it from per-room bookings.
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS is_entire_place BOOLEAN NOT NULL DEFAULT FALSE;
