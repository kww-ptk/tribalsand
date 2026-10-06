-- Migration: per-property guest extras (Oct 2026). Run via /admin/migrate.php,
-- after add_upsells.sql and add_booking_extras.sql. Idempotent.
--
-- The owner chooses, per property (Admin → Properties → property → Guest extras),
-- which activities, wellness treatments and transfers its guests are offered in
-- their booking page, in what order, which are featured (home screen + booking
-- email) and when (before arrival / during the stay / any time). The extras
-- themselves stay where they are — tours and service_options — so a price is set
-- once. A property with no rows here keeps the old behaviour (everything its
-- guests could request before, the booking-flow add-ons featured).
--
-- venues.extras_in_email      — put the featured extras in the hold/confirmation emails
-- venues.extras_reminder_days — email "add to your stay" N days before arrival (0 = off)
-- holds.extras_reminder_sent_at — the reminder went out (sent once per booking)

CREATE TABLE IF NOT EXISTS venue_extras (
    id           SERIAL PRIMARY KEY,
    venue_id     INTEGER NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('tour', 'transfer')),
    ref_id       INTEGER NOT NULL,
    is_shown     BOOLEAN NOT NULL DEFAULT TRUE,
    is_featured  BOOLEAN NOT NULL DEFAULT FALSE,
    offer_when   TEXT    NOT NULL DEFAULT 'any' CHECK (offer_when IN ('any', 'before', 'during')),
    sort_order   INTEGER NOT NULL DEFAULT 0,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (venue_id, kind, ref_id)
);
CREATE INDEX IF NOT EXISTS idx_venue_extras_venue ON venue_extras (venue_id, sort_order);

ALTER TABLE venues ADD COLUMN IF NOT EXISTS extras_in_email BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE venues ADD COLUMN IF NOT EXISTS extras_reminder_days INTEGER NOT NULL DEFAULT 3;
ALTER TABLE holds  ADD COLUMN IF NOT EXISTS extras_reminder_sent_at TIMESTAMPTZ;
