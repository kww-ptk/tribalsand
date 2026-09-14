-- Migration: internal team messaging (team ↔ team), separate from the guest
-- ↔ staff booking_messages inbox. Run via /admin/migrate.php. Idempotent.
--
-- Channels are keyed off the property/team structure: one channel per venue
-- plus an all-team channel (channel_venue_id IS NULL). Who can see a venue
-- channel = the accounts assigned to that venue (owner sees all). Unread is
-- tracked per user per channel via a last-read high-water mark.
CREATE TABLE IF NOT EXISTS internal_messages (
    id               SERIAL PRIMARY KEY,
    channel_venue_id INT REFERENCES venues(id) ON DELETE CASCADE,   -- NULL = all-team channel
    sender_admin_id  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    body             TEXT NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Poll "messages in this channel since <id>".
CREATE INDEX IF NOT EXISTS idx_internal_messages_channel ON internal_messages (channel_venue_id, id);

-- Per-user, per-channel read high-water mark. channel_key = venue_id (>0), or 0
-- for the all-team channel (venue ids are always > 0, so 0 is unambiguous).
CREATE TABLE IF NOT EXISTS internal_channel_reads (
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    channel_key   INT NOT NULL,
    last_read_id  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (admin_user_id, channel_key)
);
