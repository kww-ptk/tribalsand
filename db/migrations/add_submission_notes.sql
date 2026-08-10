-- Tribal Sand: internal, staff-only notes thread per submission (redesign #15).
-- A CRM-style timestamped log of who wrote what — NOT a guest-facing channel
-- (guest↔staff conversation already lives on the Messages surface).
-- Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS submission_notes (
    id            SERIAL PRIMARY KEY,
    submission_id INTEGER   NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
    admin_id      INTEGER   REFERENCES admin_users(id) ON DELETE SET NULL,
    body          TEXT      NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_submission_notes_sub ON submission_notes (submission_id, created_at);
