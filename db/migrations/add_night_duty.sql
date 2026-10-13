-- Night duty / night allowance (Oct 2026, Zuri first): a worked day can be a
-- NIGHT DUTY. NULL = decided by the times (a shift that runs past midnight —
-- every security night, every kiosk night shift); TRUE / FALSE = HR set it on the
-- day (the daily editor's moon toggle, or the workbook's "N" code). The amount per
-- night is a setting per property (night_allowance), shown on the Month summary.
-- Run after add_attendance.sql. Idempotent.

ALTER TABLE attendance ADD COLUMN IF NOT EXISTS night_duty BOOLEAN;
