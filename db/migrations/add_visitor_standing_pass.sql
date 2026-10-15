-- Standing passes for registered visitors (members, friends, local community,
-- contractors): a label, an end date (NULL = no end) and the places it covers
-- (NULL = all). Issued by the owner or a manager in Gate → Visitors. The visitor
-- still registers once (ID + waiver) and still picks a place each visit.
-- After add_visitor_registration. Idempotent — safe to re-run.
ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS pass_type      VARCHAR(24);
ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS pass_until     DATE;
ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS pass_place_ids INT[];
ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS pass_note      TEXT;
ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS pass_by        INT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS pass_at        TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS idx_regvis_pass ON registered_visitors (pass_type) WHERE pass_type IS NOT NULL;
