-- Activities: "Only for booked guests". A ticked activity is still offered in the
-- guest portal, the check-in "Add to your stay" step and the booking emails, but
-- never on the public website (Activities page, "Pair your stay" section, site
-- search, the public enquiry form and booking-form add-ons, the public concierge's
-- description index). Read through tours_public_sql() in includes/db.php.
-- Idempotent — safe to re-run.

ALTER TABLE tours ADD COLUMN IF NOT EXISTS guests_only BOOLEAN NOT NULL DEFAULT FALSE;
