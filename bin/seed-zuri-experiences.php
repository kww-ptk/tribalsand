#!/usr/bin/env php
<?php
/**
 * Seed the Watamu/Zuri curated experiences into the `tours` catalogue, so the
 * assistant's list_activities tool can surface them (name, indicative price,
 * area, duration, summary) and the public Activities page shows them.
 *
 * Source: the Internal Property Sales Book (Zuri – Curated Experiences). Prices
 * are the published *indicative* figures (VARCHAR display, e.g. "From $40 per
 * person"); they are third-party facilitated and "subject to change", so they are
 * stored only as the display `price` — no structured price_amount, i.e. these are
 * enquiry/on-request experiences, not instant-book. category='excursion',
 * location='watamu'.
 *
 * SAFE BY DEFAULT: dry-run unless --apply. Idempotent — upserts on slug, so
 * re-running updates rather than duplicating. Pre-migration-safe: only writes the
 * optional columns (price/location/is_guest_favourite) that actually exist.
 *
 * Run on PROD via an ECS run-task command override, like the audit/reconciler:
 *     php bin/seed-zuri-experiences.php            # preview
 *     php bin/seed-zuri-experiences.php --apply    # write
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);

// slug, name, price (display), duration, short_desc
$EXPERIENCES = [
    ['zuri-snorkelling-dolphin', 'Snorkelling & Dolphin Watching — Watamu Marine Park', 'From $40 per person (+ $15 park fee)', 'Full day', 'Explore vibrant coral reefs in Watamu Marine National Park followed by dolphin watching along the coast. Suitable for all swimming levels. Includes private boat, captain, fuel and snorkelling equipment.'],
    ['zuri-scuba-2-dives', 'Scuba Diving — Certified (2 dives)', 'From $95 per person (+ $15 park fee)', 'Half day', 'Discover deeper reef systems and marine biodiversity with professional instructors. Certification required. Includes boat, dive master and full equipment.'],
    ['zuri-discover-scuba', 'Discover Scuba Diving (beginner experience)', 'From $120 per person (+ $15 park fee)', 'Half day', 'Ideal for beginners with no prior experience: safety briefing, shallow-water training and one supervised open-water dive with instructor and equipment.'],
    ['zuri-open-water-course', 'Open Water Diving Course (certification)', 'From $450 per person', '3–4 days', 'Internationally recognised beginner certification combining theory, confined-water practice and open-water dives. Full course material, instructor, equipment and certification.'],
    ['zuri-deep-sea-fishing-half', 'Deep-Sea Fishing — Half Day', 'From $550 per boat', '4 hrs', 'Target marlin, sailfish, tuna and seasonal game fish offshore. Fully equipped fishing boat, crew, fuel and gear (1–5 guests).'],
    ['zuri-deep-sea-fishing-full', 'Deep-Sea Fishing — Full Day', 'From $900 per boat', '8 hrs', 'Extended offshore fishing expedition for experienced anglers. Fully equipped boat, crew, fuel and gear (1–5 guests).'],
    ['zuri-sunset-dhow-mida', 'Sunset Private Dhow Cruise — Mida Creek', 'From $45 per person', '3 hrs', 'Relaxing sunset cruise through the mangrove-lined waters of Mida Creek on a traditional dhow with captain and crew. Optional prosecco & canapé upgrade (2–12 guests).'],
    ['zuri-arabuko-birdwatching', 'Arabuko Sokoke Forest Birdwatching', 'From $50 per person (+ ~$10–15 entry)', '3–4 hrs', "Explore East Africa's largest coastal forest, home to rare bird species and indigenous wildlife, with transport and a professional guide (2–6 guests)."],
    ['zuri-bioken-snake-farm', 'Bio-Ken Snake Farm Visit', 'From $25 per person (+ ~$8–10 entry)', '1–2 hrs', "Educational visit to Kenya's leading reptile research centre focusing on conservation and venom research. Transport included."],
    ['zuri-gede-ruins', 'Gede Ruins Historical Tour', 'From $35 per person (+ ~$10–15 entry)', '2 hrs', 'Visit the 12th-century Swahili settlement hidden within forest surroundings, with transport and a local guide (2–6 guests).'],
    ['zuri-falconry', 'Falconry Experience', 'From $30 per person', '1–2 hrs', 'Interactive experience with trained birds of prey and conservation experts.'],
    ['zuri-turtle-watch', 'Turtle Watch & Conservation Visit', 'From $30 per person', '1–2 hrs', 'Learn about turtle rehabilitation and marine conservation efforts. Seasonal activity; guided conservation visit.'],
    ['zuri-swahili-cooking', 'Swahili Cooking Class with Chef', 'From $75 per person', '3–4 hrs', 'Hands-on culinary experience discovering traditional Swahili coastal flavours. Includes ingredients, chef guidance, the meal and recipes (2–8 guests).'],
    ['zuri-malindi-half-day', 'Malindi Tour — Half Day', 'From $60 per person', '4 hrs', 'Explore Malindi Old Town, the Vasco da Gama Pillar, local markets and coastal landmarks with a private vehicle and driver-guide (2–6 guests).'],
    ['zuri-kitesurf-lessons', 'Kitesurfing Lessons — Tribal Kite School', 'On request', '2–3 hrs per session', "Learn to kitesurf in Watamu's ideal wind conditions with certified instructors, equipment and safety gear. Beginner to advanced (1–4 guests)."],
    ['zuri-watersports', 'Water Sports — Tribal Kite School', 'On request', 'Varies', 'Kitesurfing, paddleboarding, wing foiling and other water-based activities; equipment and instructor depending on the activity.'],
];

/** Which optional tours columns exist on this DB. */
function tours_has_column(string $col): bool {
    try {
        return (bool) db_query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'tours' AND column_name = :c LIMIT 1",
            [':c' => $col]
        )->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

$hasPrice = tours_has_column('price');
$hasLoc   = tours_has_column('location');
$hasFav   = tours_has_column('is_guest_favourite');

echo "Seed Zuri/Watamu experiences → tours  (" . date('Y-m-d H:i:s') . ")\n";
echo $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (no changes — pass --apply)\n";
echo "optional columns: price=" . ($hasPrice ? 'yes' : 'NO') . " location=" . ($hasLoc ? 'yes' : 'NO') . " is_guest_favourite=" . ($hasFav ? 'yes' : 'NO') . "\n";
echo str_repeat('=', 92) . "\n";

if ($apply) db()->beginTransaction();
try {
    $n = 0;
    foreach ($EXPERIENCES as $i => $e) {
        [$slug, $name, $price, $duration, $desc] = $e;
        $cols = ['slug', 'name', 'category', 'duration', 'short_desc', 'sort_order', 'is_published'];
        $vals = [':slug' => $slug, ':name' => $name, ':category' => 'excursion', ':duration' => $duration, ':short_desc' => $desc, ':sort_order' => ($i + 1) * 10, ':is_published' => true];
        $updates = ['name = EXCLUDED.name', 'category = EXCLUDED.category', 'duration = EXCLUDED.duration', 'short_desc = EXCLUDED.short_desc', 'is_published = EXCLUDED.is_published'];
        if ($hasPrice) { $cols[] = 'price';    $vals[':price'] = $price;   $updates[] = 'price = EXCLUDED.price'; }
        if ($hasLoc)   { $cols[] = 'location'; $vals[':location'] = 'watamu'; $updates[] = 'location = EXCLUDED.location'; }

        $ph = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $sql = 'INSERT INTO tours (' . implode(', ', $cols) . ') VALUES (' . $ph . ')'
             . ' ON CONFLICT (slug) DO UPDATE SET ' . implode(', ', $updates) . ', updated_at = NOW()';

        echo '  • ' . $slug . '  — ' . ($hasPrice ? $price : '(no price column)') . ($apply ? '  [upsert]' : '  [would upsert]') . "\n";
        if ($apply) {
            // Bind params keyed by column name.
            $bound = [];
            foreach ($cols as $c) $bound[':' . $c] = $vals[':' . $c];
            db_query($sql, $bound);
        }
        $n++;
    }
    if ($apply) db()->commit();
    echo str_repeat('=', 92) . "\n";
    echo ($apply ? "APPLIED. " : "DRY-RUN. ") . "{$n} experience(s) " . ($apply ? 'seeded' : 'to seed') . " (category=excursion, location=watamu).\n";
    if (!$hasPrice) echo "NOTE: the tours.price column is missing — run db/migrations/add_tour_price.sql first to store prices.\n";
    echo $apply ? "list_activities will now surface them; check the Activities admin page too.\n" : "Re-run with --apply to write.\n";
} catch (\Throwable $ex) {
    if ($apply && db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, "\nFAILED (rolled back): " . $ex->getMessage() . "\n");
    exit(1);
}
