-- Migration: let a hold carry an authoritative quoted price.
-- Run via /admin/migrate.php. Idempotent.
--
-- Maya Ilai's guest price comes from maya_ilai_quote() (group discount,
-- availability band, extra-guest supplements, eco fee) — NOT from the rooms/rates
-- card that bookings_sync_hold() prices with. So a multi-room Maya Ilai booking
-- freezes each hold's share of the configurator total here, and the ledger reads
-- it at confirm time instead of re-deriving a different number. Every other hold
-- leaves this NULL and prices exactly as before (room_stay_quote).
ALTER TABLE holds ADD COLUMN IF NOT EXISTS quoted_amount   NUMERIC(10,2);
ALTER TABLE holds ADD COLUMN IF NOT EXISTS quoted_currency TEXT;
