-- Tribal Sand: multi-room bookings — the holds of one room-combination request
-- share a group reference, so they are held, confirmed and cancelled together.
-- Run via /admin/migrate.php. Idempotent. Before it runs, combinations stay the
-- v1 enquiry (includes/hold-groups.php is pre-migration-safe).
ALTER TABLE holds ADD COLUMN IF NOT EXISTS group_ref VARCHAR(24);
CREATE INDEX IF NOT EXISTS idx_holds_group_ref ON holds (group_ref) WHERE group_ref IS NOT NULL;
