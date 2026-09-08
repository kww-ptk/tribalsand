-- Inbound guest-reply intake log (SES → SNS → api/inbound-mail.php).
--
-- Serves two jobs, mirroring the concierge_log pattern:
--   1. De-duplication — SNS delivers "at least once", so the same received email
--      can arrive twice. The UNIQUE message_id lets the endpoint skip a repeat
--      instead of posting a second identical guest_reply into the thread.
--   2. Observability — one row per inbound email we accepted, matched or not, so a
--      reply that failed to thread (bad/missing TSR tag) is still visible.
--
-- The endpoint is pre-migration-safe (inbound_mail_log_supported()); without this
-- table it still threads replies, it just can't de-dupe. Run via /admin/migrate.php.
-- Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS inbound_mail_log (
    id            SERIAL PRIMARY KEY,
    message_id    VARCHAR(255) NOT NULL UNIQUE,   -- SES mail.messageId
    submission_id INTEGER,                         -- matched submission, or NULL if unmatched
    from_addr     VARCHAR(320) NOT NULL DEFAULT '',
    subject       VARCHAR(998) NOT NULL DEFAULT '',
    matched       BOOLEAN NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS inbound_mail_log_submission_idx
    ON inbound_mail_log (submission_id);
