-- Migration: travel-agency partner logos (the "Our Partners" ticker on
-- /for-agents.php). Run via /admin/migrate.php. Idempotent.
--
-- Two ways a row gets here, and the difference is `submission_id`:
--   1. The owner adds an approved partner directly in Admin → Partners
--      (submission_id NULL, published straight away).
--   2. An agency registers itself through the public "Become Our Partner" form
--      on /for-agents.php, supplying its own logo + website. That writes an
--      UNPUBLISHED row carrying the submission it came from, so the owner
--      reviews it before it appears. Publishing is the approval step — an
--      anonymous visitor must never be able to put a logo (and a backlink to
--      their own site) on tribalsand.com unreviewed.
-- Published rows show in the ticker, each linked to the partner's site.
CREATE TABLE IF NOT EXISTS agency_partners (
    id           SERIAL PRIMARY KEY,
    name         TEXT NOT NULL,
    website_url  TEXT NOT NULL DEFAULT '',
    logo_key     TEXT NOT NULL DEFAULT '',   -- storage key/URL (storage_put), like venue_images
    sort_order   INT  NOT NULL DEFAULT 0,
    is_published BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_agency_partners_pub ON agency_partners (is_published, sort_order);

-- Self-registration trail (added after the first cut of this table, so ALTERs).
ALTER TABLE agency_partners ADD COLUMN IF NOT EXISTS contact_email TEXT NOT NULL DEFAULT '';
ALTER TABLE agency_partners ADD COLUMN IF NOT EXISTS submission_id INT;

-- One partner per agency name — the self-registration path upserts on it, so a
-- resubmission refreshes the pending row instead of stacking duplicates.
CREATE UNIQUE INDEX IF NOT EXISTS uq_agency_partners_name ON agency_partners (lower(name));
