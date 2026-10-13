-- Staff balances (Oct 2026): off days owed and annual leave, kept by the system
-- instead of HR's spreadsheet.
--
--  • hr_balance_moves — an APPEND-ONLY ledger per person: a balance is the SUM of
--    its rows. Reasons: 'opening' (taken from HR's last summary), 'month' (written
--    by Close month — one row per person, balance and month; re-closing a month
--    replaces that month's rows), 'paid_out' (days paid instead of taken),
--    'adjustment' (HR correction, with a note). detail keeps the month's figures
--    (entitled off days, days off taken, overtime hours …) so a balance can always
--    be explained.
--  • hr_month_closes — which months HR has closed, and by whom.
--  • attendance_imports.balances — opening balances read from an uploaded
--    workbook's consolidated summary sheet (applied separately, with a preview).
--
-- Run after add_attendance_import.sql. Idempotent.

CREATE TABLE IF NOT EXISTS hr_balance_moves (
    id           SERIAL PRIMARY KEY,
    hr_staff_id  INT          NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    kind         TEXT         NOT NULL CHECK (kind IN ('off_days', 'annual_leave')),
    reason       TEXT         NOT NULL CHECK (reason IN ('opening', 'month', 'paid_out', 'adjustment')),
    period       CHAR(7),                                -- 'YYYY-MM' for month rows
    effective_on DATE         NOT NULL,                  -- the day the change belongs to (month end for 'month')
    days         NUMERIC(8,3) NOT NULL,
    detail       JSONB        NOT NULL DEFAULT '{}'::jsonb,
    note         TEXT,
    created_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_hr_balance_moves_staff ON hr_balance_moves (hr_staff_id, kind, effective_on);
CREATE UNIQUE INDEX IF NOT EXISTS uq_hr_balance_moves_month
    ON hr_balance_moves (hr_staff_id, kind, period) WHERE reason = 'month';

CREATE TABLE IF NOT EXISTS hr_month_closes (
    period     CHAR(7)     PRIMARY KEY,
    people     INT         NOT NULL DEFAULT 0,
    closed_by  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    closed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE attendance_imports ADD COLUMN IF NOT EXISTS balances JSONB;
