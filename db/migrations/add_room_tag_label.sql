-- Optional short badge shown on the room card (e.g. "Oceanfront", "Family Suite").
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS tag_label VARCHAR(100);
