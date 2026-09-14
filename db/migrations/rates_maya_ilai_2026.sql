-- Maya Ilai — Direct nightly rates, 1 Jan 2026 → 31 Dec 2026. USD.
--
-- Prices are the owner's internal rate tool (the Maya Ilai Rate & Quote Tool),
-- whose five primitives compose into all eight products:
--
--   double 350 · bunk 150 · studio 390 · living 400 · villa 1170
--
--   PRODUCT                         HIGH   STANDARD
--   Private Bunk Room                150        120
--   Double Room                      350        280
--   Studio with Kitchenette          390        312
--   Two-Bedroom Family Room          500        400
--   One-Bedroom Suite                750        600
--   Two-Bedroom Family Suite         900        720
--   Two-Bedroom Suite               1100        880
--   Three-Bedroom Villa             1170        936
--
-- Standard is High x 0.8 throughout — the tool's `standardReduction: 20`. Every
-- Standard figure above is that product of that rule, not a separately-quoted
-- number, so if the reduction ever changes they all move together.
--
-- SEASONS. The tool has only a High/Standard switch and no calendar, so the
-- windows come from Maya Kobe's 2026 card (rates_maya_kobe_2026.sql). Maya Kobe
-- runs THREE seasons and Maya Ilai has two, so the agreed collapse is:
--
--   HIGH     = Maya Kobe's PEAK windows only ......  32 nights
--   STANDARD = its Mid AND Standard windows ....... 333 nights
--
-- That is deliberate and was chosen with the consequence stated: 333 nights a
-- year, January-March and August included, sell at the -20% rate, so the villa
-- is $936 for all but 32 nights. Revisit here if the intent was for Mid to hold
-- the full rate.
--
-- Maya Kobe's two adjacent peak windows (26 Mar-1 Apr and 1-5 Apr) merge into
-- one here, so the year is five contiguous ranges and nothing is double-covered.
--
-- Dates are NIGHTS with the last night inclusive; rates.date_to is EXCLUSIVE (it
-- is the checkout morning), so each range below reads as "last night + 1 day".
--
-- KNOWN, inherited from the replayed windows: the peak window closes 4 April, and
-- Easter Sunday 2026 falls on 5 April — one day outside it. Easter weekend 2026
-- therefore prices as Standard. Same quirk as the Zuri and Maya Kobe 2026 cards.
--
-- Idempotent and surgical: clears only 2026-01-01 .. 2027-01-01 for these eight
-- rooms, using rates_clear_span()'s rules — spanning rows split, straddling rows
-- trimmed, enclosed rows deleted — so nothing outside the window is disturbed.

BEGIN;

-- Refuse rather than half-apply if the catalogue migration has not run: without
-- these rooms the INSERT below would silently price nothing and COMMIT clean.
DO $gate$
DECLARE v_rooms int;
BEGIN
  SELECT count(*) INTO v_rooms
    FROM rooms r JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'maya_ilai'
     AND r.slug IN ('maya-ilai-bunk-room','maya-ilai-double','maya-ilai-studio',
                    'maya-ilai-family-room','maya-ilai-one-bed-suite',
                    'maya-ilai-family-suite','maya-ilai-two-bed-suite','maya-ilai-villa');
  IF v_rooms <> 8 THEN
    RAISE EXCEPTION
      'Expected 8 Maya Ilai products, found % - run db/migrations/maya_ilai_rooms_2026.sql first.', v_rooms;
  END IF;
END $gate$;

-- 1. Split a rate spanning the whole window. MUST come before the one-sided
--    trims: a row covering the window on both sides satisfies both of those
--    conditions, so trimming first would swallow it instead of splitting around it.
INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
SELECT r.room_id, DATE '2027-01-01', r.date_to, r.price_amount, r.label, r.created_at
  FROM rates r JOIN rooms rm ON rm.id = r.room_id
  JOIN venues v ON v.id = rm.venue_id
 WHERE v.slug = 'maya_ilai'
   AND r.date_from < DATE '2026-01-01' AND r.date_to > DATE '2027-01-01';

-- 2. Trim rows overlapping either edge.
UPDATE rates r SET date_to = DATE '2026-01-01'
  FROM rooms rm JOIN venues v ON v.id = rm.venue_id
 WHERE rm.id = r.room_id AND v.slug = 'maya_ilai'
   AND r.date_from < DATE '2026-01-01' AND r.date_to > DATE '2026-01-01';

