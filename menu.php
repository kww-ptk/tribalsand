<?php
/**
 * Public restaurant menu — DB-driven, per property.
 *   /menu.php?m=<slug>   e.g. /menu.php?m=zuri  ·  /menu.php?m=maya-kobe-breakfast
 *
 * Standalone "digital menu" page (noindex) from the `menus` / `menu_categories` /
 * `menu_items` tables, managed in /admin/menus.php. This file prepares the data;
 * the page itself is includes/menu-page.php (the "Cards" design: dark header,
 * sticky Food | Drinks + course chips, each dish a card with its photo).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/menu.php';
require_once __DIR__ . '/includes/reservations.php';   // "Reserve a Table" CTA (pre-migration-safe)

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['m'] ?? ''));
$menu = $slug !== '' ? fetch_menu_by_slug($slug) : null;

if (!$menu) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$cats     = fetch_menu_categories((int)$menu['id'], false);   // public: visible cats, available items
$curLabel = $menu['currency_label'] ?: 'Kes';

// "Reserve a Table" CTA — only when this menu is tied to a published venue and
// reservations are live. Resolves the venue slug for the deep link.
$reserveSlug = '';
if (!empty($menu['venue_id']) && reservations_supported()) {
    $rv = db_query(
        'SELECT slug FROM venues WHERE id = :id AND is_published = TRUE',
        [':id' => (int)$menu['venue_id']]
    )->fetchColumn();
    if ($rv) $reserveSlug = (string)$rv;
}

$food   = array_values(array_filter($cats, fn($c) => $c['section'] === 'food'));
$drinks = array_values(array_filter($cats, fn($c) => $c['section'] === 'drinks'));
$hasFood = (bool)$food; $hasDrinks = (bool)$drinks;
$showTabs = $hasFood && $hasDrinks;

/**
 * A category renders as a compact two-column list (sides, soft drinks) when no
 * dish in it has a description, a dietary mark or a photo.
 */
function menu_cat_is_simple(array $cat): bool {
    foreach ($cat['items'] as $it) {
        if (trim((string)$it['description']) !== '' || trim((string)($it['image_key'] ?? '')) !== '') return false;
        foreach (menu_badge_defs() as $col => $_) if (!empty($it[$col]) && $it[$col] !== 'f') return false;
    }
    return true;
}

require __DIR__ . '/includes/menu-page.php';
