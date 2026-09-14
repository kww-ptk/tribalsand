-- Migration: staff leave requests (request → approve/decline).
-- Run via /admin/migrate.php. Idempotent. Depends on add_hr_staff + add_attendance.
--
-- A manager records a leave request for a directory staff member; owner/manager
-- approves or declines. Approving stamps the attendance for each non-off day in
-- the range as 'LV' (leave), so the month grid + summaries reflect it.
CREATE TABLE IF NOT EXISTS leave_requests (
    id           SERIAL PRIMARY KEY,
    hr_staff_id  INT NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    start_date   DATE NOT NULL,
    end_date     DATE NOT NULL,
    leave_type   TEXT NOT NULL DEFAULT 'annual',   -- annual | sick | unpaid | other
    reason       TEXT,
    status       TEXT NOT NULL DEFAULT 'pending',  -- pending | approved | declined
    requested_by INT REFERENCES admin_users(id) ON DELETE SET NULL,
    decided_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    decided_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_leave_requests_status ON leave_requests (status, start_date);
CREATE INDEX IF NOT EXISTS idx_leave_requests_staff  ON leave_requests (hr_staff_id);
