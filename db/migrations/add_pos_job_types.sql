-- Migration: POS job types — 'shop', 'spa', 'kite'. Run via /admin/migrate.php, after add_pos.sql. Idempotent.
--
-- Extends the admin_users.job_type CHECK (last set by add_laundry_job.sql). Staff
-- with one of these jobs land on the till (/pos/) after signing in and get no guest
-- messaging. Drop + recreate so re-running is safe; NULL still means 'frontdesk'.
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_job_type_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_job_type_check
    CHECK (job_type IS NULL OR job_type IN ('frontdesk','housekeeping','laundry','maintenance','gardening','security','driver','shop','spa','kite'));
