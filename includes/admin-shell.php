<?php
declare(strict_types=1);
/**
 * Admin-wide no-flicker shell (redesign #18).
 *
 * A single app-shell that swaps only `.admin-content` on navigation, so the
 * sidebar/topbar never repaint. This is the *layout-level* half: `_layout.php`
 * and `_layout_end.php` call these helpers so EVERY admin page inherits the
 * shell — no per-page `?ajax` branch needed.
 *
 * Swap-ownership boundary (one owner per region):
 *   • `?shell=1`  → the shell owns `.admin-content` (this file). Cross-page nav.
 *   • `?ajax=1`   → admin-table.js owns the inner `.dt-body`; the booking
 *                   workspace owns `[data-ws-panel]`. Those are unchanged.
 * Because the two use different params AND different triggers, they never fight.
 *
 * Progressive enhancement: with no JS every link still does a full navigation —
 * the server renders the identical page. `?shell=1` is purely additive.
 *
 * Depends on includes/db.php (e()).
 */

/** True when the client asked for just the content fragment (shell navigation). */
function admin_shell_requested(): bool {
    return ($_GET['shell'] ?? '') !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
}

/**
 * Emit the captured `.admin-content` inner as a shell fragment and exit.
 * The wrapper carries the metadata the client needs to update chrome without a
 * repaint: the active-menu key and the full document title.
 *
 * @param string $content Buffered page content (what normally sits in .admin-content).
 * @param string $menu    $activeMenu for the page (drives the sidebar highlight).
 * @param string $title   Full <title> text for the page.
 */
function admin_shell_emit(string $content, string $menu, string $title): void {
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<div data-shell-frag data-menu="' . e($menu) . '" data-title="' . e($title) . '">' . "\n";
    echo $content;
    echo "\n" . '</div>';
    exit;
}
