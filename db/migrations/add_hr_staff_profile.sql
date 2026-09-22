-- Migration: employment / contract details for the employee profile (Item 7).
-- Run via /admin/migrate.php AFTER add_hr_staff.sql. Idempotent.
--
-- The directory row (add_hr_staff) holds "who works where". The profile page adds
-- the employment record: which company employs them, contract type and dates, an
-- ID number and free-form HR notes. Every read is guarded by
-- hr_staff_profile_supported() so a pre-migration deploy just hides these fields.

ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS company        TEXT;
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS contract_type  TEXT;   -- Permanent | Contract | Casual | Probation | …
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS contract_start DATE;
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS contract_end   DATE;   -- NULL = open-ended
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS national_id    TEXT;
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS hr_notes       TEXT;
