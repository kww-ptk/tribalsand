-- Two-way iCal sync with Airbnb / Booking.com / VRBO / Expedia — tracking.
-- After add_availability + add_channel_conflicts. Additive and idempotent.
--
-- Before this, an imported OTA booking was a plain 'blocked' row with nothing
-- tying it to the feed that brought it in, so a booking CANCELLED or MOVED on
-- the OTA stayed blocked on our calendar for good (and, through our export
-- feed, on every other channel too). Each imported block now remembers its feed
-- and the event's UID, so the sync can remove or move it when the OTA does.

ALTER TABLE availability_blocks
    ADD COLUMN IF NOT EXISTS ical_feed_id INT REFERENCES ical_feeds(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS ical_uid     TEXT;

CREATE INDEX IF NOT EXISTS idx_ab_ical_feed
    ON availability_blocks(ical_feed_id) WHERE ical_feed_id IS NOT NULL;

-- What the last sync of each feed found — shown on Admin → iCal feeds so a
-- broken link is visible instead of silently "Synced".
ALTER TABLE ical_feeds
    ADD COLUMN IF NOT EXISTS last_status      VARCHAR(10),
    ADD COLUMN IF NOT EXISTS last_error       TEXT,
    ADD COLUMN IF NOT EXISTS last_event_count INT,
    ADD COLUMN IF NOT EXISTS last_ok_at       TIMESTAMPTZ;

-- Echoes: an OTA re-exporting dates WE sent it (our booking, or another
-- channel's) back to us. Remembered per feed + event UID so that, once the
-- original goes away, the leftover echo is not imported as a new booking — that
-- would keep the dates blocked forever, bouncing between the channels.
-- Created LAST: ical_tracking_supported() probes this table.
CREATE TABLE IF NOT EXISTS ical_echoes (
    feed_id        INT  NOT NULL REFERENCES ical_feeds(id) ON DELETE CASCADE,
    uid            TEXT NOT NULL,
    date_from      DATE NOT NULL,
    date_to        DATE NOT NULL,
    origin_gone_at TIMESTAMPTZ,
    seen_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (feed_id, uid)
);
