-- Tribal Sand: restaurant table reservations (request model). Idempotent.
-- Sorts after add_menus.sql (reservations optionally link to a menu).
CREATE TABLE IF NOT EXISTS reservations (
    id               SERIAL PRIMARY KEY,
    venue_id         INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    menu_id          INT REFERENCES menus(id) ON DELETE SET NULL,
    reference        VARCHAR(32) UNIQUE,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    party_size       INT  NOT NULL CHECK (party_size > 0),
    guest_name       VARCHAR(160) NOT NULL,
    guest_phone      VARCHAR(40)  NOT NULL,
    guest_email      VARCHAR(200),
    notes            TEXT,
    status           VARCHAR(20) NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','confirmed','cancelled')),
    source           VARCHAR(40) NOT NULL DEFAULT 'web',
    client_ip        VARCHAR(64),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_reservations_venue_date ON reservations (venue_id, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_status     ON reservations (status, reservation_date);
