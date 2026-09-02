<?php
declare(strict_types=1);
/**
 * Editable page content — text and image slots, managed in Admin → Content → Pages.
 *
 * WHICH slots exist is declared here in code, not in the database. The DB only
 * stores values an owner has overridden. That split is load-bearing:
 *
 *   - A page with no rows (or an unapplied migration) renders its registry
 *     defaults, which are the exact copy the page shipped with. Editing content
 *     can never blank a page, and neither can a missing migration.
 *   - Adding a page later = add an entry below + swap that template's literals
 *     for page_text()/page_image() calls. The admin screen picks it up with no
 *     admin code changes.
 *
 * Every read is pre-migration-safe via page_content_supported().
 */
require_once __DIR__ . '/db.php';

/** True once add_page_content.sql has been applied. Cached per request. */
function page_content_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT to_regclass('public.page_content') IS NOT NULL AS ok"
        )->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * The registry: pages → groups → slots.
 *
 * type:   'text'     single-line, escaped on output
 *         'textarea' multi-line, escaped on output
 *         'html'     rendered RAW — only for copy that carries inline markup
 *                    (<br>, <em>). Owner-only screen, so this is a trusted
 *                    field, but it is still marked explicitly so nobody has to
 *                    guess which fields skip escaping.
 *         'image'    a storage key / URL, chosen with the media picker
 */
