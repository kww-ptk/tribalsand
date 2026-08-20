<?php
/**
 * Legacy Zuri menu URL — now served from the DB-driven menu system.
 * 301 → /menu.php?m=zuri when the menu exists; 404 otherwise (never a blank page).
 * (Old links, QR codes and printed cards pointing here keep working.)
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/menu.php';

if (fetch_menu_by_slug('zuri')) {
    header('Location: /menu.php?m=zuri', true, 301);
    exit;
}
http_response_code(404);
require __DIR__ . '/404.php';
