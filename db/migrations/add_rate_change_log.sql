-- Tribal Sand: change log for the global rate editor (admin/rates.php, owner only).
-- One row per applied change. before_json holds every `rates` row of the changed
-- rooms that overlapped [span_from, span_to) BEFORE the change; after_json the same
-- rows AFTER it. Undo restores before_json inside the span — only when no later,
-- not-undone change overlaps the same rooms and nights, and only while the rows
-- still equal after_json (includes/rate-editor.php).
-- span_to is EXCLUSIVE (the morning after the last night), like rates.date_to.
-- The editor works without this table (preview + apply, no log / undo); it checks
-- for it with to_regclass, never a failing SELECT.
-- Run via /admin/migrate.php. Idempotent (IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS rate_change_log (
    id           SERIAL PRIMARY KEY,
    admin_id     INTEGER     REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    summary      TEXT        NOT NULL,
    rooms        INTEGER[]   NOT NULL,
    span_from    DATE        NOT NULL,
    span_to      DATE        NOT NULL,
    request_json JSONB       NOT NULL DEFAULT '{}'::jsonb,
    before_json  JSONB       NOT NULL DEFAULT '[]'::jsonb,
    after_json   JSONB       NOT NULL DEFAULT '[]'::jsonb,
    undone_at    TIMESTAMPTZ,
    undone_by    INTEGER     REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT rate_change_log_span_check CHECK (span_from < span_to)
);
CREATE INDEX IF NOT EXISTS idx_rate_change_log_rooms ON rate_change_log USING GIN (rooms);
