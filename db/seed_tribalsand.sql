-- Tribal Sand seed: venues + bookable rooms + one unit each.
-- Idempotent via ON CONFLICT (slug).

-- Venues -------------------------------------------------------------
INSERT INTO venues (slug, name, location, sort_order) VALUES
  ('my-amani',     'My Amani',     'Vipingo',  1),
  ('maya-kobe',    'Maya Kobe',    'Kilifi',   2),
  ('zuri',         'Zuri',         'Watamu',   3),
  ('enkare-bofa',  'Enkare Bofa',  'Kilifi',   4),
  ('sandbox',      'Sandbox',      'Vipingo',  5),
  ('maya_ilai',    'Maya Ilai',    'Kilifi',   6),
  ('tribal-dunes', 'Tribal Dunes', 'Community',7)
ON CONFLICT (slug) DO NOTHING;

-- Rooms (slug = booking-widget target; price 0 = set in admin) --------
-- Whole-villa venues: one room per venue (slug matches venue page).
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT v.slug, v.name || ' — Whole Villa', v.id, 0, 'USD', 'availability', 0
FROM venues v
WHERE v.slug IN ('zuri','enkare-bofa','sandbox','maya_ilai','tribal-dunes')
ON CONFLICT (slug) DO NOTHING;

-- Maya Kobe: main house + cottages.
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT r.slug, r.name, v.id, 0, 'USD', 'availability', r.so
FROM (VALUES
  ('maya-kobe-main-house', 'Maya Kobe — Main House', 1),
  ('maya-kobe-cottages',   'Maya Kobe — Cottages',   2)
) AS r(slug, name, so)
JOIN venues v ON v.slug = 'maya-kobe'
ON CONFLICT (slug) DO NOTHING;

-- My Amani room types (the my-amani-*.php pages).
INSERT INTO rooms (slug, name, venue_id, price_amount, price_currency, form_mode, sort_order)
SELECT r.slug, r.name, v.id, 0, 'USD', 'availability', r.so
FROM (VALUES
  ('my-amani-premium-sea-view-single',   'My Amani — Premium Sea View (Single)',   1),
  ('my-amani-premium-sea-view-twin',     'My Amani — Premium Sea View (Twin)',     2),
  ('my-amani-superior-sea-view-single',  'My Amani — Superior Sea View (Single)',  3),
  ('my-amani-superior-sea-view-twin',    'My Amani — Superior Sea View (Twin)',    4),
  ('my-amani-superior-garden-view-single','My Amani — Superior Garden View (Single)',5),
  ('my-amani-superior-garden-view-twin', 'My Amani — Superior Garden View (Twin)', 6),
  ('my-amani-twin-sea-view-single',      'My Amani — Twin Sea View (Single)',      7),
  ('my-amani-twin-sea-view-twin',        'My Amani — Twin Sea View (Twin)',        8),
  ('my-amani-twin-garden-view-single',   'My Amani — Twin Garden View (Single)',   9),
  ('my-amani-twin-garden-view-twin',     'My Amani — Twin Garden View (Twin)',     10),
  ('my-amani-full-rental',               'My Amani — Full Villa Rental',           11)
) AS r(slug, name, so)
JOIN venues v ON v.slug = 'my-amani'
ON CONFLICT (slug) DO NOTHING;

-- One bookable unit per room (rooms were created after add_availability's seed) --
INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms r
WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id);

-- Settings -----------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_email', 'reservations@tribalsand.com'),
  ('form_mode', 'enquiry'),
  ('checkin_instructions', '')
ON CONFLICT (setting_key) DO NOTHING;
