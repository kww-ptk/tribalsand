-- Zuri restaurant reservations (request → manager confirms). Piece B of the
-- Restaurant feature. See docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md
--
-- Idempotent: safe to re-run from /admin/migrate.php.

CREATE TABLE IF NOT EXISTS restaurant_reservations (
    id             SERIAL PRIMARY KEY,
    reference      VARCHAR(20)  NOT NULL UNIQUE,
    venue_id       INT          NOT NULL REFERENCES venues(id) ON DELETE RESTRICT,
    status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
    guest_name     VARCHAR(255) NOT NULL,
    guest_email    VARCHAR(255) NOT NULL,
    guest_phone    VARCHAR(50),
    party_size     INT          NOT NULL,
    reserved_on    DATE         NOT NULL,
    reserved_at    TIME         NOT NULL,
    occasion       VARCHAR(40),
    notes          TEXT,
    staff_notes    TEXT,
    confirmed_by   INT          REFERENCES admin_users(id) ON DELETE SET NULL,
    confirmed_at   TIMESTAMPTZ,
    decline_reason TEXT,
    source_page    TEXT,
    referrer       TEXT,
    utm_source     VARCHAR(255),
    utm_medium     VARCHAR(255),
    utm_campaign   VARCHAR(255),
    utm_term       VARCHAR(255),
    utm_content    VARCHAR(255),
    user_agent     TEXT,
    ip_address     VARCHAR(64),
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT restaurant_reservations_status_check
        CHECK (status IN ('pending', 'confirmed', 'declined', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS idx_resv_venue_date ON restaurant_reservations(venue_id, reserved_on);
CREATE INDEX IF NOT EXISTS idx_resv_status     ON restaurant_reservations(status);
CREATE INDEX IF NOT EXISTS idx_resv_date       ON restaurant_reservations(reserved_on);
