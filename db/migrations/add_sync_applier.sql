-- Migration: the inbound half of the Zuri sync — what bin/sync-apply.php writes.
-- Run AFTER add_restaurant_sync.sql and add_restaurant_sync_models.sql.
-- Run via /admin/migrate.php. Idempotent.
--
-- 1. Sold out (Zuri owns it — `item_availability`, sync_uuid = the menu item's).
--    Kept apart from menu_items.is_available, which is OUR "Hidden" toggle and is
--    sent to Zuri as is_active. item_availability has its own version sequence
--    on Zuri's side, so it gets its own counter here — bumping the menu item's
--    sync_version for a Zuri-owned field would make our next menu edit look stale.
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS is_sold_out      BOOLEAN     NOT NULL DEFAULT FALSE;
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS sold_out_at      TIMESTAMPTZ;
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS sold_out_version INTEGER     NOT NULL DEFAULT 0;

-- 2. The ordering race (handover §8): a reservation can arrive before the
--    customer it references. The applier leaves such an event pending with an
--    attempt counter and a back-off, and fails it after 5 tries.
ALTER TABLE sync_inbox ADD COLUMN IF NOT EXISTS attempts      SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE sync_inbox ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMPTZ;

-- 3. Reservation fields from the contract (handover §6) we had no column for.
--    Our existing `notes` holds the guest's own request (contract
--    special_requests); the contract's staff `notes` go to staff_notes.
--    `reference` already exists (UNIQUE) and holds Zuri's ZR-XXXXXX for
--    Zuri-created bookings; external_id is OUR id sent to Zuri's /reserve.
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS external_id         VARCHAR(64);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS duration_minutes    INT;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS preference          VARCHAR(20);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS staff_notes         TEXT;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancellation_reason TEXT;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS confirmed_at        TIMESTAMPTZ;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS seated_at           TIMESTAMPTZ;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancelled_at        TIMESTAMPTZ;
