-- Migration: custom group chats for team messaging (Item 4).
-- Run via /admin/migrate.php AFTER add_internal_messages.sql. Idempotent.
--
-- The existing internal_messages model has two implicit channel kinds:
--   all-team   (channel_venue_id IS NULL)
--   per-venue  (channel_venue_id = <venue>)
-- This adds a THIRD kind: an admin/manager-created group with a chosen set of
-- members. A group message carries group_channel_id (and channel_venue_id NULL).
--
-- The per-user read high-water table (internal_channel_reads.channel_key) is
-- reused unchanged: venue ids are > 0 and the all-team key is 0, so a group uses
-- the negative key -group_id — no collision, no schema change to that table.
--
-- Every read is guarded by internal_group_channels_supported() so a deploy that
-- has add_internal_messages but not this one behaves exactly as before.

CREATE TABLE IF NOT EXISTS internal_channels (
    id         SERIAL PRIMARY KEY,
    name       TEXT NOT NULL,
    created_by INT REFERENCES admin_users(id) ON DELETE SET NULL,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    is_direct  BOOLEAN NOT NULL DEFAULT FALSE,   -- TRUE = a 1:1 direct message (Item 6)
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Idempotent for a DB that already has internal_channels from an earlier run.
ALTER TABLE internal_channels ADD COLUMN IF NOT EXISTS is_direct BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS internal_channel_members (
    channel_id    INT NOT NULL REFERENCES internal_channels(id) ON DELETE CASCADE,
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    PRIMARY KEY (channel_id, admin_user_id)
);
CREATE INDEX IF NOT EXISTS idx_internal_channel_members_user ON internal_channel_members (admin_user_id);

-- A message may now belong to a group channel instead of a venue / all-team.
ALTER TABLE internal_messages
    ADD COLUMN IF NOT EXISTS group_channel_id INT REFERENCES internal_channels(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_internal_messages_group ON internal_messages (group_channel_id, id);
