-- Freeze WHICH scan a waiver was signed with. A photo retaken after signing used
-- to delete the old file, so the signed record pointed at a different picture.
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_scan_snapshot TEXT;
DO $$ BEGIN
  IF to_regclass('public.registered_visitors') IS NOT NULL THEN
    ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS waiver_photo_snapshot TEXT;
  END IF;
END $$;
