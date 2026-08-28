<?php
/**
 * Seed the admin-editable mega menu with the CURRENT hardcoded nav, 1:1, so the
 * site looks identical the moment it starts rendering from the DB.
 *
 * Idempotent: truncates the nav tables (cascade) and re-inserts. Safe to re-run.
 *   Run: php db/seeds/seed_nav_menu.php
 *
 * Restaurants is seeded as a locked placeholder (auto_source='restaurants') with
 * no groups — the renderer fills it from the live published-menus logic.
 *
 * NOTE: like the other seeds, the local .env points at a LOCAL database, not
 * production. Apply the migration + this seed to production separately.
 */
require_once __DIR__ . '/../../includes/db.php';

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

try {
    db_query("SELECT 1 FROM nav_items LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "nav_items table missing — run db/migrations/add_nav_menu.sql first.\n");
    exit(1);
}

/**
 * The whole tree as data. Each item: label, layout, optional auto, and groups.
 * Each group: optional label + rows. Each row is a link with any of:
 *   label, href, sub, img, tag(open|soon), role(row|footer_link|cta_button),
 *   note (cta), blank(true → target=_blank).
 */
$tree = [
    ['label' => 'Accommodations', 'layout' => 'wide3', 'groups' => [
        ['label' => 'Beachfront Boutique Hotels', 'rows' => [
            ['label' => 'Zuri',      'href' => 'zuri.php',       'sub' => 'Watamu · 6 Suites', 'img' => 'images/zuri/Aerial/zuri-3.webp'],
            ['label' => 'Maya Kobe', 'href' => 'maya-kobe.php',  'sub' => 'Kilifi · 5 Suites', 'img' => 'images/Maya-Kobe-1-hero.webp'],
        ]],
        ['label' => 'Beachfront Private Villas', 'rows' => [
            ['label' => 'My Amani',    'href' => 'my-amani.php',    'sub' => 'Vipingo · 5 Rooms', 'img' => 'images/my-amani/Aerial/myamani-11.webp'],
            ['label' => 'Enkare Bofa',  'href' => 'enkare-bofa.php', 'sub' => 'Kilifi · 5 Rooms',  'img' => 'images/enkare-bofa/Outdoors/IMG-20251117-WA0032.jpg'],
            ['label' => 'Sandbox',     'href' => 'sandbox.php',     'sub' => 'Kilifi · 4 Rooms',  'img' => 'images/Sandbox/outdoors/IMG-20251117-WA0091.jpg'],
        ]],
        ['label' => 'Tribal Dunes · Kilifi', 'rows' => [
            ['label' => 'Maya Ilai', 'href' => 'maya_ilai.php', 'sub' => 'Eco Compound',   'img' => 'images/maya_illai/Best1.jpg'],
            ['label' => 'Off Duty',  'href' => 'off-duty.php',  'sub' => 'Coworking Hotel', 'img' => 'images/maya_illai/Studios/Studio1.jpeg'],
            ['label' => 'Enquire Now →', 'href' => 'enquire.php', 'role' => 'cta_button', 'note' => 'Not sure yet?'],
        ]],
    ]],
    ['label' => 'Experiences', 'layout' => 'simple', 'groups' => [
        ['rows' => [
            ['label' => 'Activities',  'href' => 'activities.php'],
            ['label' => 'Kite School', 'href' => 'http://tribalkiteschool.com/', 'blank' => true],
        ]],
    ]],
    ['label' => 'Restaurants', 'layout' => 'simple', 'auto' => 'restaurants', 'groups' => []],
    ['label' => 'Tribal Dunes', 'layout' => 'wide2', 'groups' => [
        ['label' => 'Kilifi · Beachfront', 'rows' => [
            ['label' => 'Maya Kobe', 'href' => 'maya-kobe.php', 'sub' => 'Boutique Hotel · Kilifi', 'img' => 'images/Maya-Kobe-1-hero.webp'],
            ['label' => 'Maya Ilai', 'href' => 'maya_ilai.php', 'sub' => 'Eco Compound · Kilifi',   'img' => 'images/maya_illai/Best1.jpg'],
            ['label' => 'Off Duty',  'href' => 'off-duty.php',  'sub' => 'Coworking Hotel · Kilifi', 'img' => 'images/maya_illai/Studios/Studio1.jpeg'],
            ['label' => 'Read the Tribal Dunes story →', 'href' => 'tribal-dunes.php', 'role' => 'footer_link'],
        ]],
        ['label' => 'Dining & Lifestyle', 'rows' => [
            ['label' => 'Tribal Table',   'href' => 'tribal-table.php',  'sub' => 'Restaurant & Bar · Kilifi', 'img' => 'images/maya-kobe/Maya Kobe - Day Outdoor, Pool, Beach/Maya Kobe Best4.jpg', 'tag' => 'open'],
            ['label' => 'Somewhere Café', 'href' => 'somewhere-cafe.php', 'sub' => 'Beachfront Café · Kilifi',  'img' => 'images/maya_illai/best6.jpg', 'tag' => 'soon'],
            ['label' => 'Kite & Watersport School', 'href' => '#', 'sub' => 'Ocean Sports · Kilifi', 'img' => 'images/34t.jpg', 'tag' => 'soon'],
            ['label' => 'View Interactive Site Map →', 'href' => 'interactive-site-map.php', 'role' => 'footer_link'],
        ]],
    ]],
    ['label' => 'Events', 'layout' => 'simple', 'groups' => [
        ['rows' => [
            ['label' => 'Weddings', 'href' => 'events.php'],
            ['label' => 'Retreats', 'href' => 'retreats.php'],
        ]],
    ]],
    ['label' => 'Gallery', 'layout' => 'simple', 'groups' => [
        ['rows' => [
            ['label' => 'My Amani',    'href' => 'gallery.php?venue=my-amani'],
            ['label' => 'Maya Kobe',   'href' => 'gallery.php?venue=maya-kobe'],
            ['label' => 'Maya Ilai',   'href' => 'gallery.php?venue=maya_ilai'],
            ['label' => 'Zuri',        'href' => 'gallery.php?venue=zuri'],
            ['label' => 'Enkare Bofa', 'href' => 'gallery.php?venue=enkare-bofa'],
            ['label' => 'Sandbox',     'href' => 'gallery.php?venue=sandbox'],
        ]],
        ['rows' => [
            ['label' => 'Events Gallery', 'href' => 'events-gallery.php'],
        ]],
    ]],
    ['label' => 'About', 'layout' => 'simple', 'groups' => [
        ['rows' => [
            ['label' => 'Our Story',      'href' => 'tribalsandstory.php'],
            ['label' => 'Sustainability', 'href' => 'sustainability.php'],
            ['label' => 'Journal',        'href' => 'blog.php'],
        ]],
        ['label' => 'Destinations', 'rows' => [
            ['label' => 'Kilifi Guide',       'href' => 'kilifi.php'],
            ['label' => 'Watamu Guide',       'href' => 'watamu.php'],
            ['label' => 'Kenya Coast Guide',  'href' => 'kenya-coast-guide.php'],
            ['label' => 'Honeymoon in Kenya', 'href' => 'kenya-honeymoon.php'],
        ]],
        ['rows' => [
            ['label' => 'Press · Cometa', 'href' => 'wp-content/uploads/2024/12/Watamu-Kenya-COMETA-2025.pdf', 'blank' => true],
            ['label' => 'For Agents',     'href' => 'for-agents.php'],
            ['label' => 'Contact Us',     'href' => 'contact.php'],
        ]],
    ]],
];

db()->beginTransaction();
try {
    // Wipe (cascade clears groups + links).
    db_query("DELETE FROM nav_items");

    $itemSort = 0;
    foreach ($tree as $item) {
        $iid = (int) db_query(
            "INSERT INTO nav_items (label, layout, auto_source, sort_order, is_published)
             VALUES (:l, :ly, :a, :s, TRUE) RETURNING id",
            [':l' => $item['label'], ':ly' => $item['layout'], ':a' => $item['auto'] ?? null, ':s' => $itemSort++]
        )->fetchColumn();

        $gSort = 0;
        foreach ($item['groups'] as $g) {
            $gid = (int) db_query(
                "INSERT INTO nav_groups (nav_item_id, label, sort_order) VALUES (:i, :l, :s) RETURNING id",
                [':i' => $iid, ':l' => $g['label'] ?? null, ':s' => $gSort++]
            )->fetchColumn();

            $lSort = 0;
            foreach ($g['rows'] as $r) {
                db_query(
                    "INSERT INTO nav_links (nav_group_id, label, href, sublabel, image_key, tag, role, cta_note, target_blank, sort_order, is_published)
                     VALUES (:g, :label, :href, :sub, :img, :tag, :role, :note, :blank, :s, TRUE)",
                    [
                        ':g'     => $gid,
                        ':label' => $r['label'],
                        ':href'  => $r['href'] ?? '#',
                        ':sub'   => $r['sub'] ?? null,
                        ':img'   => $r['img'] ?? null,
                        ':tag'   => $r['tag'] ?? '',
                        ':role'  => $r['role'] ?? 'row',
                        ':note'  => $r['note'] ?? null,
                        ':blank' => !empty($r['blank']) ? 'TRUE' : 'FALSE',
                        ':s'     => $lSort++,
                    ]
                );
            }
        }
    }
    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
    exit(1);
}

$items  = (int) db_query("SELECT COUNT(*) FROM nav_items")->fetchColumn();
$groups = (int) db_query("SELECT COUNT(*) FROM nav_groups")->fetchColumn();
$links  = (int) db_query("SELECT COUNT(*) FROM nav_links")->fetchColumn();
echo "Seeded nav: {$items} items, {$groups} groups, {$links} links.\n";
