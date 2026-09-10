-- Seed: Watamu / Zuri curated experiences into the Activities catalogue (tours).
-- Run once from Admin -> Migrations. Idempotent (upserts on slug, safe to re-run).
-- Adds the price/location columns first if they are missing, so it is self-contained.
-- Safe to delete this file after it has been run.

ALTER TABLE tours ADD COLUMN IF NOT EXISTS price    VARCHAR(80);
ALTER TABLE tours ADD COLUMN IF NOT EXISTS location VARCHAR(20) NOT NULL DEFAULT 'all';

INSERT INTO tours (slug, name, category, duration, short_desc, price, location, sort_order, is_published) VALUES
 ('zuri-snorkelling-dolphin','Snorkelling & Dolphin Watching — Watamu Marine Park','excursion','Full day','Coral reefs in Watamu Marine National Park plus dolphin watching. All swimming levels. Private boat, captain, fuel and snorkelling gear.','From $40 per person (+ $15 park fee)','watamu',10,TRUE),
 ('zuri-scuba-2-dives','Scuba Diving — Certified (2 dives)','excursion','Half day','Deeper reefs with professional instructors. Certification required. Boat, dive master and full equipment.','From $95 per person (+ $15 park fee)','watamu',20,TRUE),
 ('zuri-discover-scuba','Discover Scuba Diving (beginner)','excursion','Half day','For beginners with no experience: safety briefing, shallow-water training and one supervised dive.','From $120 per person (+ $15 park fee)','watamu',30,TRUE),
 ('zuri-open-water-course','Open Water Diving Course (certification)','excursion','3-4 days','Beginner certification: theory, confined-water practice and open-water dives. Instructor, equipment and certification.','From $450 per person','watamu',40,TRUE),
 ('zuri-deep-sea-fishing-half','Deep-Sea Fishing — Half Day','excursion','4 hrs','Marlin, sailfish, tuna and seasonal game fish offshore. Fully equipped boat, crew, fuel and gear. 1 to 5 guests.','From $550 per boat','watamu',50,TRUE),
 ('zuri-deep-sea-fishing-full','Deep-Sea Fishing — Full Day','excursion','8 hrs','Extended offshore fishing for experienced anglers. Fully equipped boat, crew, fuel and gear. 1 to 5 guests.','From $900 per boat','watamu',60,TRUE),
 ('zuri-sunset-dhow-mida','Sunset Private Dhow Cruise — Mida Creek','excursion','3 hrs','Sunset cruise through the mangroves of Mida Creek on a traditional dhow with captain and crew. Optional prosecco upgrade. 2 to 12 guests.','From $45 per person','watamu',70,TRUE),
 ('zuri-arabuko-birdwatching','Arabuko Sokoke Forest Birdwatching','excursion','3-4 hrs','East Africa largest coastal forest, home to rare birds and wildlife. Transport and a professional guide. 2 to 6 guests.','From $50 per person (+ entry ~$10-15)','watamu',80,TRUE),
 ('zuri-bioken-snake-farm','Bio-Ken Snake Farm Visit','excursion','1-2 hrs','Kenya leading reptile research centre focused on conservation and venom research. Transport included.','From $25 per person (+ entry ~$8-10)','watamu',90,TRUE),
 ('zuri-gede-ruins','Gede Ruins Historical Tour','excursion','2 hrs','The 12th-century Swahili settlement hidden in the forest. Transport and a local guide. 2 to 6 guests.','From $35 per person (+ entry ~$10-15)','watamu',100,TRUE),
 ('zuri-falconry','Falconry Experience','excursion','1-2 hrs','Interactive experience with trained birds of prey and conservation experts.','From $30 per person','watamu',110,TRUE),
 ('zuri-turtle-watch','Turtle Watch & Conservation Visit','excursion','1-2 hrs','Turtle rehabilitation and marine conservation. Seasonal. Guided visit.','From $30 per person','watamu',120,TRUE),
 ('zuri-swahili-cooking','Swahili Cooking Class with Chef','excursion','3-4 hrs','Hands-on class in traditional Swahili coastal flavours. Ingredients, chef guidance, the meal and recipes. 2 to 8 guests.','From $75 per person','watamu',130,TRUE),
 ('zuri-malindi-half-day','Malindi Tour — Half Day','excursion','4 hrs','Malindi Old Town, the Vasco da Gama Pillar, markets and coastal landmarks. Private vehicle and driver-guide. 2 to 6 guests.','From $60 per person','watamu',140,TRUE),
 ('zuri-kitesurf-lessons','Kitesurfing Lessons — Tribal Kite School','excursion','2-3 hrs per session','Learn to kitesurf in Watamu ideal wind with certified instructors, equipment and safety gear. Beginner to advanced. 1 to 4 guests.','On request','watamu',150,TRUE),
 ('zuri-watersports','Water Sports — Tribal Kite School','excursion','Varies','Kitesurfing, paddleboarding, wing foiling and other water activities. Equipment and instructor depending on activity.','On request','watamu',160,TRUE)
ON CONFLICT (slug) DO UPDATE SET
  name        = EXCLUDED.name,
  category    = EXCLUDED.category,
  duration    = EXCLUDED.duration,
  short_desc  = EXCLUDED.short_desc,
  price       = EXCLUDED.price,
  location    = EXCLUDED.location,
  is_published= EXCLUDED.is_published,
  updated_at  = NOW();
