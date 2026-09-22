-- Migration: Tribalsand ↔ Zuri two-way restaurant sync — the §4 foundation.
-- Run via /admin/migrate.php. Idempotent (safe to re-run).
--
-- This adds ONLY the sync transport layer:
--   • sync_* bookkeeping columns on every table we sync today
--     (menus, menu_categories, menu_items, reservations)
--   • the four sync engine tables (outbox / inbox / id_map / conflicts)
--
-- It deliberately does NOT create the restaurant_table, opening_hours or
-- customers models the spec also has Tribalsand own/consume — those are separate
-- feature migrations, and each will get the same six sync_* columns when built.
--
-- Cross-system identity is sync_uuid (see docs/restaurant-sync.md §3). Local
-- auto-increment ids stay local and are NEVER sent. gen_random_uuid() is built
-- into PostgreSQL 13+ (available on our AWS RDS PostgreSQL).
--
-- NEVER hard-delete a synced row: soft-delete via is_deleted, or the peer will
-- re-create it on the next changes pull.

-- ── sync_* columns on the synced tables ─────────────────────────────────────
-- Adding a column with a volatile DEFAULT (gen_random_uuid) fills every existing
-- row with its own distinct value in one pass, so old rows get a stable uuid.

ALTER TABLE menus            ADD COLUMN IF NOT EXISTS sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid();
ALTER TABLE menus            ADD COLUMN IF NOT EXISTS sync_version    INTEGER     NOT NULL DEFAULT 1;
ALTER TABLE menus            ADD COLUMN IF NOT EXISTS sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE menus            ADD COLUMN IF NOT EXISTS sync_source     VARCHAR(20) NOT NULL DEFAULT 'tribalsand';
ALTER TABLE menus            ADD COLUMN IF NOT EXISTS sync_last_at    TIMESTAMPTZ;
ALTER TABLE menus            ADD COLUMN IF NOT EXISTS is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE;

ALTER TABLE menu_categories  ADD COLUMN IF NOT EXISTS sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid();
ALTER TABLE menu_categories  ADD COLUMN IF NOT EXISTS sync_version    INTEGER     NOT NULL DEFAULT 1;
ALTER TABLE menu_categories  ADD COLUMN IF NOT EXISTS sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE menu_categories  ADD COLUMN IF NOT EXISTS sync_source     VARCHAR(20) NOT NULL DEFAULT 'tribalsand';
ALTER TABLE menu_categories  ADD COLUMN IF NOT EXISTS sync_last_at    TIMESTAMPTZ;
ALTER TABLE menu_categories  ADD COLUMN IF NOT EXISTS is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE;

ALTER TABLE menu_items       ADD COLUMN IF NOT EXISTS sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid();
ALTER TABLE menu_items       ADD COLUMN IF NOT EXISTS sync_version    INTEGER     NOT NULL DEFAULT 1;
ALTER TABLE menu_items       ADD COLUMN IF NOT EXISTS sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE menu_items       ADD COLUMN IF NOT EXISTS sync_source     VARCHAR(20) NOT NULL DEFAULT 'tribalsand';
ALTER TABLE menu_items       ADD COLUMN IF NOT EXISTS sync_last_at    TIMESTAMPTZ;
ALTER TABLE menu_items       ADD COLUMN IF NOT EXISTS is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE;

-- Reservation status is owned by neither side alone (§6 state machine); a new
-- reservation and its seat inventory originate on Zuri, so sync_source on
-- reservations rows defaults to 'zuri'. Staff-created ones flip it to
-- 'tribalsand' at write time.
ALTER TABLE reservations     ADD COLUMN IF NOT EXISTS sync_uuid       UUID        NOT NULL DEFAULT gen_random_uuid();
ALTER TABLE reservations     ADD COLUMN IF NOT EXISTS sync_version    INTEGER     NOT NULL DEFAULT 1;
ALTER TABLE reservations     ADD COLUMN IF NOT EXISTS sync_updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE reservations     ADD COLUMN IF NOT EXISTS sync_source     VARCHAR(20) NOT NULL DEFAULT 'zuri';
ALTER TABLE reservations     ADD COLUMN IF NOT EXISTS sync_last_at    TIMESTAMPTZ;
ALTER TABLE reservations     ADD COLUMN IF NOT EXISTS is_deleted      BOOLEAN     NOT NULL DEFAULT FALSE;

CREATE UNIQUE INDEX IF NOT EXISTS ux_menus_sync_uuid            ON menus            (sync_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS ux_menu_categories_sync_uuid  ON menu_categories  (sync_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS ux_menu_items_sync_uuid       ON menu_items       (sync_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS ux_reservations_sync_uuid     ON reservations     (sync_uuid);

-- ── sync_outbox: local changes waiting to be pushed to the peer ──────────────
CREATE TABLE IF NOT EXISTS sync_outbox (
    id            BIGSERIAL PRIMARY KEY,
    event_id      VARCHAR(40) NOT NULL UNIQUE,       -- our generated id; the peer dedupes on it
    entity        VARCHAR(40) NOT NULL,              -- menu_item, reservation, …
    sync_uuid     UUID        NOT NULL,
    operation     VARCHAR(10) NOT NULL,              -- create | update | delete (always soft)
    payload       JSONB       NOT NULL,              -- the full §3 envelope, ready to send
    version       INTEGER     NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'pending',  -- pending | sent | failed
    attempts      SMALLINT    NOT NULL DEFAULT 0,
    next_retry_at TIMESTAMPTZ,
    last_error    TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    sent_at       TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS ix_outbox_dispatch ON sync_outbox (status, next_retry_at);

-- ── sync_inbox: events received from the peer, applied exactly once ──────────
-- The UNIQUE index on event_id is what makes application idempotent: a redelivery
-- (delivery is at-least-once) collides on insert and is reported as a duplicate,
-- never applied twice.
CREATE TABLE IF NOT EXISTS sync_inbox (
    id            BIGSERIAL PRIMARY KEY,
    event_id      VARCHAR(40) NOT NULL UNIQUE,
    entity        VARCHAR(40) NOT NULL,
    sync_uuid     UUID        NOT NULL,
    operation     VARCHAR(10) NOT NULL,
    payload       JSONB       NOT NULL,
    version       INTEGER     NOT NULL,
    source        VARCHAR(20) NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'pending',  -- pending | applied | rejected
    error         TEXT,
    received_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    applied_at    TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS ix_inbox_apply ON sync_inbox (status, received_at);

-- ── sync_id_map: (entity, sync_uuid) → our local auto-increment id ───────────
CREATE TABLE IF NOT EXISTS sync_id_map (
    entity     VARCHAR(40) NOT NULL,
    sync_uuid  UUID        NOT NULL,
    local_id   BIGINT      NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (entity, sync_uuid)
);
CREATE INDEX IF NOT EXISTS ix_id_map_local ON sync_id_map (entity, local_id);

-- ── sync_conflicts: never discard the losing side silently (§6) ──────────────
CREATE TABLE IF NOT EXISTS sync_conflicts (
    id             BIGSERIAL PRIMARY KEY,
    entity         VARCHAR(40) NOT NULL,
    sync_uuid      UUID        NOT NULL,
    local_version  INTEGER,
    remote_version INTEGER,
    local_data     JSONB,
    remote_data    JSONB,
    resolution     VARCHAR(24),                       -- owner_wins | later_wins | zuri_wins | …
    resolved_at    TIMESTAMPTZ,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_conflicts_open ON sync_conflicts (resolved_at, created_at);
