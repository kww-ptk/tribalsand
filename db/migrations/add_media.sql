-- Tribal Sand: media library. Idempotent.
--
-- Records images uploaded through the media library itself. The PICKER does not
-- read this table alone — media_library_items() UNIONs it with the filenames
-- already in venue_images / room_images / tour_images / property_images, so
-- photos uploaded through any existing gallery appear without a backfill and
-- without a sync job that could drift.
--
-- Private check-in scans (passport / deposit card, keys under 'checkin/') are
-- excluded in the query, never listed here, and must never reach a picker.
CREATE TABLE IF NOT EXISTS media (
    id            SERIAL PRIMARY KEY,
    storage_key   VARCHAR(500) NOT NULL,          -- storage_url()-resolvable key or absolute URL
    original_name VARCHAR(255),
    alt_text      VARCHAR(300),
    bytes         BIGINT,
    width         INT,
    height        INT,
    uploaded_by   INT,                            -- admin_users.id, best-effort
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_media_key ON media (storage_key);
CREATE INDEX IF NOT EXISTS idx_media_created ON media (created_at DESC, id DESC);
