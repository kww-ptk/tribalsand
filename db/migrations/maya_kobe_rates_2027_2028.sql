-- Maya Kobe — DIRECT nightly rates (KES, bed & breakfast), 11 Jan 2027 → last night 10 Jan 2029.
--
-- Source: the owner's rate sheet "MAYA KOBE - JAN 11 27 - 10 JAN 29.xlsx", DIRECT
-- column only (TA and OTA are not stored). Per night:
--                         Standard   Mid       Peak
--   Haze / Drift / Tide / Glow   40,560    48,360    63,960
--   Prestige Suite               87,360    95,160   110,760
--   Full buyout (sum of all 5)  249,600   288,600   366,600
-- Owner decisions (2026-09-28), where the sheet was inconsistent:
--   * August 2027 + 2028 are "mid season" but the sheet priced them at 40,300 /
--     79,300 (below standard). Owner: use the regular mid-season rate.
--   * The 2028 sheet skips 13 April ("1-12 April", then "14-19 April").
--     Owner: 13 April is mid season (1-13 April, as in the Zuri sheet).
--   * The sheet has no buyout row. Owner: buyout = sum of the five suites.
--
-- Rates carry no currency of their own — they take the room's price_currency —
-- so the gate refuses to run unless all six rooms exist and price in KES.
-- (Production does, as of 2026-09-28.)
--
-- date_to is EXCLUSIVE (the checkout morning). Adjacent periods with the same
-- price + season are merged into one row. NOT stored (no column for it): the
-- minimum stay — 2 nights mid/standard, 5 nights peak.
--
-- Writes leave no overlaps, like rates_apply_ranges(): any existing row on these
-- rooms touching the window is trimmed, split or deleted first (the four cases
-- of rates_clear_span(), spanning first). Idempotent — a re-run clears its own
-- rows and writes the same ones again.

BEGIN;

DROP TABLE IF EXISTS pg_temp.mk_rate_targets;
CREATE TEMP TABLE mk_rate_targets (venue_slug text, room_slug text) ON COMMIT DROP;
INSERT INTO mk_rate_targets VALUES
    ('maya-kobe', 'maya-kobe-haze'),
    ('maya-kobe', 'maya-kobe-drift'),
    ('maya-kobe', 'maya-kobe-tide'),
    ('maya-kobe', 'maya-kobe-glow'),
    ('maya-kobe', 'maya-kobe-prestige'),
    ('maya-kobe', 'maya-kobe-buyout');

DO $gate$
DECLARE missing text; wrong text;
BEGIN
  SELECT string_agg(t.venue_slug || '/' || t.room_slug, ', ') INTO missing
    FROM mk_rate_targets t
   WHERE NOT EXISTS (SELECT 1 FROM rooms r JOIN venues v ON v.id = r.venue_id
                      WHERE v.slug = t.venue_slug AND r.slug = t.room_slug);
  IF missing IS NOT NULL THEN
    RAISE EXCEPTION 'Rooms missing: % - nothing was changed', missing;
  END IF;
  SELECT string_agg(r.slug || ' (' || COALESCE(r.price_currency, 'none') || ')', ', ') INTO wrong
    FROM mk_rate_targets t
    JOIN venues v ON v.slug = t.venue_slug
    JOIN rooms r ON r.venue_id = v.id AND r.slug = t.room_slug
   WHERE COALESCE(r.price_currency, '') <> 'KES';
  IF wrong IS NOT NULL THEN
    RAISE EXCEPTION 'These rooms do not price in KES, so KES rates would be read in the wrong currency: % - nothing was changed', wrong;
  END IF;
END
$gate$;

