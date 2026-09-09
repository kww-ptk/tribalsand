-- Maya Kobe — Direct nightly rates, 11 Jan 2027 → 10 Jan 2029.
-- Source: 'MAYA KOBE - JAN 11 27 - 10 JAN 29.xlsx', DIRECT column (not TA, not OTAS), KES.
--
-- Dates in the sheet are NIGHTS, last night inclusive. rates.date_to is EXCLUSIVE
-- (the checkout morning), so every range below is last night + 1 day. A period
-- reading '20-31 December' is twelve nights and ends 2028-01-01 here.
--
-- Idempotent. It first clears the span for these five rooms using the same rules
-- as rates_clear_span(): rows fully inside are deleted, a row straddling one end
-- is trimmed, and a row spanning the whole window is split in two so rates
-- outside 2027-01-11 .. 2029-01-11 are never disturbed. Re-running is safe and
-- leaves no overlaps.
--
-- NOTE: 13 April 2028 is deliberately NOT priced — the sheet jumps 1-12 to 14-19
-- April. That night falls back to the room's base price until the sheet says.

BEGIN;

-- 1. Split any existing rate that spans the whole window (must run before the
--    one-sided trims, or those would swallow it).
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
SELECT r.room_id, DATE '2029-01-11', r.date_to, r.price_amount, r.label, r.created_at
  FROM rates r JOIN rooms rm ON rm.id = r.room_id
 WHERE rm.slug IN ('maya-kobe-drift', 'maya-kobe-glow', 'maya-kobe-haze', 'maya-kobe-prestige', 'maya-kobe-tide')
   AND r.date_from < DATE '2027-01-11' AND r.date_to > DATE '2029-01-11';

-- 2. Trim rows that overlap either edge.
UPDATE rates r SET date_to = DATE '2027-01-11'
  FROM rooms rm WHERE rm.id = r.room_id
   AND rm.slug IN ('maya-kobe-drift', 'maya-kobe-glow', 'maya-kobe-haze', 'maya-kobe-prestige', 'maya-kobe-tide')
   AND r.date_from < DATE '2027-01-11' AND r.date_to > DATE '2027-01-11';
UPDATE rates r SET date_from = DATE '2029-01-11'
  FROM rooms rm WHERE rm.id = r.room_id
   AND rm.slug IN ('maya-kobe-drift', 'maya-kobe-glow', 'maya-kobe-haze', 'maya-kobe-prestige', 'maya-kobe-tide')
   AND r.date_from < DATE '2029-01-11' AND r.date_to > DATE '2029-01-11'
   AND r.date_from >= DATE '2027-01-11';

-- 3. Delete whatever now sits wholly inside the window.
DELETE FROM rates r USING rooms rm
 WHERE rm.id = r.room_id
   AND rm.slug IN ('maya-kobe-drift', 'maya-kobe-glow', 'maya-kobe-haze', 'maya-kobe-prestige', 'maya-kobe-tide')
   AND r.date_from >= DATE '2027-01-11' AND r.date_to <= DATE '2029-01-11';

