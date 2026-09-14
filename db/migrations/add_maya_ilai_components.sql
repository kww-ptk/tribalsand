-- Maya Ilai composite inventory: which components of a villa a block consumes.
-- NULL means "the whole unit", which is what every existing row means, so this
-- is a no-op for Zuri, Maya Kobe and every other property.
-- Values are a subset of {double_a, double_b, bunk, living}. Studio blocks stay NULL.
ALTER TABLE availability_blocks ADD COLUMN IF NOT EXISTS components TEXT[] NULL;

-- Villas held back for whole-villa sales only. The last N villas by sort order.
INSERT INTO settings (setting_key, setting_value)
VALUES ('maya_ilai_reserved_villas', '2')
ON CONFLICT (setting_key) DO NOTHING;
