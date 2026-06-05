-- Per-room form mode override
-- NULL  -> inherit the global `form_mode` setting
-- 'enquiry'     -> always show the enquiry form on this room
-- 'availability'-> always show the live availability calendar on this room

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS form_mode VARCHAR(20);

ALTER TABLE rooms
  DROP CONSTRAINT IF EXISTS rooms_form_mode_check;

ALTER TABLE rooms
  ADD CONSTRAINT rooms_form_mode_check
  CHECK (form_mode IS NULL OR form_mode IN ('enquiry', 'availability'));
