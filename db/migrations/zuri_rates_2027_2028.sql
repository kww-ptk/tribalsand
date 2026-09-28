-- Zuri — DIRECT nightly rates (KES, bed & breakfast), 11 Jan 2027 → last night 10 Jan 2029.
--
-- Source: the owner's rate sheet "ZURI - JAN 11 27 - JAN 10 29.xlsx", DIRECT column only
-- (the TA and OTA columns are not stored here). Three tiers across the sheet:
--   Standard  Ua 34,020 · Maji/Bahari/Anga/Mwezi 41,820 · Jua 46,980
--   Mid       Ua 37,920 · Maji/Bahari/Anga/Mwezi 49,620 · Jua 54,780
--   Peak      Ua 42,600 · Maji/Bahari/Anga/Mwezi 65,220 · Jua 67,560
-- zuri-buyout = the SUM of the six suites for every period (the owner's rule):
--   Standard 248,280 · Mid 291,180 · Peak 371,040.
--
-- Rates carry no currency of their own — they take the room's price_currency —
-- so the gate below refuses to run unless all seven Zuri rooms price in KES.
-- (Production does, as of 2026-09-28.)
--
-- date_to is EXCLUSIVE (the checkout morning), as everywhere in `rates`.
-- Adjacent sheet periods with the same price + season are merged into one row.
--
-- NOT stored, because `rates` has no column for them: the minimum stay
-- (2 nights mid/standard, 5 nights peak) and Mwezi's extra 3rd/4th guest
-- (KES 5,500 pp/night).
--
-- Writes leave no overlaps, like rates_apply_ranges(): any existing row on these
-- rooms that touches the window is trimmed, split or deleted first (same four
-- cases as rates_clear_span(), the spanning case first). Idempotent — a re-run
-- clears its own rows and writes the same ones again.

BEGIN;

DO $gate$
DECLARE missing text; wrong text;
BEGIN
  SELECT string_agg(s, ', ') INTO missing
    FROM unnest(ARRAY['zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua', 'zuri-buyout']) s
   WHERE NOT EXISTS (SELECT 1 FROM rooms r JOIN venues v ON v.id = r.venue_id
                      WHERE v.slug = 'zuri' AND r.slug = s);
  IF missing IS NOT NULL THEN
    RAISE EXCEPTION 'Zuri rooms missing: % - nothing was changed', missing;
  END IF;
  SELECT string_agg(r.slug || ' (' || COALESCE(r.price_currency, 'none') || ')', ', ') INTO wrong
    FROM rooms r JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'zuri' AND r.slug IN ('zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua', 'zuri-buyout')
     AND COALESCE(r.price_currency, '') <> 'KES';
  IF wrong IS NOT NULL THEN
    RAISE EXCEPTION 'These Zuri rooms do not price in KES, so KES rates would be read in the wrong currency: % - nothing was changed', wrong;
  END IF;
END
$gate$;

DROP TABLE IF EXISTS pg_temp.zuri_direct_rates;
CREATE TEMP TABLE zuri_direct_rates (slug text, date_from date, date_to date, price numeric, label text) ON COMMIT DROP;
INSERT INTO zuri_direct_rates (slug, date_from, date_to, price, label) VALUES
  ('zuri-ua', '2027-01-11', '2027-03-26', 37920, 'Mid season'),
  ('zuri-ua', '2027-03-26', '2027-04-05', 42600, 'Peak season'),
  ('zuri-ua', '2027-04-05', '2027-05-01', 37920, 'Mid season'),
  ('zuri-ua', '2027-05-01', '2027-08-01', 34020, 'Standard season'),
  ('zuri-ua', '2027-08-01', '2027-09-01', 37920, 'Mid season'),
  ('zuri-ua', '2027-09-01', '2027-10-01', 34020, 'Standard season'),
  ('zuri-ua', '2027-10-01', '2027-11-01', 37920, 'Mid season'),
  ('zuri-ua', '2027-11-01', '2027-12-01', 34020, 'Standard season'),
  ('zuri-ua', '2027-12-01', '2027-12-20', 37920, 'Mid season'),
  ('zuri-ua', '2027-12-20', '2028-01-10', 42600, 'Peak season'),
  ('zuri-ua', '2028-01-10', '2028-04-14', 37920, 'Mid season'),
  ('zuri-ua', '2028-04-14', '2028-04-20', 42600, 'Peak season'),
  ('zuri-ua', '2028-04-20', '2028-05-01', 37920, 'Mid season'),
  ('zuri-ua', '2028-05-01', '2028-08-01', 34020, 'Standard season'),
  ('zuri-ua', '2028-08-01', '2028-09-01', 37920, 'Mid season'),
  ('zuri-ua', '2028-09-01', '2028-10-01', 34020, 'Standard season'),
  ('zuri-ua', '2028-10-01', '2028-11-01', 37920, 'Mid season'),
  ('zuri-ua', '2028-11-01', '2028-12-01', 34020, 'Standard season'),
  ('zuri-ua', '2028-12-01', '2028-12-20', 37920, 'Mid season'),
  ('zuri-ua', '2028-12-20', '2029-01-11', 42600, 'Peak season'),
  ('zuri-maji', '2027-01-11', '2027-03-26', 49620, 'Mid season'),
  ('zuri-maji', '2027-03-26', '2027-04-05', 65220, 'Peak season'),
  ('zuri-maji', '2027-04-05', '2027-05-01', 49620, 'Mid season'),
  ('zuri-maji', '2027-05-01', '2027-08-01', 41820, 'Standard season'),
  ('zuri-maji', '2027-08-01', '2027-09-01', 49620, 'Mid season'),
  ('zuri-maji', '2027-09-01', '2027-10-01', 41820, 'Standard season'),
  ('zuri-maji', '2027-10-01', '2027-11-01', 49620, 'Mid season'),
  ('zuri-maji', '2027-11-01', '2027-12-01', 41820, 'Standard season'),
  ('zuri-maji', '2027-12-01', '2027-12-20', 49620, 'Mid season'),
  ('zuri-maji', '2027-12-20', '2028-01-10', 65220, 'Peak season'),
  ('zuri-maji', '2028-01-10', '2028-04-14', 49620, 'Mid season'),
  ('zuri-maji', '2028-04-14', '2028-04-20', 65220, 'Peak season'),
  ('zuri-maji', '2028-04-20', '2028-05-01', 49620, 'Mid season'),
  ('zuri-maji', '2028-05-01', '2028-08-01', 41820, 'Standard season'),
  ('zuri-maji', '2028-08-01', '2028-09-01', 49620, 'Mid season'),
  ('zuri-maji', '2028-09-01', '2028-10-01', 41820, 'Standard season'),
  ('zuri-maji', '2028-10-01', '2028-11-01', 49620, 'Mid season'),
  ('zuri-maji', '2028-11-01', '2028-12-01', 41820, 'Standard season'),
  ('zuri-maji', '2028-12-01', '2028-12-20', 49620, 'Mid season'),
  ('zuri-maji', '2028-12-20', '2029-01-11', 65220, 'Peak season'),
  ('zuri-bahari', '2027-01-11', '2027-03-26', 49620, 'Mid season'),
  ('zuri-bahari', '2027-03-26', '2027-04-05', 65220, 'Peak season'),
  ('zuri-bahari', '2027-04-05', '2027-05-01', 49620, 'Mid season'),
  ('zuri-bahari', '2027-05-01', '2027-08-01', 41820, 'Standard season'),
  ('zuri-bahari', '2027-08-01', '2027-09-01', 49620, 'Mid season'),
  ('zuri-bahari', '2027-09-01', '2027-10-01', 41820, 'Standard season'),
  ('zuri-bahari', '2027-10-01', '2027-11-01', 49620, 'Mid season'),
  ('zuri-bahari', '2027-11-01', '2027-12-01', 41820, 'Standard season'),
  ('zuri-bahari', '2027-12-01', '2027-12-20', 49620, 'Mid season'),
  ('zuri-bahari', '2027-12-20', '2028-01-10', 65220, 'Peak season'),
  ('zuri-bahari', '2028-01-10', '2028-04-14', 49620, 'Mid season'),
  ('zuri-bahari', '2028-04-14', '2028-04-20', 65220, 'Peak season'),
  ('zuri-bahari', '2028-04-20', '2028-05-01', 49620, 'Mid season'),
  ('zuri-bahari', '2028-05-01', '2028-08-01', 41820, 'Standard season'),
  ('zuri-bahari', '2028-08-01', '2028-09-01', 49620, 'Mid season'),
  ('zuri-bahari', '2028-09-01', '2028-10-01', 41820, 'Standard season'),
  ('zuri-bahari', '2028-10-01', '2028-11-01', 49620, 'Mid season'),
  ('zuri-bahari', '2028-11-01', '2028-12-01', 41820, 'Standard season'),
  ('zuri-bahari', '2028-12-01', '2028-12-20', 49620, 'Mid season'),
  ('zuri-bahari', '2028-12-20', '2029-01-11', 65220, 'Peak season'),
  ('zuri-anga', '2027-01-11', '2027-03-26', 49620, 'Mid season'),
  ('zuri-anga', '2027-03-26', '2027-04-05', 65220, 'Peak season'),
  ('zuri-anga', '2027-04-05', '2027-05-01', 49620, 'Mid season'),
  ('zuri-anga', '2027-05-01', '2027-08-01', 41820, 'Standard season'),
  ('zuri-anga', '2027-08-01', '2027-09-01', 49620, 'Mid season'),
  ('zuri-anga', '2027-09-01', '2027-10-01', 41820, 'Standard season'),
  ('zuri-anga', '2027-10-01', '2027-11-01', 49620, 'Mid season'),
  ('zuri-anga', '2027-11-01', '2027-12-01', 41820, 'Standard season'),
  ('zuri-anga', '2027-12-01', '2027-12-20', 49620, 'Mid season'),
  ('zuri-anga', '2027-12-20', '2028-01-10', 65220, 'Peak season'),
  ('zuri-anga', '2028-01-10', '2028-04-14', 49620, 'Mid season'),
  ('zuri-anga', '2028-04-14', '2028-04-20', 65220, 'Peak season'),
  ('zuri-anga', '2028-04-20', '2028-05-01', 49620, 'Mid season'),
  ('zuri-anga', '2028-05-01', '2028-08-01', 41820, 'Standard season'),
  ('zuri-anga', '2028-08-01', '2028-09-01', 49620, 'Mid season'),
  ('zuri-anga', '2028-09-01', '2028-10-01', 41820, 'Standard season'),
  ('zuri-anga', '2028-10-01', '2028-11-01', 49620, 'Mid season'),
  ('zuri-anga', '2028-11-01', '2028-12-01', 41820, 'Standard season'),
  ('zuri-anga', '2028-12-01', '2028-12-20', 49620, 'Mid season'),
  ('zuri-anga', '2028-12-20', '2029-01-11', 65220, 'Peak season'),
  ('zuri-mwezi', '2027-01-11', '2027-03-26', 49620, 'Mid season'),
  ('zuri-mwezi', '2027-03-26', '2027-04-05', 65220, 'Peak season'),
  ('zuri-mwezi', '2027-04-05', '2027-05-01', 49620, 'Mid season'),
  ('zuri-mwezi', '2027-05-01', '2027-08-01', 41820, 'Standard season'),
  ('zuri-mwezi', '2027-08-01', '2027-09-01', 49620, 'Mid season'),
  ('zuri-mwezi', '2027-09-01', '2027-10-01', 41820, 'Standard season'),
  ('zuri-mwezi', '2027-10-01', '2027-11-01', 49620, 'Mid season'),
  ('zuri-mwezi', '2027-11-01', '2027-12-01', 41820, 'Standard season'),
  ('zuri-mwezi', '2027-12-01', '2027-12-20', 49620, 'Mid season'),
  ('zuri-mwezi', '2027-12-20', '2028-01-10', 65220, 'Peak season'),
  ('zuri-mwezi', '2028-01-10', '2028-04-14', 49620, 'Mid season'),
  ('zuri-mwezi', '2028-04-14', '2028-04-20', 65220, 'Peak season'),
  ('zuri-mwezi', '2028-04-20', '2028-05-01', 49620, 'Mid season'),
  ('zuri-mwezi', '2028-05-01', '2028-08-01', 41820, 'Standard season'),
  ('zuri-mwezi', '2028-08-01', '2028-09-01', 49620, 'Mid season'),
  ('zuri-mwezi', '2028-09-01', '2028-10-01', 41820, 'Standard season'),
  ('zuri-mwezi', '2028-10-01', '2028-11-01', 49620, 'Mid season'),
  ('zuri-mwezi', '2028-11-01', '2028-12-01', 41820, 'Standard season'),
  ('zuri-mwezi', '2028-12-01', '2028-12-20', 49620, 'Mid season'),
  ('zuri-mwezi', '2028-12-20', '2029-01-11', 65220, 'Peak season'),
  ('zuri-jua', '2027-01-11', '2027-03-26', 54780, 'Mid season'),
  ('zuri-jua', '2027-03-26', '2027-04-05', 67560, 'Peak season'),
  ('zuri-jua', '2027-04-05', '2027-05-01', 54780, 'Mid season'),
  ('zuri-jua', '2027-05-01', '2027-08-01', 46980, 'Standard season'),
  ('zuri-jua', '2027-08-01', '2027-09-01', 54780, 'Mid season'),
  ('zuri-jua', '2027-09-01', '2027-10-01', 46980, 'Standard season'),
  ('zuri-jua', '2027-10-01', '2027-11-01', 54780, 'Mid season'),
  ('zuri-jua', '2027-11-01', '2027-12-01', 46980, 'Standard season'),
  ('zuri-jua', '2027-12-01', '2027-12-20', 54780, 'Mid season'),
  ('zuri-jua', '2027-12-20', '2028-01-10', 67560, 'Peak season'),
  ('zuri-jua', '2028-01-10', '2028-04-14', 54780, 'Mid season'),
  ('zuri-jua', '2028-04-14', '2028-04-20', 67560, 'Peak season'),
  ('zuri-jua', '2028-04-20', '2028-05-01', 54780, 'Mid season'),
  ('zuri-jua', '2028-05-01', '2028-08-01', 46980, 'Standard season'),
  ('zuri-jua', '2028-08-01', '2028-09-01', 54780, 'Mid season'),
  ('zuri-jua', '2028-09-01', '2028-10-01', 46980, 'Standard season'),
  ('zuri-jua', '2028-10-01', '2028-11-01', 54780, 'Mid season'),
  ('zuri-jua', '2028-11-01', '2028-12-01', 46980, 'Standard season'),
  ('zuri-jua', '2028-12-01', '2028-12-20', 54780, 'Mid season'),
  ('zuri-jua', '2028-12-20', '2029-01-11', 67560, 'Peak season'),
  ('zuri-buyout', '2027-01-11', '2027-03-26', 291180, 'Mid season'),
  ('zuri-buyout', '2027-03-26', '2027-04-05', 371040, 'Peak season'),
  ('zuri-buyout', '2027-04-05', '2027-05-01', 291180, 'Mid season'),
  ('zuri-buyout', '2027-05-01', '2027-08-01', 248280, 'Standard season'),
  ('zuri-buyout', '2027-08-01', '2027-09-01', 291180, 'Mid season'),
  ('zuri-buyout', '2027-09-01', '2027-10-01', 248280, 'Standard season'),
  ('zuri-buyout', '2027-10-01', '2027-11-01', 291180, 'Mid season'),
  ('zuri-buyout', '2027-11-01', '2027-12-01', 248280, 'Standard season'),
  ('zuri-buyout', '2027-12-01', '2027-12-20', 291180, 'Mid season'),
  ('zuri-buyout', '2027-12-20', '2028-01-10', 371040, 'Peak season'),
  ('zuri-buyout', '2028-01-10', '2028-04-14', 291180, 'Mid season'),
  ('zuri-buyout', '2028-04-14', '2028-04-20', 371040, 'Peak season'),
  ('zuri-buyout', '2028-04-20', '2028-05-01', 291180, 'Mid season'),
  ('zuri-buyout', '2028-05-01', '2028-08-01', 248280, 'Standard season'),
  ('zuri-buyout', '2028-08-01', '2028-09-01', 291180, 'Mid season'),
  ('zuri-buyout', '2028-09-01', '2028-10-01', 248280, 'Standard season'),
  ('zuri-buyout', '2028-10-01', '2028-11-01', 291180, 'Mid season'),
  ('zuri-buyout', '2028-11-01', '2028-12-01', 248280, 'Standard season'),
  ('zuri-buyout', '2028-12-01', '2028-12-20', 291180, 'Mid season'),
  ('zuri-buyout', '2028-12-20', '2029-01-11', 371040, 'Peak season');

DROP TABLE IF EXISTS pg_temp.zuri_rate_rooms;
CREATE TEMP TABLE zuri_rate_rooms ON COMMIT DROP AS
  SELECT r.id, r.slug FROM rooms r JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'zuri' AND r.slug IN ('zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua', 'zuri-buyout');

-- Clear the window [2027-01-11, 2029-01-11) on these rooms.
-- 1. A row spanning the whole window: keep its tail as a new row (created_at kept) ...
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
  SELECT room_id, DATE '2029-01-11', date_to, price_amount, label, created_at FROM rates
   WHERE room_id IN (SELECT id FROM zuri_rate_rooms)
     AND date_from < DATE '2027-01-11' AND date_to > DATE '2029-01-11';
-- 2. ... and end it (and any row overlapping the start) at the window's first night.
UPDATE rates SET date_to = DATE '2027-01-11'
 WHERE room_id IN (SELECT id FROM zuri_rate_rooms)
   AND date_from < DATE '2027-01-11' AND date_to > DATE '2027-01-11';
-- 3. A row overlapping the end starts after the window instead.
UPDATE rates SET date_from = DATE '2029-01-11'
 WHERE room_id IN (SELECT id FROM zuri_rate_rooms)
   AND date_from >= DATE '2027-01-11' AND date_from < DATE '2029-01-11' AND date_to > DATE '2029-01-11';
-- 4. Anything left inside the window goes.
DELETE FROM rates
 WHERE room_id IN (SELECT id FROM zuri_rate_rooms)
   AND date_from >= DATE '2027-01-11' AND date_to <= DATE '2029-01-11';

INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
  SELECT zr.id, z.date_from, z.date_to, z.price, z.label
    FROM zuri_direct_rates z JOIN zuri_rate_rooms zr ON zr.slug = z.slug
   ORDER BY zr.id, z.date_from;

COMMIT;