UPDATE rates r SET date_from = DATE '2027-01-01'
  FROM rooms rm JOIN venues v ON v.id = rm.venue_id
 WHERE rm.id = r.room_id AND v.slug = 'maya_ilai'
   AND r.date_from < DATE '2027-01-01' AND r.date_to > DATE '2027-01-01'
   AND r.date_from >= DATE '2026-01-01';

-- 3. Delete whatever now sits wholly inside.
DELETE FROM rates r USING rooms rm, venues v
 WHERE rm.id = r.room_id AND v.id = rm.venue_id AND v.slug = 'maya_ilai'
   AND r.date_from >= DATE '2026-01-01' AND r.date_to <= DATE '2027-01-01';

-- 4. Insert: 40 rates (5 windows x 8 products).
--    Cross-joined rather than written out as 40 rows, so a price appears exactly
--    once and a window appears exactly once — there is no way for the two to
--    drift out of step.
INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
SELECT rm.id, w.date_from, w.date_to,
       CASE WHEN w.is_high THEN p.high ELSE p.standard END,
       CASE WHEN w.is_high THEN 'High season' ELSE 'Standard season' END
  FROM (VALUES
    ('maya-ilai-bunk-room',      150,  120),
    ('maya-ilai-double',         350,  280),
    ('maya-ilai-studio',         390,  312),
    ('maya-ilai-family-room',    500,  400),
    ('maya-ilai-one-bed-suite',  750,  600),
    ('maya-ilai-family-suite',   900,  720),
    ('maya-ilai-two-bed-suite', 1100,  880),
    ('maya-ilai-villa',         1170,  936)
  ) AS p(slug, high, standard)
  JOIN rooms rm ON rm.slug = p.slug
  CROSS JOIN (VALUES
    (DATE '2026-01-01', DATE '2026-01-11', TRUE),   -- New Year        10 nights
    (DATE '2026-01-11', DATE '2026-03-26', FALSE),  --                 74 nights
    (DATE '2026-03-26', DATE '2026-04-05', TRUE),   -- Easter shoulder 10 nights
    (DATE '2026-04-05', DATE '2026-12-20', FALSE),  --                259 nights
    (DATE '2026-12-20', DATE '2027-01-01', TRUE)    -- Christmas       12 nights
  ) AS w(date_from, date_to, is_high);

-- 5. Post-conditions. A silent partial apply here would misprice a live booking.
DO $check$
DECLARE
  v_rows   int;
  v_nights int;
  v_high   int;
BEGIN
  SELECT count(*) INTO v_rows
    FROM rates r JOIN rooms rm ON rm.id = r.room_id JOIN venues v ON v.id = rm.venue_id
   WHERE v.slug = 'maya_ilai'
     AND r.date_from >= DATE '2026-01-01' AND r.date_to <= DATE '2027-01-01';
  IF v_rows <> 40 THEN
    RAISE EXCEPTION 'Expected 40 Maya Ilai 2026 rate rows, got %.', v_rows;
  END IF;

  -- The year must be covered exactly once per product: 365 nights, no gap, no
  -- overlap. Checked on one product; all eight share the same window set.
  SELECT sum(r.date_to - r.date_from) INTO v_nights
    FROM rates r JOIN rooms rm ON rm.id = r.room_id
   WHERE rm.slug = 'maya-ilai-villa'
     AND r.date_from >= DATE '2026-01-01' AND r.date_to <= DATE '2027-01-01';
  IF v_nights <> 365 THEN
    RAISE EXCEPTION 'Maya Ilai 2026 covers % nights, expected 365 (gap or overlap).', v_nights;
  END IF;

  SELECT sum(r.date_to - r.date_from) INTO v_high
    FROM rates r JOIN rooms rm ON rm.id = r.room_id
   WHERE rm.slug = 'maya-ilai-villa' AND r.label = 'High season'
     AND r.date_from >= DATE '2026-01-01' AND r.date_to <= DATE '2027-01-01';
  IF v_high <> 32 THEN
    RAISE EXCEPTION 'Maya Ilai 2026 has % High nights, expected 32.', v_high;
  END IF;
END $check$;

COMMIT;
