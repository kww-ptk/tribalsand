-- ============================================================
-- Tribal Sand — Run this once in the Neon SQL console
-- Paste the entire file and click Run
-- All statements are idempotent (safe to re-run)
-- ============================================================

-- ── 1. Availability tables ────────────────────────────────────

CREATE TABLE IF NOT EXISTS units (
    id         SERIAL PRIMARY KEY,
    room_id    INT          NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    name       VARCHAR(100) NOT NULL DEFAULT 'Unit A',
    sort_order INT          NOT NULL DEFAULT 0,
    is_active  BOOLEAN      NOT NULL DEFAULT TRUE,
    feed_token VARCHAR(40)  NOT NULL DEFAULT md5(random()::text || clock_timestamp()::text),
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_units_room_id ON units(room_id);

CREATE TABLE IF NOT EXISTS holds (
    id             SERIAL PRIMARY KEY,
    submission_id  INT          REFERENCES submissions(id) ON DELETE SET NULL,
    unit_id        INT          NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    check_in       DATE         NOT NULL,
    check_out      DATE         NOT NULL,
    guest_name     VARCHAR(255) NOT NULL DEFAULT '',
    guest_email    VARCHAR(255) NOT NULL DEFAULT '',
    status         VARCHAR(20)  NOT NULL DEFAULT 'pending'
                                CHECK (status IN ('pending','confirmed','expired','cancelled')),
    expires_at     TIMESTAMP    NOT NULL,
    confirmed_at   TIMESTAMP,
    cancelled_at   TIMESTAMP,
    admin_notes    TEXT,
    created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_holds_unit_status ON holds(unit_id, status);
CREATE INDEX IF NOT EXISTS idx_holds_status_exp  ON holds(status, expires_at);

CREATE TABLE IF NOT EXISTS availability_blocks (
    id         SERIAL PRIMARY KEY,
    unit_id    INT          NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    date_from  DATE         NOT NULL,
    date_to    DATE         NOT NULL,
    block_type VARCHAR(20)  NOT NULL DEFAULT 'booked'
                            CHECK (block_type IN ('hold','booked','blocked')),
    hold_id    INT          REFERENCES holds(id) ON DELETE SET NULL,
    notes      TEXT,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_avail_unit_dates ON availability_blocks(unit_id, date_from, date_to);

CREATE TABLE IF NOT EXISTS rates (
    id           SERIAL PRIMARY KEY,
    room_id      INT           NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    date_from    DATE          NOT NULL,
    date_to      DATE          NOT NULL,
    price_amount NUMERIC(10,2) NOT NULL,
    label        VARCHAR(100),
    created_at   TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_rates_room_dates ON rates(room_id, date_from, date_to);

CREATE TABLE IF NOT EXISTS ical_feeds (
    id             SERIAL PRIMARY KEY,
    unit_id        INT       NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    label          VARCHAR(100),
    feed_url       TEXT      NOT NULL,
    last_synced_at TIMESTAMP,
    created_at     TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ── 2. form_mode column on rooms ─────────────────────────────

ALTER TABLE rooms
    ADD COLUMN IF NOT EXISTS form_mode VARCHAR(20);

ALTER TABLE rooms
    DROP CONSTRAINT IF EXISTS rooms_form_mode_check;

ALTER TABLE rooms
    ADD CONSTRAINT rooms_form_mode_check
    CHECK (form_mode IS NULL OR form_mode IN ('enquiry', 'availability'));

-- ── 3. venues table (required by seed) ───────────────────────

CREATE TABLE IF NOT EXISTS venues (
    id         SERIAL PRIMARY KEY,
    slug       VARCHAR(100) NOT NULL UNIQUE,
    name       VARCHAR(255) NOT NULL,
    location   VARCHAR(255),
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

ALTER TABLE rooms
    ADD COLUMN IF NOT EXISTS venue_id INT REFERENCES venues(id) ON DELETE SET NULL;

-- ── 4. Seed venues ────────────────────────────────────────────

INSERT INTO venues (slug, name, location, sort_order) VALUES
  ('my-amani',     'My Amani',     'Vipingo',   1),
  ('maya-kobe',    'Maya Kobe',    'Kilifi',    2),
  ('zuri',         'Zuri',         'Watamu',    3),
  ('enkare-bofa',  'Enkare Bofa',  'Kilifi',    4),
  ('sandbox',      'Sandbox',      'Vipingo',   5),
  ('maya_ilai',    'Maya Ilai',    'Kilifi',    6),
  ('tribal-dunes', 'Tribal Dunes', 'Community', 7)
ON CONFLICT (slug) DO NOTHING;

-- ── 5. Seed rooms (the 3 live booking pages + full set) ───────

-- Maya Kobe rooms
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT r.slug, r.name, v.id, 0, 'USD', 'availability', r.so
FROM (VALUES
  ('maya-kobe-main-house', 'Maya Kobe — Main House', 1),
  ('maya-kobe-cottages',   'Maya Kobe — Cottages',   2)
) AS r(slug, name, so)
JOIN venues v ON v.slug = 'maya-kobe'
ON CONFLICT (slug) DO UPDATE SET
    venue_id  = EXCLUDED.venue_id,
    form_mode = 'availability';

-- My Amani full rental
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT 'my-amani-full-rental', 'My Amani — Full Villa Rental', v.id, 0, 'USD', 'availability', 11
FROM venues v WHERE v.slug = 'my-amani'
ON CONFLICT (slug) DO UPDATE SET
    venue_id  = EXCLUDED.venue_id,
    form_mode = 'availability';

-- Other venues (whole-villa, enquiry mode by default until ready)
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT v.slug, v.name || ' — Whole Villa', v.id, 0, 'USD', 'enquiry', 0
FROM venues v
WHERE v.slug IN ('zuri', 'enkare-bofa', 'sandbox', 'maya_ilai', 'tribal-dunes')
ON CONFLICT (slug) DO NOTHING;

-- ── 6. Seed one unit per room ─────────────────────────────────

INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms r
WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id);

-- Maya Kobe Cottages gets a second unit (Cottage B)
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Unit B', 1 FROM rooms r
WHERE r.slug = 'maya-kobe-cottages'
  AND (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id) < 2;

-- ── 7. Global settings ────────────────────────────────────────

INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_email', 'reservations@tribalsand.com'),
  ('form_mode',    'enquiry')
ON CONFLICT (setting_key) DO NOTHING;

-- ── Guest booking management ──

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
