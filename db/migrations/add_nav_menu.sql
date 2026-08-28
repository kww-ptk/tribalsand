-- Tribal Sand: admin-editable site navigation (mega menu).
-- Replaces the hardcoded top-nav in includes/header.php with a 3-level model:
--   nav_items  → top-level buttons (Accommodations, Gallery, About, …)
--   nav_groups → a column/section within an item's dropdown
--   nav_links  → a row within a group (link, optional thumbnail, tag, CTA)
--
-- Pre-migration-safe: includes/nav-data.php guards every read with nav_supported(),
-- and includes/header.php falls back to the hardcoded nav when these tables are
-- empty/absent — so a deploy without this migration never yields a blank menu.
--
-- Restaurants stays auto-driven: it is seeded as a locked placeholder row
-- (auto_source = 'restaurants') so it keeps its position in the bar while the
-- renderer fills it from the existing published-menus logic; the builder never
-- edits it.

CREATE TABLE IF NOT EXISTS nav_items (
    id           SERIAL PRIMARY KEY,
    label        VARCHAR(120) NOT NULL,
    layout       VARCHAR(20)  NOT NULL DEFAULT 'simple',   -- simple | wide2 | wide3
    auto_source  VARCHAR(50),                              -- NULL = editable; 'restaurants' = locked/dynamic
    sort_order   INT          NOT NULL DEFAULT 0,
    is_published BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS nav_groups (
    id          SERIAL PRIMARY KEY,
    nav_item_id INT          NOT NULL REFERENCES nav_items(id) ON DELETE CASCADE,
    label       VARCHAR(120),                              -- optional column/section heading
    sort_order  INT          NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_nav_groups_item ON nav_groups(nav_item_id);

CREATE TABLE IF NOT EXISTS nav_links (
    id           SERIAL PRIMARY KEY,
    nav_group_id INT          NOT NULL REFERENCES nav_groups(id) ON DELETE CASCADE,
    label        VARCHAR(160) NOT NULL,
    href         VARCHAR(400) NOT NULL DEFAULT '#',
    sublabel     VARCHAR(200),                             -- the small location/subtitle line
    image_key    VARCHAR(255),                             -- thumbnail storage key (storage_url); empty = text row
    tag          VARCHAR(20)  NOT NULL DEFAULT '',         -- '' | open | soon
    role         VARCHAR(20)  NOT NULL DEFAULT 'row',      -- row | footer_link | cta_button | divider
    cta_note     VARCHAR(200),                             -- note above a cta_button ("Not sure yet?")
    target_blank BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order   INT          NOT NULL DEFAULT 0,
    is_published BOOLEAN      NOT NULL DEFAULT TRUE
);
CREATE INDEX IF NOT EXISTS idx_nav_links_group ON nav_links(nav_group_id);
