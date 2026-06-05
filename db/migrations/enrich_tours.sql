-- Migration: enrich seeded tours with long descriptions, highlights and durations.
-- Idempotent — safe to re-run. Only updates rows that match by slug.
-- Run: psql $DATABASE_URL -f db/migrations/enrich_tours.sql

-- 1. Tsavo East
UPDATE tours SET
  duration = '2 days / 1 night',
  long_desc = 'Tsavo East is Kenya''s largest national park and the closest great wilderness to Watamu — a few hours by road inland from the coast. Its red earth, vast open plains and famous "red elephants" coated in Tsavo dust make for an unmistakable first safari.\n\nFrom the seasonal banks of the Galana River to the Yatta Plateau — the world''s longest lava flow — Tsavo East rewards visitors with sightings of elephant, lion, buffalo, giraffe, zebra and an exceptional variety of birdlife. Game drives are unhurried, with time to stop, observe and take in the silence of the bush.',
  highlights_json = '["Game drives across the open red-earth plains","Encounters with Tsavo''s famous red elephants","Sunset over the Yatta Plateau","Stops at Aruba Dam and the Galana River","Overnight at a tented camp or safari lodge inside the park"]'::jsonb
WHERE slug = 'tsavo-east';

-- 2. Tsavo East & West
UPDATE tours SET
  duration = '3 days / 2 nights',
  long_desc = 'Two contrasting landscapes in one journey. Tsavo East offers the wide red-earth plains and abundant elephants Kenya is famous for; Tsavo West is greener and more dramatic, with volcanic hills, lava flows and the crystal-clear waters of Mzima Springs.\n\nCrossing between the two parks gives travellers a fuller picture of southern Kenya''s wilderness — from the open savannah of the east to the forested ridges and underground rivers of the west. Game viewing is excellent across both parks, with strong chances of lion, leopard, buffalo, giraffe and large elephant herds.',
  highlights_json = '["Two parks, two distinct landscapes","Mzima Springs — watch hippos and crocodiles through underwater viewing","The Shetani lava flow and Chyulu Hills","Strong chances of lion, leopard and big elephant herds","Two nights inside the parks at safari camps"]'::jsonb
WHERE slug = 'tsavo-east-west';

-- 3. Tsavo East & Amboseli
UPDATE tours SET
  duration = '3 days / 2 nights',
  long_desc = 'A journey from the red plains of Tsavo East to the wildlife-rich swamps of Amboseli, set against the snow-capped silhouette of Mount Kilimanjaro just across the Tanzanian border.\n\nAmboseli is famous for its huge elephant families and for the photography it offers — herds crossing in front of Africa''s highest mountain, framed by acacia and dust. Combined with Tsavo East, the trip covers two of Kenya''s most iconic safari landscapes in a single itinerary.',
  highlights_json = '["Game drives in Tsavo East''s vast plains","Amboseli''s legendary elephant herds","Kilimanjaro views at sunrise and sunset","Excellent photography across both parks","Two nights at safari lodges or tented camps"]'::jsonb
WHERE slug = 'tsavo-east-amboseli';

-- 4. Tsavo West, Amboseli & Tsavo East
UPDATE tours SET
  duration = '4 days / 3 nights',
  long_desc = 'The grand circuit — three legendary parks across four days for the complete southern Kenya safari. From the lava springs and rugged hills of Tsavo West, on to the Kilimanjaro backdrop of Amboseli, and finally the open plains of Tsavo East.\n\nThis is the most comprehensive safari we offer from Watamu, designed for travellers who want depth as well as variety. Each park brings its own landscape, its own light and its own wildlife. Comfortable lodges and tented camps inside the parks make the long days feel restful.',
  highlights_json = '["Three national parks in one journey","Mzima Springs in Tsavo West","Elephants framed by Mount Kilimanjaro in Amboseli","Red-earth plains and Galana River in Tsavo East","Three nights at safari camps inside the parks"]'::jsonb
WHERE slug = 'tsavo-west-amboseli-east';

-- 5. Kenya Colours
UPDATE tours SET
  duration = 'Flexible — 4 to 10 days',
  long_desc = 'A tailored journey shaped around your interests, your pace and the time you have. Kenya Colours is our most flexible option — combining coast, bush and culture in whatever proportion suits you best.\n\nWe build the itinerary together: a few days of safari in Tsavo or Amboseli, time on a wild beach, a visit to a Swahili town or a community walk. Lodging, transport and guides are arranged end to end, so all you do is travel and enjoy.',
  highlights_json = '["Fully custom itinerary, designed with you","Mix of safari, coast, culture and cuisine","Private vehicle and English-speaking guide throughout","Hand-picked lodges and camps","Single point of contact for the whole trip"]'::jsonb
