-- Migration: guest board posts (admin-authored updates / excursions / promotions)
-- Run via /admin/migrate.php (or: php bin/migrate.php db/migrations/add_guest_board.sql)
-- Idempotent — safe to re-run.

CREATE TABLE IF NOT EXISTS guest_board_posts (
    id             SERIAL PRIMARY KEY,
    venue_id       INT REFERENCES venues(id) ON DELETE CASCADE,   -- NULL = all properties
    category       TEXT NOT NULL CHECK (category IN ('update','excursion','promotion')),
    title          TEXT NOT NULL,
    body           TEXT NOT NULL DEFAULT '',
    image_filename TEXT,
    is_published   BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order     INT NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_gbp_visible
    ON guest_board_posts (is_published, venue_id, sort_order DESC, created_at DESC);
