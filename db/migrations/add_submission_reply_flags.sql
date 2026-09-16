-- Migration: unread-reply markers on submissions (Item 4).
-- Run via /admin/migrate.php. Idempotent. Safe to run before or after any of the
-- submission_notes migrations — it only touches the submissions table.
--
-- Two nullable timestamps give reservations a reliable "new reply since I last
-- looked" signal, instead of discovering a guest (or trade-agent) reply only by
-- opening every thread:
--   • last_guest_reply_at — stamped now() whenever a guest_reply is threaded
--     (api/inbound-mail.php for an inbound email, api/agent-message.php for a
--     trade-portal reply).
--   • reply_seen_at        — stamped now() when a staff member opens the thread
--     (admin/submission-view.php on GET).
-- A row is "unread" when last_guest_reply_at IS NOT NULL AND (reply_seen_at IS
-- NULL OR reply_seen_at < last_guest_reply_at). Every read is guarded by
-- submission_reply_flags_supported() so a pre-migration deploy simply shows no
-- badges.
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS last_guest_reply_at TIMESTAMPTZ NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS reply_seen_at       TIMESTAMPTZ NULL;

-- The list badge + nav count only ever look at rows with a reply, so index those.
CREATE INDEX IF NOT EXISTS idx_submissions_last_guest_reply
    ON submissions (last_guest_reply_at) WHERE last_guest_reply_at IS NOT NULL;
