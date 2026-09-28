<?php
declare(strict_types=1);
/**
 * Seed the reviews table with the reviews the pages show today (My Amani, Maya Kobe,
 * home page), so Admin → Reviews starts from what guests already see.
 * Idempotent: does nothing when the table already has rows (the admin is then the
 * source of truth). Usage: php db/seeds/seed_reviews.php [--dry-run]
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/reviews.php';

$dry = in_array('--dry-run', $argv ?? [], true);
if (!reviews_supported()) { fwrite(STDERR, "Run db/migrations/add_reviews.sql first.\n"); exit(1); }
if ((int) db_query('SELECT COUNT(*) FROM reviews')->fetchColumn() > 0) { echo "Reviews already exist — nothing to do.\n"; exit(0); }

$byVenue = [
    'my-amani' => [
        ['Mr & Mrs B Vyas', '', 5, 'Stunning place, great staff — friendly and attentive. They made our visit perfect. We will be coming back again and again.'],
        ['Sonal Patel', '', 5, 'We will always remember this stunning place — its vibrance, its calm aura, its unbelievably kind staff. Five days of astonishment.'],
        ['Viv & Peter Thairu', '', 4, 'The perfect place to relax and unwind. We loved the house, the pool, the outdoor shower, the beach… everything!'],
        ['Erin', '', 5, 'Thank you for helping create the most unforgettable birthday. A visual masterpiece — the staff made us feel like family.'],
    ],
    'maya-kobe' => [
        ['Amara & David K.', '', 5, 'Absolutely magical. The Balinese design, the sound of the ocean from our suite, the pool at golden hour — we never wanted to leave. Staff went above and beyond every single day.'],
        ['Sophie M.', '', 5, 'We took the Prestige Suite for our anniversary and it was beyond perfect. The private pool and open-air bath looking out to the Indian Ocean — nothing compares.'],
        ['Tarquin & Lex', '', 5, 'Kilifi\'s hidden gem. The whole Tribal Dunes experience — kite school, the café, the hotel — felt like discovering a place the world hasn\'t found yet. Truly special.'],
        ['Farai & Nadia', '', 5, 'We booked the full property for a boutique wedding celebration. The team executed everything flawlessly. Our guests are still talking about it months later.'],
    ],
];
$home = [
    ['Mr & Mrs B Vyas', '', 5, 'Stunning place, great staff — friendly and attentive. They made our visit perfect. Fabulous location, we will be coming back again and again.'],
    ['Sonal Patel', '', 5, 'We will always remember this stunning place — its vibrance, its calm aura, its unbelievably kind staff. Our five days were filled with astonishment.'],
    ['Erin', '', 5, 'Thank you for helping create the most unforgettable birthday. A visual masterpiece — the staff made us feel like family. Every detail was perfect.'],
];

$n = 0;
$put = function (?int $venueId, array $r, bool $onHome) use ($dry, &$n): void {
    [$d] = review_clean(['venue_id' => $venueId, 'author' => $r[0], 'detail' => $r[1], 'rating' => $r[2], 'quote' => $r[3], 'is_published' => 1, 'show_on_home' => $onHome ? 1 : 0]);
    echo ($dry ? '[dry-run] ' : '') . "+ {$r[0]}" . ($onHome ? ' (home page)' : '') . "\n";
    if (!$dry) review_save(null, $d);
    $n++;
};
foreach ($byVenue as $slug => $rows) {
    $vid = db_query('SELECT id FROM venues WHERE slug = :s', [':s' => $slug])->fetchColumn();
    if (!$vid) { echo "skip {$slug}: no such property\n"; continue; }
    foreach ($rows as $r) $put((int)$vid, $r, false);
}
foreach ($home as $r) $put(null, $r, true);
echo ($dry ? '[dry-run] ' : '') . "Done: {$n} reviews.\n";
