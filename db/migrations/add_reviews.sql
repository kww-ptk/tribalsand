-- Tribal Sand: guest reviews, editable in Admin → Reviews (owner). A review belongs
-- to one property (shown on its page) or to the whole group (venue_id NULL); either
-- can also be picked for the home page. Pages fall back to their built-in reviews
-- until published rows exist. Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS reviews (
    id           SERIAL PRIMARY KEY,
    venue_id     INT          REFERENCES venues(id) ON DELETE CASCADE,   -- NULL = Tribal Sand as a whole
    author       VARCHAR(120) NOT NULL,
    detail       VARCHAR(120) NOT NULL DEFAULT '',                        -- e.g. "United Kingdom · August 2024"
    rating       SMALLINT     NOT NULL DEFAULT 5 CHECK (rating BETWEEN 1 AND 5),
    quote        TEXT         NOT NULL,
    is_published BOOLEAN      NOT NULL DEFAULT TRUE,
    show_on_home BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order   INT          NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_reviews_venue ON reviews (venue_id, sort_order);
