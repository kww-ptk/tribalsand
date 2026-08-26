-- Tribal Sand: security-deposit step for pre-arrival check-in.
-- Run via /admin/migrate.php. Idempotent — safe to re-run.
-- Sorts after add_checkin_record_integrity.sql.
--
-- deposit_card_file_key  booking-level: private storage key for the credit-card
--                        image the lead uploads. NEVER a public URL — served only
--                        through admin/checkin-file.php?kind=deposit, exactly like
--                        the passport scan. NULL until uploaded.
--
-- venues.deposit_amount   per-property security-deposit amount (NULL/0 = no set
--                         amount → the step shows the "bring your card" copy with
--                         no figure). Payment is taken at the property; this is a
--                         pre-arrival heads-up, not a charge.
-- venues.deposit_currency ISO code the amount is quoted in (default USD — villa
--                         deposits are typically quoted in USD).
ALTER TABLE booking_checkin
    ADD COLUMN IF NOT EXISTS deposit_card_file_key TEXT;

ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS deposit_amount   NUMERIC(12,2),
    ADD COLUMN IF NOT EXISTS deposit_currency VARCHAR(3) NOT NULL DEFAULT 'USD';
