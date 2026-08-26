<?php
declare(strict_types=1);
// Shared venue-gallery resolver — DB mapping, fallback rules, memoization.
// Run: php tests/property_gallery.php
// The DB assertions run inside ONE transaction that is ROLLED BACK at the end,
// so no venue or image rows are ever left behind.
require_once __DIR__ . '/../includes/property-gallery-data.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Unknown slug: fallback rules ────────────────────────────────────────────
$g = pg_gallery('__pg_missing_a__');
check('unknown slug, no fallback → empty',   $g['images'] === []);
check('unknown slug, no fallback → no badge', $g['badge'] === '');

$g = pg_gallery('__pg_missing_b__', ['images/x.jpg'], 'Badge Text');
check('string fallback → one image',         count($g['images']) === 1);
check('string fallback → url passthrough',   $g['images'][0]['url'] === 'images/x.jpg');
check('string fallback → alt from badge',    $g['images'][0]['alt'] === 'Badge Text');
check('fallback badge used',                 $g['badge'] === 'Badge Text');

$g = pg_gallery('__pg_missing_c__', [['src' => 'images/y.jpg', 'alt' => 'Why']], 'B');
check('array fallback → url',                $g['images'][0]['url'] === 'images/y.jpg');
check('array fallback → alt',                $g['images'][0]['alt'] === 'Why');

$g = pg_gallery('__pg_missing_d__', ['', ['src' => 'images/z.jpg', 'alt' => 'Zed']], 'B');
check('fallback drops empty urls',           count($g['images']) === 1 && $g['images'][0]['url'] === 'images/z.jpg');

// ── DB-backed venue (rolled back) ───────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB assertions (database unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n");
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    db_query("INSERT INTO venues (slug, name, location, sort_order) VALUES ('__pg_test__', 'Test Venue', 'Testland', 999)");
    $vid = (int) db_query("SELECT id FROM venues WHERE slug = '__pg_test__'")->fetch()['id'];
    db_query(
        "INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order) VALUES
           (:v, '/images/a.jpg',  'Alpha', TRUE,  5),
           (:v, '/images/b.jpg',  '',      FALSE, 0),
           (:v, 'gallery/c.jpg',  'Cee',   FALSE, 2)",
        [':v' => $vid]
    );

    // Hero row is given the HIGHEST sort_order on purpose: if the resolver ever
    // regressed to ordering by sort_order alone (dropping `is_hero DESC`), the
    // hero row would sort LAST, not first — so this can only pass when is_hero
    // genuinely wins the ordering.
    $g = pg_gallery('__pg_test__');
    check('DB → three images',               count($g['images']) === 3);
    check('DB → hero first',                 $g['images'][0]['url'] === '/images/a.jpg');
    check('DB → alt_text used',              $g['images'][0]['alt'] === 'Alpha');
    check('storage_url passes /images/ through', $g['images'][1]['url'] === '/images/b.jpg');
    check('empty alt_text → venue name',     $g['images'][1]['alt'] === 'Test Venue');
    check('storage_url maps bare keys',      $g['images'][2]['url'] === '/assets/img/gallery/c.jpg');
    check('badge = name · location',         $g['badge'] === 'Test Venue · Testland');

    check('memoized: identical on 2nd call', pg_gallery('__pg_test__') === $g);

    // A venue WITH DB rows must ignore any fallback passed to it.
    db_query("INSERT INTO venues (slug, name, location, sort_order) VALUES ('__pg_test2__', 'Test Two', '', 999)");
    $vid2 = (int) db_query("SELECT id FROM venues WHERE slug = '__pg_test2__'")->fetch()['id'];
    db_query("INSERT INTO venue_images (venue_id, filename, alt_text, is_hero, sort_order) VALUES (:v, '/images/real.jpg', 'Real', TRUE, 0)", [':v' => $vid2]);
    $g2 = pg_gallery('__pg_test2__', ['images/ignored.jpg'], 'Ignored');
    check('DB rows beat fallback',           count($g2['images']) === 1 && $g2['images'][0]['url'] === '/images/real.jpg');
    check('badge from venue, not fallback',  $g2['badge'] === 'Test Two');

    // THE MEMOIZATION TRAP: hero calls WITH fallback, grid calls WITHOUT.
    // The no-fallback call must still return empty for an image-less venue.
    db_query("INSERT INTO venues (slug, name, location, sort_order) VALUES ('__pg_empty__', 'Empty Venue', '', 999)");
    $withFb = pg_gallery('__pg_empty__', ['images/hero-fallback.jpg'], 'Empty Venue');
    check('image-less venue + fallback → fallback shown', count($withFb['images']) === 1);
    $noFb = pg_gallery('__pg_empty__');
    check('image-less venue, no fallback → still empty',  $noFb['images'] === []);
} finally {
    db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
