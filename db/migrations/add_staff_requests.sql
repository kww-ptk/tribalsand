-- Staff requests (Oct 2026): leave_requests grows into ONE request system for
-- annual / sick / day off (recovery) / unpaid / maternity / paternity /
-- compassionate leave, attendance corrections, overtime and shift swaps, with a
-- two-step approval: supervisor → HR.
--
--  status:  pending (waiting for the supervisor) → pending_hr → approved,
--           or declined / cancelled at any point. Old rows keep working: their
--           'pending' is the first step.
--  leave_type now holds the request TYPE (annual, sick, day_off, unpaid,
--           maternity, paternity, compassionate, correction, overtime, swap, other).
--  details  per type: correction {date, in1..out2, original}, overtime {date,
--           hours, worked}, swap {with_staff_id, accepted_at}, half_day, and
--           'replaced' = what each stamped day held before (so a cancellation
--           puts it back exactly).
--  requested_by = who ENTERED it (the employee, a manager or HR — "on behalf");
--  hr_staff_id  = who it is FOR.
--  attendance.request_id marks the days an approved request wrote.
--
-- Run after add_leave_requests.sql and add_attendance_import.sql. Idempotent.

ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS details        JSONB NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS supervisor_by  INT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS supervisor_at  TIMESTAMPTZ;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS hr_by          INT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS hr_at          TIMESTAMPTZ;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS decline_reason TEXT;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS cancelled_by   INT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS cancelled_at   TIMESTAMPTZ;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS doc_id         INT;   -- a private hr_staff_documents row (sick note)
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS updated_at     TIMESTAMPTZ NOT NULL DEFAULT now();

ALTER TABLE attendance ADD COLUMN IF NOT EXISTS request_id INT REFERENCES leave_requests(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_attendance_request ON attendance (request_id) WHERE request_id IS NOT NULL;
