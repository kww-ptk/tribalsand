-- Migration: assign a lead / booking to a specific team member (Items 2 & 3).
-- Run via /admin/migrate.php. Idempotent.
--
-- Both the enquiry (submissions) and the booking (holds) can now carry an owner:
-- the team member responsible for following it up. NULL = unassigned. The FK
-- SET NULL keeps the lead/booking alive if the account is later deleted.
-- Every read is guarded by *_assignee_supported() so a pre-migration deploy
-- behaves exactly as today (no assignee column, no assign control).
--
-- The "who assigned / reassigned / changed status" history reuses the existing
-- admin_audit_log (target_type 'submission' / 'hold') — no new table needed.

ALTER TABLE submissions ADD COLUMN IF NOT EXISTS assigned_to INT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE holds       ADD COLUMN IF NOT EXISTS assigned_to INT REFERENCES admin_users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_submissions_assigned ON submissions (assigned_to);
CREATE INDEX IF NOT EXISTS idx_holds_assigned       ON holds (assigned_to);
