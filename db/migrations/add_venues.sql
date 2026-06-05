-- Tribal Sand: venue layer above rooms (My Amani, Maya Kobe, Zuri, Enkare Bofa,
-- Sandbox, Maya Ilai, Tribal Dunes). Sorts after add_tours.sql, before enrich_tours.sql.
CREATE TABLE IF NOT EXISTS venues (
    id           SERIAL PRIMARY KEY,
    slug         VARCHAR(100) NOT NULL UNIQUE,
    name         VARCHAR(255) NOT NULL,
    location     VARCHAR(255),
    sort_order   INT          NOT NULL DEFAULT 0,
    is_published BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at   TIMESTAMP    NOT NULL DEFAULT NOW()
);

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS venue_id INT REFERENCES venues(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_rooms_venue_id ON rooms(venue_id);
