<?php
/**
 * TRIBAL SAND · Journal / Articles Manifest
 * ------------------------------------------------------------------
 * Single source of truth for the blog index, per-article SEO heads
 * (includes/article-seo.php) and the sitemap generator.
 *
 * Each entry is keyed by its page slug (filename without .php). Fields:
 *   title     — full <title>/<h1>-grade heading (brand suffix added by SEO helper)
 *   desc      — meta description (~150-160 chars, used for OG too)
 *   excerpt   — short teaser shown on the blog card
 *   category  — one of the CATEGORY labels below (drives grouping/filter)
 *   image     — hero/card image (path under /images, may contain spaces)
 *   cta       — ['url'=>…, 'label'=>…] internal link shown on the card (conversion)
 *
 * To publish a new article: drop the .php page in the web root, add its
 * <?php $article='<slug>'; include 'includes/article-seo.php'; ?> head,
 * and add one row here. It then appears in the Journal and the sitemap.
 */

const TS_ARTICLE_CATEGORIES = ['Guides', 'Experiences', 'Our Properties', 'Sustainability'];

function ts_articles(): array
{
    return [

        // ─────────────────────────── GUIDES ───────────────────────────
        'why-kenya-coast-best-luxury-destination-africa' => [
            'title'    => 'Why the Kenya Coast Is the Best Luxury Destination in Africa',
            'desc'     => 'Why Kenya\'s North Coast is Africa\'s most desirable luxury beach destination — safety, year-round weather, pristine beaches, wildlife and beachfront villas.',
            'excerpt'  => 'Safety, year-round sun, pristine beaches and world-class wildlife — the complete case for Kenya\'s North Coast.',
            'category' => 'Guides',
            'image'    => 'images/Tethys-frontentrance.jpeg',
            'cta'      => ['url' => 'properties.php', 'label' => 'Explore our properties'],
        ],
        'the-ultimate-guide-to-luxury-travel-in-kenya' => [
            'title'    => 'The Ultimate Guide to Luxury Travel in Kenya',
            'desc'     => 'Everything you need to plan a luxury trip to Kenya — when to go, where to stay on the coast, safaris, transfers and how to travel in comfort.',
            'excerpt'  => 'When to go, where to stay, and how to combine beach and bush for the perfect luxury Kenya trip.',
            'category' => 'Guides',
            'image'    => 'images/blog/details/IMAGE-1_The-Ultimate-Guide-to-Luxury-Travel-in-Kenya.webp',
            'cta'      => ['url' => 'trip-builder.php', 'label' => 'Plan your trip'],
        ],
        'how-to-plan-a-group-vacation-on-the-kenyan-coast-a-perfect-guide-for-unforgettable-moments' => [
            'title'    => 'How to Plan a Group Vacation on the Kenyan Coast',
            'desc'     => 'A practical guide to planning a group holiday on the Kenya coast — choosing a villa, catering, activities and logistics for families and friends.',
            'excerpt'  => 'Choosing a villa, arranging a private chef, and organising activities for a seamless group getaway.',
            'category' => 'Guides',
            'image'    => 'images/blog/details/IMAGE-1_How-to-Plan-a-Group-Vacation-on-the-Kenyan-Coast-A-Perfect-Guide-for-Unforgettable-Moments.webp',
            'cta'      => ['url' => 'enkare-bofa.php', 'label' => 'See our group villas'],
        ],
        'the-best-time-to-visit-my-amani-in-kilifi' => [
            'title'    => 'The Best Time to Visit My Amani in Kilifi',
            'desc'     => 'When to visit My Amani in Vipingo, Kilifi — a month-by-month guide to weather, seas, wildlife and the best windows for a beachfront villa stay.',
            'excerpt'  => 'A month-by-month look at weather, seas and wildlife to time your beachfront villa stay perfectly.',
            'category' => 'Guides',
            'image'    => 'images/blog/details/IMAGE-1_The-Best-Time-to-Visit-My-Amani-in-Kilifi-1-2048x1152.webp',
            'cta'      => ['url' => 'my-amani.php', 'label' => 'Discover My Amani'],
        ],
        'kilifi-bofa-beach-growing-destination' => [
            'title'    => 'Kilifi & Bofa Beach: A Growing Luxury Destination',
            'desc'     => 'Why Kilifi and Bofa Beach are becoming top destinations on the Kenya Coast — digital-nomad living, family lifestyle, kitesurfing and beachfront property.',
            'excerpt'  => 'Digital-nomad living, kitesurfing and beachfront property are putting Kilifi and Bofa Beach on the map.',
            'category' => 'Guides',
            'image'    => 'images/maya-kobe/Aerial/mayakobe-2.webp',
            'cta'      => ['url' => 'kilifi.php', 'label' => 'Read the Kilifi guide'],
        ],
        'top-luxury-travel-trends-in-kenya-for-2024' => [
            'title'    => 'Luxury Travel Trends Shaping Kenya',
            'desc'     => 'The luxury travel trends shaping Kenya — sustainable stays, private villas, experiential travel and the shift toward slower, more meaningful coastal holidays.',
            'excerpt'  => 'Sustainable stays, private villas and experiential travel — the trends reshaping luxury holidays in Kenya.',
            'category' => 'Guides',
            'image'    => 'images/blog/details/IMAGE-1_Top-Luxury-Travel-Trends-in-Kenya-for-2024-2048x1152.webp',
            'cta'      => ['url' => 'properties.php', 'label' => 'Browse stays'],
        ],

        // ───────────────────────── EXPERIENCES ────────────────────────
        'the-pga-baobab-golf-course' => [
            'title'    => 'The PGA Baobab Golf Course at Vipingo Ridge',
            'desc'     => 'Play Africa\'s only PGA-accredited championship golf course at Vipingo Ridge — an 18-hole, par-72 wildlife course minutes from My Amani villa.',
            'excerpt'  => 'Africa\'s only PGA-accredited championship course — 18 holes of wildlife-lined fairways, minutes from My Amani.',
            'category' => 'Experiences',
            'image'    => 'images/golf.jpg',
            'cta'      => ['url' => 'activities.php', 'label' => 'See all activities'],
        ],
        'the-seasonal-whale-watching' => [
            'title'    => 'Seasonal Whale Watching at Watamu Marine Park',
            'desc'     => 'Seasonal whale watching on Kenya\'s North Coast — when and where to see humpback whales and whale sharks around Watamu Marine Park.',
            'excerpt'  => 'When and where to spot humpback whales and whale sharks off the Watamu Marine Park.',
            'category' => 'Experiences',
            'image'    => 'images/humpback-whale-436120_1280.webp',
            'cta'      => ['url' => 'activities.php', 'label' => 'See all activities'],
        ],
        'the-traditional-swahili-dhow-cruise' => [
            'title'    => 'The Traditional Swahili Sunset Dhow Cruise',
            'desc'     => 'Sail Kilifi Creek at golden hour on a traditional Swahili dhow — a sunset cruise with Captain Issa and crew, one of the coast\'s most memorable evenings.',
            'excerpt'  => 'Golden-hour sailing on Kilifi Creek aboard a traditional Swahili dhow with Captain Issa and crew.',
            'category' => 'Experiences',
            'image'    => 'images/Traditional-Swahili-sunset-dhow-cruise-1.jpg',
            'cta'      => ['url' => 'activities.php', 'label' => 'See all activities'],
        ],
        'the-watamu-malindi-beaches' => [
            'title'    => 'The Watamu & Malindi Beaches',
            'desc'     => 'A guide to the Watamu and Malindi beaches — white sand, turquoise water and world-class snorkelling, diving and marine life on Kenya\'s North Coast.',
            'excerpt'  => 'White sand, turquoise water and world-class snorkelling along Kenya\'s most beautiful stretch of coast.',
            'category' => 'Experiences',
            'image'    => 'images/IMAGE-3_Unforgettable-Coastal-Getaways-Exploring-Kenyas-Pristine-Beaches.webp',
            'cta'      => ['url' => 'zuri.php', 'label' => 'Stay in Watamu at Zuri'],
        ],
        'the-arabuko-sokoke-national-reserve' => [
            'title'    => 'The Arabuko-Sokoke National Reserve',
            'desc'     => 'Explore Arabuko-Sokoke, East Africa\'s largest coastal forest — extraordinary birdlife, rare wildlife and guided walks near Watamu and Kilifi.',
            'excerpt'  => 'East Africa\'s largest coastal forest — extraordinary birdlife and rare wildlife, minutes from the beach.',
            'category' => 'Experiences',
            'image'    => 'images/Arabuko-Sokoke.png',
            'cta'      => ['url' => 'activities.php', 'label' => 'See all activities'],
        ],
        'high-end-adventure-sports-in-kenya-for-the-luxury-thrill-seeker' => [
            'title'    => 'High-End Adventure Sports in Kenya',
            'desc'     => 'For the luxury thrill-seeker — kitesurfing, skydiving, deep-sea fishing and more high-adrenaline adventure sports along Kenya\'s North Coast.',
            'excerpt'  => 'Kitesurfing, skydiving and deep-sea fishing — adrenaline for the luxury traveller who wants more.',
            'category' => 'Experiences',
            'image'    => 'images/blog/details/IMAGE-1_High-End-Adventure-Sports-in-Kenya-For-the-Luxury-Thrill-Seeker.webp',
            'cta'      => ['url' => 'activities.php', 'label' => 'See all activities'],
        ],
        'bioluminescent-algae-kilifi-creek-indulge-in-coastal-beauty-and-luxury-stays-with-tribalsand-in-kenya' => [
            'title'    => 'Bioluminescent Algae on Kilifi Creek',
            'desc'     => 'Witness bioluminescent algae light up Kilifi Creek after dark — when to see this natural wonder and where to stay for the best coastal experience.',
            'excerpt'  => 'On dark nights, Kilifi Creek glows electric blue — when and how to witness the bioluminescence.',
            'category' => 'Experiences',
            'image'    => 'images/blog/details/IMAGE-1-Bioluminescent-Algae-Kilifi-Creek-Indulge-in-Coastal-Beauty-and-Luxury-Stays-with-TribalSand-in-Kenya.jpg.webp',
            'cta'      => ['url' => 'activities.php', 'label' => 'See all activities'],
        ],
        'exploring-the-local-markets-of-kilifi-a-cultural-journey-like-no-other' => [
            'title'    => 'Exploring the Local Markets of Kilifi',
            'desc'     => 'A cultural journey through Kilifi\'s local markets — Swahili crafts, spices, fresh produce and the coastal culture that makes the town unforgettable.',
            'excerpt'  => 'Swahili crafts, spices and coastal culture — a walk through the markets that give Kilifi its soul.',
            'category' => 'Experiences',
            'image'    => 'images/blog/details/IMAGE-1_Exploring-the-Local-Markets-of-Kilifi-A-Cultural-Journey-Like-No-Other-2048x1152.webp',
            'cta'      => ['url' => 'kilifi.php', 'label' => 'Read the Kilifi guide'],
        ],
        'unforgettable-coastal-getaways-exploring-kenyas-pristine-beaches' => [
            'title'    => 'Unforgettable Coastal Getaways: Kenya\'s Pristine Beaches',
            'desc'     => 'Discover Kenya\'s most pristine beaches — the secluded bays, marine parks and beachfront stays that make the North Coast an unforgettable getaway.',
            'excerpt'  => 'The secluded bays, marine parks and beachfront stays that make the North Coast unforgettable.',
            'category' => 'Experiences',
            'image'    => 'images/IMAGE-1_Unforgettable-Coastal-Getaways-Exploring-Kenyas-Pristine-Beaches.webp',
            'cta'      => ['url' => 'properties.php', 'label' => 'Find your beach stay'],
        ],

        // ─────────────────────── OUR PROPERTIES ───────────────────────
        'discover-maya-kobe-where-secluded-luxury-meets-the-ocean-breeze' => [
            'title'    => 'Discover Maya Kobe: Where Secluded Luxury Meets the Ocean Breeze',
            'desc'     => 'Discover Maya Kobe — a Balinese-inspired boutique hotel on Bofa Beach, Kilifi, with ocean-facing suites, a 20m pool and chef-led dining.',
            'excerpt'  => 'A Balinese-inspired boutique hotel on Bofa Beach — ocean-facing suites, a 20m pool and chef-led dining.',
            'category' => 'Our Properties',
            'image'    => 'images/IMAGE-3_Discover-Maya-Kobe-Where-Secluded-Luxury-Meets-the-Ocean-Breeze-1024x576.webp',
            'cta'      => ['url' => 'maya-kobe.php', 'label' => 'Discover Maya Kobe'],
        ],
        'the-magic-of-maya-kobe-bofa-beach' => [
            'title'    => 'The Magic of Maya Kobe, Bofa Beach',
            'desc'     => 'The magic of Maya Kobe on Bofa Beach, Kilifi — the design, the setting and the experience behind Tribal Sand\'s Balinese-inspired boutique hotel.',
            'excerpt'  => 'The design, the setting and the experience behind Tribal Sand\'s Balinese-inspired boutique hotel.',
            'category' => 'Our Properties',
            'image'    => 'images/blog/details/3-Dec-08-2023-01-44-57-6512-PM.webp',
            'cta'      => ['url' => 'maya-kobe.php', 'label' => 'Discover Maya Kobe'],
        ],
        'a-day-in-paradise-experiencing-my-amanis-luxuries' => [
            'title'    => 'A Day in Paradise: Experiencing My Amani\'s Luxuries',
            'desc'     => 'A day at My Amani, Vipingo — infinity pool, private chef and beachfront serenity at Tribal Sand\'s most private five-bedroom villa.',
            'excerpt'  => 'Infinity pool, private chef and beachfront serenity — a day inside our most private villa.',
            'category' => 'Our Properties',
            'image'    => 'images/blog/details/IMAGE-3_Discover-Maya-Kobe-Where-Secluded-Luxury-Meets-the-Ocean-Breeze.webp',
            'cta'      => ['url' => 'my-amani.php', 'label' => 'Discover My Amani'],
        ],
        'a-day-in-watamu-exploring-around-zuri' => [
            'title'    => 'A Day in Watamu: Exploring Around Zuri',
            'desc'     => 'A day in Watamu around Zuri — beachfront suites, the marine park, dining and the best of Watamu on Kenya\'s North Coast.',
            'excerpt'  => 'Beachfront suites, the marine park and the best of Watamu, all around our Zuri hotel.',
            'category' => 'Our Properties',
            'image'    => 'images/IMAGE-1_A-Day-in-Watamu-Exploring-Around-Zuri.webp',
            'cta'      => ['url' => 'zuri.php', 'label' => 'Discover Zuri'],
        ],
        'transition-to-boutique-hotel' => [
            'title'    => 'From Private Villa to Boutique Hotel',
            'desc'     => 'The story of Tribal Sand\'s evolution — from a single private villa to a complete beachfront boutique-hotel ecosystem on Kenya\'s North Coast.',
            'excerpt'  => 'How a single beachfront villa grew into a complete coastal boutique-hotel ecosystem.',
            'category' => 'Our Properties',
            'image'    => 'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best14.jpg',
            'cta'      => ['url' => 'tribalsandstory.php', 'label' => 'Read our story'],
        ],
        'discover-the-best-of-both-worlds-on-the-kenya-coast-with-tribal-sand' => [
            'title'    => 'The Best of Both Worlds on the Kenya Coast',
            'desc'     => 'Beach and bush, boutique hotels and private villas — how Tribal Sand lets you experience the best of both worlds on Kenya\'s North Coast.',
            'excerpt'  => 'Beach and bush, boutique hotels and private villas — the best of both worlds in one trip.',
            'category' => 'Our Properties',
            'image'    => 'images/blog/details/IMAGE-3_Discover-Maya-Kobe-Where-Secluded-Luxury-Meets-the-Ocean-Breeze.webp',
            'cta'      => ['url' => 'properties.php', 'label' => 'Explore our properties'],
        ],

        // ────────────────────── SUSTAINABILITY ────────────────────────
        'eco-luxury-sustainable-travel-experiences-in-kenya' => [
            'title'    => 'Eco-Luxury: Sustainable Travel Experiences in Kenya',
            'desc'     => 'How Tribal Sand delivers eco-luxury in Kenya — solar power, desalinated water and marine conservation woven into every beachfront stay.',
            'excerpt'  => 'Solar power, desalinated water and marine conservation — luxury that gives back to the coast.',
            'category' => 'Sustainability',
            'image'    => 'images/blog/details/IMAGE-1_Eco-Luxury-Sustainable-Travel-Experiences-in-Kenya-2048x1152.webp',
            'cta'      => ['url' => 'sustainability.php', 'label' => 'Our sustainability commitment'],
        ],
        'restoration-of-riparian-habitat-on-maya-kobe-beachfront-bofa-beach-kilifi-kenya' => [
            'title'    => 'Restoring Riparian Habitat on Maya Kobe Beachfront',
            'desc'     => 'Inside Tribal Sand\'s riparian habitat restoration on the Maya Kobe beachfront at Bofa Beach, Kilifi — protecting the coastline for the future.',
            'excerpt'  => 'Inside our project to restore the natural riparian habitat along the Bofa Beach coastline.',
            'category' => 'Sustainability',
            'image'    => 'images/blog/details/IMAGE-1-Restoration-of-Riparian-Habitat-on-Maya-Kobe-Beachfront-–-Bofa-Beach-Kilifi-Kenya.jpg.webp',
            'cta'      => ['url' => 'sustainability.php', 'label' => 'Our sustainability commitment'],
        ],

    ];
}

/** Return a single article row by slug, or null. */
function ts_article(string $slug): ?array
{
    return ts_articles()[$slug] ?? null;
}
