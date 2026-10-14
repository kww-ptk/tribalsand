-- Gate arrivals (Oct 2026): gate security checks a booked party in (and out)
-- at the gate, usually by scanning the gate pass QR the guest gets in their
-- booking page once online check-in is done. Stored on the booking so the
-- Front desk, the Gate and the Dashboard all read one record.
-- gate_people = how many people security let in (the party can be smaller than
-- the booking on the day). Run after add_checkin.sql. Idempotent.

ALTER TABLE holds ADD COLUMN IF NOT EXISTS gate_arrived_at  TIMESTAMPTZ;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS gate_arrived_by  INT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS gate_people      SMALLINT;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS gate_departed_at TIMESTAMPTZ;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS gate_departed_by INT REFERENCES admin_users(id) ON DELETE SET NULL;
