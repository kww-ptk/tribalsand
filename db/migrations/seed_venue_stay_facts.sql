-- Seed: per-property WiFi + house-rules facts onto the venue stay-info fields
-- (board, WiFi/no-TV, staff/nanny, security hours, Maya Ilai age policy).
-- Run once from Admin -> Migrations. NON-DESTRUCTIVE: each UPDATE only fills a
-- field that is currently blank, so it never overwrites text you have set.
-- Safe to re-run; safe to delete this file after it has been run.

-- Ensure the stay-info columns exist (no-op if the migration already ran).
ALTER TABLE venues
  ADD COLUMN IF NOT EXISTS stay_wifi        TEXT,
  ADD COLUMN IF NOT EXISTS stay_house_rules TEXT;

-- Zuri
UPDATE venues SET stay_wifi = 'WiFi throughout the property. No televisions in the rooms.', updated_at = NOW()
 WHERE slug = 'zuri' AND (stay_wifi IS NULL OR stay_wifi = '');
UPDATE venues SET stay_house_rules = 'Non-smoking property. Board: bed & breakfast, with an a la carte menu for other meals and bar service. Baby cot and high chair on request. Private staff, nanny and driver accommodation at extra cost. Massage area on site. Day use available from 1 July 2026.', updated_at = NOW()
 WHERE slug = 'zuri' AND (stay_house_rules IS NULL OR stay_house_rules = '');

-- Maya Kobe
UPDATE venues SET stay_wifi = 'WiFi throughout. No televisions in the rooms.', updated_at = NOW()
 WHERE slug = 'maya-kobe' AND (stay_wifi IS NULL OR stay_wifi = '');
UPDATE venues SET stay_house_rules = 'Non-smoking property. Board: bed & breakfast, a la carte for other meals and bar. Cot and high chair on request. Staff and driver accommodation at extra cost. Part of Tribal Dunes, walking distance to Tribal Table, Somewhere Cafe and the kite school.', updated_at = NOW()
 WHERE slug = 'maya-kobe' AND (stay_house_rules IS NULL OR stay_house_rules = '');

-- My Amani
UPDATE venues SET stay_wifi = 'Basic WiFi with limited coverage and speed. No televisions.', updated_at = NOW()
 WHERE slug = 'my-amani' AND (stay_wifi IS NULL OR stay_wifi = '');
UPDATE venues SET stay_house_rules = 'Non-smoking property. Board: self-catering with a dedicated chef and on-site support staff included. Cot and high chair on request. No accommodation for nannies or private staff. Whole-villa exclusive use. 5 ensuite bedrooms, sleeps 10.', updated_at = NOW()
 WHERE slug = 'my-amani' AND (stay_house_rules IS NULL OR stay_house_rules = '');

-- Enkare
UPDATE venues SET stay_wifi = 'Basic WiFi with limited coverage and speed. No televisions.', updated_at = NOW()
 WHERE slug = 'enkare-bofa' AND (stay_wifi IS NULL OR stay_wifi = '');
UPDATE venues SET stay_house_rules = 'Non-smoking property. Board: self-catering with an in-house cook. Daily housekeeping and a pool attendant or gardener. Security staff on site 6pm to 5am. Cot and high chair on request. No nanny or private-staff accommodation. Whole-villa exclusive use. 5 bedrooms, sleeps 10.', updated_at = NOW()
 WHERE slug = 'enkare-bofa' AND (stay_house_rules IS NULL OR stay_house_rules = '');

-- Sandbox
UPDATE venues SET stay_wifi = 'Basic WiFi with limited coverage and speed. No televisions.', updated_at = NOW()
 WHERE slug = 'sandbox' AND (stay_wifi IS NULL OR stay_wifi = '');
UPDATE venues SET stay_house_rules = 'Non-smoking property. Board: self-catering, no chef (a cook can be arranged at extra cost, subject to availability). Daily housekeeping and a pool attendant or gardener. Security 6pm to 5am. Cot and high chair on request. No nanny or private-staff accommodation. Whole-villa exclusive use. 4 bedrooms, sleeps 8.', updated_at = NOW()
 WHERE slug = 'sandbox' AND (stay_house_rules IS NULL OR stay_house_rules = '');

-- Maya Ilai (age policy)
UPDATE venues SET stay_house_rules = 'Adults-only property: guests must be 16 or older; children under 16 cannot stay. Guests aged 16 to 17 may stay without a parent present, but a parent or legal guardian must sign a consent form at check-in to authorise alcohol. Fully solar-powered with a desalinated water system. Communal pool, bars and gardens. Electric bikes and golf carts on site.', updated_at = NOW()
 WHERE slug = 'maya_ilai' AND (stay_house_rules IS NULL OR stay_house_rules = '');
