-- Migration: the 'reception' account type on admin_users.
-- Run via /admin/migrate.php. Idempotent.
-- Order: after add_team_roles.sql.
--
-- Reception is a front-of-house tier that sees everything the owner sees EXCEPT
-- the Catalog group, the Admin group and site-content editing: the full
-- Operations group, the Bookings group (holds / calendar / submissions /
-- conflicts) and restaurant Reservations — all scoped to the properties the
-- account is assigned in admin_user_venues.
--
-- No new columns: reception logs in with email + password (login(), which is
-- role-agnostic) and is scoped through the existing admin_user_venues table.
-- Staff access codes stay closed to it — login_staff() hard-codes role='staff'.

-- Expand the role CHECK. Drop+recreate (matches add_team_roles.sql) so re-running is safe.
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check
    CHECK (role IN ('owner','manager','reception','staff'));
