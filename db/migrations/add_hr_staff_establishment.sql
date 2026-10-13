-- Staff establishment (Oct 2026): every team member belongs to an employing
-- COMPANY and carries that company's PAYROLL NUMBER. Payroll numbers repeat
-- across companies ("01" exists at Tribal Sand Holidays AND at Retreats Zuri),
-- so a person is identified by (company, payroll no.), never the number alone.
--
-- Also: a manager can be given a company's staff to manage (hr_company_managers)
-- — Tribal Sand Limited (kite school + transfers) has no property, so venue
-- scoping alone would show its manager nobody.
--
-- Run after add_companies.sql and add_hr_staff_profile.sql. Idempotent.

ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS company_id  INT REFERENCES companies(id) ON DELETE RESTRICT;
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS payroll_no  TEXT;   -- exactly as the company writes it (MKH032, 03, 1)
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS surname     TEXT;
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS first_names TEXT;

CREATE INDEX IF NOT EXISTS idx_hr_staff_company ON hr_staff (company_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_hr_staff_company_payroll
    ON hr_staff (company_id, UPPER(payroll_no))
    WHERE company_id IS NOT NULL AND payroll_no IS NOT NULL AND payroll_no <> '';

CREATE TABLE IF NOT EXISTS hr_company_managers (
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    company_id    INT NOT NULL REFERENCES companies(id)   ON DELETE CASCADE,
    PRIMARY KEY (admin_user_id, company_id)
);
CREATE INDEX IF NOT EXISTS idx_hr_company_managers_company ON hr_company_managers (company_id);
