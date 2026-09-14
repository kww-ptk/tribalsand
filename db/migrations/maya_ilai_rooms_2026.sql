-- Maya Ilai — rebuild the room catalogue as eight products over shared villas.
--
-- Physical inventory: 8 villas x (2 double + 1 bunk + 1 living) + 8 studios.
-- The eight villa UNITS belong to maya-ilai-villa; the six other composite
-- products own no units and resolve against those same villas via
-- includes/maya-ilai-inventory.php. The studio is an ordinary independent room.
--
-- Prices are rooms.price_amount = the HIGH-season figure from the internal rate
-- tool. Dated seasonal ranges are a separate follow-on migration.
--
-- capacity = MAXIMUM guests, because that is what the guest-count filter in
-- ts_search_availability() compares against.
--
-- DESTRUCTIVE: deletes the previous Maya Ilai rooms. Check for live holds first
-- (see the plan, Task 9 Step 1).

BEGIN;

-- Ordering gate. Without availability_blocks.components every composite product
-- would fall back to whole-unit blocking and silently oversell, so this fails
-- loudly rather than degrading. Run add_maya_ilai_components.sql first.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'availability_blocks' AND column_name = 'components'
  ) THEN
    RAISE EXCEPTION
      'availability_blocks.components is missing - run db/migrations/add_maya_ilai_components.sql first';
  END IF;
END $$;

-- Existence gate. Everything below keys off venues.slug = 'maya_ilai'. If that
-- slug is not exactly right on this database the DELETE matches nothing, the
-- INSERT ... WHERE v.slug = 'maya_ilai' inserts nothing, and the whole thing
-- COMMITs successfully having done absolutely nothing — the worst outcome
-- available, because it looks like a clean run. Fail loudly instead.
DO $venue$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM venues WHERE slug = 'maya_ilai') THEN
    RAISE EXCEPTION
      'No venue with slug ''maya_ilai'' - nothing to rebuild. Check the real slug (SELECT id, slug, name FROM venues) before running this.';
  END IF;
END $venue$;

-- Data-loss gate. The DELETE below removes every existing Maya Ilai room, and
-- the foreign keys into rooms/units cascade further than they look:
--
--   units.room_id               -> rooms  CASCADE   (and from there:)
--     holds.unit_id             -> units  CASCADE
--     availability_blocks.unit_id       CASCADE
--     ical_feeds.unit_id                CASCADE     <- OTA sync for the property
--     channel_conflicts.unit_id         CASCADE
--   rates.room_id               -> rooms  CASCADE   <- every seasonal price
--   room_images.room_id         -> rooms  CASCADE   <- every photo
--
-- The first version of this gate counted only holds, blocks and ledger rows.
-- Production's `superior-suite` room (see fix_maya_ilai_superior_suite_booking.sql)
-- will plausibly have room_images, and possibly rates and an ical_feeds row,
-- while having zero live holds — so the gate would have passed and destroyed all
-- of them silently, OTA sync included. Every cascading table is counted here.
--
-- Not counted, deliberately: bookings.room_id/unit_id, holds.room_id and
-- submissions.room_id are ON DELETE SET NULL, so those rows survive (the ledger
-- rows are still caught below via their hold, which does cascade).
--
-- Each table is guarded with to_regclass: not every install has all of them.
DO $gate$
DECLARE
  v_holds     bigint := 0;
  v_blocks    bigint := 0;
  v_ledger    bigint := 0;
  v_rates     bigint := 0;
  v_images    bigint := 0;
  v_feeds     bigint := 0;
  v_conflicts bigint := 0;
