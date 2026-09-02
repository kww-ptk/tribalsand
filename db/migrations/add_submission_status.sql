-- Workflow status for each submission (admin pipeline / lead tracking).
-- Slugs map to display labels in includes/submission-status.php.
-- Run via /admin/migrate.php. Safe to re-run (IF NOT EXISTS + DROP/ADD CONSTRAINT).
-- Existing rows default to 'received'.

ALTER TABLE submissions
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'received';

ALTER TABLE submissions
  DROP CONSTRAINT IF EXISTS submissions_status_check;

ALTER TABLE submissions
  ADD CONSTRAINT submissions_status_check
  CHECK (status IN (
    'received',
    'answered',
    'option_sent',
    'waiting',
    'to_follow_up',
    'booked',
    'not_interested',
    'dates_unavailable'
  ));

CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status);
