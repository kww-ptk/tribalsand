-- Check-in deposit card: what the front desk needs to recognise the card, read
-- from the guest's photo (or typed): name on card, card type, LAST 4 digits and
-- expiry. NEVER the full card number — the CHECK constraint refuses anything but
-- exactly four digits, so no code path can store more.
-- After add_checkin_deposit_plan. Idempotent — safe to re-run.

ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_card_name   VARCHAR(120);
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_card_brand  VARCHAR(30);
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_card_last4  CHAR(4);
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_card_expiry CHAR(7);   -- YYYY-MM

ALTER TABLE booking_checkin DROP CONSTRAINT IF EXISTS booking_checkin_card_last4_check;
ALTER TABLE booking_checkin ADD CONSTRAINT booking_checkin_card_last4_check
  CHECK (deposit_card_last4 IS NULL OR deposit_card_last4 ~ '^[0-9]{4}$');
ALTER TABLE booking_checkin DROP CONSTRAINT IF EXISTS booking_checkin_card_expiry_check;
ALTER TABLE booking_checkin ADD CONSTRAINT booking_checkin_card_expiry_check
  CHECK (deposit_card_expiry IS NULL OR deposit_card_expiry ~ '^[0-9]{4}-(0[1-9]|1[0-2])$');