BEGIN
  SELECT count(*) INTO v_holds
    FROM holds h
    JOIN units u  ON u.id = h.unit_id
    JOIN rooms r  ON r.id = u.room_id
    JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'maya_ilai';

  SELECT count(*) INTO v_blocks
    FROM availability_blocks ab
    JOIN units u  ON u.id = ab.unit_id
    JOIN rooms r  ON r.id = u.room_id
    JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'maya_ilai';

  -- The finance ledger is a later migration on some installs; guard the read.
  IF to_regclass('public.bookings') IS NOT NULL THEN
    EXECUTE $q$
      SELECT count(*) FROM bookings b
       WHERE b.hold_id IN (
             SELECT h.id FROM holds h
               JOIN units u  ON u.id = h.unit_id
               JOIN rooms r  ON r.id = u.room_id
               JOIN venues v ON v.id = r.venue_id
              WHERE v.slug = 'maya_ilai')
    $q$ INTO v_ledger;
  END IF;

  -- Nightly rate overrides. rates.room_id -> rooms is ON DELETE CASCADE, so
  -- every seasonal price for the property goes with the rooms.
  IF to_regclass('public.rates') IS NOT NULL THEN
    EXECUTE $q$
      SELECT count(*) FROM rates x
        JOIN rooms r  ON r.id = x.room_id
        JOIN venues v ON v.id = r.venue_id
       WHERE v.slug = 'maya_ilai'
    $q$ INTO v_rates;
  END IF;

  -- Room photos. This is the one production almost certainly has.
  IF to_regclass('public.room_images') IS NOT NULL THEN
    EXECUTE $q$
      SELECT count(*) FROM room_images x
        JOIN rooms r  ON r.id = x.room_id
        JOIN venues v ON v.id = r.venue_id
       WHERE v.slug = 'maya_ilai'
    $q$ INTO v_images;
  END IF;

  -- OTA iCal feeds. Losing these silently stops channel sync for the property
  -- and the URLs are held by Airbnb/Booking.com, not by us.
  IF to_regclass('public.ical_feeds') IS NOT NULL THEN
    EXECUTE $q$
      SELECT count(*) FROM ical_feeds x
        JOIN units u  ON u.id = x.unit_id
        JOIN rooms r  ON r.id = u.room_id
        JOIN venues v ON v.id = r.venue_id
       WHERE v.slug = 'maya_ilai'
    $q$ INTO v_feeds;
  END IF;

  IF to_regclass('public.channel_conflicts') IS NOT NULL THEN
    EXECUTE $q$
      SELECT count(*) FROM channel_conflicts x
        JOIN units u  ON u.id = x.unit_id
        JOIN rooms r  ON r.id = u.room_id
        JOIN venues v ON v.id = r.venue_id
       WHERE v.slug = 'maya_ilai'
    $q$ INTO v_conflicts;
  END IF;

  IF v_holds > 0 OR v_blocks > 0 OR v_ledger > 0
     OR v_rates > 0 OR v_images > 0 OR v_feeds > 0 OR v_conflicts > 0 THEN
    RAISE EXCEPTION
      'Refusing to rebuild Maya Ilai - this would destroy: % hold(s), % availability block(s), % ledger row(s), % rate override(s), % room image(s), % iCal feed(s), % channel conflict(s). Re-point or archive them first - see docs/superpowers/plans/2026-09-13-maya-ilai-composite-inventory.md, Task 9.',
      v_holds, v_blocks, v_ledger, v_rates, v_images, v_feeds, v_conflicts;
  END IF;
END $gate$;

DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug = 'maya_ilai');

INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc,
                   price_amount, price_currency, form_mode, is_entire_place,
                   is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, x.price, 'USD',
       'availability', FALSE, TRUE, x.so
FROM venues v, (VALUES
  ('maya-ilai-bunk-room',     'Private Bunk Room',            6,  3,
   'A private room with three bunk beds and six individual beds. Includes 3 guests.', 150, 1),
  ('maya-ilai-double',        'Double Room',                  2,  1,
   'A double bedroom overlooking the pool.', 350, 2),
  ('maya-ilai-studio',        'Studio with Kitchenette',      2,  1,
   'A separate studio within the complex, with a small kitchenette.', 390, 3),
  ('maya-ilai-family-room',   'Two-Bedroom Family Room',      8,  2,
   'A double bedroom and a private bunk room. Includes 5 guests.', 500, 4),
  ('maya-ilai-one-bed-suite', 'One-Bedroom Suite with Kitchen', 2, 1,
   'A double bedroom with a separate living area and kitchen.', 750, 5),
  ('maya-ilai-family-suite',  'Two-Bedroom Family Suite with Kitchen', 8, 2,
   'A double bedroom, bunk room, living area and kitchen. Includes 5 guests.', 900, 6),
  ('maya-ilai-two-bed-suite', 'Two-Bedroom Suite with Kitchen', 4, 2,
   'Two double bedrooms, a living area and kitchen.', 1100, 7),
  ('maya-ilai-villa',         'Three-Bedroom Villa',          10, 3,
   'An entire villa: two double bedrooms, a bunk room, living area and kitchen. Includes 7 guests.', 1170, 8)
) AS x(slug, name, cap, beds, descr, price, so)
WHERE v.slug = 'maya_ilai';

-- The eight physical villas. Every composite product allocates against these.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Villa ' || g, g
  FROM rooms r, generate_series(1, 8) g
 WHERE r.slug = 'maya-ilai-villa';

-- The eight studios are ordinary independent units.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Studio ' || g, g
  FROM rooms r, generate_series(1, 8) g
 WHERE r.slug = 'maya-ilai-studio';

-- Post-condition. Every statement above is driven off venues.slug and the room
-- slugs, and every one of them is happy to affect zero rows. Assert the shape we
-- actually intended before committing, so a partial or empty rebuild aborts
-- loudly instead of leaving the property half-built and looking fine.
DO $verify$
DECLARE
  v_rooms bigint;
  v_units bigint;
BEGIN
  SELECT count(*) INTO v_rooms
    FROM rooms r JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'maya_ilai';

  SELECT count(*) INTO v_units
    FROM units u
    JOIN rooms r  ON r.id = u.room_id
    JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'maya_ilai';

  -- 8 products; 8 villa units + 8 studio units. The six unitless composite
  -- products own no units of their own by design.
  IF v_rooms <> 8 OR v_units <> 16 THEN
    RAISE EXCEPTION
      'Maya Ilai rebuild produced % room(s) and % unit(s), expected 8 and 16. Rolling back.',
      v_rooms, v_units;
  END IF;
END $verify$;

COMMIT;
