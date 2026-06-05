-- iCal channel conflict tracking
-- A conflict is created when an OTA block overlaps an existing hold/booked block
CREATE TABLE IF NOT EXISTS channel_conflicts (
    id              SERIAL PRIMARY KEY,
    ical_feed_id    INTEGER REFERENCES ical_feeds(id) ON DELETE SET NULL,
    unit_id         INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    date_from       DATE NOT NULL,
    date_to         DATE NOT NULL,  -- exclusive (day after last night)
    hold_id         INTEGER REFERENCES holds(id) ON DELETE SET NULL,
    ota_summary     VARCHAR(500) NOT NULL DEFAULT '',
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','resolved_keep_hold','resolved_keep_ota')),
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    resolved_at     TIMESTAMP WITH TIME ZONE,
    resolved_by     INTEGER REFERENCES admin_users(id) ON DELETE SET NULL,
    resolution_notes TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_conflicts_status  ON channel_conflicts(status);
CREATE INDEX IF NOT EXISTS idx_conflicts_unit    ON channel_conflicts(unit_id);
CREATE INDEX IF NOT EXISTS idx_conflicts_created ON channel_conflicts(created_at DESC);
