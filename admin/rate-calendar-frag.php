<?php
declare(strict_types=1);
/**
 * HTML fragment: one room's rate calendar for a given month window.
 *
 * Backs the Prev/Next buttons in includes/rate-calendar.php, which swap this in
 * rather than reloading the whole admin page. The <a> links stay real URLs, so
 * with JavaScript off (or if this endpoint fails) the browser still navigates
 * and the calendar still works.
 *
 * Read-only, and scoped exactly like admin/rates.php: require_login() plus an
 * admin_venue_ids() check on the room. The room id arrives from the client, so
 * it is validated against the account's own venues — never trusted.
 *
 * Price and currency are read from the room here rather than accepted from the
 * client, so a crafted request cannot make the calendar render invented prices.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/rates.php';
require_login();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$roomId = isset($_GET['room']) ? (int)$_GET['room'] : 0;
$room   = $roomId
    ? db_query('SELECT id, name, venue_id, price_amount, price_currency FROM rooms WHERE id = :id',
        [':id' => $roomId])->fetch()
    : false;

$scope = admin_venue_ids();                     // null = owner (every venue)
if (!$room || ($scope !== null && !in_array((int)$room['venue_id'], array_map('intval', $scope), true))) {
    http_response_code(404);
    exit('Not found');
}

$rc_room_id       = (int)$room['id'];
$rc_default_price = (float)$room['price_amount'];
$rc_currency      = (string)$room['price_currency'];
$rc_months        = max(1, min(12, (int)($_GET['months'] ?? 3)));   // capped: this is a public-ish surface
$rc_compact       = ($_GET['compact'] ?? '1') !== '0';
$rc_month         = rates_window_ymd(((string)($_GET['month'] ?? '')) . '-01') !== null
    ? substr((string)$_GET['month'], 0, 7)
    : date('Y-m');
$rc_base_url      = (string)($_GET['base'] ?? '');
if ($rc_base_url !== '' && !preg_match('#^/admin/[A-Za-z0-9._\-]+\.php(\?[^\s"\'<>]*)?$#', $rc_base_url)) {
    $rc_base_url = '';                          // only same-site admin URLs may be echoed back
}

// The host page already carries the calendar's CSS and JS; a fragment must not
// ship a second copy of either.
$GLOBALS['__rc_css_done'] = true;
$GLOBALS['__rc_js_done']  = true;

include __DIR__ . '/../includes/rate-calendar.php';
