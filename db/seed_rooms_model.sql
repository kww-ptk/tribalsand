-- Per-property booking model fix. Idempotent: deletes each venue's rooms then re-inserts the correct set.
-- Pre-launch DB only; test holds/units/blocks cascade via FK. Room slugs are booking identifiers
-- (resolved by /api/check-availability.php?room=slug) and need NOT match a page file for sub-rooms.

-- Also clean up orphaned rooms (no venue_id) left by dev experiments
DELETE FROM rooms WHERE venue_id IS NULL;

-- ENTIRE VILLA: My Amani — one whole-villa room
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='my-amani');
INSERT INTO rooms (slug, name, venue_id, capacity, price_amount, price_currency, form_mode, is_entire_place, is_published, sort_order)
SELECT 'my-amani', 'My Amani — Whole Villa', id, 10, 0, 'USD', 'availability', TRUE, TRUE, 0 FROM venues WHERE slug='my-amani';

-- ENTIRE VILLA: Enkare Bofa, Sandbox — flag existing single room
UPDATE rooms SET is_entire_place = TRUE
WHERE venue_id IN (SELECT id FROM venues WHERE slug IN ('enkare-bofa','sandbox'));

-- BY-ROOM: Zuri — 6 named suites
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='zuri');
INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc, price_amount, price_currency, form_mode, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, 0, 'USD', 'availability', TRUE, x.so
FROM venues v, (VALUES
  ('zuri-maji','Maji Suite',2,1,'An intimate oceanfront suite with king bed, en-suite bathroom and direct Indian Ocean views.',1),
  ('zuri-mwezi','Mwezi Suite',4,3,'Zuri''s most spacious suite — a king bed plus two twins, perfect for families or a group of four.',2),
  ('zuri-ua','Ua Suite',2,1,'A serene king suite with en-suite bathroom and air conditioning, made for couples.',3),
  ('zuri-anga','Anga Suite',2,1,'A tranquil king suite with full suite comforts and ocean-side calm.',4),
  ('zuri-jua','Jua Suite',2,1,'A light-filled king suite with en-suite bathroom and all suite amenities.',5),
  ('zuri-bahari','Bahari Suite',2,2,'A twin-bed suite with ocean-facing comforts, ideal for friends travelling together.',6)
) AS x(slug,name,cap,beds,descr,so)
WHERE v.slug='zuri';

-- BY-ROOM: Maya Kobe — 5 suites, KES prices
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='maya-kobe');
INSERT INTO rooms (slug, name, venue_id, capacity, price_amount, price_currency, form_mode, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.price, 'KES', 'availability', TRUE, x.so
FROM venues v, (VALUES
  ('maya-kobe-prestige','Prestige Suite 2',4,72800,1),
  ('maya-kobe-haze','Haze Suite',2,30000,2),
  ('maya-kobe-glow','Glow Suite',2,31009,3),
  ('maya-kobe-tide','Tide Suite',2,33800,4),
  ('maya-kobe-drift','Drift Suite',2,33800,5)
) AS x(slug,name,cap,price,so)
WHERE v.slug='maya-kobe';

-- BY-ROOM: Maya Ilai — placeholder room types
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='maya_ilai');
INSERT INTO rooms (slug, name, venue_id, capacity, price_amount, price_currency, form_mode, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, 0, 'USD', 'availability', TRUE, x.so
FROM venues v, (VALUES
  ('maya-ilai-studio','Studio',2,1),
  ('maya-ilai-garden-room','Garden Room',2,2)
) AS x(slug,name,cap,so)
WHERE v.slug='maya_ilai';

-- Units: ensure one per room
INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms r WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id=r.id);

-- Zuri suite hero photos (full URLs so storage_url() passes them through; proxied in dev)
-- Jua uses the Garden balcony shot; Bahari uses the Beach shot (both confirmed in zuri.php)
INSERT INTO room_images (room_id, filename, is_hero, sort_order)
SELECT r.id, x.url, TRUE, 0
FROM rooms r, (VALUES
  ('zuri-maji','https://tribalsand.com/images/zuri/Maji Suite/Maji Suite 1.jpg'),
  ('zuri-mwezi','https://tribalsand.com/images/zuri/Mwezi Suite/IMG-20251121-WA0030.jpg'),
  ('zuri-ua','https://tribalsand.com/images/zuri/Ua Suite/Ua Suite 1.jpg'),
  ('zuri-anga','https://tribalsand.com/images/zuri/Anga Suite/Anga Suite 1.jpg'),
  ('zuri-jua','https://tribalsand.com/images/zuri/Garden/zuri.watamu.morning.upstares.outdoor.webp'),
  ('zuri-bahari','https://tribalsand.com/images/zuri/Beach/zuri.watamu.beach.webp')
) AS x(slug,url)
WHERE r.slug = x.slug;
