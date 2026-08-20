-- Migration: DB-driven restaurant menus, per property, manager-editable.
-- Run via /admin/migrate.php. Idempotent.
--
-- Model mirrors the Tours catalog: a `menus` row per property's menu (keyed by a
-- URL slug, optionally linked to a venue for manager scoping), with ordered
-- `menu_categories` grouped into a Food / Drinks section, each holding ordered
-- `menu_items`. Managers only see menus for venues in their scope (admin_venue_ids);
-- the owner sees all. Public page renders published menus with visible categories
-- and available items only.

CREATE TABLE IF NOT EXISTS menus (
    id             SERIAL PRIMARY KEY,
    venue_id       INT REFERENCES venues(id) ON DELETE SET NULL,
    slug           VARCHAR(120) NOT NULL UNIQUE,     -- public URL key: /menu.php?m=<slug>
    title          VARCHAR(160) NOT NULL,            -- "Zuri"
    subtitle       VARCHAR(200),                     -- "Boutique Hotel · Watamu"
    tagline        TEXT,                             -- italic hero line
    location_label VARCHAR(200),                     -- "📍 Watamu · Kenya's North Coast"
    footer_note    TEXT,                             -- optional footer paragraph
    currency_label VARCHAR(16) NOT NULL DEFAULT 'Kes', -- rendered after the amount
    is_published   BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order     INT NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_menus_venue ON menus (venue_id);

CREATE TABLE IF NOT EXISTS menu_categories (
    id          SERIAL PRIMARY KEY,
    menu_id     INT NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
    section     VARCHAR(20) NOT NULL DEFAULT 'food'
                CHECK (section IN ('food','drinks')),
    name        VARCHAR(160) NOT NULL,              -- "Starter Soups"
    tag         VARCHAR(160),                       -- "First Course" / "All 1,200 Kes"
    icon        VARCHAR(16),                        -- emoji for the slider pill
    sort_order  INT NOT NULL DEFAULT 0,
    is_visible  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_menu_categories_menu ON menu_categories (menu_id, sort_order);

CREATE TABLE IF NOT EXISTS menu_items (
    id           SERIAL PRIMARY KEY,
    category_id  INT NOT NULL REFERENCES menu_categories(id) ON DELETE CASCADE,
    name         VARCHAR(200) NOT NULL,
    description  TEXT,
    price        NUMERIC(10,2),                     -- KES; NULL = no price shown
    is_veg       BOOLEAN NOT NULL DEFAULT FALSE,
    is_vegan     BOOLEAN NOT NULL DEFAULT FALSE,
    is_spicy     BOOLEAN NOT NULL DEFAULT FALSE,
    has_nuts     BOOLEAN NOT NULL DEFAULT FALSE,
    has_gluten   BOOLEAN NOT NULL DEFAULT FALSE,
    is_gf        BOOLEAN NOT NULL DEFAULT FALSE,
    is_signature BOOLEAN NOT NULL DEFAULT FALSE,
    is_available BOOLEAN NOT NULL DEFAULT TRUE,      -- the "Hidden" toggle
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_menu_items_category ON menu_items (category_id, sort_order);
