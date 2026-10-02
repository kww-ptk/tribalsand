-- Migration: 'storekeeper' job type. Run via /admin/migrate.php, after add_pos_job_types.sql. Idempotent.
--
-- Extends the admin_users.job_type CHECK (last set by add_pos_job_types.sql). A
-- storekeeper is a staff account that runs the stock: the Inventory pages, orders
-- and stock counts, scoped to its assigned properties (can_manage_inventory() in
-- includes/auth.php). No guest messaging, bookings or finance. Drop + recreate so
-- re-running is safe; NULL still means 'frontdesk'.
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_job_type_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_job_type_check
    CHECK (job_type IS NULL OR job_type IN ('frontdesk','housekeeping','laundry','maintenance','gardening','security','driver','shop','spa','kite','storekeeper'));
