<?php
/**
 * Legacy Maya Kobe breakfast menu URL — now served from the DB-driven menu system.
 * 301 → /menu.php?m=maya-kobe-breakfast when the menu exists; 404 otherwise.
 * (Old links, QR codes and printed cards pointing here keep working.)
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/menu.php';

if (fetch_menu_by_slug('maya-kobe-breakfast')) {
    header('Location: /menu.php?m=maya-kobe-breakfast', true, 301);
    exit;
}
http_response_code(404);
require __DIR__ . '/404.php';
