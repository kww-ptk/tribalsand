-- Migration: guest booking management (access code, add-ons, change requests)
-- Run: php bin/migrate.php db/migrations/add_booking_management.sql
-- Idempotent — safe to re-run.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 1. Guest access code on holds
ALTER TABLE holds ADD COLUMN IF NOT EXISTS access_code VARCHAR(12);

CREATE UNIQUE INDEX IF NOT EXISTS idx_holds_access_code
    ON holds(access_code) WHERE access_code IS NOT NULL;

-- Backfill existing holds that have no code yet (random 6-char, unambiguous alphabet).
UPDATE holds
SET access_code = upper(substr(translate(encode(gen_random_bytes(6), 'hex'),
                                         'abcdef0189', 'GHJKMN2345'), 1, 6))
WHERE access_code IS NULL;

-- 2. Add-on requests (tours / transfers / itinerary)
CREATE TABLE IF NOT EXISTS booking_addons (
    id         SERIAL PRIMARY KEY,
    hold_id    INT          NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    kind       VARCHAR(20)  NOT NULL CHECK (kind IN ('tour','transfer','itinerary','other')),
    tour_id    INT          REFERENCES tours(id) ON DELETE SET NULL,
    details    TEXT         NOT NULL DEFAULT '',
    status     VARCHAR(20)  NOT NULL DEFAULT 'requested'
                            CHECK (status IN ('requested','confirmed','declined','cancelled')),
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_booking_addons_hold ON booking_addons(hold_id);

-- 3. Change requests (dates / guests)
CREATE TABLE IF NOT EXISTS booking_change_requests (
    id                  SERIAL PRIMARY KEY,
    hold_id             INT          NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    requested_check_in  DATE,
    requested_check_out DATE,
    requested_guests    INT,
    note                TEXT         NOT NULL DEFAULT '',
    status              VARCHAR(20)  NOT NULL DEFAULT 'requested'
                                     CHECK (status IN ('requested','handled','declined')),
    created_at          TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_booking_changes_hold ON booking_change_requests(hold_id);
