-- Tribal Sand — unified bookings ledger (financial reporting foundation)
-- Run: psql "$DATABASE_URL" -f db/migrations/add_bookings_finance.sql
-- Idempotent. Depends on: add_availability.sql (holds, units, availability_blocks).
--
-- One row per booking across every source (website / OTA / agent / direct), so
-- reports have a single place to read revenue from. Website bookings snapshot
-- their gross at confirm time (a defensible historical figure that does not shift
-- when nightly rates are later edited); imported bookings carry the amount staff
-- entered in the import preview. All app reads are guarded by bookings_supported()
-- so nothing breaks on a database that has not run this migration yet.

CREATE TABLE IF NOT EXISTS bookings (
    id           SERIAL PRIMARY KEY,
    venue_id     INT           REFERENCES venues(id)              ON DELETE SET NULL,
    room_id      INT           REFERENCES rooms(id)               ON DELETE SET NULL,
    unit_id      INT           REFERENCES units(id)               ON DELETE SET NULL,
    source       VARCHAR(20)   NOT NULL DEFAULT 'website'
                               CHECK (source IN ('website','ota','agent','direct')),
    guest_name   VARCHAR(255)  NOT NULL DEFAULT '',
    guest_email  VARCHAR(255)  NOT NULL DEFAULT '',
    agent        VARCHAR(255)  NOT NULL DEFAULT '',
    check_in     DATE          NOT NULL,
    check_out    DATE          NOT NULL,   -- exclusive: guest checks out this day
    nights       INT           NOT NULL DEFAULT 0,
    gross_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    currency     VARCHAR(3)    NOT NULL DEFAULT 'USD',
    amount_paid  NUMERIC(12,2) NOT NULL DEFAULT 0,
    status       VARCHAR(20)   NOT NULL DEFAULT 'confirmed'
                               CHECK (status IN ('pending','confirmed','cancelled')),
    hold_id      INT           REFERENCES holds(id)               ON DELETE SET NULL,
    block_id     INT           REFERENCES availability_blocks(id) ON DELETE SET NULL,
    external_ref VARCHAR(255)  NOT NULL DEFAULT '',
    created_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
    imported_at  TIMESTAMP
);

-- Idempotent upsert keys: one bookings row per source record.
--  · a website hold maps to exactly one bookings row (re-confirm refreshes it)
--  · an imported calendar block maps to exactly one bookings row (re-import skips)
CREATE UNIQUE INDEX IF NOT EXISTS uq_bookings_hold_id
    ON bookings(hold_id)  WHERE hold_id  IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_bookings_block_id
    ON bookings(block_id) WHERE block_id IS NOT NULL;

-- Reporting access paths.
CREATE INDEX IF NOT EXISTS idx_bookings_venue_checkin ON bookings(venue_id, check_in);
CREATE INDEX IF NOT EXISTS idx_bookings_source        ON bookings(source);
CREATE INDEX IF NOT EXISTS idx_bookings_status        ON bookings(status);
