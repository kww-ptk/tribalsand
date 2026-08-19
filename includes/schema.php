<?php
require_once __DIR__ . '/db.php'; // asset_url()/site_url() available to pages that require schema first
/**
 * TRIBAL SAND · JSON-LD Structured Data Functions
 *
 * Usage: echo ts_schema_org(); in $page_schema before including head.php
 *
 * All functions return a complete <script type="application/ld+json"> block.
 */

/**
 * Canonical hygiene for structured-data URLs. The server 301-redirects
 * /foo.php → /foo, so every URL emitted in JSON-LD (breadcrumb items, ItemList
 * entries, LodgingBusiness url) must be the clean, final form — never the .php
 * variant that redirects. Mirrors the same normalisation done for the canonical
 * <link> in includes/head.php, so page URL and structured data always agree.
 */
function ts_seo_clean_url(string $url): string {
    return preg_replace('~\.php(?=$|[?#])~i', '', $url);
}

/**
 * TripAdvisor listing URL — SINGLE SOURCE OF TRUTH.
 *
 * Once the listing is approved at tripadvisor.com/GetListedNew, paste the
 * canonical listing URL between the quotes below (NOT a search URL) and every
 * consumer updates at once: the JSON-LD `sameAs` on Organization + LocalBusiness
 * (via ts_sameas()) and all three homepage badges (via ts_tripadvisor_badge_url()).
 * Leave empty until approved.
 */
function ts_tripadvisor_url(): string {
    return ''; // e.g. 'https://www.tripadvisor.com/Hotel_Review-gXXXXXX-dXXXXXXX-Reviews-Tribal_Sand-Kilifi.html'
}

/**
 * Badge link target. Uses the real listing once approved; until then a search
 * link so the homepage badges still lead somewhere sensible. (Schema `sameAs`
 * must be the canonical entity URL, so it stays empty until approved — never a search.)
 */
function ts_tripadvisor_badge_url(): string {
    return ts_tripadvisor_url() ?: 'https://www.tripadvisor.com/Search?q=Tribal+Sand+Kenya';
}

/** Append the TripAdvisor listing URL to a social sameAs[] list, only once approved. */
function ts_sameas(array $base): array {
    $ta = ts_tripadvisor_url();
    if ($ta !== '') { $base[] = $ta; }
    return $base;
}

/**
 * Organization schema — use on every page alongside page-specific schema.
 */
function ts_schema_org(): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => 'Tribal Sand',
        'url'      => 'https://tribalsand.com',
        'logo'     => asset_url('images/whitelogo11.png'),
        'description' => 'Luxury sustainable beachfront hospitality ecosystem on Kenya\'s North Coast. Boutique hotels and private villas in Watamu, Kilifi and Vipingo.',
        'telephone'   => '+254115115247',
        'email'       => 'reservations@tribalsand.com',
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Kilifi',
            'addressRegion'   => 'Kilifi County',
            'addressCountry'  => 'KE',
        ],
        'sameAs' => ts_sameas([
            'https://www.instagram.com/tribalsand/',
            'https://www.facebook.com/tribalsand/',
            'https://www.youtube.com/@tribalsand7436',
        ]), // TripAdvisor added automatically once ts_tripadvisor_url() is set
        'contactPoint' => [
            '@type'             => 'ContactPoint',
            'telephone'         => '+254-115-115-247',
            'contactType'       => 'reservations',
            'availableLanguage' => ['English', 'French', 'German', 'Italian', 'Swahili'],
        ],
    ];
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

/**
 * LodgingBusiness schema — for property pages.
 *
 * @param array $data  Keys: name, description, url, image (array), addressLocality,
 *                     addressRegion, lat, lng, numberOfRooms, priceRange,
 *                     amenities (array of strings), starRating (default 5)
 */
function ts_schema_lodging(array $data): string {
    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'LodgingBusiness',
        'name'        => $data['name']        ?? '',
        'description' => $data['description'] ?? '',
        'url'         => ts_seo_clean_url($data['url'] ?? ''),
        'image'       => $data['image']       ?? [],
        'telephone'   => '+254115115247',
        'email'       => 'reservations@tribalsand.com',
        'priceRange'  => $data['priceRange']  ?? '$$$',
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => $data['addressLocality'] ?? 'Kilifi',
            'addressRegion'   => $data['addressRegion']   ?? 'Kilifi County',
            'addressCountry'  => 'KE',
        ],
        'geo' => [
            '@type'     => 'GeoCoordinates',
            'latitude'  => $data['lat'] ?? -3.40,
            'longitude' => $data['lng'] ?? 39.85,
        ],
        'starRating' => [
            '@type'       => 'Rating',
            'ratingValue' => $data['starRating'] ?? '5',
        ],
        'numberOfRooms' => $data['numberOfRooms'] ?? '',
    ];

    if (!empty($data['amenities'])) {
        $schema['amenityFeature'] = array_map(function($a) {
            return ['@type' => 'LocationFeatureSpecification', 'name' => $a, 'value' => true];
        }, $data['amenities']);
    }

    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

/**
 * BreadcrumbList schema — for all pages except homepage.
 *
 * @param array $items  Array of ['name' => '…', 'url' => '…']. Last item = current page.
 */
function ts_schema_breadcrumb(array $items): string {
    $list = [];
    foreach ($items as $pos => $item) {
        $entry = [
            '@type'    => 'ListItem',
            'position' => $pos + 1,
            'name'     => $item['name'],
        ];
        if (!empty($item['url'])) {
            $entry['item'] = ts_seo_clean_url($item['url']);
        }
        $list[] = $entry;
    }
    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

/**
 * FAQPage schema.
 *
 * @param array $faqs  Array of ['q' => '…', 'a' => '…']
 */
function ts_schema_faq(array $faqs): string {
    $entries = array_map(function($faq) {
        return [
            '@type'          => 'Question',
            'name'           => $faq['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $faq['a'],
            ],
        ];
    }, $faqs);

    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entries,
    ];
    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

/**
 * LocalBusiness schema — for contact page.
 */
function ts_schema_local_business(): string {
    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'LocalBusiness',
        'name'        => 'Tribal Sand',
        'description' => 'Luxury beachfront boutique hotels and private villas on Kenya\'s North Coast — Watamu, Kilifi and Vipingo.',
        'url'         => 'https://tribalsand.com',
        'logo'        => asset_url('images/whitelogo11.png'),
        'image'       => asset_url('images/Maya-Kobe-1-hero.webp'),
        'telephone'   => '+254115115247',
        'email'       => 'reservations@tribalsand.com',
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Kilifi',
            'addressRegion'   => 'Kilifi County',
            'addressCountry'  => 'KE',
        ],
        'geo' => [
            '@type'     => 'GeoCoordinates',
            'latitude'  => -3.6340,
            'longitude' => 39.8503,
        ],
        'openingHoursSpecification' => [
            [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                'opens'     => '08:00',
                'closes'    => '20:00',
            ],
        ],
        'sameAs' => ts_sameas([
            'https://www.instagram.com/tribalsand/',
            'https://www.facebook.com/tribalsand/',
        ]), // TripAdvisor added automatically once ts_tripadvisor_url() is set
    ];
    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

/**
 * ItemList schema — for homepage property list.
 */
function ts_schema_item_list(array $items): string {
    $list = [];
    foreach ($items as $pos => $item) {
        $list[] = [
            '@type'    => 'ListItem',
            'position' => $pos + 1,
            'name'     => $item['name'],
            'url'      => ts_seo_clean_url($item['url']),
        ];
    }
    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => 'Tribal Sand Properties',
        'itemListElement' => $list,
    ];
    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
}
