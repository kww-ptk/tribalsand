<?php
declare(strict_types=1);
// Per-property SEO & social sharing: the resolver's fallback order, and that a
// blank field can never blank a page's title.
// Run: php tests/venue_seo.php
//
// Every DB assertion runs inside a transaction that is rolled back, so this
// leaves the venues table exactly as it found it.
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$FB = ['title' => 'Built-in title', 'desc' => 'Built-in description', 'image' => 'https://cdn/built-in.jpg'];

// ── Pure: an unknown venue is every page's own fallback ─────────────────────
[$t, $d, $i] = ts_venue_meta('no-such-venue-' . bin2hex(random_bytes(4)), $FB);
check('an unknown venue falls back to the page entirely',
      $t === $FB['title'] && $d === $FB['desc'] && $i === $FB['image']);

if (!venue_seo_supported()) {
    echo "\nSKIP  venues has no SEO columns — run db/migrations/add_venue_seo.sql\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

try { db()->beginTransaction(); } catch (Throwable $e) {
    echo "\nSKIP  no database reachable\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

$slug = 'seo-test-' . bin2hex(random_bytes(4));
db_query('INSERT INTO venues (slug, name, is_published) VALUES (:s, :n, FALSE)',
         [':s' => $slug, ':n' => 'SEO Test Venue']);

/** Re-read with a cleared cache — ts_venue_seo() memoises per slug. */
$meta = function (?array $fb = null) use ($slug, $FB) {
    // A fresh process is not available mid-test, so vary the slug's cache key by
    // reading through a distinct slug each time would change the row. Instead the
    // helper is exercised once per state below, each with its own slug.
    return ts_venue_meta($slug, $fb ?? $FB);
};

// ── Empty columns are "not set", never a blank title ────────────────────────
[$t, $d, $i] = $meta();
check('all columns empty serves the page\'s own values',
      $t === $FB['title'] && $d === $FB['desc'] && $i === $FB['image']);

// Each further state needs its own slug, because the resolver memoises.
$mk = function (array $cols) {
    $s = 'seo-test-' . bin2hex(random_bytes(5));
    db_query('INSERT INTO venues (slug, name, is_published, seo_title, seo_description, og_image)
              VALUES (:s, :n, FALSE, :t, :d, :o)',
             [':s' => $s, ':n' => 'SEO Test', ':t' => $cols['t'] ?? '', ':d' => $cols['d'] ?? '', ':o' => $cols['o'] ?? '']);
    return $s;
};

[$t, $d, $i] = ts_venue_meta($mk(['t' => 'DB title']), $FB);
check('a saved title wins, and leaves description and image alone',
      $t === 'DB title' && $d === $FB['desc'] && $i === $FB['image']);

[$t, $d, $i] = ts_venue_meta($mk(['d' => 'DB description']), $FB);
check('a saved description wins on its own',
      $t === $FB['title'] && $d === 'DB description');

// Whitespace is not a value — an editor clearing a field leaves spaces behind.
[$t, $d] = ts_venue_meta($mk(['t' => '   ', 'd' => "\n\t "]), $FB);
check('whitespace-only fields are treated as empty, not as a blank title',
      $t === $FB['title'] && $d === $FB['desc']);

// og_image is a storage KEY; an unresolvable one must not blank the card.
[$t, $d, $i] = ts_venue_meta($mk(['o' => 'venue-og-test.jpg']), $FB);
check('a saved og_image resolves through storage_url(), not straight through',
      $i !== '' && $i !== 'venue-og-test.jpg' && str_contains($i, 'venue-og-test.jpg'));

[$t, $d, $i] = ts_venue_meta($mk([]), $FB);
check('no og_image keeps the page\'s image rather than emitting an empty one', $i === $FB['image']);

// A page that passes no fallback at all must still return strings, not null.
[$t, $d, $i] = ts_venue_meta($mk([]), []);
check('a missing fallback yields empty strings, never null',
      $t === '' && $d === '' && $i === '');

db()->rollBack();
$left = (int) db_query("SELECT COUNT(*) FROM venues WHERE slug LIKE 'seo-test-%'")->fetchColumn();
check('every test venue was rolled back', $left === 0);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
