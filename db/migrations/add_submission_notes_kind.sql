-- Extend the existing submission_notes thread (Tribal Sand already has the table
-- from add_submission_notes.sql) with a `kind` so a row is either a private team
-- note, a reply logged/sent to the guest, or (future) an inbound guest reply,
-- plus a frozen author_name for the thread display.
-- Run via /admin/migrate.php. Safe to re-run (IF NOT EXISTS + DROP/ADD CONSTRAINT).

ALTER TABLE submission_notes
  ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'note';

ALTER TABLE submission_notes DROP CONSTRAINT IF EXISTS submission_notes_kind_check;
ALTER TABLE submission_notes ADD CONSTRAINT submission_notes_kind_check
  CHECK (kind IN ('note','reply','guest_reply'));

ALTER TABLE submission_notes
  ADD COLUMN IF NOT EXISTS author_name VARCHAR(255) NOT NULL DEFAULT '';
