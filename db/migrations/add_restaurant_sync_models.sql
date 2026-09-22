-- Migration: the restaurant-sync entities that didn't exist yet, plus the
-- reservation state-machine extension. Sorts AFTER add_restaurant_sync.sql.
-- Run via /admin/migrate.php. Idempotent.
--
-- Adds three synced models the spec's ownership table needs:
--   • restaurant_tables — Tribalsand owns (TS → Zuri): the floor plan
--   • opening_hours     — Tribalsand owns (TS → Zuri)
--   • customers         — Zuri owns (Zuri → TS): we receive and store them
-- Each carries the same six sync_* columns as the tables in add_restaurant_sync.
--
-- Also links reservations to a table + customer (nullable, filled by sync) and
-- extends the status CHECK from pending/confirmed/cancelled to the full §6 state
-- machine (adds seated, completed, no_show). The terminal-state guard itself is
-- enforced in code (sync_reservation_transition_allowed + set_reservation_status),
-- not by this constraint — the constraint only bounds the allowed VALUES.

-- ── restaurant_tables (TS owns → Zuri) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS restaurant_tables (
    id          SERIAL PRIMARY KEY,
    venue_id    INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    label       VARCHAR(40)  NOT NULL,               -- "T1", "Terrace 3"
    seats       INT          NOT NULL DEFAULT 2 CHECK (seats > 0),
    section     VARCHAR(80),                          -- floor area / zone
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid(),
    sync_version    INTEGER     NOT NULL DEFAULT 1,
    sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_source     VARCHAR(20) NOT NULL DEFAULT 'tribalsand',
    sync_last_at    TIMESTAMPTZ,
    is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE
);
CREATE INDEX        IF NOT EXISTS ix_restaurant_tables_venue     ON restaurant_tables (venue_id, sort_order);
CREATE UNIQUE INDEX IF NOT EXISTS ux_restaurant_tables_sync_uuid ON restaurant_tables (sync_uuid);

-- ── opening_hours (TS owns → Zuri) ───────────────────────────────────────────
-- Multiple rows per (venue, day) allowed (split lunch/dinner service). A row with
-- is_closed = TRUE marks the whole day closed and ignores the times.
CREATE TABLE IF NOT EXISTS opening_hours (
    id           SERIAL PRIMARY KEY,
    venue_id     INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    day_of_week  SMALLINT NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),  -- 0 = Sunday
    open_time    TIME,
    close_time   TIME,
    is_closed    BOOLEAN  NOT NULL DEFAULT FALSE,
    sort_order   INT      NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid(),
    sync_version    INTEGER     NOT NULL DEFAULT 1,
    sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_source     VARCHAR(20) NOT NULL DEFAULT 'tribalsand',
    sync_last_at    TIMESTAMPTZ,
    is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE
);
CREATE INDEX        IF NOT EXISTS ix_opening_hours_venue     ON opening_hours (venue_id, day_of_week, sort_order);
CREATE UNIQUE INDEX IF NOT EXISTS ux_opening_hours_sync_uuid ON opening_hours (sync_uuid);

-- ── customers (Zuri owns → TS) ───────────────────────────────────────────────
-- We RECEIVE these; sync_source defaults to 'zuri'. Phone is the primary match
-- key in the backfill (§7), email secondary.
CREATE TABLE IF NOT EXISTS customers (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(160),
    phone       VARCHAR(40),
    email       VARCHAR(200),
    notes       TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid(),
    sync_version    INTEGER     NOT NULL DEFAULT 1,
    sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    sync_source     VARCHAR(20) NOT NULL DEFAULT 'zuri',
    sync_last_at    TIMESTAMPTZ,
    is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_customers_sync_uuid ON customers (sync_uuid);
CREATE INDEX        IF NOT EXISTS ix_customers_phone      ON customers (phone);
CREATE INDEX        IF NOT EXISTS ix_customers_email      ON customers (email);

-- ── reservations: link to table + customer, extend the status machine ────────
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS table_id    INT REFERENCES restaurant_tables(id) ON DELETE SET NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS customer_id INT REFERENCES customers(id)         ON DELETE SET NULL;

-- Extend the status value set to the full §6 machine. The old inline CHECK is
-- named reservations_status_check; drop and re-add so re-running is safe.
ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_status_check;
ALTER TABLE reservations ADD  CONSTRAINT reservations_status_check
    CHECK (status IN ('pending','confirmed','seated','completed','cancelled','no_show'));
