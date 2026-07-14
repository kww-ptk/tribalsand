-- Pre-load each property's current gallery photos. Idempotent: clear then insert. Full URLs render via dev proxy + prod.

DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='zuri');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Zuri', x.hero, x.so FROM venues v, (VALUES
  ('/images/hero-zuri.jpg', TRUE, 0)
) AS x(url,hero,so) WHERE v.slug='zuri';

DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='my-amani');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'My Amani', x.hero, x.so FROM venues v, (VALUES
  ('/images/my-amani/Aerial/myamani-11.webp', TRUE, 0),
  ('/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best18.jpg', FALSE, 1),
  ('/images/my-amani/My Amani - Outdoor/My Amani Outdoor Day/My Amani Best20.jpg', FALSE, 2)
) AS x(url,hero,so) WHERE v.slug='my-amani';

DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='maya-kobe');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Maya Kobe', x.hero, x.so FROM venues v, (VALUES
  ('/images/hero-maya-kobe.jpg', TRUE, 0),
  ('/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best3.jpg', FALSE, 1),
  ('/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg', FALSE, 2),
  ('/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best12.jpg', FALSE, 3),
  ('/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best14.jpg', FALSE, 4)
) AS x(url,hero,so) WHERE v.slug='maya-kobe';

DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='enkare-bofa');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, '/images/hero-enkare-bofa.jpg', 'Enkare Bofa', TRUE, 0 FROM venues v WHERE v.slug='enkare-bofa';

DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='sandbox');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, '/images/hero-sandbox.jpg', 'Sandbox', TRUE, 0 FROM venues v WHERE v.slug='sandbox';

DELETE FROM venue_images WHERE venue_id=(SELECT id FROM venues WHERE slug='maya_ilai');
INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order)
SELECT v.id, x.url, 'Maya Ilai', x.hero, x.so FROM venues v, (VALUES
  ('/images/maya_illai/Best1.jpg', TRUE, 0),
  ('/images/maya_illai/Best 2.jpg', FALSE, 1),
  ('/images/maya_illai/Best4.jpg', FALSE, 2)
) AS x(url,hero,so) WHERE v.slug='maya_ilai';
