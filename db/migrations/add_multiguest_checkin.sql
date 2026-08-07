-- Migration: multiple guests per booking. Run via /admin/migrate.php. Idempotent.
-- Party size (adults) lives on the hold; checkin_guests already holds N rows
-- (add_checkin.sql). This adds a per-guest waiver signature and child support.

ALTER TABLE holds ADD COLUMN IF NOT EXISTS guest_count INT NOT NULL DEFAULT 1;  -- number of adults

-- Per-guest waiver signature + children on the existing checkin_guests table.
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS is_child           BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS parent_guest_id    INT REFERENCES checkin_guests(id) ON DELETE CASCADE;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS date_of_birth      DATE;               -- optional (children)
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_name TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_at   TIMESTAMPTZ;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_ip   TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_version     TEXT;

CREATE INDEX IF NOT EXISTS idx_checkin_guests_parent ON checkin_guests (parent_guest_id);

-- Best-effort backfill of the adult count from the originating enquiry, where known.
UPDATE holds h
   SET guest_count = GREATEST(1, LEAST(s.guests_adults, 12))
  FROM submissions s
 WHERE h.submission_id = s.id
   AND s.guests_adults IS NOT NULL
   AND h.guest_count = 1;
