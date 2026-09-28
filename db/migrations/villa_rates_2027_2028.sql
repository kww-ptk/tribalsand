-- Enkare Bofa, Sandbox and My Amani — DIRECT nightly rates (KES, whole villa),
-- 11 Jan 2027 → last night 10 Jan 2029.
--
-- Source: the owner's rate sheets "EK & SD JAN 11 27 - 10 jan 2029.xlsx" and
-- "MY AMANI -  JAN 11 27 - JAN 10 29.xlsx", DIRECT column only (TA and OTA are
-- not stored). Per night:
--                 Standard   Mid       Peak     Peak 20 Dec – 10 Jan*
--   Enkare Bofa    89,880    97,812   143,400   119,500
--   Sandbox        71,940    78,252   114,720    95,600
--   My Amani      180,000   240,000   250,000   250,000
-- * The Enkare/Sandbox sheet prices 20–31 Dec 2027, 20–31 Dec 2028 and
--   1–10 Jan 2029 lower than the other peak periods (Easter, 1–9 Jan 2028).
--   Owner confirmed 2026-09-28: use those December rows exactly as written.
-- My Amani's last sheet row reads "10 Jan 29"; owner confirmed it means
-- 1–10 Jan 2029 at peak, matching the other sheets.
--
-- Rates carry no currency of their own — they take the room's price_currency —
-- so the gate refuses to run unless all three rooms exist and price in KES.
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

DROP TABLE IF EXISTS pg_temp.villa_rate_targets;
CREATE TEMP TABLE villa_rate_targets (venue_slug text, room_slug text) ON COMMIT DROP;
INSERT INTO villa_rate_targets VALUES
    ('enkare-bofa', 'enkare-bofa'),
    ('sandbox', 'sandbox'),
    ('my-amani', 'my-amani-full-rental');

DO $gate$
DECLARE missing text; wrong text;
BEGIN
  SELECT string_agg(t.venue_slug || '/' || t.room_slug, ', ') INTO missing
    FROM villa_rate_targets t
   WHERE NOT EXISTS (SELECT 1 FROM rooms r JOIN venues v ON v.id = r.venue_id
                      WHERE v.slug = t.venue_slug AND r.slug = t.room_slug);
  IF missing IS NOT NULL THEN
    RAISE EXCEPTION 'Rooms missing: % - nothing was changed', missing;
  END IF;
  SELECT string_agg(r.slug || ' (' || COALESCE(r.price_currency, 'none') || ')', ', ') INTO wrong
    FROM villa_rate_targets t
    JOIN venues v ON v.slug = t.venue_slug
    JOIN rooms r ON r.venue_id = v.id AND r.slug = t.room_slug
   WHERE COALESCE(r.price_currency, '') <> 'KES';
  IF wrong IS NOT NULL THEN
    RAISE EXCEPTION 'These rooms do not price in KES, so KES rates would be read in the wrong currency: % - nothing was changed', wrong;
  END IF;
END
$gate$;

