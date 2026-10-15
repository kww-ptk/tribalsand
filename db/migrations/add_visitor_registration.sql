-- General registration link — visitors register once, sign the waiver, get a pass.
-- Spec: docs/superpowers/specs/2026-10-14-visitor-registration-design.md
-- After add_visitors + add_checkin_id_type. Idempotent — safe to re-run.
-- visitor_signing_audit is created LAST: visitor_reg_supported() probes it.

CREATE TABLE IF NOT EXISTS visitor_places (
    id          SERIAL PRIMARY KEY,
    name        TEXT    NOT NULL,
    venue_id    INT     NOT NULL REFERENCES venues(id) ON DELETE RESTRICT,
    sort_order  INT     NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_visitor_places_name ON visitor_places (LOWER(name));
-- One place per published property to start with; the owner adds the restaurants.
INSERT INTO visitor_places (name, venue_id, sort_order)
SELECT v.name, v.id, v.sort_order FROM venues v
 WHERE v.is_published = TRUE
   AND NOT EXISTS (SELECT 1 FROM visitor_places p WHERE p.venue_id = v.id)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS registered_visitors (
    id                          SERIAL PRIMARY KEY,
    full_name                   TEXT NOT NULL,
    phone                       TEXT,
    phone_key                   VARCHAR(9),
    email                       TEXT,
    id_type                     VARCHAR(16) NOT NULL DEFAULT 'passport' CHECK (id_type IN ('passport', 'kenya_id')),
    id_number                   TEXT,
    nationality                 TEXT,
    id_expiry                   DATE,
    id_photo_key                TEXT,
    parent_id                   INT REFERENCES registered_visitors(id) ON DELETE CASCADE,
    age                         SMALLINT CHECK (age IS NULL OR age BETWEEN 0 AND 17),
    invited_by                  INT REFERENCES registered_visitors(id) ON DELETE SET NULL,
    waiver_signature            TEXT,
    waiver_signed_name          TEXT,
    waiver_signed_at            TIMESTAMPTZ,
    waiver_terms_snapshot       TEXT,
    waiver_version              VARCHAR(12),
    waiver_reference            VARCHAR(24) UNIQUE,
    waiver_name_snapshot        TEXT,
    waiver_id_type_snapshot     VARCHAR(16),
    waiver_id_number_snapshot   TEXT,
    waiver_nationality_snapshot TEXT,
    waiver_id_expiry_snapshot   DATE,
    signed_ip                   TEXT,
    signed_ua                   TEXT,
    signed_via                  VARCHAR(8) CHECK (signed_via IS NULL OR signed_via IN ('self', 'gate')),
    created_via                 VARCHAR(8) NOT NULL DEFAULT 'self' CHECK (created_via IN ('self', 'gate', 'invite')),
    created_by                  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    removed_at                  TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_regvis_phone   ON registered_visitors (phone_key) WHERE removed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_regvis_email   ON registered_visitors (LOWER(email)) WHERE removed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_regvis_parent  ON registered_visitors (parent_id);
CREATE INDEX IF NOT EXISTS idx_regvis_invited ON registered_visitors (invited_by);

CREATE TABLE IF NOT EXISTS visitor_visits (
    id          SERIAL PRIMARY KEY,
    visitor_id  INT  NOT NULL REFERENCES registered_visitors(id) ON DELETE CASCADE,
    place_id    INT  NOT NULL REFERENCES visitor_places(id) ON DELETE RESTRICT,
    visit_date  DATE NOT NULL,
    party       JSONB NOT NULL DEFAULT '{"adults":[],"children":[]}',
    created_via VARCHAR(8) NOT NULL DEFAULT 'self' CHECK (created_via IN ('self', 'gate')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (visitor_id, visit_date)
);

ALTER TABLE visitors ADD COLUMN IF NOT EXISTS registered_visitor_id INT REFERENCES registered_visitors(id) ON DELETE SET NULL;
ALTER TABLE visitors ADD COLUMN IF NOT EXISTS visit_id INT REFERENCES visitor_visits(id) ON DELETE SET NULL;
ALTER TABLE visitors ADD COLUMN IF NOT EXISTS people INT;
-- A registered visitor is on site at most once: a double tap or a forgotten
-- sign-out can never open a second row.
CREATE UNIQUE INDEX IF NOT EXISTS uq_visitors_open_registered
    ON visitors (registered_visitor_id) WHERE time_out IS NULL AND registered_visitor_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS visitor_reg_attempts (
    id          BIGSERIAL PRIMARY KEY,
    ip          TEXT NOT NULL,
    kind        VARCHAR(16) NOT NULL,
    subject     TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_vra_ip      ON visitor_reg_attempts (kind, ip, created_at);
CREATE INDEX IF NOT EXISTS idx_vra_subject ON visitor_reg_attempts (kind, subject, created_at);

-- Append-only, hash-chained evidence of every signing step (own chain — mixing
-- rows into checkin_signing_audit would invalidate its stored hashes).
CREATE TABLE IF NOT EXISTS visitor_signing_audit (
    id             BIGSERIAL PRIMARY KEY,
    reference      VARCHAR(24),
    visitor_id     INT,
    step           VARCHAR(24) NOT NULL,
    waiver_version VARCHAR(12),
    ip             TEXT,
    user_agent     TEXT,
    method         VARCHAR(16),
    detail         TEXT,
    prev_hash      CHAR(64) NOT NULL,
    row_hash       CHAR(64) NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_vsa_visitor ON visitor_signing_audit (visitor_id);
