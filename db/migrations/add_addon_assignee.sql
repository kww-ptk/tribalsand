-- Migration: assignee on guest requests (booking_addons).
-- Run via /admin/migrate.php. Idempotent.
--
-- Phase 2 of the Staff / Team Roles extension: a request can be routed to a
-- specific team member. NULL = unassigned (sits in the manager's queue). The
-- FK SET NULL keeps the request alive if the assigned account is deleted.
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS assigned_to INT REFERENCES admin_users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_booking_addons_assigned ON booking_addons (assigned_to);
