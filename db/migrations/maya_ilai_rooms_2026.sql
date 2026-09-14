-- Maya Ilai — the room catalogue as eight products over shared villas.
--
-- Physical inventory: 8 villas x (2 double + 1 bunk + 1 living) + 8 studios.
-- The eight villa UNITS belong to maya-ilai-villa; the six other composite
-- products own no units and resolve against those same villas via
-- includes/maya-ilai-inventory.php. The studio is an ordinary independent room.
--
-- capacity = MAXIMUM guests, because that is what the guest-count filter in
-- ts_search_availability() compares against.
--
-- NON-DESTRUCTIVE, and deliberately so. This migration was first written as a
-- DELETE-then-INSERT rebuild, on the assumption that production was close to
-- db/seed_rooms_2026.sql. It is not. Production already carries maya-ilai-villa
-- and maya-ilai-studio under exactly these slugs, with their eight units each,
-- and six holds and an availability block standing on them. The rebuild's own
-- safety gate refused to run, correctly: rooms -> units -> holds all cascade, so
-- it would have deleted sixteen units and six bookings in order to recreate
-- fifteen of those units identically.
--
-- So this upserts instead. The two existing rooms are updated in place and the
-- six composite products are inserted beside them. Nothing is deleted, no hold
-- moves, no unit is recreated, and the end state is the same eight products.
--
-- Idempotent: safe on a database that has the old destructive version applied,
-- on production's three-room catalogue, and on a re-run of itself.

BEGIN;

-- Ordering gate. Without availability_blocks.components every composite product
-- would fall back to whole-unit blocking and silently oversell, so this fails
-- loudly rather than degrading. Run add_maya_ilai_components.sql first.
DO $ordering$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'availability_blocks' AND column_name = 'components'
  ) THEN
    RAISE EXCEPTION
      'availability_blocks.components is missing - run db/migrations/add_maya_ilai_components.sql first';
  END IF;
END $ordering$;

-- Existence gate. Everything below keys off venues.slug = 'maya_ilai'. If that
-- slug is not exactly right on this database every statement matches nothing and
-- the whole thing COMMITs having done absolutely nothing — the worst outcome
-- available, because it looks like a clean run. Fail loudly instead.
DO $venue$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM venues WHERE slug = 'maya_ilai') THEN
    RAISE EXCEPTION
      'No venue with slug ''maya_ilai'' - nothing to build. Check the real slug (SELECT id, slug, name FROM venues) before running this.';
  END IF;
END $venue$;

-- Ownership gate. rooms.slug is globally unique, not unique per venue, so an
-- upsert keyed on slug would quietly adopt a row belonging to another property
-- and move it to Maya Ilai. Refuse rather than steal it.
DO $owner$
DECLARE
  v_stolen text;
BEGIN
  SELECT string_agg(r.slug, ', ') INTO v_stolen
    FROM rooms r
   WHERE r.slug IN ('maya-ilai-bunk-room','maya-ilai-double','maya-ilai-studio',
                    'maya-ilai-family-room','maya-ilai-one-bed-suite',
                    'maya-ilai-family-suite','maya-ilai-two-bed-suite','maya-ilai-villa')
     AND r.venue_id IS DISTINCT FROM (SELECT id FROM venues WHERE slug = 'maya_ilai');

  IF v_stolen IS NOT NULL THEN
    RAISE EXCEPTION
      'These room slugs already belong to another venue: %. Re-point or rename them before running this.', v_stolen;
  END IF;
END $owner$;

-- The eight products.
--
-- Prices are rooms.price_amount = the HIGH-season figure from the internal rate
-- tool, in USD — the currency the tool and the guest configurator quote in.
-- Maya Ilai previously priced in KES; see the note at the foot of this file.
-- Dated seasonal ranges are a separate follow-on migration.
--
-- short_desc is written only when the row has none. Names and capacities are
-- authoritative here (production had the villa listed as "Family Room" at
-- capacity 6, which collides with the new Two-Bedroom Family Room and understates
-- the villa), but descriptive copy belongs to whoever edits it in admin.
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
WHERE v.slug = 'maya_ilai'
ON CONFLICT (slug) DO UPDATE SET
  name            = EXCLUDED.name,
  venue_id        = EXCLUDED.venue_id,
  capacity        = EXCLUDED.capacity,
  bed_count       = EXCLUDED.bed_count,
  short_desc      = COALESCE(NULLIF(rooms.short_desc, ''), EXCLUDED.short_desc),
  price_amount    = EXCLUDED.price_amount,
  price_currency  = EXCLUDED.price_currency,
  form_mode       = EXCLUDED.form_mode,
  is_entire_place = EXCLUDED.is_entire_place,
  is_published    = TRUE,
  sort_order      = EXCLUDED.sort_order,
  updated_at      = NOW();

