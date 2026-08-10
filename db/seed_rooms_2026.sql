-- Rebuild by-room inventory for Zuri, Maya Kobe, Maya Ilai (2026-08-10).
-- Source of truth: the live venue pages (zuri.php, maya-kobe.php, maya_ilai.php).
--   Zuri       = 6 ocean suites + full-property buyout (up to 14).
--   Maya Kobe  = 5 ocean suites + full-property buyout (up to 12; 16 with Prestige).
--   Maya Ilai  = 2 room types: Three-Bedroom Villa (x8 units) + Studio Apartment (x8 units). Adults 16+.
-- My Amani, Enkare Bofa, Sandbox, Tribal Dunes are already correct (entire-property) and are NOT touched.
--
-- Idempotent: deletes each target venue's rooms then re-inserts. rooms->units->holds->availability_blocks
-- all cascade on delete, so this also clears the pre-launch TEST holds on Zuri/Maya Kobe. Submissions (leads)
-- are NOT deleted. Prices left 0 / USD -- owner sets real price + currency in admin (existing convention).

-- Clean up any orphaned rooms (no venue) left by dev experiments.
DELETE FROM rooms WHERE venue_id IS NULL;

-- ===================== ZURI: 6 suites + buyout =====================
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug = 'zuri');
INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc, price_amount, price_currency, form_mode, is_entire_place, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, 0, 'USD', 'availability', x.entire, TRUE, x.so
FROM venues v, (VALUES
  ('zuri-maji',   'Maji Suite',   2, 1, 'An intimate oceanfront suite with king bed, en-suite bathroom and direct Indian Ocean views.',                         FALSE, 1),
  ('zuri-mwezi',  'Mwezi Suite',  4, 3, 'Zuri''s most spacious suite -- a king bed plus two twins, perfect for families or a group of four.',                     FALSE, 2),
  ('zuri-ua',     'Ua Suite',     2, 1, 'A serene king suite with en-suite bathroom and air conditioning, made for couples.',                                   FALSE, 3),
  ('zuri-anga',   'Anga Suite',   2, 1, 'A tranquil king suite with full suite comforts and ocean-side calm.',                                                   FALSE, 4),
  ('zuri-jua',    'Jua Suite',    2, 1, 'A light-filled king suite with en-suite bathroom and all suite amenities.',                                             FALSE, 5),
  ('zuri-bahari', 'Bahari Suite', 2, 2, 'A twin-bed suite with ocean-facing comforts, ideal for friends travelling together.',                                  FALSE, 6),
  ('zuri-buyout', 'Zuri -- Full Property Buyout', 14, NULL, 'Exclusive use of all six ocean-facing suites, private pool and beachfront -- up to 14 guests.',     TRUE,  7)
) AS x(slug, name, cap, beds, descr, entire, so)
WHERE v.slug = 'zuri';

-- ===================== MAYA KOBE: 5 suites + buyout =====================
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug = 'maya-kobe');
INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc, price_amount, price_currency, form_mode, is_entire_place, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, 0, 'USD', 'availability', x.entire, TRUE, x.so
FROM venues v, (VALUES
  ('maya-kobe-prestige', 'Prestige Suite', 4, 2, 'A private two-bedroom suite with its own pool and open-air bathtub, sleeping up to 4.', FALSE, 1),
  ('maya-kobe-haze',     'Haze Suite',     2, 1, 'An ocean-facing suite with king bed and en-suite bathroom.',                             FALSE, 2),
  ('maya-kobe-glow',     'Glow Suite',     2, 1, 'A bright ocean suite with king bed and full suite comforts.',                            FALSE, 3),
  ('maya-kobe-tide',     'Tide Suite',     2, 1, 'An ocean-facing king suite steps from the beach.',                                       FALSE, 4),
  ('maya-kobe-drift',    'Drift Suite',    2, 1, 'A serene ocean-view king suite with en-suite bathroom.',                                 FALSE, 5),
  ('maya-kobe-buyout',   'Maya Kobe -- Full Property Buyout', 16, NULL, 'Exclusive use of all five ocean suites -- up to 12 guests (16 with the Prestige Suite).', TRUE, 6)
) AS x(slug, name, cap, beds, descr, entire, so)
WHERE v.slug = 'maya-kobe';

-- ===================== MAYA ILAI: villa + studio room types + compound buyout =====================
-- The maya_ilai.php booking sidebar is a whole-compound "Book Maya Ilai" panel, so a buyout room
-- (is_entire_place) backs it; the villa/studio room types carry the 8+8 bookable units.
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug = 'maya_ilai');
INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc, price_amount, price_currency, form_mode, is_entire_place, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, 0, 'USD', 'availability', x.entire, TRUE, x.so
FROM venues v, (VALUES
  ('maya-ilai-villa',   'Three-Bedroom Villa', 6,  3,    'A self-contained three-bedroom villa (rooms configured as doubles or bunks). Eight identical villas share the communal pool, bar and gardens. Adults 16+.', FALSE, 1),
  ('maya-ilai-studio',  'Studio Apartment',    2,  1,    'A private studio apartment with shared access to the communal pool, bar and gardens. Eight identical studios. Adults 16+.',                              FALSE, 2),
  ('maya-ilai-buyout',  'Maya Ilai -- Full Compound Buyout', 48, NULL, 'Exclusive use of the entire eco compound -- all eight three-bedroom villas and eight studios, communal pool, bar and gardens. Adults 16+.', TRUE, 3)
) AS x(slug, name, cap, beds, descr, entire, so)
WHERE v.slug = 'maya_ilai';

-- ===================== UNITS =====================
-- Maya Ilai: 8 bookable units per room type.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Villa ' || g, g FROM rooms r, generate_series(1, 8) g WHERE r.slug = 'maya-ilai-villa';

INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Studio ' || g, g FROM rooms r, generate_series(1, 8) g WHERE r.slug = 'maya-ilai-studio';

-- Every other rebuilt room (suites + buyouts) gets a single unit.
INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms r WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id);
