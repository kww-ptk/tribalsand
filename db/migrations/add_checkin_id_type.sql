-- Check-in: Passport OR Kenyan National ID. Each guest's identity document type;
-- the document number keeps living in passport_number (so lists, exports and the
-- admin search keep working). waiver_id_type_snapshot freezes the type at signing,
-- next to the other identity snapshots, so the signed record can never relabel.
-- After add_checkin_record_integrity. Idempotent — safe to re-run.

ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS id_type VARCHAR(16) NOT NULL DEFAULT 'passport';
ALTER TABLE checkin_guests DROP CONSTRAINT IF EXISTS checkin_guests_id_type_check;
ALTER TABLE checkin_guests ADD CONSTRAINT checkin_guests_id_type_check CHECK (id_type IN ('passport', 'kenya_id'));
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_id_type_snapshot VARCHAR(16);
