-- Migration: restaurant opening hours as ONE record per venue — the shape Zuri's
-- sync contract expects (handover §6: opening_hours = one record, fixed uuid).
-- Run AFTER add_restaurant_sync_models.sql (it sorts before it alphabetically,
-- but depends only on `venues`, so either order works). Run via
-- /admin/migrate.php. Idempotent.
--
-- Supersedes the per-day `opening_hours` table from add_restaurant_sync_models.
-- That table is left in place (unused) — nothing reads it and nothing is dropped,
-- so no data is lost. Drop it separately once confirmed empty.
--
-- lunch / dinner are free text shown on Zuri's site ("12:00 – 15:00").
-- first_slot / last_slot / slot_minutes bound the bookable times;
-- duration_minutes is how long a table is held per booking.
-- The row's sync_uuid is minted once here and never changes — it is the fixed
-- uuid Zuri keys the record on (send it to Bhumika after the migration runs).

CREATE TABLE IF NOT EXISTS restaurant_hours (
    id               SERIAL PRIMARY KEY,
    venue_id         INT NOT NULL UNIQUE REFERENCES venues(id) ON DELETE CASCADE,
    lunch            TEXT,
    dinner           TEXT,
    first_slot       TIME    NOT NULL DEFAULT '12:00',
    last_slot        TIME    NOT NULL DEFAULT '22:00',
    slot_minutes     INT     NOT NULL DEFAULT 30 CHECK (slot_minutes >= 15),
    duration_minutes INT     NOT NULL DEFAULT 90 CHECK (duration_minutes >= 15),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_uuid        UUID        NOT NULL DEFAULT gen_random_uuid(),
    sync_version     INTEGER     NOT NULL DEFAULT 1,
    sync_updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_source      VARCHAR(20) NOT NULL DEFAULT 'tribalsand',
    sync_last_at     TIMESTAMPTZ,
    is_deleted       BOOLEAN     NOT NULL DEFAULT FALSE,
    CONSTRAINT restaurant_hours_slot_order CHECK (last_slot >= first_slot)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_restaurant_hours_sync_uuid ON restaurant_hours (sync_uuid);

-- Seed Zuri from today's reservation rules (reservation_slots(): 12:00–22:00,
-- 30-min steps). lunch/dinner wording is left blank for staff to fill in.
INSERT INTO restaurant_hours (venue_id, first_slot, last_slot, slot_minutes, duration_minutes)
SELECT id, '12:00', '22:00', 30, 90 FROM venues WHERE slug = 'zuri'
ON CONFLICT (venue_id) DO NOTHING;
