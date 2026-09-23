-- Migration: admin-editable calendar highlights (school holidays, Easter week,
-- events, peak season …) shown on the admin calendars on top of the built-in
-- Kenyan public holidays. Run via /admin/migrate.php. Idempotent.
--
-- date_to is INCLUSIVE — the LAST highlighted day (a one-day highlight has
-- date_from = date_to). This is deliberately different from rates/blocks, whose
-- date_to is the exclusive checkout morning: a highlight marks days, not nights.
--
-- The Kenyan public holidays stay computed in includes/holidays.php (Easter moves
-- every year, so rows would need re-seeding annually); these rows are ADDED to
-- them. Every read is guarded by cal_highlights_supported(), so a deploy that runs
-- before this migration simply shows the public holidays as before.

CREATE TABLE IF NOT EXISTS calendar_highlights (
    id          SERIAL PRIMARY KEY,
    label       TEXT         NOT NULL,
    date_from   DATE         NOT NULL,
    date_to     DATE         NOT NULL,                 -- inclusive last day
    color       TEXT         NOT NULL DEFAULT 'amber'
                CHECK (color IN ('red','amber','green','blue','purple')),
    note        TEXT,
    created_by  INT          REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT calendar_highlights_range CHECK (date_to >= date_from)
);

CREATE INDEX IF NOT EXISTS idx_calendar_highlights_range ON calendar_highlights(date_from, date_to);
