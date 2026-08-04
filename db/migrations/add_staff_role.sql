-- Migration: staff role + per-venue scoping on admin_users
-- Run via /admin/migrate.php. Idempotent.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'owner';
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('owner','staff'));
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS name TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS access_code VARCHAR(16) UNIQUE;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE admin_users ALTER COLUMN email DROP NOT NULL;
ALTER TABLE admin_users ALTER COLUMN password_hash DROP NOT NULL;

CREATE TABLE IF NOT EXISTS admin_user_venues (
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    venue_id      INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    PRIMARY KEY (admin_user_id, venue_id)
);
