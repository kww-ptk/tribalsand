-- Migration: booking channel name + commission on the bookings ledger
-- (after add_bookings_finance). Lets Finance → Reports show the monthly report the
-- reservations team writes from eZee: gross → commission → net, top partners, and
-- the right source (direct / agent / OTA) instead of "OTA" for every import.
--
--   channel            the channel or partner as eZee names it ("Booking.com",
--                      "Walk-in", "Paola Safaris"). '' = unknown.
--   commission_amount  commission paid on this booking, same currency as gross.
--                      Net = gross_amount − commission_amount.
--
-- Additive and idempotent. Existing rows keep commission 0 and an empty channel
-- until the next import of the eZee CRS report refreshes them.

ALTER TABLE bookings ADD COLUMN IF NOT EXISTS channel           VARCHAR(120)  NOT NULL DEFAULT '';
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS commission_amount NUMERIC(12,2) NOT NULL DEFAULT 0;
