-- Migration: add For Sale real-estate feature
-- Run (prod):  psql $DATABASE_URL -f db/migrations/add_properties.sql
-- Run (local): psql -U patrikgiuliana -h localhost -p 5432 -d claris -f db/migrations/add_properties.sql

CREATE TABLE IF NOT EXISTS properties (
    id               SERIAL PRIMARY KEY,
    slug             VARCHAR(100)  NOT NULL UNIQUE,
    title            VARCHAR(255)  NOT NULL,
    location         VARCHAR(255),
    property_type    VARCHAR(30)   NOT NULL DEFAULT 'villa',   -- villa | apartment | house | land | commercial
    status           VARCHAR(20)   NOT NULL DEFAULT 'for_sale', -- for_sale | under_offer | sold
    price_amount     NUMERIC(14,2) NOT NULL DEFAULT 0,          -- 0 = "Price on request"
    price_currency   VARCHAR(10)   NOT NULL DEFAULT 'USD',
    price_qualifier  VARCHAR(50),                               -- e.g. "Guide price", "Negotiable"
    bedrooms         INT,
    bathrooms        INT,
    plot_sqm         INT,
    built_sqm        INT,
    ref_code         VARCHAR(50),
    short_desc       TEXT,
    long_desc        TEXT,
    features_json    JSONB         NOT NULL DEFAULT '[]',
    seo_title        VARCHAR(255),
    seo_description  VARCHAR(320),
    sort_order       INT           NOT NULL DEFAULT 0,
    is_published     BOOLEAN       NOT NULL DEFAULT TRUE,
    updated_at       TIMESTAMP     NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS property_images (
    id          SERIAL PRIMARY KEY,
    property_id INT          NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    filename    VARCHAR(255) NOT NULL,
    alt_text    VARCHAR(255),
    is_hero     BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_property_images_property_id ON property_images(property_id);
