-- Pre-load each property's gallery photos. Idempotent: clear then insert.
-- Paths are the existing /images/... files (served in prod + via dev proxy).
-- Run with: php bin/migrate.php db/seed_venue_images.sql

-- ── ZURI ─────────────────────────────────────────────────────────────
DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='zuri');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Zuri · Watamu', x.hero, x.so FROM venues v, (VALUES
  ('/images/hero-zuri.jpg', TRUE, 0),
  ('/images/zuri/Aerial/zuri-3.webp', FALSE, 1),
  ('/images/zuri/Aerial/zuri-5.webp', FALSE, 2),
  ('/images/zuri/Garden/zuri.watamu.morning.pool-3.webp', FALSE, 3),
  ('/images/zuri/Beach/zuri.watamu.beach.webp', FALSE, 4),
  ('/images/zuri/Aerial/zuri-11.webp', FALSE, 5),
  ('/images/zuri/Aerial/zuri-12.webp', FALSE, 6)
) AS x(url,hero,so) WHERE v.slug='zuri';

-- ── MY AMANI ─────────────────────────────────────────────────────────
DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='my-amani');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'My Amani · Vipingo', x.hero, x.so FROM venues v, (VALUES
  ('/images/my-amani/Aerial/myamani-11.webp', TRUE, 0),
  ('/images/my-amani/Aerial/myamani-3.webp', FALSE, 1),
  ('/images/my-amani/Aerial/myamani-5.webp', FALSE, 2),
  ('/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best18.jpg', FALSE, 3),
  ('/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best20.jpg', FALSE, 4),
  ('/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best21.jpg', FALSE, 5),
  ('/images/my-amani/My Amani - Bedrooms/Bedroom 1/My Amani Best47.jpg', FALSE, 6)
) AS x(url,hero,so) WHERE v.slug='my-amani';

-- ── MAYA KOBE ────────────────────────────────────────────────────────
DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='maya-kobe');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Maya Kobe · Kilifi', x.hero, x.so FROM venues v, (VALUES
  ('/images/hero-maya-kobe.jpg', TRUE, 0),
  ('/images/maya-kobe/Aerial/mayakobe-2.webp', FALSE, 1),
  ('/images/maya-kobe/Aerial/mayakobe-5.webp', FALSE, 2),
  ('/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg', FALSE, 3),
  ('/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg', FALSE, 4),
  ('/images/maya-kobe/Maya Kobe - Bedrooms/Bedroom 1/mayakobe.bedroomrightfrontsunrise.webp', FALSE, 5),
  ('/images/maya-kobe/Maya Kobe - Cottage/Cottage Bedrooms/Maya Kobe prestige suite.jpg', FALSE, 6)
) AS x(url,hero,so) WHERE v.slug='maya-kobe';

-- ── ENKARE BOFA ──────────────────────────────────────────────────────
DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='enkare-bofa');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Enkare Bofa · Kilifi', x.hero, x.so FROM venues v, (VALUES
  ('/images/hero-enkare-bofa.jpg', TRUE, 0),
  ('/images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg', FALSE, 1),
  ('/images/enkare-bofa/SwimmingPool/IMG-20251117-WA0008.jpg', FALSE, 2),
  ('/images/enkare-bofa/Bedrooms/IMG-20251117-WA0002.jpg', FALSE, 3),
  ('/images/enkare-bofa/Living-Dining/IMG-20251117-WA0001.jpg', FALSE, 4),
  ('/images/enkare-bofa/Bedrooms/IMG-20251117-WA0004.jpg', FALSE, 5)
) AS x(url,hero,so) WHERE v.slug='enkare-bofa';

-- ── SANDBOX ──────────────────────────────────────────────────────────
DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='sandbox');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Sandbox · Kilifi', x.hero, x.so FROM venues v, (VALUES
  ('/images/hero-sandbox.jpg', TRUE, 0),
  ('/images/Sandbox/outdoors/IMG-20251117-WA0091.jpg', FALSE, 1),
  ('/images/Sandbox/swimmingpool/IMG-20251117-WA0054.jpg', FALSE, 2),
  ('/images/Sandbox/Bedroom/IMG-20251117-WA0077.jpg', FALSE, 3),
  ('/images/Sandbox/living-dining/IMG-20251117-WA0045.jpg', FALSE, 4),
  ('/images/Sandbox/Bedroom/IMG-20251117-WA0078.jpg', FALSE, 5)
) AS x(url,hero,so) WHERE v.slug='sandbox';

-- ── MAYA ILAI ────────────────────────────────────────────────────────
DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='maya_ilai');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Maya Ilai · Kilifi', x.hero, x.so FROM venues v, (VALUES
  ('/images/maya_illai/Best1.jpg', TRUE, 0),
  ('/images/maya_illai/Best 2.jpg', FALSE, 1),
  ('/images/maya_illai/Best4.jpg', FALSE, 2),
  ('/images/maya_illai/Best9.jpg', FALSE, 3),
  ('/images/maya_illai/SITE PHOTOS BAR AND POOL-images-1.jpg', FALSE, 4),
  ('/images/maya_illai/Studios/Studio1.jpeg', FALSE, 5),
  ('/images/maya_illai/Studios/Studio10.jpeg', FALSE, 6)
) AS x(url,hero,so) WHERE v.slug='maya_ilai';
