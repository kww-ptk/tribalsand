<?php
/**
 * Seed the POS starting catalogue: four outlets with their till categories,
 * activities linked into Experiences / Kite School, and a starter spa menu.
 *
 * Run:  D:\php84\php.exe db/seeds/seed_pos.php          (after db/migrations/add_pos.sql)
 *       D:\php84\php.exe db/seeds/seed_pos.php --dry-run
 *
 * Idempotent and NON-destructive: outlets are matched by slug, categories by
 * (outlet, name), linked activities by (outlet, tour_id), spa items by
 * (outlet, name). Anything that already exists is left exactly as the owner
 * edited it — re-running only fills gaps. Day-to-day edits happen in
 * Admin → Point of Sale.
 *
 * Activities are LINKED (pos_items.tour_id, price NULL), never copied: the till
 * reads tours.price_amount at sale time. An "on request" activity (no price)
 * rings up with an open price.
 *
 * Spa items are seeded INACTIVE with no price — they stay off the till until the
 * owner prices and switches them on (a seeded 0 would sell treatments for free).
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pos.php';

$dry = in_array('--dry-run', $argv ?? [], true);

if (!pos_supported()) {
    fwrite(STDERR, "POS tables missing — run db/migrations/add_pos.sql first.\n");
    exit(1);
}

$currency = strtoupper(setting('site_currency', 'USD'));

$OUTLETS = [
    ['slug' => 'experiences', 'name' => 'Experiences', 'kind' => 'experiences', 'cats' => ['Water sports', 'Land & culture', 'Sunset', 'Private']],
    ['slug' => 'shop',        'name' => 'Shop',        'kind' => 'shop',        'cats' => ['Apparel', 'Beach', 'Drinks', 'Crafts']],
    ['slug' => 'salon-spa',   'name' => 'Salon & Spa', 'kind' => 'salon_spa',   'cats' => ['Massage', 'Nails', 'Hair', 'Barber']],
    ['slug' => 'kite-school', 'name' => 'Kite School', 'kind' => 'kite',        'cats' => ['Lessons', 'Rental', 'Gear']],
];

/** [category, name] — inactive, unpriced until the owner fills them in. */
$SPA = [
    ['Massage', 'Swedish Massage — 60 min'],
    ['Massage', 'Deep Tissue Massage — 90 min'],
    ['Massage', 'Foot Reflexology — 30 min'],
    ['Nails',   'Manicure'],
    ['Nails',   'Pedicure'],
    ['Nails',   'Gel Manicure'],
    ['Hair',    'Blow-dry & Style'],
    ['Hair',    'Braiding'],
    ['Barber',  'Men’s Cut'],
    ['Barber',  'Cut & Beard Trim'],
];

$made = ['outlets' => 0, 'categories' => 0, 'activities' => 0, 'spa' => 0];
$log  = fn(string $m) => print(($dry ? '[dry-run] ' : '') . $m . "\n");

$run = function () use ($OUTLETS, $SPA, $currency, $dry, &$made, $log): void {
    $ids = [];
    foreach ($OUTLETS as $i => $o) {
        $id = db_query('SELECT id FROM pos_outlets WHERE slug = :s', [':s' => $o['slug']])->fetchColumn();
        if (!$id) {
            $log("outlet  + {$o['name']}");
            $made['outlets']++;
            if (!$dry) {
                db_query("INSERT INTO pos_outlets (name, slug, kind, currency, sort_order) VALUES (:n, :s, :k, :c, :o)",
                    [':n' => $o['name'], ':s' => $o['slug'], ':k' => $o['kind'], ':c' => $currency, ':o' => $i]);
                $id = db()->lastInsertId();
            }
        }
        $ids[$o['slug']] = (int)$id;
        foreach ($o['cats'] as $j => $cat) {
            $has = $id ? db_query('SELECT 1 FROM pos_categories WHERE outlet_id = :o AND name = :n', [':o' => (int)$id, ':n' => $cat])->fetchColumn() : false;
            if ($has) continue;
            $log("category + {$o['name']} / {$cat}");
            $made['categories']++;
            if (!$dry) db_query('INSERT INTO pos_categories (outlet_id, name, sort_order) VALUES (:o, :n, :s)', [':o' => (int)$id, ':n' => $cat, ':s' => $j]);
        }
    }

    $catId = function (int $outletId, string $name): ?int {
        if (!$outletId) return null;
        $c = db_query('SELECT id FROM pos_categories WHERE outlet_id = :o AND name = :n', [':o' => $outletId, ':n' => $name])->fetchColumn();
        return $c ? (int)$c : null;
    };

    // Activities (excursions) → Experiences, kite ones → Kite School. Safaris are
    // multi-day journeys sold by reservations, not at a till, so they are skipped.
    $tours = db_query("SELECT id, name, price_amount, price_per_person FROM tours
                        WHERE is_published = TRUE AND category = 'excursion' ORDER BY sort_order, name")->fetchAll();
    foreach ($tours as $k => $t) {
        $isKite = (bool) preg_match('/\bkite/i', (string)$t['name']);
        $slug   = $isKite ? 'kite-school' : 'experiences';
        $oid    = $ids[$slug] ?? 0;
        if ($oid && db_query('SELECT 1 FROM pos_items WHERE outlet_id = :o AND tour_id = :t', [':o' => $oid, ':t' => (int)$t['id']])->fetchColumn()) continue;
        $cat = $isKite ? 'Lessons' : (preg_match('/sunset|dhow/i', (string)$t['name']) ? 'Sunset'
             : (preg_match('/snorkel|dive|diving|fish|kayak|paddle|surf|boat|marine|dolphin/i', (string)$t['name']) ? 'Water sports' : 'Land & culture'));
        $price = $t['price_amount'] === null ? 'open price' : pos_money((float)$t['price_amount'], $currency);
        $log("activity + " . ($isKite ? 'Kite School' : 'Experiences') . " / {$t['name']} ({$price})");
        $made['activities']++;
        if (!$dry) {
            db_query("INSERT INTO pos_items (outlet_id, category_id, kind, tour_id, name, price, per_person, sort_order)
                      VALUES (:o, :c, 'service', :t, :n, NULL, :pp, :s)",
                [':o' => $oid, ':c' => $catId($oid, $cat), ':t' => (int)$t['id'], ':n' => mb_substr((string)$t['name'], 0, 160),
                 ':pp' => pos_bool($t['price_per_person']) ? 'TRUE' : 'FALSE', ':s' => $k]);
        }
    }

    $spa = $ids['salon-spa'] ?? 0;
    foreach ($SPA as $k => [$cat, $name]) {
        if ($spa && db_query('SELECT 1 FROM pos_items WHERE outlet_id = :o AND name = :n', [':o' => $spa, ':n' => $name])->fetchColumn()) continue;
        $log("spa     + {$name} (inactive, unpriced)");
        $made['spa']++;
        if (!$dry) {
            db_query("INSERT INTO pos_items (outlet_id, category_id, kind, name, price, is_active, sort_order)
                      VALUES (:o, :c, 'service', :n, NULL, FALSE, :s)",
                [':o' => $spa, ':c' => $catId($spa, $cat), ':n' => $name, ':s' => $k]);
        }
    }
};

if ($dry) { $run(); } else { pos_tx($run); }

printf("%sDone: %d outlets, %d categories, %d activities linked, %d spa items.\n",
    $dry ? '[dry-run] ' : '', $made['outlets'], $made['categories'], $made['activities'], $made['spa']);