WHERE slug = 'kenya-colours';

-- 6. Masai Footpaths
UPDATE tours SET
  duration = '2 to 4 days',
  long_desc = 'Walk the land with Masai guides and meet the communities who have lived alongside Kenya''s wildlife for generations. Masai Footpaths is a slower, more intimate way to experience the bush — on foot, at the pace of a conversation.\n\nDays are spent walking with experienced Masai trackers, learning about plants used for food and medicine, reading animal signs and visiting families in their homesteads. Nights are at simple, comfortable camps under the stars.',
  highlights_json = '["Walking safaris led by Masai guides","Visits to Masai homesteads and villages","Bush tracking and traditional plant knowledge","Sleeping under the stars at small camps","A cultural experience as much as a wildlife one"]'::jsonb
WHERE slug = 'masai-footpaths';

-- 7. Author Lakes (Great Rift Valley lakes)
UPDATE tours SET
  duration = '3 days / 2 nights',
  long_desc = 'The lakes of the Great Rift Valley — flamingo-pink shallows, soda flats and some of the richest birdlife in East Africa. This relaxed, photography-led journey takes in Lake Nakuru, Lake Naivasha and Lake Bogoria, each with its own character and wildlife.\n\nExpect huge flocks of greater and lesser flamingos, white rhino at Nakuru, hippos and fish eagles at Naivasha, and dramatic geothermal hot springs at Bogoria. The pace is unhurried and the landscapes endlessly photogenic.',
  highlights_json = '["Flamingo flocks on the soda lakes","White rhino at Lake Nakuru","Boat trip on Lake Naivasha","Hot springs and geysers at Lake Bogoria","Specialist guide for birdlife and photography"]'::jsonb
WHERE slug = 'author-lakes';

-- 8. Masai Mara (duration already seeded)
UPDATE tours SET
  long_desc = 'The Masai Mara at its best — the wide plains, the great migration when in season, and some of the densest big-cat populations on the continent. From July to October the wildebeest arrive in their millions, crossing the Mara River in one of the most extraordinary wildlife events on earth.\n\nOutside the migration season the Mara remains exceptional, with resident lion prides, cheetah on the plains, leopard in the riverine forest and elephant herds across the reserve. Stays are in tented camps and lodges chosen for location, comfort and quiet.',
  highlights_json = '["The great wildebeest migration (July–October)","Strong populations of lion, cheetah and leopard","Game drives on the open plains of the Mara","Optional hot-air balloon safari at sunrise","Tented camps inside or bordering the reserve"]'::jsonb
WHERE slug = 'masai-mara';

-- 9. Safari Tsavo East — Adventure (duration already seeded)
UPDATE tours SET
  long_desc = 'An overnight adventure into the heart of Tsavo East, travelling in an open 4x4 cross-country vehicle. The open sides mean nothing comes between you and the bush — every scent, every sound, every animal seen at full sensory range.\n\nDays are spent on game drives across the red plains, with an overnight in a comfortable tented camp inside the park. Best for travellers who want to feel the bush rather than just observe it through a window.',
  highlights_json = '["Open 4x4 vehicle — no glass between you and the wildlife","Two days of game drives in Tsavo East","Overnight at a tented camp inside the park","Sunset and sunrise game drives","Picnic lunch in the bush"]'::jsonb
WHERE slug = 'safari-tsavo-adventure';

-- 10. Safari Tsavo East — Explorer (duration already seeded)
UPDATE tours SET
  long_desc = 'A full-day exploration of Tsavo East by open 4x4 cross-country vehicle — perfect for travellers with limited time who still want a proper taste of the Kenyan bush. Early start from Watamu, a full day in the park, return to the resort by evening.\n\nThe itinerary covers the southern section of Tsavo East including Aruba Dam and the Galana River area, with strong chances of elephant, giraffe, zebra, lion and a rich variety of birdlife.',
  highlights_json = '["Full day in Tsavo East without an overnight","Open 4x4 vehicle for the full sensory experience","Aruba Dam and the Galana River area","Picnic lunch in the bush","Back in Watamu in time for dinner"]'::jsonb
WHERE slug = 'safari-tsavo-explorer';

