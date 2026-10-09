-- Check-in deposit step: a guest who can't upload a card photo says how they will
-- pay the security deposit at arrival (card or cash). Run after add_checkin_deposit.sql.
-- Read through checkin_deposit_plan_supported(); before this runs the choice is not offered.
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_plan    TEXT;
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_plan_at TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'booking_checkin_deposit_plan_check') THEN
        ALTER TABLE booking_checkin ADD CONSTRAINT booking_checkin_deposit_plan_check
            CHECK (deposit_plan IS NULL OR deposit_plan IN ('card_at_arrival', 'cash_at_arrival'));
    END IF;
END $$;
