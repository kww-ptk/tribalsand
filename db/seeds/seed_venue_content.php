<?php
/**
 * Seed the editable property-page copy (venues.tagline / about_heading / about_body)
 * from the built-in fallbacks that ship in the per-property pages, so Admin →
 * Properties → Content shows real, editable text instead of blank boxes.
 *
 * Run:  D:\php84\php.exe db/seeds/seed_venue_content.php
 *       (add --force to overwrite existing DB values; default only fills blanks)
 *
 * SAFE BY DEFAULT: only fills a field that is currently NULL/'' — it never
 * clobbers copy the owner has already edited in the admin. Once a field is set,
 * the property page renders the DB value (see includes/venue-about.php), so the
 * admin form becomes the source of truth.
 *
 * about_heading is stored with *asterisks* (not <em>) because the admin/partial
 * pipeline runs ts_emphasis(e()) on it — see includes/db.php ts_emphasis().
 * Keep these strings in step with the $va_*_fallback values on each page; when
 * a page's fallback copy changes, re-run this (with --force) to match.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';

if (!venue_content_supported()) {
    fwrite(STDERR, "venues content columns missing — run db/migrations/add_venue_content.sql first.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);

// slug => [tagline, about_heading (use *word* for emphasis), about_body ("\n\n" between paragraphs)]
$content = [
    'zuri' => [
        'tagline' => 'Luxury Boutique Hotel · Direct Beachfront',
        'heading' => 'Best Boutique Hotel in *Watamu, Kenya*',
        'body'    => "Zuri is Tribal Sand's luxury beachfront boutique hotel, set directly on the white-sand shoreline of Watamu — one of Kenya's most celebrated coastal destinations. Six elegantly appointed ocean-facing suites are arranged around a private pool, each designed to frame the shifting blues of the Indian Ocean at every hour of the day.\n\nWith an elevated culinary offering — including à la carte dining and private chef experiences — Zuri redefines what a boutique beach hotel can be on Kenya's North Coast. Whether you book a single suite for a romantic escape or take the entire property for a boutique destination wedding or intimate family gathering, Zuri delivers a fully-serviced, immersive experience.\n\nLocated within easy reach of the Watamu Marine National Reserve — Kenya's UNESCO-listed marine park — and approximately 120 km north of Mombasa, Zuri places guests at the heart of one of East Africa's most extraordinary natural environments. Malindi Airport (MYD) is just 20 minutes away.",
    ],
    'enkare-bofa' => [
        'tagline' => 'Beachfront Private Villa · Kilifi',
        'heading' => 'Best Beachfront Villa on *Bofa Road, Kilifi*',
        'body'    => "Enkare Bofa sits on one of Kenya's most sought-after coastal addresses — Bofa Road, Kilifi. A comfortable, stylish five-bedroom beachfront villa, it offers everything a family or group needs for a genuine coastal escape: direct beach access, a private pool, a garden, and the ease of an in-house cook from the moment you arrive.\n\nThis is Kilifi done right — relaxed, unpretentious and genuinely on the water — without the ultra-premium price tag. Whether you are travelling from Nairobi, Johannesburg, or further afield, Enkare Bofa gives you a full kitchen, a flexible self-catering option, and a comfortable base for everything Kilifi has to offer.\n\nThe villa takes the whole group — up to 10 guests across 5 bedrooms and 4 bathrooms — so there is space to breathe, gather and properly unwind.",
    ],
    'maya-kobe' => [
        'tagline' => 'Luxury Beachfront Boutique Hotel · Balinese-Inspired',
        'heading' => 'Best Boutique Hotel on *Bofa Beach, Kilifi*',
        'body'    => "Maya Kobe is a Balinese-inspired luxury boutique hotel sitting directly on Bofa Beach in Kilifi — one of Kenya's most breathtaking stretches of coastline. Five ocean suites are wrapped in rich textures, natural materials and panoramic Indian Ocean views, creating a setting that feels both intimate and effortlessly indulgent.\n\nAt the heart of the property, a 20-metre beachfront swimming pool leads directly onto the white sand beach. A spacious gazebo hangs over the water's edge, while private beachfront massage huts offer wellness without leaving the estate. Every detail — from the Balinese craftsmanship to the chef-led dining — is curated to surpass expectation.\n\nMaya Kobe is available for individual suite stays — perfect for couples and intimate groups — or as a full property buyout for up to 12 guests. The Prestige Suite, a self-contained two-bedroom sanctuary with its own private pool and open-air bathtub, adds another four guests to make a total of 16 when the whole estate is yours.",
    ],
    'my-amani' => [
        'tagline' => 'Ultra-Luxury Private Beachfront Villa · Entire Property Only',
        'heading' => 'Best Beachfront Villa in *Vipingo, Kenya*',
        'body'    => "Along the north coast of Kenya, overlooking the Indian Ocean, lies Vipingo's best-kept secret — My Amani. A tastefully furnished five-bedroom retreat with endless views of the Indian Ocean at one end and lush indigenous gardens at the other. Slumbering up to 10 guests across five en-suite bedrooms, My Amani is available exclusively as a full private retreat.\n\nMy Amani was recycled from an existing home and renovated with the finest local craftsmanship. The infinity pool overlooks the Indian Ocean, a private outdoor hot tub sits within the verdant garden, and a spacious ocean-view gazebo opens to dual decks made for outdoor hosting and relaxation. The entire property is yours — no shared spaces, no compromise.\n\nA private chef is available on request at additional cost, while the state-of-the-art kitchen is fully equipped for self-catering. Immaculate daily housekeeping, 24-hour on-site security, free Wi-Fi throughout, and air conditioning in all rooms ensure every comfort is met from arrival to departure.",
    ],
    'maya_ilai' => [
        'tagline' => 'Eco Retreat Compound · Solar Powered · Adults 16+',
        'heading' => 'Best Eco Retreat Compound in *Kilifi, Kenya*',
        'body'    => "Maya Ilai is a solar-powered eco retreat compound nestled at the back of the Tribal Dunes property in Kilifi — Kenya's most exciting beachfront destination. With 16 units across three-bedroom villas and studio apartments, it is designed for groups, conscious travellers and anyone seeking flexible, affordable longer-term coastal living without sacrificing comfort or community.\n\nThe beach is a short five-minute walk through Somewhere Café. Electric bikes and golf carts are on hand for guests who prefer a ride. A large communal pool, bar and garden spaces form the heart of the compound — places where corporate retreat delegates, kitesurf campers, and wellness seekers naturally gather.\n\nEvery kilowatt of energy here is generated by the sun. Fresh water is desalinated from the ocean. Rainwater is harvested. And the nearby coral restoration project is part of an active commitment to the ocean that surrounds this place. Maya Ilai is sustainable hospitality done with integrity.",
    ],
    'sandbox' => [
        'tagline' => 'Beachfront Self-Catering Villa · Kilifi',
        'heading' => 'Best Self-Catering Villa in *Kilifi, Kenya*',
        'body'    => "Sandbox is a self-catering beachfront villa on Kilifi's coveted Bofa Road — the same stretch of coast as Maya Kobe and Enkare Bofa. Four bedrooms, three bathrooms, a private pool, and direct beach access make it an ideal base for groups who want privacy, flexibility and genuine coastal living.\n\nThe villa is fully equipped for self-catering: a spacious, well-appointed kitchen, generous outdoor living areas, and the kind of easy, informal atmosphere that makes holidays feel effortless. No cook is included — you bring your own or self-cater — which also keeps the price point accessible for groups who value independence over full service.\n\nSandbox sleeps up to 8 guests comfortably. Whether you are coming from Nairobi for a long weekend, or travelling from South Africa or beyond for a week on the coast, it delivers exactly what Kilifi does best: warm water, wide skies and no agenda.",
    ],
];

$updated = 0; $skippedNoVenue = 0; $filled = [];
foreach ($content as $slug => $c) {
    $venue = db_query('SELECT id, tagline, about_heading, about_body FROM venues WHERE slug = :s', [':s' => $slug])->fetch();
    if (!$venue) { echo "  · no venue with slug '{$slug}' — skipped\n"; $skippedNoVenue++; continue; }

    $sets = []; $args = [':id' => $venue['id']];
    foreach (['tagline' => 'tagline', 'about_heading' => 'heading', 'about_body' => 'body'] as $col => $key) {
        $blank = trim((string)($venue[$col] ?? '')) === '';
        if ($force || $blank) { $sets[] = "$col = :$col"; $args[":$col"] = $c[$key]; }
    }
    if (!$sets) { echo "  · {$slug}: already has content (use --force to overwrite)\n"; continue; }

    db_query('UPDATE venues SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id', $args);
    echo "  ✓ {$slug}: set " . implode(', ', array_map(fn($s) => explode(' ', $s)[0], $sets)) . "\n";
    $updated++;
    $filled[] = $slug;
}

echo "\nDone. {$updated} property row(s) updated"
   . ($skippedNoVenue ? ", {$skippedNoVenue} slug(s) had no venue" : '') . ".\n";
echo $force ? "(--force: existing values were overwritten)\n" : "(blanks only; existing edits left untouched — pass --force to overwrite)\n";
exit(0);