DROP TABLE IF EXISTS pg_temp.villa_direct_rates;
CREATE TEMP TABLE villa_direct_rates (slug text, date_from date, date_to date, price numeric, label text) ON COMMIT DROP;
INSERT INTO villa_direct_rates (slug, date_from, date_to, price, label) VALUES
  ('enkare-bofa', '2027-01-11', '2027-03-26', 97812, 'Mid season'),
  ('enkare-bofa', '2027-03-26', '2027-04-05', 143400, 'Peak season'),
  ('enkare-bofa', '2027-04-05', '2027-05-01', 97812, 'Mid season'),
  ('enkare-bofa', '2027-05-01', '2027-08-01', 89880, 'Standard season'),
  ('enkare-bofa', '2027-08-01', '2027-09-01', 97812, 'Mid season'),
  ('enkare-bofa', '2027-09-01', '2027-10-01', 89880, 'Standard season'),
  ('enkare-bofa', '2027-10-01', '2027-11-01', 97812, 'Mid season'),
  ('enkare-bofa', '2027-11-01', '2027-12-01', 89880, 'Standard season'),
  ('enkare-bofa', '2027-12-01', '2027-12-20', 97812, 'Mid season'),
  ('enkare-bofa', '2027-12-20', '2028-01-01', 119500, 'Peak season'),
  ('enkare-bofa', '2028-01-01', '2028-01-10', 143400, 'Peak season'),
  ('enkare-bofa', '2028-01-10', '2028-04-14', 97812, 'Mid season'),
  ('enkare-bofa', '2028-04-14', '2028-04-20', 143400, 'Peak season'),
  ('enkare-bofa', '2028-04-20', '2028-05-01', 97812, 'Mid season'),
  ('enkare-bofa', '2028-05-01', '2028-08-01', 89880, 'Standard season'),
  ('enkare-bofa', '2028-08-01', '2028-09-01', 97812, 'Mid season'),
  ('enkare-bofa', '2028-09-01', '2028-10-01', 89880, 'Standard season'),
  ('enkare-bofa', '2028-10-01', '2028-11-01', 97812, 'Mid season'),
  ('enkare-bofa', '2028-11-01', '2028-12-01', 89880, 'Standard season'),
  ('enkare-bofa', '2028-12-01', '2028-12-20', 97812, 'Mid season'),
  ('enkare-bofa', '2028-12-20', '2029-01-11', 119500, 'Peak season'),
  ('sandbox', '2027-01-11', '2027-03-26', 78252, 'Mid season'),
  ('sandbox', '2027-03-26', '2027-04-05', 114720, 'Peak season'),
  ('sandbox', '2027-04-05', '2027-05-01', 78252, 'Mid season'),
  ('sandbox', '2027-05-01', '2027-08-01', 71940, 'Standard season'),
  ('sandbox', '2027-08-01', '2027-09-01', 78252, 'Mid season'),
  ('sandbox', '2027-09-01', '2027-10-01', 71940, 'Standard season'),
  ('sandbox', '2027-10-01', '2027-11-01', 78252, 'Mid season'),
  ('sandbox', '2027-11-01', '2027-12-01', 71940, 'Standard season'),
  ('sandbox', '2027-12-01', '2027-12-20', 78252, 'Mid season'),
  ('sandbox', '2027-12-20', '2028-01-01', 95600, 'Peak season'),
  ('sandbox', '2028-01-01', '2028-01-10', 114720, 'Peak season'),
  ('sandbox', '2028-01-10', '2028-04-14', 78252, 'Mid season'),
  ('sandbox', '2028-04-14', '2028-04-20', 114720, 'Peak season'),
  ('sandbox', '2028-04-20', '2028-05-01', 78252, 'Mid season'),
  ('sandbox', '2028-05-01', '2028-08-01', 71940, 'Standard season'),
  ('sandbox', '2028-08-01', '2028-09-01', 78252, 'Mid season'),
  ('sandbox', '2028-09-01', '2028-10-01', 71940, 'Standard season'),
  ('sandbox', '2028-10-01', '2028-11-01', 78252, 'Mid season'),
  ('sandbox', '2028-11-01', '2028-12-01', 71940, 'Standard season'),
  ('sandbox', '2028-12-01', '2028-12-20', 78252, 'Mid season'),
  ('sandbox', '2028-12-20', '2029-01-11', 95600, 'Peak season'),
  ('my-amani-full-rental', '2027-01-11', '2027-03-26', 240000, 'Mid season'),
  ('my-amani-full-rental', '2027-03-26', '2027-04-05', 250000, 'Peak season'),
  ('my-amani-full-rental', '2027-04-05', '2027-05-01', 240000, 'Mid season'),
  ('my-amani-full-rental', '2027-05-01', '2027-08-01', 180000, 'Standard season'),
  ('my-amani-full-rental', '2027-08-01', '2027-09-01', 240000, 'Mid season'),
  ('my-amani-full-rental', '2027-09-01', '2027-10-01', 180000, 'Standard season'),
  ('my-amani-full-rental', '2027-10-01', '2027-11-01', 240000, 'Mid season'),
  ('my-amani-full-rental', '2027-11-01', '2027-12-01', 180000, 'Standard season'),
  ('my-amani-full-rental', '2027-12-01', '2027-12-20', 240000, 'Mid season'),
  ('my-amani-full-rental', '2027-12-20', '2028-01-10', 250000, 'Peak season'),
  ('my-amani-full-rental', '2028-01-10', '2028-04-14', 240000, 'Mid season'),
  ('my-amani-full-rental', '2028-04-14', '2028-04-20', 250000, 'Peak season'),
  ('my-amani-full-rental', '2028-04-20', '2028-05-01', 240000, 'Mid season'),
  ('my-amani-full-rental', '2028-05-01', '2028-08-01', 180000, 'Standard season'),
  ('my-amani-full-rental', '2028-08-01', '2028-09-01', 240000, 'Mid season'),
  ('my-amani-full-rental', '2028-09-01', '2028-10-01', 180000, 'Standard season'),
  ('my-amani-full-rental', '2028-10-01', '2028-11-01', 240000, 'Mid season'),
  ('my-amani-full-rental', '2028-11-01', '2028-12-01', 180000, 'Standard season'),
  ('my-amani-full-rental', '2028-12-01', '2028-12-20', 240000, 'Mid season'),
  ('my-amani-full-rental', '2028-12-20', '2029-01-11', 250000, 'Peak season');

DROP TABLE IF EXISTS pg_temp.villa_rate_rooms;
CREATE TEMP TABLE villa_rate_rooms ON COMMIT DROP AS
  SELECT r.id, r.slug FROM villa_rate_targets t
    JOIN venues v ON v.slug = t.venue_slug
    JOIN rooms r ON r.venue_id = v.id AND r.slug = t.room_slug;

-- Clear the window [2027-01-11, 2029-01-11) on these rooms.
-- 1. A row spanning the whole window: keep its tail as a new row (created_at kept) ...
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
  SELECT room_id, DATE '2029-01-11', date_to, price_amount, label, created_at FROM rates
   WHERE room_id IN (SELECT id FROM villa_rate_rooms)
     AND date_from < DATE '2027-01-11' AND date_to > DATE '2029-01-11';
-- 2. ... and end it (and any row overlapping the start) at the window's first night.
UPDATE rates SET date_to = DATE '2027-01-11'
 WHERE room_id IN (SELECT id FROM villa_rate_rooms)
   AND date_from < DATE '2027-01-11' AND date_to > DATE '2027-01-11';
-- 3. A row overlapping the end starts after the window instead.
UPDATE rates SET date_from = DATE '2029-01-11'
 WHERE room_id IN (SELECT id FROM villa_rate_rooms)
   AND date_from >= DATE '2027-01-11' AND date_from < DATE '2029-01-11' AND date_to > DATE '2029-01-11';
-- 4. Anything left inside the window goes.
DELETE FROM rates
 WHERE room_id IN (SELECT id FROM villa_rate_rooms)
   AND date_from >= DATE '2027-01-11' AND date_to <= DATE '2029-01-11';

INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
  SELECT vr.id, z.date_from, z.date_to, z.price, z.label
    FROM villa_direct_rates z JOIN villa_rate_rooms vr ON vr.slug = z.slug
   ORDER BY vr.id, z.date_from;

COMMIT;
