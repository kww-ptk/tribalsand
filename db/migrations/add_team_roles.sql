-- Migration: team roles (manager) + operational job types on admin_users.
-- Run via /admin/migrate.php. Idempotent.
--
-- Phase 1 of the Staff / Team Roles extension:
--   • role gains 'manager' (per-property ops tier, between owner and staff)
--   • job_type gives a staff member an operational specialty that drives their
--     home + nav. NULL is treated as 'frontdesk' in code (backward-compatible:
--     every existing staff account keeps landing on Front Desk).

-- Expand the role CHECK. Drop+recreate (matches add_staff_role.sql) so re-running is safe.
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('owner','manager','staff'));

-- Operational specialty. NULL = frontdesk (existing staff have NULL here).
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS job_type TEXT;
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_job_type_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_job_type_check
    CHECK (job_type IS NULL OR job_type IN ('frontdesk','housekeeping','maintenance','gardening','security','driver'));
