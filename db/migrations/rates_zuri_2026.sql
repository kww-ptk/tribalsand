-- Zuri — Direct nightly rates, 1 Jan 2026 → 31 Dec 2026.
-- Prices supplied directly by the owner, PER SUITE (not one figure for all):
--   UA        standard 28,350   mid 31,600   peak 35,500
--   MAJI      standard 34,850   mid 41,350   peak 54,350
--   BAHARI    standard 34,850   mid 41,350   peak 54,350
--   ANGA      standard 34,850   mid 41,350   peak 54,350
--   MWEZI     standard 34,850   mid 41,350   peak 54,350
--   JUA       standard 39,150   mid 45,650   peak 56,300
--
-- Season windows match the Maya Kobe 2026 card exactly: the 2027 card's periods
-- replayed on 2026, plus 1-10 January as peak. February is recomputed per year,
-- so a leap year cannot invent a 29th or lose one. The year is contiguous.
--
-- Dates are NIGHTS, last night inclusive; rates.date_to is EXCLUSIVE, so each
-- range is last night + 1 day.
--
-- Idempotent and surgical: clears the window with rates_clear_span()'s rules —
-- inside deleted, straddling trimmed, spanning split — so nothing outside
-- 2026-01-01 .. 2027-01-01 is disturbed. Touches only these six Zuri suites;
-- the zuri whole-villa room is not priced here.
--
-- KNOWN, inherited from the replayed windows: the peak window is 26 Mar - 4 Apr,
-- which bracketed Easter 2027. Easter 2026 falls 5 April, one day after it
-- closes, so Easter weekend 2026 prices as mid season.

BEGIN;

-- 1. Split a rate spanning the whole window (before the one-sided trims).
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
SELECT r.room_id, DATE '2027-01-01', r.date_to, r.price_amount, r.label, r.created_at
  FROM rates r JOIN rooms rm ON rm.id = r.room_id
 WHERE rm.slug IN ('zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua')
   AND r.date_from < DATE '2026-01-01' AND r.date_to > DATE '2027-01-01';

-- 2. Trim rows overlapping either edge.
UPDATE rates r SET date_to = DATE '2026-01-01'
  FROM rooms rm WHERE rm.id = r.room_id AND rm.slug IN ('zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua')
   AND r.date_from < DATE '2026-01-01' AND r.date_to > DATE '2026-01-01';
UPDATE rates r SET date_from = DATE '2027-01-01'
  FROM rooms rm WHERE rm.id = r.room_id AND rm.slug IN ('zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua')
   AND r.date_from < DATE '2027-01-01' AND r.date_to > DATE '2027-01-01' AND r.date_from >= DATE '2026-01-01';

-- 3. Delete whatever now sits wholly inside.
DELETE FROM rates r USING rooms rm
 WHERE rm.id = r.room_id AND rm.slug IN ('zuri-ua', 'zuri-maji', 'zuri-bahari', 'zuri-anga', 'zuri-mwezi', 'zuri-jua')
   AND r.date_from >= DATE '2026-01-01' AND r.date_to <= DATE '2027-01-01';

-- 4. Insert: 96 rates (16 periods x 6 suites).
INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 35500, 'Peak season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-01-01', DATE '2026-01-11', 56300, 'Peak season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-01-11', DATE '2026-02-01', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-02-01', DATE '2026-03-01', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-03-01', DATE '2026-03-26', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 35500, 'Peak season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-03-26', DATE '2026-04-01', 56300, 'Peak season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 35500, 'Peak season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-04-01', DATE '2026-04-05', 56300, 'Peak season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-04-05', DATE '2026-05-01', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 28350, 'Standard season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-05-01', DATE '2026-06-01', 39150, 'Standard season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 28350, 'Standard season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-06-01', DATE '2026-07-01', 39150, 'Standard season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 28350, 'Standard season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-07-01', DATE '2026-08-01', 39150, 'Standard season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-08-01', DATE '2026-09-01', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 28350, 'Standard season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-09-01', DATE '2026-10-01', 39150, 'Standard season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-10-01', DATE '2026-11-01', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 28350, 'Standard season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 34850, 'Standard season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-11-01', DATE '2026-12-01', 39150, 'Standard season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 31600, 'Mid season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 41350, 'Mid season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-12-01', DATE '2026-12-20', 45650, 'Mid season' FROM rooms WHERE slug = 'zuri-jua'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 35500, 'Peak season' FROM rooms WHERE slug = 'zuri-ua'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-maji'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-bahari'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-anga'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 54350, 'Peak season' FROM rooms WHERE slug = 'zuri-mwezi'
UNION ALL
  SELECT id, DATE '2026-12-20', DATE '2027-01-01', 56300, 'Peak season' FROM rooms WHERE slug = 'zuri-jua';

COMMIT;
