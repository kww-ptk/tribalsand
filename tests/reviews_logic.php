<?php
declare(strict_types=1);
// Guest reviews. Run: php tests/reviews_logic.php
// Pure rules always; DB round trip in a rolled-back transaction (SKIPs with no DB / migration).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reviews.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('stars: 5 and 4', review_stars(5) === '★★★★★' && review_stars(4) === '★★★★☆');
check('stars: out of range is clamped', review_stars(9) === '★★★★★' && review_stars(0) === '★☆☆☆☆');
check('average: one decimal', reviews_average([['rating' => 5], ['rating' => 4], ['rating' => 5]]) === '4.7');
check('average: none → null', reviews_average([]) === null);
check('quote: surrounding quote marks removed', review_plain_quote('  "Loved it!"  ') === 'Loved it!' && review_plain_quote('“Magic”') === 'Magic');
[$d, $e] = review_clean(['author' => ' Sophie ', 'quote' => '"Great"', 'rating' => '4', 'venue_id' => '3', 'is_published' => '1']);
check('clean: a valid review', !$e && $d['author'] === 'Sophie' && $d['quote'] === 'Great' && $d['rating'] === 4 && $d['venue_id'] === 3 && $d['is_published'] && !$d['show_on_home']);
check('clean: author and text required', count(review_clean(['author' => '', 'quote' => ''])[1]) === 2);
check('clean: rating 1–5 only', isset(review_clean(['author' => 'a', 'quote' => 'b', 'rating' => 6])[1]['rating']));
check('clean: over-long text refused', isset(review_clean(['author' => 'a', 'quote' => str_repeat('x', REVIEW_QUOTE_MAX + 1)])[1]['quote']));
check('clean: venue 0 = the whole group', review_clean(['author' => 'a', 'quote' => 'b', 'venue_id' => '0'])[0]['venue_id'] === null);

try { db()->query('SELECT 1'); } catch (Throwable $e) { echo "\nSKIP  DB block\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }
if (!reviews_supported()) { echo "\nSKIP  DB block (add_reviews.sql not applied)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    db_query('DELETE FROM reviews');
    $v = db_query('SELECT id, slug FROM venues ORDER BY id LIMIT 1')->fetch();
    check('db: nothing published → the page falls back to its built-in reviews', reviews_for_venue((string)$v['slug']) === [] && reviews_for_home() === []);
    $a = review_save(null, review_clean(['venue_id' => $v['id'], 'author' => 'ZZ A', 'quote' => 'One', 'rating' => 5, 'is_published' => 1])[0]);
    $b = review_save(null, review_clean(['venue_id' => $v['id'], 'author' => 'ZZ B', 'quote' => 'Two', 'rating' => 4, 'is_published' => 1, 'show_on_home' => 1])[0]);
    $h = review_save(null, review_clean(['venue_id' => $v['id'], 'author' => 'ZZ Hidden', 'quote' => 'Three', 'rating' => 1])[0]);
    $g = review_save(null, review_clean(['author' => 'ZZ Group', 'quote' => 'Four', 'rating' => 5, 'is_published' => 1, 'show_on_home' => 1])[0]);
    $rows = reviews_for_venue((string)$v['slug']);
    check('db: a property shows its published reviews, in order', array_column($rows, 'author') === ['ZZ A', 'ZZ B']);
    check('db: an unpublished review is never shown', !in_array('ZZ Hidden', array_column($rows, 'author'), true));
    check('db: the page score is their average', reviews_average($rows) === '4.5');
    check('db: the home page shows the ones picked for it', array_column(reviews_for_home(), 'author') === ['ZZ Group', 'ZZ B']);
    reviews_reorder([$b, $a]);
    check('db: reordering changes the page order', array_column(reviews_for_venue((string)$v['slug']), 'author') === ['ZZ B', 'ZZ A']);
    review_save($a, review_clean(['venue_id' => $v['id'], 'author' => 'ZZ A', 'quote' => 'Edited', 'rating' => 3, 'is_published' => 1])[0]);
    check('db: an edit shows on the page', in_array('Edited', array_column(reviews_for_venue((string)$v['slug']), 'quote'), true));
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
