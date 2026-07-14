-- Editable property page copy: tagline (eyebrow line), About heading + body.
-- Empty/NULL => the page keeps its built-in fallback text.
ALTER TABLE venues
  ADD COLUMN IF NOT EXISTS tagline       VARCHAR(255),
  ADD COLUMN IF NOT EXISTS about_heading VARCHAR(255),
  ADD COLUMN IF NOT EXISTS about_body    TEXT;
