-- Maya Kobe — Direct nightly rates, 1 Jan 2026 → 31 Dec 2026.
-- Prices supplied directly by the owner (not from a sheet):
--   HAZE / DRIFT / TIDE / GLOW   standard 33,800   mid 40,300   peak 53,300
--   Prestige Suite               standard 72,800   mid 79,300   peak 90,350
--
-- Season windows are the 2027 card's, replayed on 2026 at the owner's request,
-- plus 1-10 January as peak (the 2027 card starts on the 11th because the
-- previous card owned New Year; the 2028 card prices that stretch peak, so this
-- matches). February is recomputed per year rather than copied, so a leap year
-- cannot invent a 29th or lose one. The year is contiguous: no unpriced nights.
--
-- Dates are NIGHTS, last night inclusive; rates.date_to is EXCLUSIVE, so each
-- range is last night + 1 day. '20-31 December' is twelve nights ending 1 Jan.
--
-- Idempotent, and surgical: clears the window with the same rules as
-- rates_clear_span() — inside deleted, straddling trimmed, spanning split — so
-- nothing outside 2026-01-01 .. 2027-01-01 is disturbed.
--
-- KNOWN, deliberate: the peak window replayed from 2027 is 26 Mar - 4 Apr, which
-- bracketed Easter Sunday 28 Mar 2027. Easter 2026 falls on 5 April, one day
-- after it closes, so Easter weekend 2026 prices as mid season. Copying the
-- dates literally was the instruction; moving the window is a pricing decision.

BEGIN;

-- 1. Split a rate spanning the whole window (before the one-sided trims).
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
SELECT r.room_id, DATE '2027-01-01', r.date_to, r.price_amount, r.label, r.created_at
  FROM rates r JOIN rooms rm ON rm.id = r.room_id
 WHERE rm.slug IN ('maya-kobe-haze', 'maya-kobe-drift', 'maya-kobe-tide', 'maya-kobe-glow', 'maya-kobe-prestige')
   AND r.date_from < DATE '2026-01-01' AND r.date_to > DATE '2027-01-01';

-- 2. Trim rows overlapping either edge.
UPDATE rates r SET date_to = DATE '2026-01-01'
  FROM rooms rm WHERE rm.id = r.room_id AND rm.slug IN ('maya-kobe-haze', 'maya-kobe-drift', 'maya-kobe-tide', 'maya-kobe-glow', 'maya-kobe-prestige')
   AND r.date_from < DATE '2026-01-01' AND r.date_to > DATE '2026-01-01';
UPDATE rates r SET date_from = DATE '2027-01-01'
  FROM rooms rm WHERE rm.id = r.room_id AND rm.slug IN ('maya-kobe-haze', 'maya-kobe-drift', 'maya-kobe-tide', 'maya-kobe-glow', 'maya-kobe-prestige')
   AND r.date_from < DATE '2027-01-01' AND r.date_to > DATE '2027-01-01' AND r.date_from >= DATE '2026-01-01';

-- 3. Delete whatever now sits wholly inside.
DELETE FROM rates r USING rooms rm
 WHERE rm.id = r.room_id AND rm.slug IN ('maya-kobe-haze', 'maya-kobe-drift', 'maya-kobe-tide', 'maya-kobe-glow', 'maya-kobe-prestige')
   AND r.date_from >= DATE '2026-01-01' AND r.date_to <= DATE '2027-01-01';

-- 4. Insert: 80 rates (16 periods x 5 suites).
INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 90350, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 90350, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 90350, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 72800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 72800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 72800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 72800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 33800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 72800, 'Standard season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 40300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 79300, 'Mid season' FROM rooms WHERE slug = 'maya-kobe-prestige'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-haze'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-drift'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-tide'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 53300, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-glow'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 90350, 'Peak season' FROM rooms WHERE slug = 'maya-kobe-prestige';

COMMIT;
