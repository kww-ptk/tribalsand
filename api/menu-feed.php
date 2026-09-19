<?php
declare(strict_types=1);
/**
 * Public restaurant-menu sync feed — read-only JSON, CORS-enabled.
 *
 *   GET /api/menu-feed.php                → index of published menus
 *   GET /api/menu-feed.php?slug=zuri      → one published menu (categories+items)
 *
 * Purpose: an external site (e.g. the standalone Zuri restaurant website) can
 * fetch this and render the SAME menu the team edits in Admin → Menus. There is
 * ONE source of truth (the menus/menu_categories/menu_items tables), so an edit
 * in the admin is live on the external site on its next fetch — no duplicate to
 * keep in step, no push.
 *
 * Auth + ETag + CORS come from includes/restaurant-api.php; the key is OPTIONAL
 * here because a menu is public information (the same data /menu.php serves).
 * See docs/restaurant-api.md for the integration guide.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant-api.php';
require_once __DIR__ . '/../includes/menu.php';
require_once __DIR__ . '/../includes/reservations.php';   // reserve deep link + slots (pre-migration-safe)

restaurant_api_begin(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    restaurant_api_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

restaurant_api_authenticate(false);

if (!menus_supported()) {
    restaurant_api_json(['ok' => false, 'error' => 'Menus are not available'], 503);
}

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['slug'] ?? ($_GET['m'] ?? ''))));

if ($slug === '') {
    restaurant_api_cached_json(['ok' => true, 'menus' => menu_feed_index()]);
}

$menu = menu_feed_payload($slug);
if ($menu === null) {
    restaurant_api_json(['ok' => false, 'error' => 'Menu not found or not published'], 404);
}

restaurant_api_cached_json(['ok' => true, 'menu' => $menu]);
