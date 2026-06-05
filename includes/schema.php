<?php
/**
 * TRIBAL SAND · JSON-LD Structured Data Functions
 *
 * Usage: echo ts_schema_org(); in $page_schema before including head.php
 *
 * All functions return a complete <script type="application/ld+json"> block.
 */

/**
 * Organization schema — use on every page alongside page-specific schema.
 */
function ts_schema_org(): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => 'Tribal Sand',
        'url'      => 'https://tribalsand.com',
        'logo'     => 'https://tribalsand.com/images/whitelogo11.png',
        'description' => 'Luxury sustainable beachfront hospitality ecosystem on Kenya\'s North Coast. Boutique hotels and private villas in Watamu, Kilifi and Vipingo.',
        'telephone'   => '+254115115247',
        'email'       => 'reservations@tribalsand.com',
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Kilifi',
            'addressRegion'   => 'Kilifi County',
            'addressCountry'  => 'KE',
        ],
        'sameAs' => [
            'https://www.instagram.com/tribalsand/',
            'https://www.facebook.com/tribalsand/',
            'https://www.youtube.com/@tribalsand7436',
        ],
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
        'url'         => $data['url']         ?? '',
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
            $entry['item'] = $item['url'];
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
        'logo'        => 'https://tribalsand.com/images/whitelogo11.png',
        'image'       => 'https://tribalsand.com/images/Maya-Kobe-1-hero.webp',
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
        'sameAs' => [
            'https://www.instagram.com/tribalsand/',
            'https://www.facebook.com/tribalsand/',
        ],
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
            'url'      => $item['url'],
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
