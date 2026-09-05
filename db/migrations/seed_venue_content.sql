-- Seed the editable property-page copy (venues.tagline / about_heading / about_body)
-- from the built-in page fallbacks, so Admin → Properties → Content shows real,
-- editable text instead of blank boxes. SQL twin of db/seeds/seed_venue_content.php
-- so it can be run from /admin/migrate.php without shell access.
--
-- SAFE + IDEMPOTENT: COALESCE(NULLIF(col,''), …) fills a field ONLY when it is
-- currently NULL/'' — it never overwrites copy the owner has already edited.
-- Re-running is harmless. Requires add_venue_content.sql first (the columns).
--
-- about_heading stores emphasis as *asterisks* (NOT <em>) — the page pipeline
-- runs ts_emphasis(e($value)); storing raw <em> would render escaped.

UPDATE venues SET
  tagline       = COALESCE(NULLIF(tagline,''),       $$Luxury Boutique Hotel · Direct Beachfront$$),
  about_heading = COALESCE(NULLIF(about_heading,''), $$Best Boutique Hotel in *Watamu, Kenya*$$),
  about_body    = COALESCE(NULLIF(about_body,''),    $body$Zuri is Tribal Sand's luxury beachfront boutique hotel, set directly on the white-sand shoreline of Watamu — one of Kenya's most celebrated coastal destinations. Six elegantly appointed ocean-facing suites are arranged around a private pool, each designed to frame the shifting blues of the Indian Ocean at every hour of the day.

With an elevated culinary offering — including à la carte dining and private chef experiences — Zuri redefines what a boutique beach hotel can be on Kenya's North Coast. Whether you book a single suite for a romantic escape or take the entire property for a boutique destination wedding or intimate family gathering, Zuri delivers a fully-serviced, immersive experience.

Located within easy reach of the Watamu Marine National Reserve — Kenya's UNESCO-listed marine park — and approximately 120 km north of Mombasa, Zuri places guests at the heart of one of East Africa's most extraordinary natural environments. Malindi Airport (MYD) is just 20 minutes away.$body$),
  updated_at = NOW()
WHERE slug = 'zuri'
  AND (tagline IS NULL OR tagline = '' OR about_heading IS NULL OR about_heading = '' OR about_body IS NULL OR about_body = '');

UPDATE venues SET
  tagline       = COALESCE(NULLIF(tagline,''),       $$Beachfront Private Villa · Kilifi$$),
  about_heading = COALESCE(NULLIF(about_heading,''), $$Best Beachfront Villa on *Bofa Road, Kilifi*$$),
  about_body    = COALESCE(NULLIF(about_body,''),    $body$Enkare Bofa sits on one of Kenya's most sought-after coastal addresses — Bofa Road, Kilifi. A comfortable, stylish five-bedroom beachfront villa, it offers everything a family or group needs for a genuine coastal escape: direct beach access, a private pool, a garden, and the ease of an in-house cook from the moment you arrive.

This is Kilifi done right — relaxed, unpretentious and genuinely on the water — without the ultra-premium price tag. Whether you are travelling from Nairobi, Johannesburg, or further afield, Enkare Bofa gives you a full kitchen, a flexible self-catering option, and a comfortable base for everything Kilifi has to offer.

The villa takes the whole group — up to 10 guests across 5 bedrooms and 4 bathrooms — so there is space to breathe, gather and properly unwind.$body$),
  updated_at = NOW()
WHERE slug = 'enkare-bofa'
  AND (tagline IS NULL OR tagline = '' OR about_heading IS NULL OR about_heading = '' OR about_body IS NULL OR about_body = '');

UPDATE venues SET
  tagline       = COALESCE(NULLIF(tagline,''),       $$Luxury Beachfront Boutique Hotel · Balinese-Inspired$$),
  about_heading = COALESCE(NULLIF(about_heading,''), $$Best Boutique Hotel on *Bofa Beach, Kilifi*$$),
  about_body    = COALESCE(NULLIF(about_body,''),    $body$Maya Kobe is a Balinese-inspired luxury boutique hotel sitting directly on Bofa Beach in Kilifi — one of Kenya's most breathtaking stretches of coastline. Five ocean suites are wrapped in rich textures, natural materials and panoramic Indian Ocean views, creating a setting that feels both intimate and effortlessly indulgent.

At the heart of the property, a 20-metre beachfront swimming pool leads directly onto the white sand beach. A spacious gazebo hangs over the water's edge, while private beachfront massage huts offer wellness without leaving the estate. Every detail — from the Balinese craftsmanship to the chef-led dining — is curated to surpass expectation.

Maya Kobe is available for individual suite stays — perfect for couples and intimate groups — or as a full property buyout for up to 12 guests. The Prestige Suite, a self-contained two-bedroom sanctuary with its own private pool and open-air bathtub, adds another four guests to make a total of 16 when the whole estate is yours.$body$),
  updated_at = NOW()
