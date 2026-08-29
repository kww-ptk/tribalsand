<?php
/**
 * Seed a few demo Offers & Specials for the homepage promo strip.
 * Idempotent: wipes offers whose title matches a seeded one, then re-inserts.
 *
 * Run:  D:\php84\php.exe db/seeds/seed_offers.php
 * This lays down starting content only — day-to-day edits happen in /admin/offers.php.
 * Images are left blank here (upload per-offer in admin); cards render fine without one.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/offers.php';

if (!offers_supported()) {
    fwrite(STDERR, "offers table missing — run db/migrations/add_offers.sql first.\n");
    exit(1);
}

/** [title, subtitle, category, badge, body, sort] */
$rows = [
    ['Stay 4, Pay 3',        'Any villa · low season',       'stay',       '25% off',        'Book four consecutive nights at any Tribal Sand villa and the fourth is on us. Low-season stays only.', 10],
    ['Sunset Dinner for Two', 'Beachfront tasting menu',      'dining',     'From $60',       'A five-course coastal tasting menu with a bottle of house wine, served on the sand at sunset.',          20],
    ['Tsavo Safari Escape',   'Two nights · full board',      'experience', 'From $340pp',    'A guided two-night safari to Tsavo East with transfers, park fees and full board included.',              30],
    ['Honeymoon Package',     'Villa · spa · private chef',   'special',    'Complimentary',  'A private villa, in-suite spa treatment and a private chef dinner for couples celebrating with us.',      40],
];

$ins = 0;
foreach ($rows as $r) {
    [$title, $subtitle, $category, $badge, $body, $sort] = $r;
    db_query("DELETE FROM offers WHERE title = :t", [':t' => $title]);   // idempotent
    db_query(
        "INSERT INTO offers (title, subtitle, category, badge, body, sort_order, is_published)
         VALUES (:title, :subtitle, :category, :badge, :body, :sort, TRUE)",
        [':title' => $title, ':subtitle' => $subtitle, ':category' => $category, ':badge' => $badge, ':body' => $body, ':sort' => $sort]
    );
    $ins++;
}

echo "Seeded {$ins} offers.\n";