function page_content_registry(): array {
    return [
        'home' => [
            'label' => 'Home',
            'url'   => '/',
            'groups' => [
                'Hero' => [
                    'hero_eyebrow' => ['type'=>'html', 'label'=>'Eyebrow',
                        'hint'=>'Small line above the headline. The · separators are <span class="sep">·</span>.',
                        'default'=>'Kenya\'s North Coast <span class="sep">·</span> Watamu <span class="sep">·</span> Kilifi <span class="sep">·</span> Vipingo'],
                    'hero_title' => ['type'=>'html', 'label'=>'Headline',
                        'hint'=>'Uses &lt;br&gt; for line breaks and &lt;em&gt; for the italic gold words.',
                        'default'=>'Luxury Beachfront<br><em>Hotels &amp; Villas</em><br>in Kenya'],
                    'hero_sub' => ['type'=>'textarea', 'label'=>'Sub-heading',
                        'default'=>'Boutique hotels, private villas, lifestyle venues and unique experiences on the Kenyan coast, Africa.'],
                    'hero_image'  => ['type'=>'image', 'label'=>'Hero slide 1 — Tribal Dunes',
                        'hint'=>'Shown first, before the slideshow advances.',
                        'default'=>'images/New-hero-banner.jpg'],
                    'hero_image2' => ['type'=>'image', 'label'=>'Hero slide 2 — Maya Kobe',
                        'default'=>'images/hero-maya-kobe.jpg'],
                    'hero_image3' => ['type'=>'image', 'label'=>'Hero slide 3 — Zuri',
                        'default'=>'images/hero-zuri.jpg'],
                    'hero_image4' => ['type'=>'image', 'label'=>'Hero slide 4 — My Amani',
                        'default'=>'images/my-amani/Aerial/myamani-11.webp'],
                    'hero_image5' => ['type'=>'image', 'label'=>'Hero slide 5 — Enkare Bofa',
                        'default'=>'images/hero-enkare-bofa.jpg'],
                ],
                'Intro strip' => [
                    'intro_tagline' => ['type'=>'text', 'label'=>'Tagline',
                        'default'=>'"Kenya as it was meant to be experienced."'],
                    'intro_sub' => ['type'=>'html', 'label'=>'Sub-line',
                        'default'=>'Watamu &nbsp;·&nbsp; Kilifi &nbsp;·&nbsp; Vipingo &nbsp;&nbsp;·&nbsp;&nbsp; Boutique hotels, private villas and an integrated beachfront ecosystem on Kenya\'s North Coast.'],
                ],
                'Stats' => [
                    'stat1_num' => ['type'=>'text','label'=>'Stat 1 — number','default'=>'6'],
                    'stat1_lbl' => ['type'=>'text','label'=>'Stat 1 — label', 'default'=>'Properties'],
                    'stat2_num' => ['type'=>'text','label'=>'Stat 2 — number','default'=>'3'],
                    'stat2_lbl' => ['type'=>'text','label'=>'Stat 2 — label', 'default'=>'Locations'],
                    'stat3_num' => ['type'=>'text','label'=>'Stat 3 — number','default'=>'100%'],
                    'stat3_lbl' => ['type'=>'text','label'=>'Stat 3 — label', 'default'=>'Solar · Tribal Dunes'],
                    'stat4_num' => ['type'=>'text','label'=>'Stat 4 — number','default'=>'24h'],
                    'stat4_lbl' => ['type'=>'text','label'=>'Stat 4 — label', 'default'=>'Concierge'],
                ],
                'Properties section' => [
                    'props_title' => ['type'=>'html', 'label'=>'Heading',
                        'hint'=>'The six property photos below this heading come from each property\'s own gallery — edit them in Properties → Gallery.',
                        'default'=>'Exclusive Stays Along<br><em>Kenya\'s Coastline</em>'],
                ],
                'Tribal Dunes section' => [
                    'dunes_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'Tribal Dunes —<br><em>One Address,<br>Many Reasons to Stay</em>'],
                    'dunes_img_main' => ['type'=>'image', 'label'=>'Main image',
                        'hint'=>'Renders 4:3 — a 16:9 photo gets cropped top and bottom.',
                        'default'=>'images/maya-kobe/Aerial/mayakobe-2.webp'],
                    'dunes_img_accent' => ['type'=>'image', 'label'=>'Accent image (overlaps the main one)',
                        'default'=>'images/maya_illai/best6.jpg'],
                ],
                'How It Works section' => [
                    'how_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'We Handle<br><em>Every Detail</em>'],
                    'how_img_main' => ['type'=>'image', 'label'=>'Main image',
                        'hint'=>'Renders 4:5 PORTRAIT — a landscape photo crops hard.',
                        'default'=>'images/My-Amani-8.jpg'],
                    'how_img_accent' => ['type'=>'image', 'label'=>'Accent image (overlaps the main one)',
                        'hint'=>'Renders 4:3 landscape.',
                        'default'=>'https://images.tribalsand.com/da8332ef15c50327eedc.jpg'],
                ],
                'Photo strip' => [
                    'gal_1' => ['type'=>'image','label'=>'Photo 1','default'=>'images/Maya-Kobe-1.jpeg'],
                    'gal_2' => ['type'=>'image','label'=>'Photo 2','default'=>'images/My-Amani-5.jpg'],
                    'gal_3' => ['type'=>'image','label'=>'Photo 3','default'=>'images/updated-hero-banner.jpg'],
                    'gal_4' => ['type'=>'image','label'=>'Photo 4','default'=>'images/My-Amani-1.jpg'],
                    'gal_5' => ['type'=>'image','label'=>'Photo 5','default'=>'images/34t.jpg'],
                ],
                'Closing banner' => [
                    'cta_image' => ['type'=>'image', 'label'=>'Full-width banner photo',
                        'hint'=>'Very wide (1920×490) — use a landscape shot.',
                        'default'=>'images/D8.jpg'],
                ],
                'Trust bar' => [
                    'ta_badge' => ['type'=>'image', 'label'=>'TripAdvisor badge',
                        'hint'=>'Swap this when the real TripAdvisor listing is approved.',
                        'default'=>'/images/tripadvisor.jpg'],
                ],
            ],
        ],
        'contact' => [
            'label' => 'Contact Us',
            'url'   => '/contact.php',
            'groups' => [
                'Hero' => [
                    'hero_image' => ['type'=>'image', 'label'=>'Hero background photo (optional)',
                        'hint'=>'The hero ships as a plain gradient with no photo. Choose one here and it sits behind the heading; leave it empty and the gradient stays exactly as it is.',
                        'default'=>''],
                    'hero_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow',
                        'default'=>'Reservations & Enquiries · Tribal Sand Kenya'],
                    'hero_title' => ['type'=>'html', 'label'=>'Headline',
                        'hint'=>'&lt;em&gt; makes the italic gold part.',
                        'default'=>'Get in Touch · <em>We Respond Within 24 Hours</em>'],
                ],
                'Left column' => [
                    'left_label' => ['type'=>'text', 'label'=>'Small label',
                        'default'=>'Contact Tribal Sand'],
                    'left_title' => ['type'=>'text', 'label'=>'Heading',
                        'default'=>'We\'re here to help you plan the right trip.'],
                    'left_body'  => ['type'=>'textarea', 'label'=>'Paragraph',
                        'default'=>'Whether you\'re ready to book or just starting to explore, our team will put together a personalised quote covering transfers, activities and accommodations across any of our Kenya coast properties. No pressure at enquiry stage.'],
                ],
                'Enquiry form' => [
                    'form_label' => ['type'=>'text', 'label'=>'Small label', 'default'=>'Send an Enquiry'],
                    'form_title' => ['type'=>'text', 'label'=>'Heading',    'default'=>'Tell us about your trip'],
                    'form_body'  => ['type'=>'textarea', 'label'=>'Intro line',
                        'default'=>'Fill in what you know — we\'ll work out the rest and come back to you within 24 hours.'],
                ],
                'Sharing' => [
                    'og_image' => ['type'=>'image', 'label'=>'Social share image',
                        'hint'=>'Shown when the page is shared on WhatsApp, Facebook or LinkedIn. Not visible on the page itself.',
                        'default'=>'images/whitelogo11.png'],
                ],
            ],
        ],
        'our-story' => [
            'label' => 'About — Our Story',
            'url'   => '/tribalsandstory.php',
            'groups' => [
                'Hero' => [
                    'hero_image' => ['type'=>'image', 'label'=>'Hero background photo',
                        'hint'=>'Full-screen background behind the heading. Use a wide landscape shot.',
                        'default'=>'images/New-hero-banner.jpg'],
                    'hero_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow', 'default'=>'Tribal Sand'],
                    'hero_title' => ['type'=>'html', 'label'=>'Headline',
                        'hint'=>'&lt;em&gt; makes the italic gold part.',
                        'default'=>'Kenya as It Was <em>Meant to Be</em>'],
                    'hero_sub' => ['type'=>'textarea', 'label'=>'Sub-heading',
                        'default'=>'Our story begins at the edge of the Indian Ocean.'],
                ],
                'Sharing' => [
                    'og_image' => ['type'=>'image', 'label'=>'Social share image',
                        'hint'=>'Shown when the page is shared. Not visible on the page itself.',
                        'default'=>'images/New-hero-banner.jpg'],
                ],
            ],
        ],
        'weddings' => [
            'label' => 'Weddings & Events',
            'url'   => '/events.php',
            'groups' => [
                'Hero' => [
                    'hero_image' => ['type'=>'image', 'label'=>'Hero background photo',
                        'hint'=>'Full-width background behind the headline. A dark overlay sits on top, so a bright photo works best.',
                        'default'=>'images/event-gallery/AfricanNight-260.jpg'],
                    'hero_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow',
                        'default'=>'Kilifi · Watamu · Vipingo · Kenya'],
                    'hero_title' => ['type'=>'html', 'label'=>'Headline (H1)',
                        'hint'=>'This is the page\'s only H1 — keep the main keyword in it. &lt;em&gt; makes the italic gold part.',
                        'default'=>'Beachfront Wedding Venues on <em>Kenya\'s North Coast</em>'],
                    'hero_sub' => ['type'=>'textarea', 'label'=>'Sub-heading',
                        'default'=>'Private beach ceremonies in Kilifi and Watamu — exclusive-use properties directly on the Indian Ocean, with full wedding planning and accommodation for every guest.'],
                ],
                'Wedding venues section' => [
                    'wed_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow',
                        'default'=>'Beachfront Wedding Venues'],
                    'wed_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'A beachfront wedding venue <em>on your own stretch of sand</em>'],
                    'wed_body1' => ['type'=>'textarea', 'label'=>'First paragraph',
                        'default'=>'Every Tribal Sand property sits directly on the Indian Ocean, and every one can be taken exclusively. That means your ceremony, your reception and your guests all share a single private beachfront venue — no other guests, no shared spaces, no fixed timetable set by a hotel.'],
                    'wed_body2' => ['type'=>'textarea', 'label'=>'Second paragraph',
                        'default'=>'Say your vows on the sand at Bofa Beach in Kilifi, on the coral-fringed shore at Watamu, or on a private estate in Vipingo. Our team handles the licence paperwork, catering, decor, photography, music and transfers — and your whole party sleeps on site.'],
                    'wed_image' => ['type'=>'image', 'label'=>'Section photo',
                        'hint'=>'Renders 4:5 portrait beside the text.',
                        'default'=>'images/event-gallery/AfricanNight-51.jpg'],
                ],
                'Gallery' => [
                    'gal_1' => ['type'=>'image','label'=>'Gallery photo 1','default'=>'images/event-gallery/AfricanNight-260.jpg'],
                    'gal_2' => ['type'=>'image','label'=>'Gallery photo 2','default'=>'images/event-gallery/AfricanNight-51.jpg'],
                    'gal_3' => ['type'=>'image','label'=>'Gallery photo 3','default'=>'images/event-gallery/Birthday29th-578.jpg'],
                    'gal_4' => ['type'=>'image','label'=>'Gallery photo 4','default'=>'images/event-gallery/NYE-376.jpg'],
                    'gal_5' => ['type'=>'image','label'=>'Gallery photo 5','default'=>'images/event-gallery/AfricanNight-486.jpg'],
                    'gal_6' => ['type'=>'image','label'=>'Gallery photo 6','default'=>'images/event-gallery/Birthday29th-60.jpg'],
                    'gal_7' => ['type'=>'image','label'=>'Gallery photo 7','default'=>'images/event-gallery/NYE-46.jpg'],
                    'gal_8' => ['type'=>'image','label'=>'Gallery photo 8','default'=>'images/event-gallery/AfricanNight-66.jpg'],
                ],
                'Enquiry form' => [
                    'form_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'Enquire about your <em>beachfront wedding</em>'],
                    'form_body' => ['type'=>'textarea', 'label'=>'Intro line',
                        'default'=>'Tell us the date and rough guest count and we\'ll come back within 24 hours with the venues that fit, availability and a tailored proposal.'],
                ],
            ],
        ],
        'retreats' => [
            'label' => 'Retreats',
            'url'   => '/retreats.php',
            'groups' => [
                'Hero' => [
                    'hero_image' => ['type'=>'image', 'label'=>'Hero background photo',
                        'hint'=>'Full-width photo behind the headline, with a dark gradient over it.',
                        'default'=>'https://tribalsand.com/images/maya_illai/Best1.jpg'],
                    'hero_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow',
                        'default'=>'Retreats on Kenya\'s North Coast'],
                    'hero_title' => ['type'=>'html', 'label'=>'Headline (H1)',
                        'hint'=>'The page\'s only H1. &lt;br&gt; breaks the line, &lt;em&gt; makes the italic gold part.',
                        'default'=>'Where Your Retreat<br><em>Comes to Life</em>'],
                    'hero_sub' => ['type'=>'textarea', 'label'=>'Sub-heading',
                        'default'=>'Beachfront venues in Watamu and Kilifi for yoga and wellness retreats, kitesurf camps, corporate offsites, summer camps and marine biology programmes.'],
                ],
                'Retreat types' => [
                    'types_title' => ['type'=>'html', 'label'=>'Section heading',
                        'default'=>'Retreats of <em>Every Kind</em>'],
                    'img_yoga'      => ['type'=>'image','label'=>'Yoga retreat photo',
                        'default'=>'https://tribalsand.com/images/activities/20-private-yoga-session.jpg'],
                    'img_wellness'  => ['type'=>'image','label'=>'Wellness retreat photo',
                        'default'=>'https://tribalsand.com/images/activities/22-in-house-wellness-treatment.jpeg'],
                    'img_kitesurf'  => ['type'=>'image','label'=>'Kitesurf camp photo',
                        'default'=>'https://tribalsand.com/images/kitesurfing-watamu.jpg'],
                    'img_corporate' => ['type'=>'image','label'=>'Corporate retreat photo',
                        'default'=>'https://tribalsand.com/images/maya_illai/Best1.jpg'],
                    'img_summer'    => ['type'=>'image','label'=>'Summer camp photo',
                        'default'=>'https://tribalsand.com/images/IMAGE-3_How-to-Plan-a-Group-Vacation-on-the-Kenyan-Coast-A-Perfect-Guide-for-Unforgettable-Moments.webp'],
                    'img_marine'    => ['type'=>'image','label'=>'Marine biology camp photo',
                        'default'=>'https://tribalsand.com/images/marine-park.jpg'],
                ],
                'Venues' => [
                    'venues_title' => ['type'=>'html', 'label'=>'Section heading',
                        'default'=>'Three Beachfront <em>Locations</em>'],
                    'venue_img_1' => ['type'=>'image','label'=>'Maya Ilai photo',
                        'default'=>'https://tribalsand.com/images/maya_illai/Best1.jpg'],
                    'venue_img_2' => ['type'=>'image','label'=>'Zuri photo',
                        'default'=>'https://tribalsand.com/images/zuri/Aerial/zuri-3.webp'],
                    'venue_img_3' => ['type'=>'image','label'=>'Maya Kobe photo',
                        'default'=>'https://tribalsand.com/images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best12.jpg'],
                ],
                'Enquiry form' => [
                    'form_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'Tell us about <em>your retreat</em>'],
                    'form_body' => ['type'=>'textarea', 'label'=>'Intro line',
                        'default'=>'Share your dates, group size and the kind of retreat you have in mind. We reply within 24 hours with venue options, availability and a tailored proposal.'],
                ],
                'Closing banner' => [
                    'cta_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'Your Retreat <em>Begins Here</em>'],
                    'cta_image' => ['type'=>'image','label'=>'Banner photo',
                        'hint'=>'Very wide (1920×460) — use a landscape shot.',
                        'default'=>'https://tribalsand.com/images/updated-hero-banner.jpg'],
                ],
            ],
        ],
        'zuri-restaurant' => [
            'label' => 'Zuri Restaurant',
            'url'   => '/zuri-restaurant.php',
            'groups' => [
                'Hero' => [
                    'hero_image' => ['type'=>'image', 'label'=>'Hero photo',
                        'hint'=>'Full-width photo behind the heading, with a dark gradient over it.',
                        'default'=>'images/hero-zuri.jpg'],
                    'hero_badge' => ['type'=>'text', 'label'=>'Status badge',
                        'hint'=>'The pill above the heading.',
                        'default'=>'Now Open to the Public'],
                    'hero_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow',
                        'default'=>'Garoda Beach · Watamu · Kenya'],
                    'hero_title' => ['type'=>'html', 'label'=>'Headline (H1)',
                        'hint'=>'&lt;em&gt; makes the italic gold part.',
                        'default'=>'Zuri <em>Restaurant</em>'],
                    'hero_sub' => ['type'=>'html', 'label'=>'Sub-heading',
                        'hint'=>'Accepts HTML — the &lt;strong&gt; sets "by reservation only" in white.',
                        'default'=>'Coastal à la carte dining on the Indian Ocean shoreline — now open to the public, <strong style="color:#fff;font-weight:500;">by reservation only</strong>.'],
                ],
                'Dine with us' => [
                    'info_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow',
                        'default'=>'Open to the Public · By Reservation Only'],
                    'info_title' => ['type'=>'html', 'label'=>'Heading',
                        'default'=>'Dine with us at <em>Zuri</em>'],
                    'info_body' => ['type'=>'html', 'label'=>'Paragraph',
                        'hint'=>'Accepts HTML so "by reservation only" can stay bold.',
                        'default'=>'Our beachfront kitchen is now open to outside guests, not just those staying with us. Settle in for a relaxed lunch by the pool or a candlelit dinner steps from the sand. Because seating is intimate, we welcome guests <strong>by reservation only</strong> — send us your details below and our team will confirm your table within 24 hours.'],
                ],
                'Gallery' => [
                    'gal_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow', 'default'=>'A Taste of the Setting'],
                    'gal_title'   => ['type'=>'html', 'label'=>'Heading',  'default'=>'The Zuri Table'],
                    'gal_1' => ['type'=>'image','label'=>'Gallery photo 1','default'=>'images/zuri/Beach/zuri.watamu.beach.webp'],
                    'gal_2' => ['type'=>'image','label'=>'Gallery photo 2','default'=>'images/zuri/Garden/zuri.watamu.morning.pool-10.webp'],
                    'gal_3' => ['type'=>'image','label'=>'Gallery photo 3','default'=>'images/zuri/Aerial/zuri-3.webp'],
                    'gal_4' => ['type'=>'image','label'=>'Gallery photo 4','default'=>'images/zuri/Garden/zuri.watamu.entryoutdoor.garden-2.webp'],
                    'gal_5' => ['type'=>'image','label'=>'Gallery photo 5','default'=>'images/zuri/Beach/zuri.watamu.beach-2.webp'],
                    'gal_6' => ['type'=>'image','label'=>'Gallery photo 6','default'=>'images/zuri/Garden/zuri.watamu.morning.pool-17.webp'],
                ],
                'Reservation block' => [
                    'res_eyebrow' => ['type'=>'text', 'label'=>'Eyebrow', 'default'=>'Reserve a Table'],
                    'res_title'   => ['type'=>'html', 'label'=>'Heading',  'default'=>'Book Your <em>Table</em>'],
                    'res_body'    => ['type'=>'textarea', 'label'=>'Intro line',
                        'default'=>'This is a request — we confirm within 24 hours. No payment is taken at this stage.'],
                ],
                'Sharing' => [
                    'og_image' => ['type'=>'image', 'label'=>'Social share image',
                        'hint'=>'Shown when the page is shared. Not visible on the page itself.',
                        'default'=>'images/hero-zuri.jpg'],
                ],
            ],
        ],
    ];
}

