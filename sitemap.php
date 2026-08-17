<?php
/**
 * TRIBAL SAND · Dynamic sitemap generator
 * ------------------------------------------------------------------
 * Served at /sitemap.xml (rewritten in .htaccess + router.php) so the
 * registered sitemap URL never changes. Core pages are curated below;
 * Journal articles come from the manifest (includes/articles.php), so
 * publishing an article automatically lists it here — no manual edits,
 * no drift. Domain comes from site_url() (APP_URL), so it follows the
 * AWS cutover too. Uses no database, so it can't break if the DB is down.
 */
require_once __DIR__ . '/includes/db.php';        // site_url()
require_once __DIR__ . '/includes/articles.php';  // ts_articles()

header('Content-Type: application/xml; charset=UTF-8');

// [path, priority, changefreq]
$core = [
    ['',                          '1.0', 'weekly'],
    // Boutique hotels
    ['zuri.php',                  '0.9', 'monthly'],
    ['maya-kobe.php',             '0.9', 'monthly'],
    // Private villas
    ['my-amani.php',              '0.9', 'monthly'],
    ['enkare-bofa.php',           '0.8', 'monthly'],
    ['sandbox.php',               '0.8', 'monthly'],
    // Eco compound
    ['maya_ilai.php',             '0.8', 'monthly'],
    // Location & content hubs
    ['kilifi.php',                '0.8', 'monthly'],
    ['watamu.php',                '0.8', 'monthly'],
    ['kenya-honeymoon.php',       '0.8', 'monthly'],
    ['kenya-coast-guide.php',     '0.8', 'monthly'],
    ['tribal-dunes.php',          '0.8', 'monthly'],
    // Tribal Dunes venues (coming soon)
    ['off-duty.php',              '0.6', 'monthly'],
    ['tribal-table.php',          '0.6', 'monthly'],
    ['somewhere-cafe.php',        '0.6', 'monthly'],
    // Experiences
    ['activities.php',            '0.7', 'monthly'],
    ['events.php',                '0.6', 'monthly'],
    // Content
    ['sustainability.php',        '0.6', 'monthly'],
    ['blog.php',                  '0.7', 'weekly'],
    ['trip-builder.php',          '0.7', 'monthly'],
    ['interactive-site-map.php',  '0.7', 'monthly'],
    // Company
    ['tribalsandstory.php',       '0.5', 'monthly'],
    ['contact.php',               '0.5', 'monthly'],
    ['for-agents.php',            '0.4', 'monthly'],
    // Legal
    ['privacy_policy.php',        '0.3', 'yearly'],
    ['tc.php',                    '0.3', 'yearly'],
    ['sffp.php',                  '0.3', 'yearly'],
    ['licences.php',              '0.3', 'yearly'],
    // Galleries
    ['my-amani-gallery.php',      '0.4', 'monthly'],
    ['maya-kobe-gallery.php',     '0.4', 'monthly'],
    ['zuri-gallery.php',          '0.4', 'monthly'],
    ['enkarebofa-gallery.php',    '0.3', 'monthly'],
    ['sandbox-gallery.php',       '0.3', 'monthly'],
    ['maya-ilai-gallery.php',     '0.3', 'monthly'],
    ['events-gallery.php',        '0.3', 'monthly'],
];

/** Emit one <url> block. */
function ts_sitemap_url(string $loc, string $priority, string $changefreq): string {
    return "  <url>\n"
         . "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n"
         . "    <priority>{$priority}</priority>\n"
         . "    <changefreq>{$changefreq}</changefreq>\n"
         . "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n\n";

echo "  <!-- Core pages -->\n";
foreach ($core as [$path, $pri, $freq]) {
    $loc = site_url($path) . ($path === '' ? '/' : ''); // keep homepage trailing slash (matches its canonical)
    echo ts_sitemap_url($loc, $pri, $freq);
}

echo "\n  <!-- Journal articles -->\n";
foreach (ts_articles() as $slug => $a) {
    $pri = ($slug === 'why-kenya-coast-best-luxury-destination-africa') ? '0.7' : '0.6';
    echo ts_sitemap_url(site_url($slug . '.php'), $pri, 'monthly');
}

echo "\n</urlset>\n";