-- Everything else at Maya Ilai stops being offered to guests — on production
-- that is `superior-suite` (and any buyout row). Unpublished, NOT deleted: the
-- room, its unit and every booking against it stay intact and visible in admin.
--
-- This also closes a double-sell: superior-suite is capacity 6 at one unit,
-- which is plausibly one of the same eight villas under a second listing. While
-- it is unpublished no guest can book it twice over.
UPDATE rooms r
   SET is_published = FALSE, updated_at = NOW()
  FROM venues v
 WHERE v.id = r.venue_id
   AND v.slug = 'maya_ilai'
   AND r.is_published
   AND r.slug NOT IN ('maya-ilai-bunk-room','maya-ilai-double','maya-ilai-studio',
                      'maya-ilai-family-room','maya-ilai-one-bed-suite',
                      'maya-ilai-family-suite','maya-ilai-two-bed-suite','maya-ilai-villa');

-- The eight physical villas, and the eight studios. Every composite product
-- allocates against the villa units.
--
-- Insert-what-is-missing, matched on name: units carry holds and iCal feed
-- tokens, so an existing "Villa 3" is left exactly as it is. On production all
-- sixteen already exist and both statements are no-ops.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Villa ' || g, g
  FROM rooms r, generate_series(1, 8) g
 WHERE r.slug = 'maya-ilai-villa'
   AND NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id AND u.name = 'Villa ' || g);

INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Studio ' || g, g
  FROM rooms r, generate_series(1, 8) g
 WHERE r.slug = 'maya-ilai-studio'
   AND NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id AND u.name = 'Studio ' || g);

-- Post-condition. Every statement above is driven off venues.slug and the room
-- slugs, and every one of them is happy to affect zero rows. Assert the shape we
-- actually intended before committing, so a partial build aborts loudly instead
-- of leaving the property half-done and looking fine.
DO $verify$
DECLARE
  v_rooms  bigint;
  v_villas bigint;
  v_studio bigint;
BEGIN
  SELECT count(*) INTO v_rooms
    FROM rooms r JOIN venues v ON v.id = r.venue_id
   WHERE v.slug = 'maya_ilai' AND r.is_published;

  SELECT count(*) INTO v_villas
    FROM units u JOIN rooms r ON r.id = u.room_id
   WHERE r.slug = 'maya-ilai-villa' AND u.is_active;

  SELECT count(*) INTO v_studio
    FROM units u JOIN rooms r ON r.id = u.room_id
   WHERE r.slug = 'maya-ilai-studio' AND u.is_active;

  -- Exactly the eight products are on sale; the six unitless composites own no
  -- units by design, so only the villa and studio are counted.
  IF v_rooms <> 8 OR v_villas <> 8 OR v_studio <> 8 THEN
    RAISE EXCEPTION
      'Maya Ilai build did not produce the expected shape: % published room(s) (want 8), % active villa unit(s) (want 8), % active studio unit(s) (want 8).',
      v_rooms, v_villas, v_studio;
  END IF;
END $verify$;

COMMIT;

-- AFTER THIS RUNS
--
-- Currency. The eight rooms are USD. Maya Ilai sold in KES until now, so any
-- historical booking stays KES and admin/reports.php will show two currency
-- lines for this property — money is never summed across currencies. Nothing is
-- rewritten retrospectively.
--
-- Photographs. The six new products have no room_images yet, so the guest
-- configurator falls back to the venue's own photographs for them. Upload per
-- room in Admin -> Properties -> Maya Ilai -> Rooms.