DROP TABLE IF EXISTS pg_temp.mk_direct_rates;
CREATE TEMP TABLE mk_direct_rates (slug text, date_from date, date_to date, price numeric, label text) ON COMMIT DROP;
INSERT INTO mk_direct_rates (slug, date_from, date_to, price, label) VALUES
  ('maya-kobe-haze', '2027-01-11', '2027-03-26', 48360, 'Mid season'),
  ('maya-kobe-haze', '2027-03-26', '2027-04-05', 63960, 'Peak season'),
  ('maya-kobe-haze', '2027-04-05', '2027-05-01', 48360, 'Mid season'),
  ('maya-kobe-haze', '2027-05-01', '2027-08-01', 40560, 'Standard season'),
  ('maya-kobe-haze', '2027-08-01', '2027-09-01', 48360, 'Mid season'),
  ('maya-kobe-haze', '2027-09-01', '2027-10-01', 40560, 'Standard season'),
  ('maya-kobe-haze', '2027-10-01', '2027-11-01', 48360, 'Mid season'),
  ('maya-kobe-haze', '2027-11-01', '2027-12-01', 40560, 'Standard season'),
  ('maya-kobe-haze', '2027-12-01', '2027-12-20', 48360, 'Mid season'),
  ('maya-kobe-haze', '2027-12-20', '2028-01-10', 63960, 'Peak season'),
  ('maya-kobe-haze', '2028-01-10', '2028-04-14', 48360, 'Mid season'),
  ('maya-kobe-haze', '2028-04-14', '2028-04-20', 63960, 'Peak season'),
  ('maya-kobe-haze', '2028-04-20', '2028-05-01', 48360, 'Mid season'),
  ('maya-kobe-haze', '2028-05-01', '2028-08-01', 40560, 'Standard season'),
  ('maya-kobe-haze', '2028-08-01', '2028-09-01', 48360, 'Mid season'),
  ('maya-kobe-haze', '2028-09-01', '2028-10-01', 40560, 'Standard season'),
  ('maya-kobe-haze', '2028-10-01', '2028-11-01', 48360, 'Mid season'),
  ('maya-kobe-haze', '2028-11-01', '2028-12-01', 40560, 'Standard season'),
  ('maya-kobe-haze', '2028-12-01', '2028-12-20', 48360, 'Mid season'),
  ('maya-kobe-haze', '2028-12-20', '2029-01-11', 63960, 'Peak season'),
  ('maya-kobe-drift', '2027-01-11', '2027-03-26', 48360, 'Mid season'),
  ('maya-kobe-drift', '2027-03-26', '2027-04-05', 63960, 'Peak season'),
  ('maya-kobe-drift', '2027-04-05', '2027-05-01', 48360, 'Mid season'),
  ('maya-kobe-drift', '2027-05-01', '2027-08-01', 40560, 'Standard season'),
  ('maya-kobe-drift', '2027-08-01', '2027-09-01', 48360, 'Mid season'),
  ('maya-kobe-drift', '2027-09-01', '2027-10-01', 40560, 'Standard season'),
  ('maya-kobe-drift', '2027-10-01', '2027-11-01', 48360, 'Mid season'),
  ('maya-kobe-drift', '2027-11-01', '2027-12-01', 40560, 'Standard season'),
  ('maya-kobe-drift', '2027-12-01', '2027-12-20', 48360, 'Mid season'),
  ('maya-kobe-drift', '2027-12-20', '2028-01-10', 63960, 'Peak season'),
  ('maya-kobe-drift', '2028-01-10', '2028-04-14', 48360, 'Mid season'),
  ('maya-kobe-drift', '2028-04-14', '2028-04-20', 63960, 'Peak season'),
  ('maya-kobe-drift', '2028-04-20', '2028-05-01', 48360, 'Mid season'),
  ('maya-kobe-drift', '2028-05-01', '2028-08-01', 40560, 'Standard season'),
  ('maya-kobe-drift', '2028-08-01', '2028-09-01', 48360, 'Mid season'),
  ('maya-kobe-drift', '2028-09-01', '2028-10-01', 40560, 'Standard season'),
  ('maya-kobe-drift', '2028-10-01', '2028-11-01', 48360, 'Mid season'),
  ('maya-kobe-drift', '2028-11-01', '2028-12-01', 40560, 'Standard season'),
  ('maya-kobe-drift', '2028-12-01', '2028-12-20', 48360, 'Mid season'),
  ('maya-kobe-drift', '2028-12-20', '2029-01-11', 63960, 'Peak season'),
  ('maya-kobe-tide', '2027-01-11', '2027-03-26', 48360, 'Mid season'),
  ('maya-kobe-tide', '2027-03-26', '2027-04-05', 63960, 'Peak season'),
  ('maya-kobe-tide', '2027-04-05', '2027-05-01', 48360, 'Mid season'),
  ('maya-kobe-tide', '2027-05-01', '2027-08-01', 40560, 'Standard season'),
  ('maya-kobe-tide', '2027-08-01', '2027-09-01', 48360, 'Mid season'),
  ('maya-kobe-tide', '2027-09-01', '2027-10-01', 40560, 'Standard season'),
  ('maya-kobe-tide', '2027-10-01', '2027-11-01', 48360, 'Mid season'),
  ('maya-kobe-tide', '2027-11-01', '2027-12-01', 40560, 'Standard season'),
  ('maya-kobe-tide', '2027-12-01', '2027-12-20', 48360, 'Mid season'),
  ('maya-kobe-tide', '2027-12-20', '2028-01-10', 63960, 'Peak season'),
  ('maya-kobe-tide', '2028-01-10', '2028-04-14', 48360, 'Mid season'),
  ('maya-kobe-tide', '2028-04-14', '2028-04-20', 63960, 'Peak season'),
  ('maya-kobe-tide', '2028-04-20', '2028-05-01', 48360, 'Mid season'),
  ('maya-kobe-tide', '2028-05-01', '2028-08-01', 40560, 'Standard season'),
  ('maya-kobe-tide', '2028-08-01', '2028-09-01', 48360, 'Mid season'),
  ('maya-kobe-tide', '2028-09-01', '2028-10-01', 40560, 'Standard season'),
  ('maya-kobe-tide', '2028-10-01', '2028-11-01', 48360, 'Mid season'),
  ('maya-kobe-tide', '2028-11-01', '2028-12-01', 40560, 'Standard season'),
  ('maya-kobe-tide', '2028-12-01', '2028-12-20', 48360, 'Mid season'),
  ('maya-kobe-tide', '2028-12-20', '2029-01-11', 63960, 'Peak season'),
  ('maya-kobe-glow', '2027-01-11', '2027-03-26', 48360, 'Mid season'),
  ('maya-kobe-glow', '2027-03-26', '2027-04-05', 63960, 'Peak season'),
  ('maya-kobe-glow', '2027-04-05', '2027-05-01', 48360, 'Mid season'),
  ('maya-kobe-glow', '2027-05-01', '2027-08-01', 40560, 'Standard season'),
  ('maya-kobe-glow', '2027-08-01', '2027-09-01', 48360, 'Mid season'),
  ('maya-kobe-glow', '2027-09-01', '2027-10-01', 40560, 'Standard season'),
  ('maya-kobe-glow', '2027-10-01', '2027-11-01', 48360, 'Mid season'),
  ('maya-kobe-glow', '2027-11-01', '2027-12-01', 40560, 'Standard season'),
  ('maya-kobe-glow', '2027-12-01', '2027-12-20', 48360, 'Mid season'),
  ('maya-kobe-glow', '2027-12-20', '2028-01-10', 63960, 'Peak season'),
  ('maya-kobe-glow', '2028-01-10', '2028-04-14', 48360, 'Mid season'),
  ('maya-kobe-glow', '2028-04-14', '2028-04-20', 63960, 'Peak season'),
  ('maya-kobe-glow', '2028-04-20', '2028-05-01', 48360, 'Mid season'),
  ('maya-kobe-glow', '2028-05-01', '2028-08-01', 40560, 'Standard season'),
  ('maya-kobe-glow', '2028-08-01', '2028-09-01', 48360, 'Mid season'),
  ('maya-kobe-glow', '2028-09-01', '2028-10-01', 40560, 'Standard season'),
  ('maya-kobe-glow', '2028-10-01', '2028-11-01', 48360, 'Mid season'),
  ('maya-kobe-glow', '2028-11-01', '2028-12-01', 40560, 'Standard season'),
  ('maya-kobe-glow', '2028-12-01', '2028-12-20', 48360, 'Mid season'),
  ('maya-kobe-glow', '2028-12-20', '2029-01-11', 63960, 'Peak season'),
  ('maya-kobe-prestige', '2027-01-11', '2027-03-26', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2027-03-26', '2027-04-05', 110760, 'Peak season'),
  ('maya-kobe-prestige', '2027-04-05', '2027-05-01', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2027-05-01', '2027-08-01', 87360, 'Standard season'),
  ('maya-kobe-prestige', '2027-08-01', '2027-09-01', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2027-09-01', '2027-10-01', 87360, 'Standard season'),
  ('maya-kobe-prestige', '2027-10-01', '2027-11-01', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2027-11-01', '2027-12-01', 87360, 'Standard season'),
  ('maya-kobe-prestige', '2027-12-01', '2027-12-20', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2027-12-20', '2028-01-10', 110760, 'Peak season'),
  ('maya-kobe-prestige', '2028-01-10', '2028-04-14', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2028-04-14', '2028-04-20', 110760, 'Peak season'),
  ('maya-kobe-prestige', '2028-04-20', '2028-05-01', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2028-05-01', '2028-08-01', 87360, 'Standard season'),
  ('maya-kobe-prestige', '2028-08-01', '2028-09-01', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2028-09-01', '2028-10-01', 87360, 'Standard season'),
  ('maya-kobe-prestige', '2028-10-01', '2028-11-01', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2028-11-01', '2028-12-01', 87360, 'Standard season'),
  ('maya-kobe-prestige', '2028-12-01', '2028-12-20', 95160, 'Mid season'),
  ('maya-kobe-prestige', '2028-12-20', '2029-01-11', 110760, 'Peak season'),
  ('maya-kobe-buyout', '2027-01-11', '2027-03-26', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2027-03-26', '2027-04-05', 366600, 'Peak season'),
  ('maya-kobe-buyout', '2027-04-05', '2027-05-01', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2027-05-01', '2027-08-01', 249600, 'Standard season'),
  ('maya-kobe-buyout', '2027-08-01', '2027-09-01', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2027-09-01', '2027-10-01', 249600, 'Standard season'),
  ('maya-kobe-buyout', '2027-10-01', '2027-11-01', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2027-11-01', '2027-12-01', 249600, 'Standard season'),
  ('maya-kobe-buyout', '2027-12-01', '2027-12-20', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2027-12-20', '2028-01-10', 366600, 'Peak season'),
  ('maya-kobe-buyout', '2028-01-10', '2028-04-14', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2028-04-14', '2028-04-20', 366600, 'Peak season'),
  ('maya-kobe-buyout', '2028-04-20', '2028-05-01', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2028-05-01', '2028-08-01', 249600, 'Standard season'),
  ('maya-kobe-buyout', '2028-08-01', '2028-09-01', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2028-09-01', '2028-10-01', 249600, 'Standard season'),
  ('maya-kobe-buyout', '2028-10-01', '2028-11-01', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2028-11-01', '2028-12-01', 249600, 'Standard season'),
  ('maya-kobe-buyout', '2028-12-01', '2028-12-20', 288600, 'Mid season'),
  ('maya-kobe-buyout', '2028-12-20', '2029-01-11', 366600, 'Peak season');

DROP TABLE IF EXISTS pg_temp.mk_rate_rooms;
CREATE TEMP TABLE mk_rate_rooms ON COMMIT DROP AS
  SELECT r.id, r.slug FROM mk_rate_targets t
    JOIN venues v ON v.slug = t.venue_slug
    JOIN rooms r ON r.venue_id = v.id AND r.slug = t.room_slug;

-- Clear the window [2027-01-11, 2029-01-11) on these rooms.
-- 1. A row spanning the whole window: keep its tail as a new row (created_at kept) ...
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
  SELECT room_id, DATE '2029-01-11', date_to, price_amount, label, created_at FROM rates
   WHERE room_id IN (SELECT id FROM mk_rate_rooms)
     AND date_from < DATE '2027-01-11' AND date_to > DATE '2029-01-11';
-- 2. ... and end it (and any row overlapping the start) at the window's first night.
UPDATE rates SET date_to = DATE '2027-01-11'
 WHERE room_id IN (SELECT id FROM mk_rate_rooms)
   AND date_from < DATE '2027-01-11' AND date_to > DATE '2027-01-11';
-- 3. A row overlapping the end starts after the window instead.
UPDATE rates SET date_from = DATE '2029-01-11'
 WHERE room_id IN (SELECT id FROM mk_rate_rooms)
   AND date_from >= DATE '2027-01-11' AND date_from < DATE '2029-01-11' AND date_to > DATE '2029-01-11';
-- 4. Anything left inside the window goes.
DELETE FROM rates
 WHERE room_id IN (SELECT id FROM mk_rate_rooms)
   AND date_from >= DATE '2027-01-11' AND date_to <= DATE '2029-01-11';

INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
  SELECT mr.id, z.date_from, z.date_to, z.price, z.label
    FROM mk_direct_rates z JOIN mk_rate_rooms mr ON mr.slug = z.slug
   ORDER BY mr.id, z.date_from;

COMMIT;
