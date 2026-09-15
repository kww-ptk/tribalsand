-- Migration: add the 'laundry' operational job type.
-- Run via /admin/migrate.php. Idempotent.
--
-- Extends the admin_users.job_type CHECK first set in add_team_roles.sql. Laundry
-- becomes a first-class ops specialty (its own focused My Work screen), alongside
-- housekeeping/maintenance/gardening/driver. Drop+recreate so re-running is safe;
-- NULL still means 'frontdesk' in code.
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_job_type_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_job_type_check
    CHECK (job_type IS NULL OR job_type IN ('frontdesk','housekeeping','laundry','maintenance','gardening','security','driver'));
