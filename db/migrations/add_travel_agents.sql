-- Migration: travel-agent accounts (external, read-only rate portal).
-- Run via /admin/migrate.php. Idempotent.
--
-- Agents are an EXTERNAL audience with their own login and their own minimal
-- portal — deliberately NOT an admin_users role, so an agent can never reach
-- /admin. Their rate is always derived from the ONE published price at render
-- time (published × (1 − discount)); no parallel net-rate table is stored, so a
-- base-rate change moves every agent's price automatically.
--
--   discount_pct    — flat % off published, 0..100 (the default for every venue)
--   venue_discounts — optional {"<venue_id>": pct} overrides for negotiated
--                     per-property rates; NULL/absent falls back to discount_pct
CREATE TABLE IF NOT EXISTS travel_agents (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    agency          TEXT NOT NULL DEFAULT '',
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    discount_pct    NUMERIC(5,2) NOT NULL DEFAULT 0,
    venue_discounts JSONB,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at   TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE travel_agents DROP CONSTRAINT IF EXISTS travel_agents_discount_check;
ALTER TABLE travel_agents ADD CONSTRAINT travel_agents_discount_check
    CHECK (discount_pct >= 0 AND discount_pct <= 100);
