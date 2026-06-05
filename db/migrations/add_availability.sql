-- Phase 3a: Availability & booking foundation
-- Run: psql $DATABASE_URL -f db/migrations/add_availability.sql

-- 1. Units — individual bookable room instances (1-N per room type)
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

-- Seed one default unit per existing room
INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms
ON CONFLICT DO NOTHING;

-- 2. Holds — 24-hour soft holds on a unit (created before availability_blocks for FK)
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

-- 3. Availability blocks — date ranges blocked per unit
--    block_type: hold (24h soft), booked (confirmed), blocked (manual/OTA)
CREATE TABLE IF NOT EXISTS availability_blocks (
    id         SERIAL PRIMARY KEY,
    unit_id    INT          NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    date_from  DATE         NOT NULL,
    date_to    DATE         NOT NULL,   -- exclusive: guest checks out this day
    block_type VARCHAR(20)  NOT NULL DEFAULT 'booked'
                            CHECK (block_type IN ('hold','booked','blocked')),
    hold_id    INT          REFERENCES holds(id) ON DELETE SET NULL,
    notes      TEXT,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_avail_unit_dates ON availability_blocks(unit_id, date_from, date_to);

-- 4. Rates — price overrides by date range per room (bulk pricing, Phase 3c)
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

-- 5. iCal feeds — OTA feed URLs per unit (Phase 3b/3c)
CREATE TABLE IF NOT EXISTS ical_feeds (
    id             SERIAL PRIMARY KEY,
    unit_id        INT       NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    label          VARCHAR(100),
    feed_url       TEXT      NOT NULL,
    last_synced_at TIMESTAMP,
    created_at     TIMESTAMP NOT NULL DEFAULT NOW()
);
