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
                        'default'=>'https://d38di21ab22p6u.cloudfront.net/da8332ef15c50327eedc.jpg'],
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
