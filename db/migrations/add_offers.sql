-- Tribal Sand: Offers & Specials (homepage promo strip). Idempotent.
-- v1 is SITE-WIDE — no venue_id (add later if per-property is wanted).
-- Pre-migration-safe: includes/offers.php guards every read with offers_supported().
CREATE TABLE IF NOT EXISTS offers (
    id            SERIAL PRIMARY KEY,
    title         VARCHAR(255) NOT NULL,
    subtitle      VARCHAR(255),                       -- card sub-line
    category      VARCHAR(20)  NOT NULL DEFAULT 'special'  -- stay | dining | experience | special
                  CHECK (category IN ('stay','dining','experience','special')),
    badge         VARCHAR(60),                        -- corner pill, e.g. "-20%" or "From $120/night"
    body          TEXT,                               -- longer copy (future /offers page)
    image_key     VARCHAR(500),                       -- storage key/URL, served via offer_img_url()
    cta_label     VARCHAR(80),                        -- optional external CTA
    cta_url       VARCHAR(500),
    valid_from    DATE,
    valid_to      DATE,                               -- offer hidden once past this date
    sort_order    INT          NOT NULL DEFAULT 0,
    is_published  BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_offers_pub ON offers (is_published, sort_order, id);