-- 4. Insert the sheet: 160 rates (32 periods x 5 suites).
INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
  SELECT id, DATE '2027-01-11', DATE '2027-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-01-11', DATE '2027-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-01-11', DATE '2027-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-01-11', DATE '2027-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-01-11', DATE '2027-02-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-02-01', DATE '2027-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-02-01', DATE '2027-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-02-01', DATE '2027-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-02-01', DATE '2027-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-02-01', DATE '2027-03-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-03-01', DATE '2027-03-26', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-03-01', DATE '2027-03-26', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-03-01', DATE '2027-03-26', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-03-01', DATE '2027-03-26', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-03-01', DATE '2027-03-26', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-03-26', DATE '2027-04-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-03-26', DATE '2027-04-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-03-26', DATE '2027-04-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-03-26', DATE '2027-04-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-03-26', DATE '2027-04-01', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-04-01', DATE '2027-04-05', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-04-01', DATE '2027-04-05', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-04-01', DATE '2027-04-05', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-04-01', DATE '2027-04-05', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-04-01', DATE '2027-04-05', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-04-05', DATE '2027-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-04-05', DATE '2027-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-04-05', DATE '2027-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-04-05', DATE '2027-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-04-05', DATE '2027-05-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-05-01', DATE '2027-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-05-01', DATE '2027-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-05-01', DATE '2027-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-05-01', DATE '2027-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-05-01', DATE '2027-06-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-06-01', DATE '2027-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-06-01', DATE '2027-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-06-01', DATE '2027-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-06-01', DATE '2027-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-06-01', DATE '2027-07-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-07-01', DATE '2027-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-07-01', DATE '2027-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-07-01', DATE '2027-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-07-01', DATE '2027-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-07-01', DATE '2027-08-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-08-01', DATE '2027-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-08-01', DATE '2027-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-08-01', DATE '2027-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-08-01', DATE '2027-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-08-01', DATE '2027-09-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-09-01', DATE '2027-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-09-01', DATE '2027-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-09-01', DATE '2027-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-09-01', DATE '2027-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-09-01', DATE '2027-10-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-10-01', DATE '2027-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-10-01', DATE '2027-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-10-01', DATE '2027-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-10-01', DATE '2027-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-10-01', DATE '2027-11-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-11-01', DATE '2027-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-11-01', DATE '2027-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-11-01', DATE '2027-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-11-01', DATE '2027-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-11-01', DATE '2027-12-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-12-01', DATE '2027-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-12-01', DATE '2027-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-12-01', DATE '2027-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-12-01', DATE '2027-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-12-01', DATE '2027-12-20', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2027-12-20', DATE '2028-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2027-12-20', DATE '2028-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2027-12-20', DATE '2028-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2027-12-20', DATE '2028-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2027-12-20', DATE '2028-01-01', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-01-01', DATE '2028-01-10', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-01-01', DATE '2028-01-10', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-01-01', DATE '2028-01-10', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-01-01', DATE '2028-01-10', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-01-01', DATE '2028-01-10', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-01-10', DATE '2028-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-01-10', DATE '2028-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-01-10', DATE '2028-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-01-10', DATE '2028-02-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-01-10', DATE '2028-02-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-02-01', DATE '2028-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-02-01', DATE '2028-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-02-01', DATE '2028-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-02-01', DATE '2028-03-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-02-01', DATE '2028-03-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-03-01', DATE '2028-04-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-03-01', DATE '2028-04-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-03-01', DATE '2028-04-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-03-01', DATE '2028-04-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-03-01', DATE '2028-04-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-04-01', DATE '2028-04-13', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-04-01', DATE '2028-04-13', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-04-01', DATE '2028-04-13', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-04-01', DATE '2028-04-13', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-04-01', DATE '2028-04-13', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-04-14', DATE '2028-04-20', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-04-14', DATE '2028-04-20', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-04-14', DATE '2028-04-20', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-04-14', DATE '2028-04-20', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-04-14', DATE '2028-04-20', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-04-20', DATE '2028-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-04-20', DATE '2028-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-04-20', DATE '2028-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-04-20', DATE '2028-05-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-04-20', DATE '2028-05-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-05-01', DATE '2028-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-05-01', DATE '2028-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-05-01', DATE '2028-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-05-01', DATE '2028-06-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-05-01', DATE '2028-06-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-06-01', DATE '2028-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-06-01', DATE '2028-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-06-01', DATE '2028-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-06-01', DATE '2028-07-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-06-01', DATE '2028-07-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-07-01', DATE '2028-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-07-01', DATE '2028-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-07-01', DATE '2028-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-07-01', DATE '2028-08-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-07-01', DATE '2028-08-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-08-01', DATE '2028-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-08-01', DATE '2028-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-08-01', DATE '2028-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-08-01', DATE '2028-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-08-01', DATE '2028-09-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-09-01', DATE '2028-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-09-01', DATE '2028-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-09-01', DATE '2028-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-09-01', DATE '2028-10-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-09-01', DATE '2028-10-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-10-01', DATE '2028-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-10-01', DATE '2028-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-10-01', DATE '2028-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-10-01', DATE '2028-11-01', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-10-01', DATE '2028-11-01', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-11-01', DATE '2028-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-11-01', DATE '2028-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-11-01', DATE '2028-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-11-01', DATE '2028-12-01', 40560, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-11-01', DATE '2028-12-01', 87360, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-12-01', DATE '2028-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-12-01', DATE '2028-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-12-01', DATE '2028-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-12-01', DATE '2028-12-20', 48360, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-12-01', DATE '2028-12-20', 95160, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2028-12-20', DATE '2029-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2028-12-20', DATE '2029-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2028-12-20', DATE '2029-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2028-12-20', DATE '2029-01-01', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2028-12-20', DATE '2029-01-01', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2029-01-01', DATE '2029-01-11', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2029-01-01', DATE '2029-01-11', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2029-01-01', DATE '2029-01-11', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2029-01-01', DATE '2029-01-11', 63960, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2029-01-01', DATE '2029-01-11', 110760, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige';

COMMIT;