WHERE slug = 'maya-kobe'
  AND (tagline IS NULL OR tagline = '' OR about_heading IS NULL OR about_heading = '' OR about_body IS NULL OR about_body = '');

UPDATE venues SET
  tagline       = COALESCE(NULLIF(tagline,''),       $$Ultra-Luxury Private Beachfront Villa · Entire Property Only$$),
  about_heading = COALESCE(NULLIF(about_heading,''), $$Best Beachfront Villa in *Vipingo, Kenya*$$),
  about_body    = COALESCE(NULLIF(about_body,''),    $body$Along the north coast of Kenya, overlooking the Indian Ocean, lies Vipingo's best-kept secret — My Amani. A tastefully furnished five-bedroom retreat with endless views of the Indian Ocean at one end and lush indigenous gardens at the other. Slumbering up to 10 guests across five en-suite bedrooms, My Amani is available exclusively as a full private retreat.

My Amani was recycled from an existing home and renovated with the finest local craftsmanship. The infinity pool overlooks the Indian Ocean, a private outdoor hot tub sits within the verdant garden, and a spacious ocean-view gazebo opens to dual decks made for outdoor hosting and relaxation. The entire property is yours — no shared spaces, no compromise.

A private chef is available on request at additional cost, while the state-of-the-art kitchen is fully equipped for self-catering. Immaculate daily housekeeping, 24-hour on-site security, free Wi-Fi throughout, and air conditioning in all rooms ensure every comfort is met from arrival to departure.$body$),
  updated_at = NOW()
WHERE slug = 'my-amani'
  AND (tagline IS NULL OR tagline = '' OR about_heading IS NULL OR about_heading = '' OR about_body IS NULL OR about_body = '');

UPDATE venues SET
  tagline       = COALESCE(NULLIF(tagline,''),       $$Eco Retreat Compound · Solar Powered · Adults 16+$$),
  about_heading = COALESCE(NULLIF(about_heading,''), $$Best Eco Retreat Compound in *Kilifi, Kenya*$$),
  about_body    = COALESCE(NULLIF(about_body,''),    $body$Maya Ilai is a solar-powered eco retreat compound nestled at the back of the Tribal Dunes property in Kilifi — Kenya's most exciting beachfront destination. With 16 units across three-bedroom villas and studio apartments, it is designed for groups, conscious travellers and anyone seeking flexible, affordable longer-term coastal living without sacrificing comfort or community.

The beach is a short five-minute walk through Somewhere Café. Electric bikes and golf carts are on hand for guests who prefer a ride. A large communal pool, bar and garden spaces form the heart of the compound — places where corporate retreat delegates, kitesurf campers, and wellness seekers naturally gather.

Every kilowatt of energy here is generated by the sun. Fresh water is desalinated from the ocean. Rainwater is harvested. And the nearby coral restoration project is part of an active commitment to the ocean that surrounds this place. Maya Ilai is sustainable hospitality done with integrity.$body$),
  updated_at = NOW()
WHERE slug = 'maya_ilai'
  AND (tagline IS NULL OR tagline = '' OR about_heading IS NULL OR about_heading = '' OR about_body IS NULL OR about_body = '');

UPDATE venues SET
  tagline       = COALESCE(NULLIF(tagline,''),       $$Beachfront Self-Catering Villa · Kilifi$$),
  about_heading = COALESCE(NULLIF(about_heading,''), $$Best Self-Catering Villa in *Kilifi, Kenya*$$),
  about_body    = COALESCE(NULLIF(about_body,''),    $body$Sandbox is a self-catering beachfront villa on Kilifi's coveted Bofa Road — the same stretch of coast as Maya Kobe and Enkare Bofa. Four bedrooms, three bathrooms, a private pool, and direct beach access make it an ideal base for groups who want privacy, flexibility and genuine coastal living.

The villa is fully equipped for self-catering: a spacious, well-appointed kitchen, generous outdoor living areas, and the kind of easy, informal atmosphere that makes holidays feel effortless. No cook is included — you bring your own or self-cater — which also keeps the price point accessible for groups who value independence over full service.

Sandbox sleeps up to 8 guests comfortably. Whether you are coming from Nairobi for a long weekend, or travelling from South Africa or beyond for a week on the coast, it delivers exactly what Kilifi does best: warm water, wide skies and no agenda.$body$),
  updated_at = NOW()
WHERE slug = 'sandbox'
  AND (tagline IS NULL OR tagline = '' OR about_heading IS NULL OR about_heading = '' OR about_body IS NULL OR about_body = '');
