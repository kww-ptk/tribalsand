-- Tribal Sand: per-property stay info + location. Moves stay_* off the global
-- settings table onto each venue. Run via /admin/migrate.php. Idempotent.
ALTER TABLE venues
  ADD COLUMN IF NOT EXISTS address          TEXT,
  ADD COLUMN IF NOT EXISTS maps_url         TEXT,
  ADD COLUMN IF NOT EXISTS stay_wifi        TEXT,
  ADD COLUMN IF NOT EXISTS stay_checkout    TEXT,
  ADD COLUMN IF NOT EXISTS stay_house_rules TEXT,
  ADD COLUMN IF NOT EXISTS stay_area_guide  TEXT;

-- One-time seed: carry existing GLOBAL stay values into every venue as an
-- editable starting point, so the app isn't blank on deploy. Only fills NULLs.
UPDATE venues SET
  stay_wifi        = COALESCE(stay_wifi,        (SELECT setting_value FROM settings WHERE setting_key='stay_wifi')),
  stay_checkout    = COALESCE(stay_checkout,    (SELECT setting_value FROM settings WHERE setting_key='stay_checkout')),
  stay_house_rules = COALESCE(stay_house_rules, (SELECT setting_value FROM settings WHERE setting_key='stay_house_rules')),
  stay_area_guide  = COALESCE(stay_area_guide,  (SELECT setting_value FROM settings WHERE setting_key='stay_area_guide'));
