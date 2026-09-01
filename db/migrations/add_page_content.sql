-- Tribal Sand: editable page content (text + image slots). Idempotent.
--
-- Which slots exist is declared in CODE (page_content_registry() in
-- includes/page-content.php), not here — this table only stores the VALUES an
-- owner has overridden. A page whose row is missing renders the default passed
-- at the call site, so an empty table (or an unapplied migration) renders the
-- page exactly as it ships. Pre-migration-safe via page_content_supported().
CREATE TABLE IF NOT EXISTS page_content (
    page_key    VARCHAR(60)  NOT NULL,   -- 'home' | 'contact' | 'zuri-restaurant' | …
    slot_key    VARCHAR(80)  NOT NULL,   -- 'hero_title', 'hero_image', …
    value       TEXT         NOT NULL DEFAULT '',
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_by  INT,                     -- admin_users.id, best-effort
    PRIMARY KEY (page_key, slot_key)
);
CREATE INDEX IF NOT EXISTS idx_page_content_page ON page_content (page_key);
