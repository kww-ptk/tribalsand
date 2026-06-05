CREATE TABLE IF NOT EXISTS venue_images (
    id         SERIAL PRIMARY KEY,
    venue_id   INT          NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    filename   VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(255),
    is_hero    BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_venue_images_venue_id ON venue_images(venue_id);
