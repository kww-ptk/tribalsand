-- Tribal Sand: outlets sell in Kenyan shillings (owner, Sept 2026). New outlets and
-- supplier payouts default to KES at the database level too. Existing non-KES outlets are
-- switched in Admin → POS outlets → "Switch to KES", which also converts their prices
-- (a plain UPDATE here would relabel $25 as KES 25). Run via /admin/migrate.php. Idempotent.
ALTER TABLE pos_outlets     ALTER COLUMN currency SET DEFAULT 'KES';
ALTER TABLE pos_consignor_payouts ALTER COLUMN currency SET DEFAULT 'KES';