/** Flat slot definitions for one page: slot_key => definition (+ 'group'). */
function page_slots(string $page): array {
    $reg = page_content_registry()[$page] ?? null;
    if (!$reg) return [];
    $out = [];
    foreach ($reg['groups'] as $group => $slots) {
        foreach ($slots as $key => $def) {
            $def['group'] = $group;
            $out[$key] = $def;
        }
    }
    return $out;
}

/** Stored overrides for a page: slot_key => value. Empty when unmigrated. */
function page_content_values(string $page): array {
    static $cache = [];
    if (isset($cache[$page])) return $cache[$page];
    if (!page_content_supported()) return $cache[$page] = [];
    try {
        $rows = db_query(
            'SELECT slot_key, value FROM page_content WHERE page_key = :p',
            [':p' => $page]
        )->fetchAll();
    } catch (Throwable $e) {
        return $cache[$page] = [];
    }
    $out = [];
    foreach ($rows as $r) $out[$r['slot_key']] = (string)$r['value'];
    return $cache[$page] = $out;
}

/**
 * Raw stored-or-default value for one slot. Falls back to the registry default,
 * so a page always renders even with no rows and no migration.
 */
function page_value(string $page, string $slot): string {
    $vals = page_content_values($page);
    if (isset($vals[$slot]) && trim($vals[$slot]) !== '') return $vals[$slot];
    return (string)(page_slots($page)[$slot]['default'] ?? '');
}

