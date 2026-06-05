-- Migration: add tours feature
-- Run: psql $DATABASE_URL -f db/migrations/add_tours.sql

-- Tours
CREATE TABLE IF NOT EXISTS tours (
    id               SERIAL PRIMARY KEY,
    slug             VARCHAR(100) NOT NULL UNIQUE,
    name             VARCHAR(255) NOT NULL,
    category         VARCHAR(20)  NOT NULL DEFAULT 'classic',
    tag_label        VARCHAR(100),
    duration         VARCHAR(100),
    short_desc       TEXT,
    long_desc        TEXT,
    highlights_json  JSONB        NOT NULL DEFAULT '[]',
    seo_title        VARCHAR(255),
    seo_description  VARCHAR(320),
    sort_order       INT          NOT NULL DEFAULT 0,
    is_published     BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at       TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- Tour images
CREATE TABLE IF NOT EXISTS tour_images (
    id         SERIAL PRIMARY KEY,
    tour_id    INT          NOT NULL REFERENCES tours(id) ON DELETE CASCADE,
    filename   VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(255),
    is_hero    BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tour_images_tour_id ON tour_images(tour_id);

-- Add tour_id to submissions
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS tour_id INT REFERENCES tours(id) ON DELETE SET NULL;

-- Seed: classic safaris
INSERT INTO tours (slug, name, category, tag_label, duration, short_desc, sort_order) VALUES
('tsavo-east',                  'Tsavo East',                          'classic',   'Classic Safari',  NULL, 'A first encounter with Kenya''s red-earth wilderness and its famous elephants — the closest of the great parks to Watamu.', 1),
('tsavo-east-west',             'Tsavo East & West',                   'classic',   'Classic Safari',  NULL, 'Two contrasting landscapes in one journey — open savannah and the lava springs and hills of Tsavo West.', 2),
('tsavo-east-amboseli',         'Tsavo East & Amboseli',               'classic',   'Classic Safari',  NULL, 'Wildlife-rich plains framed by the snow-capped silhouette of Mount Kilimanjaro across the border.', 3),
('tsavo-west-amboseli-east',    'Tsavo West, Amboseli & Tsavo East',   'classic',   'Classic Safari',  NULL, 'The grand circuit — three legendary parks across several days for the complete Kenyan safari.', 4),
('kenya-colours',               'Kenya Colours',                       'custom',    'Custom Journey',  NULL, 'A tailored route through the colours of the coast and the bush, shaped around your interests and pace.', 5),
('masai-footpaths',             'Masai Footpaths',                     'custom',    'Custom Journey',  NULL, 'Walk the land with Masai guides and meet the communities who have lived alongside the wildlife for generations.', 6),
('author-lakes',                'Author Lakes',                        'custom',    'Custom Journey',  NULL, 'The Rift Valley lakes — flamingo-pink shallows and birdlife — on a relaxed, photography-led itinerary.', 7),
('masai-mara',                  'Masai Mara',                          'custom',    'Custom Journey',  '2 days / 1 night or 3 days / 2 nights', 'The Mara at its best — the wide plains and great migration.', 8),
('safari-tsavo-adventure',      'Safari Tsavo East — Adventure',       'excursion', 'Excursion',       '2 days / 1 night', 'An overnight adventure in Tsavo East — 4x4 cross-country vehicle.', 9),
('safari-tsavo-explorer',       'Safari Tsavo East — Explorer',        'excursion', 'Excursion',       '1 day', 'A full-day exploration of Tsavo East by 4x4 cross-country vehicle.', 10),
('safari-blu',                  'Safari Blu',                          'excursion', 'Excursion',       NULL, 'Snorkelling at the Marine Park & Sardegna Two.', 11),
('che-shale',                   'Che Shale',                           'excursion', 'Excursion',       NULL, 'A wild stretch of coast north of Watamu.', 12),
('mida-adventure',              'Mida Adventure',                      'excursion', 'Excursion',       NULL, 'Marine Park & the Mida Creek mangroves.', 13),
('malindi-tour',                'Malindi Tour',                        'excursion', 'Excursion',       NULL, 'The historic coastal town of Malindi.', 14),
('ruins-of-gede',               'The Ruins of Gede',                   'excursion', 'Excursion',       NULL, 'The lost Swahili town in the coastal forest.', 15),
('quad-safari',                 'Quad Safari',                         'excursion', 'Excursion',       NULL, 'An off-road quad-bike trail through the bush.', 16)
ON CONFLICT (slug) DO NOTHING;
