-- Seven Islands Resort — PostgreSQL Schema
-- Run: psql $DATABASE_URL -f db/schema.sql

-- Admin users
CREATE TABLE IF NOT EXISTS admin_users (
    id            SERIAL PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    last_login_at TIMESTAMP
);

-- Rooms
CREATE TABLE IF NOT EXISTS rooms (
    id               SERIAL PRIMARY KEY,
    slug             VARCHAR(100) NOT NULL UNIQUE,
    name             VARCHAR(255) NOT NULL,
    price_amount     NUMERIC(10,2) NOT NULL DEFAULT 0,
    price_currency   VARCHAR(10)  NOT NULL DEFAULT 'USD',
    price_unit       VARCHAR(50)  NOT NULL DEFAULT 'per night',
    size_sqm         INT,
    capacity         INT,
    bed_count        INT,
    short_desc       TEXT,
    long_desc        TEXT,
    features_json    JSONB        NOT NULL DEFAULT '[]',
    faqs_json        JSONB        NOT NULL DEFAULT '[]',
    seo_title        VARCHAR(255),
    seo_description  VARCHAR(320),
    sort_order       INT          NOT NULL DEFAULT 0,
    is_published     BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at       TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Room images
CREATE TABLE IF NOT EXISTS room_images (
    id         SERIAL PRIMARY KEY,
    room_id    INT          NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    filename   VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(255),
    is_hero    BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_room_images_room_id ON room_images(room_id);

-- Tours
CREATE TABLE IF NOT EXISTS tours (
    id               SERIAL PRIMARY KEY,
    slug             VARCHAR(100) NOT NULL UNIQUE,
    name             VARCHAR(255) NOT NULL,
    category         VARCHAR(20)  NOT NULL DEFAULT 'classic',
    tag_label        VARCHAR(100),
    duration         VARCHAR(100),
    short_desc       TEXT,
    long_desc        TEXT,
    highlights_json  JSONB        NOT NULL DEFAULT '[]',
    seo_title        VARCHAR(255),
    seo_description  VARCHAR(320),
    sort_order       INT          NOT NULL DEFAULT 0,
    is_published     BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at       TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Tour images
CREATE TABLE IF NOT EXISTS tour_images (
    id         SERIAL PRIMARY KEY,
    tour_id    INT          NOT NULL REFERENCES tours(id) ON DELETE CASCADE,
    filename   VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(255),
    is_hero    BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tour_images_tour_id ON tour_images(tour_id);

-- Properties (For Sale)
CREATE TABLE IF NOT EXISTS properties (
    id               SERIAL PRIMARY KEY,
    slug             VARCHAR(100)  NOT NULL UNIQUE,
    title            VARCHAR(255)  NOT NULL,
    location         VARCHAR(255),
    property_type    VARCHAR(30)   NOT NULL DEFAULT 'villa',
    status           VARCHAR(20)   NOT NULL DEFAULT 'for_sale',
    price_amount     NUMERIC(14,2) NOT NULL DEFAULT 0,
    price_currency   VARCHAR(10)   NOT NULL DEFAULT 'USD',
    price_qualifier  VARCHAR(50),
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

-- Property images
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

-- Form submissions
CREATE TABLE IF NOT EXISTS submissions (
    id               SERIAL PRIMARY KEY,
    type             VARCHAR(20)  NOT NULL CHECK (type IN ('enquiry','contact','agency')),
    room_id          INT          REFERENCES rooms(id) ON DELETE SET NULL,
    tour_id          INT          REFERENCES tours(id) ON DELETE SET NULL,
    guest_name       VARCHAR(255),
    guest_email      VARCHAR(255),
    guest_phone      VARCHAR(50),
    message          TEXT,
    check_in         DATE,
    check_out        DATE,
    guests_adults    INT          DEFAULT 1,
    guests_children  INT          DEFAULT 0,
    payload_json     JSONB        NOT NULL DEFAULT '{}',
    source_page      TEXT,
    referrer         TEXT,
    utm_source       VARCHAR(255),
    utm_medium       VARCHAR(255),
    utm_campaign     VARCHAR(255),
    utm_term         VARCHAR(255),
    utm_content      VARCHAR(255),
    user_agent       TEXT,
    ip_address       VARCHAR(45),
    created_at       TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_submissions_type_created  ON submissions(type, created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_room_id       ON submissions(room_id);

-- Settings (key-value store)
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT         NOT NULL DEFAULT '',
    updated_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Login attempts (brute-force protection)
CREATE TABLE IF NOT EXISTS login_attempts (
    id         SERIAL PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45)  NOT NULL,
    success    BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_email     ON login_attempts(email, created_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip        ON login_attempts(ip_address, created_at);

-- Guest board posts (admin-authored updates / excursions / promotions)
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

-- Concierge desk: laundry kind + optional scheduled_for on booking_addons
-- (booking_addons is created via db/migrations; these reflect add_concierge_desk.sql)
ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other',
                    'housekeeping','amenities','maintenance','restaurant','laundry'));

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS scheduled_for TIMESTAMP;

-- Guest ↔ staff messages (per-request + general threads)
-- (booking_messages is created via db/migrations; this mirrors add_messages.sql)
CREATE TABLE IF NOT EXISTS booking_messages (
    id            SERIAL PRIMARY KEY,
    hold_id       INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    addon_id      INT REFERENCES booking_addons(id) ON DELETE CASCADE,  -- NULL = general thread
    sender        TEXT NOT NULL CHECK (sender IN ('guest','admin')),
    body          TEXT NOT NULL,
    read_by_guest BOOLEAN NOT NULL DEFAULT FALSE,
    read_by_admin BOOLEAN NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_bmsg_thread ON booking_messages (hold_id, addon_id, created_at);

-- Per-booking daily itinerary items (admin-authored)
-- (itinerary_items is created via db/migrations; this mirrors add_itinerary.sql)
CREATE TABLE IF NOT EXISTS itinerary_items (
    id         SERIAL PRIMARY KEY,
    hold_id    INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    day        DATE NOT NULL,
    at_time    TIME,
    category   TEXT NOT NULL DEFAULT 'note'
               CHECK (category IN ('flight','transfer','tour','dining','activity','checkin','checkout','note')),
    title      TEXT NOT NULL,
    detail     TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_itin_hold_day ON itinerary_items (hold_id, day, at_time);
