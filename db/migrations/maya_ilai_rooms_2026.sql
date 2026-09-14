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

COMMIT;
