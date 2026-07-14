-- Seed the coast activities (from the tribal-sand-activity page) into `tours`.
-- Categories: marine, nature, watersports, wellness, golf. Idempotent (ON CONFLICT).
-- Run: php bin/migrate.php db/seed_activities.sql   (after add_tour_price.sql)

INSERT INTO tours (slug, name, category, tag_label, duration, price, short_desc, sort_order, is_published) VALUES
-- ── Marine & Ocean ───────────────────────────────────────────────────
('watamu-marine-snorkelling-dolphin','Watamu Marine Park Snorkelling & Dolphin Watching','marine','Marine & Ocean','3–4 hours','From $60 / person','Explore coral reefs within Watamu Marine National Park followed by dolphin watching along the coastline.',101,TRUE),
('scuba-diving-certified','Scuba Diving – Certified Divers','marine','Marine & Ocean','Half day','From $225 / person','Discover deeper reef systems and marine biodiversity with professional instructors. Certification required.',102,TRUE),
('discover-scuba-beginner','Discover Scuba – Beginner Experience','marine','Marine & Ocean','Half day','From $160 / person','Introductory diving for beginners including safety training and a guided open-water dive.',103,TRUE),
('open-water-certification','Open Water Diving Certification','marine','Marine & Ocean','3–4 days','From $660 / person','Internationally recognised certification combining theory and open-water training over several days.',104,TRUE),
('deep-sea-fishing-half-day','Deep Sea Fishing – Half Day','marine','Marine & Ocean','4 hours','From $840 / boat','Offshore fishing targeting marlin, sailfish, tuna and other seasonal species.',105,TRUE),
('deep-sea-fishing-full-day','Deep Sea Fishing – Full Day','marine','Marine & Ocean','8 hours','From $1,200 / boat','Extended offshore fishing experience for enthusiasts and experienced anglers.',106,TRUE),
('sunset-dhow-cruise','Sunset Dhow Cruise','marine','Marine & Ocean','2 hours','From $80 / person','Relaxing sunset cruise through mangrove-lined waterways capturing the essence of the Kenyan coast.',107,TRUE),
('kilifi-creek-dhow-cruise','Kilifi Creek Sunset Dhow Cruise','marine','Marine & Ocean','2 hours','From $80 / person','Scenic sunset sailing through the tranquil waters of Kilifi Creek surrounded by mangroves.',108,TRUE),
('private-creek-boat','Private Creek Boat Excursion','marine','Marine & Ocean','2–3 hours','$360','Explore mangrove creeks and coastal waterways on a private boat at your own pace.',109,TRUE),
('kayaking-kilifi-creek','Kayaking in Kilifi Creek','marine','Marine & Ocean','2 hours','On Request','Paddle through peaceful mangrove channels while observing birdlife and local fishing communities.',110,TRUE),
('stand-up-paddleboarding','Stand-Up Paddleboarding','marine','Marine & Ocean','1–2 hours','On Request','Explore calm coastal waters while paddleboarding in sheltered creek environments.',111,TRUE),
('kuruwitu-snorkelling','Kuruwitu Conservancy Snorkelling','marine','Marine & Ocean','2–3 hours','On Request','Snorkel within Kenya''s first community-managed marine protected area.',112,TRUE),
-- ── Nature & Culture ─────────────────────────────────────────────────
('arabuko-sokoke-forest','Arabuko Sokoke Forest Tour','nature','Nature & Culture','3–4 hours','On Request','Guided exploration of East Africa''s largest coastal forest, home to rare bird species found nowhere else.',201,TRUE),
('gede-ruins-tour','Gede Ruins Historical Tour','nature','Nature & Culture','2 hours','From $10–$15 / person','Visit the mysterious Swahili settlement hidden within the forest — ancient mosques and coral stone houses.',202,TRUE),
('mnarani-ruins','Mnarani Ruins Guided Visit','nature','Nature & Culture','2 hours','On Request','Cultural visit to ancient Swahili ruins overlooking the tranquil waters of Kilifi Creek.',203,TRUE),
('baobab-forest-walk','Baobab Forest Exploration Walk','nature','Nature & Culture','1–2 hours','On Request','Discover iconic baobab trees and the local legends they carry in coastal landscapes.',204,TRUE),
('turtle-conservation-visit','Turtle Conservation Visit','nature','Nature & Culture','1–2 hours','On Request','Educational visit to a local turtle conservation initiative focused on marine protection.',205,TRUE),
('swahili-cooking','Swahili Cooking Experience','nature','Nature & Culture','3–4 hours','From $75 / person','Hands-on cooking with local chefs exploring traditional Swahili cuisine, ending with a shared meal.',206,TRUE),
-- ── Watersports & Kite ───────────────────────────────────────────────
('kite-taster-2hr','2hr Kite Taster Course','watersports','Kitesurfing · Beginner','2 hours','$130','Sea assessment, kite setup, safety systems, first piloting and basic launch & land.',301,TRUE),
('kite-course-6hr','6hr Kite Course','watersports','Kitesurfing · Intermediate','6 hours','$390','Water entry/exit with kite, body dragging, power strokes, upwind body dragging, water start and controlled stop.',302,TRUE),
('kite-course-9hr','9hr Kite Course','watersports','Kitesurfing · Advanced','9 hours','$585','Speed control by edging, riding upwind, sitting transition and riding toeside. Full independence on the water.',303,TRUE),
('sup-lesson','Stand-Up Paddleboard Lesson','watersports','SUP · All Levels','1–2 hours','On Request','Discover the perfect blend of adventure and tranquility on flat water or gentle waves.',304,TRUE),
('sup-excursion','SUP Excursion','watersports','SUP Excursion','2 hours','On Request','Explore the coast by paddleboard — mangroves and marine life at a relaxed pace.',305,TRUE),
('floating-experience','Floating Experience','watersports','Unique Experience','1 hour','On Request','Unwind on a gentle, drifting platform set on the calm waters of Mida Creek.',306,TRUE),
('kite-equipment-rental','Full Kite Equipment Rental','watersports','Rental · Equipment','Hourly / daily','$50/hr · $150/day','Full kite setup: kite, bar, board and harness. Available for experienced riders.',307,TRUE),
-- ── Wellness ─────────────────────────────────────────────────────────
('private-yoga','Private Yoga Session','wellness','Wellness','60–90 minutes','On Request','Private yoga session promoting relaxation and balance — available at sunrise or sunset by the ocean.',401,TRUE),
('personal-training','Personal Training Session','wellness','Wellness','60 minutes','On Request','Personalised fitness training tailored to individual goals — strength, mobility or overall wellbeing.',402,TRUE),
('in-house-wellness','In-House Wellness Treatments','wellness','Wellness','60–90 minutes','On Request','Private massages and therapeutic treatments by skilled therapists in the comfort of your accommodation.',403,TRUE),
-- ── Golf ─────────────────────────────────────────────────────────────
('vipingo-ridge-golf','Vipingo Ridge Golf Experience','golf','Golf','Half / full day','On Request','Play on one of East Africa''s most spectacular championship golf courses overlooking the Indian Ocean.',501,TRUE)
ON CONFLICT (slug) DO UPDATE SET
  name = EXCLUDED.name, category = EXCLUDED.category, tag_label = EXCLUDED.tag_label,
  duration = EXCLUDED.duration, price = EXCLUDED.price, short_desc = EXCLUDED.short_desc,
  sort_order = EXCLUDED.sort_order, is_published = EXCLUDED.is_published, updated_at = NOW();
