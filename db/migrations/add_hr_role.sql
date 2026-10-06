-- Migration: the 'hr' account type on admin_users (Oct 2026).
-- Run via /admin/migrate.php. Idempotent. Order: after add_reception_role.sql.
--
-- HR is the people admin: staff directory, employee profiles + private documents,
-- attendance (times, leave, clock cards and kiosks) for every property. It logs in
-- with email + password like reception (login() is role-agnostic) and is not scoped
-- to properties (admin_venue_ids() returns null for HR). It never sees login
-- accounts, Access by role, bookings, money or settings.
--
-- Expand the role CHECK. Drop+recreate (matches add_reception_role.sql) so re-running is safe.
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check
    CHECK (role IN ('owner','manager','reception','hr','staff'));
