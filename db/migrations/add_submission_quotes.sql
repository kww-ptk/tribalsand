-- Tribal Sand: quotes saved on an enquiry (Quote builder, Part C).
-- One row per quote option offered or printed from the enquiry pop-up. The
-- snapshot holds the quote exactly as priced (server re-priced, never client
-- figures), the selection that produced it, the guest's request block, the FX
-- rate and the terms in force — so a saved quote always reopens at the prices
-- it was quoted at. option_no is allocated server-side, next number per enquiry.
-- UNIQUE (submission_id, option_no) also serves lookups by submission_id, so no separate index.
-- Run via /admin/migrate.php. Idempotent (IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS submission_quotes (
    id            SERIAL PRIMARY KEY,
    submission_id INTEGER       NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
    option_no     INTEGER       NOT NULL,
    admin_id      INTEGER       REFERENCES admin_users(id) ON DELETE SET NULL,
    currency      VARCHAR(3)    NOT NULL,
    total         NUMERIC(14,2) NOT NULL,
    snapshot_json JSONB         NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT NOW(),
    UNIQUE (submission_id, option_no)
);
