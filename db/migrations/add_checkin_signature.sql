-- Signature & legal consent: per-guest drawn signature + frozen legal snapshot.
-- Idempotent — safe to run multiple times. Apply AFTER add_checkin.sql and
-- add_multiguest_checkin.sql (they create holds check-in cols + checkin_guests).
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signature         TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_terms_snapshot    TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_user_agent TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_method     TEXT;