-- 11. Safari Blu
UPDATE tours SET
  duration = 'Full day',
  long_desc = 'A day on the water at the Watamu Marine National Park — snorkelling on the reef at Sardegna Two, dhow sailing in the bay, fresh seafood lunch on the beach. The Marine Park is one of the oldest in Africa and home to over 600 species of fish, several species of turtle, and a healthy living reef.\n\nThe day is unhurried — time to snorkel, swim and simply float, with a long lunch on a quiet stretch of coast.',
  highlights_json = '["Snorkelling at Sardegna Two on the reef","Watamu Marine National Park — one of Africa''s oldest","Traditional dhow sailing in the bay","Fresh seafood lunch on the beach","Chances to see turtles, rays and reef fish"]'::jsonb
WHERE slug = 'safari-blu';

-- 12. Che Shale
UPDATE tours SET
  duration = 'Full day',
  long_desc = 'A wild stretch of coast north of Malindi, far from any town. Che Shale is a long, empty beach known for its kitesurfing conditions and its sense of remoteness — the kind of place where you might not see another soul for hours.\n\nThe day is spent on the beach: walks along the dunes, swimming, lunch at the beach restaurant, and (in season) watching the kites against the wind.',
  highlights_json = '["A wild, empty beach far from any town","Kitesurfing in season","Long lunch at a beach restaurant","Walks along the dunes","A complete escape from the busier coast"]'::jsonb
WHERE slug = 'che-shale';

-- 13. Mida Adventure
UPDATE tours SET
  duration = 'Full day',
  long_desc = 'A day combining the Watamu Marine Park with the mangroves of Mida Creek — two of the most striking natural areas on the Watamu coast. The morning is spent on the reef snorkelling; the afternoon, paddling or walking through the creek''s tidal mangrove forest.\n\nMida Creek is also a designated wetland of international importance, with a remarkable variety of waders and seabirds visible from the boardwalk that winds through the mangroves.',
  highlights_json = '["Morning snorkelling at the Marine Park","Afternoon in the Mida Creek mangroves","Boardwalk through the tidal forest","Outstanding birdlife — waders, kingfishers, fish eagles","Lunch at a community-run restaurant on the creek"]'::jsonb
WHERE slug = 'mida-adventure';

-- 14. Malindi Tour
UPDATE tours SET
  duration = 'Half day',
  long_desc = 'The historic coastal town of Malindi, a 30-minute drive north of Watamu. Founded as a Swahili trading port and later visited by Portuguese explorers and Arab merchants, Malindi has layers of history visible in its old town, market and seafront.\n\nThe tour visits the Vasco da Gama Pillar, the old Portuguese chapel, the lively market and the seafront. There is also time to walk through the Italian quarter — Malindi has a long-standing Italian community and is sometimes called "Little Italy on the Indian Ocean".',
  highlights_json = '["The Vasco da Gama Pillar from 1498","The old Portuguese chapel","Malindi market and Old Town","Walk along the historic seafront","A glimpse of the Italian-influenced quarter"]'::jsonb
WHERE slug = 'malindi-tour';

-- 15. The Ruins of Gede
UPDATE tours SET
  duration = 'Half day',
  long_desc = 'The lost Swahili town of Gede, hidden in the coastal forest just inland from Watamu. Founded in the 12th century and mysteriously abandoned in the 17th, Gede was a thriving Swahili town with coral-stone houses, mosques, a palace and trade goods from as far as China.\n\nWalking the ruins among the giant baobabs and forest birds is one of the most atmospheric experiences on the Watamu coast. A small site museum sets the historical context.',
  highlights_json = '["12th–17th century Swahili ruins","Coral-stone houses, mosques and palace","Coastal forest setting with giant baobabs","Small museum with artefacts and history","Easy walking trails through the site"]'::jsonb
WHERE slug = 'ruins-of-gede';

-- 16. Quad Safari
UPDATE tours SET
  duration = '2 hours',
  long_desc = 'An off-road quad-bike trail through the bush behind the coast — a fast-paced, slightly dusty alternative to a traditional game drive. The route winds through scrubland, baobab clearings and small villages, with a guide leading the way.\n\nNo experience is needed; quad bikes are easy to handle and a short briefing covers everything. Best done early morning or late afternoon to avoid the midday heat.',
  highlights_json = '["Two hours of off-road quad biking","Bush trails through scrubland and baobab country","No experience needed — full briefing included","Helmet and safety gear provided","Great for groups and families"]'::jsonb
WHERE slug = 'quad-safari';

-- Bump updated_at so admin shows fresh dates
UPDATE tours SET updated_at = NOW() WHERE long_desc IS NOT NULL AND long_desc != '';
