-- Per-property SEO and social-sharing metadata.
--
-- Until now every property page hardcoded its <title>, meta description and
-- og:image in the PHP file, so changing what Google or WhatsApp shows meant a
-- code deploy. These three columns move that into the DB, edited in
-- Admin → Properties → <property> → Content.
--
-- All three are NULLABLE and empty by default, and the read side
-- (ts_venue_meta) falls back to each page's built-in values. So applying this
-- migration changes NOTHING about what any page currently serves — the pages
-- only start differing once someone actually fills a field in.
--
-- og_image holds a STORAGE KEY (the same kind of filename venue_images holds),
-- not a URL, so it resolves through storage_url() like every other uploaded
-- image and survives a change of asset origin.
--
-- Idempotent — safe to run more than once.

ALTER TABLE venues ADD COLUMN IF NOT EXISTS seo_title       TEXT NULL;
ALTER TABLE venues ADD COLUMN IF NOT EXISTS seo_description TEXT NULL;
ALTER TABLE venues ADD COLUMN IF NOT EXISTS og_image        TEXT NULL;

COMMENT ON COLUMN venues.seo_title IS
    'Overrides the property page''s <title> and og:title. Empty = the page''s built-in title.';
COMMENT ON COLUMN venues.seo_description IS
    'Overrides the meta description and og:description. Empty = the page''s built-in text.';
COMMENT ON COLUMN venues.og_image IS
    'Storage key (not a URL) for the social-sharing image. Empty = the page''s built-in image.';