/** Escaped text for a slot. Use for everything that is not marked 'html'. */
function page_text(string $page, string $slot): string {
    return e(page_value($page, $slot));
}

/**
 * RAW output for a slot — only for slots declared 'html' in the registry.
 * A slot of any other type is escaped instead, so mislabelling a field at the
 * call site can't turn it into an injection point.
 */
function page_html(string $page, string $slot): string {
    $type = page_slots($page)[$slot]['type'] ?? 'text';
    $val  = page_value($page, $slot);
    return $type === 'html' ? $val : e($val);
}

/** Browser-usable URL for an image slot (storage key, /path or absolute URL). */
function page_image(string $page, string $slot): string {
    $v = trim(page_value($page, $slot));
    if ($v === '') return '';
    if (str_starts_with($v, 'http') || str_starts_with($v, '/')) return $v;
    if (str_starts_with($v, 'images/')) return asset_url($v);
    return storage_url($v);
}

/** Write one slot. Empty string deletes the override (back to the default). */
function page_content_save(string $page, string $slot, string $value, ?int $adminId = null): void {
    if (!page_content_supported()) return;
    if (!isset(page_slots($page)[$slot])) return;   // never store unknown slots
    if (trim($value) === '') {
        db_query('DELETE FROM page_content WHERE page_key = :p AND slot_key = :s',
                 [':p'=>$page, ':s'=>$slot]);
        return;
    }
    db_query(
        'INSERT INTO page_content (page_key, slot_key, value, updated_at, updated_by)
         VALUES (:p, :s, :v, NOW(), :u)
         ON CONFLICT (page_key, slot_key)
         DO UPDATE SET value = :v, updated_at = NOW(), updated_by = :u',
        [':p'=>$page, ':s'=>$slot, ':v'=>$value, ':u'=>$adminId]
    );
}
