<?php
declare(strict_types=1);
/**
 * Property availability — single-venue configurations (Phase 3), JSON.
 *   GET ?venue=<slug>&check_in&check_out&adults&children
 *     → { ok, venue:{slug,name}, check_in, check_out, nights, guests,
 *         singles[], entire[], combos[], max_capacity }
 *
 * Powers the availability-first property-page sidebar. Mirrors the guards of
 * api/check-availability.php: validate the read window with rates_window_ymd()
 * (422 on bad), Nairobi-local, CORS. Public → published venues only. Every price
 * comes from ts_property_configurations() → room_stay_quote() (the ONE pricing
 * path); this endpoint adds no nightly loop of its own.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$slug = trim($_GET['venue'] ?? '');
if ($slug === '') {
    http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'venue parameter required']));
}

$venue = db_query(
    'SELECT id, name, slug FROM venues WHERE slug = :s AND is_published = TRUE',
    [':s' => $slug]
)->fetch();
if (!$venue) {
    http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Property not found']));
}

$ci = rates_window_ymd(trim($_GET['check_in']  ?? '')) ?? '';
$co = rates_window_ymd(trim($_GET['check_out'] ?? '')) ?? '';
if ($ci === '' || $co === '') {
    http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Dates must be valid and formatted YYYY-MM-DD']));
}
if ($ci >= $co) {
    http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Check-out must be after check-in']));
}

$adults   = max(0, (int)($_GET['adults']   ?? 0));
$children = max(0, (int)($_GET['children'] ?? 0));
$guests   = max(1, $adults + $children);

// $always_combos = true: on the property page we also suggest multi-room
// combinations when a single room fits (e.g. two doubles for a party of 4),
// since a combo can be cheaper than the one larger room.
$cfg = ts_property_configurations($venue, $ci, $co, $guests, null, true);

$nights = (int)((strtotime($co) - strtotime($ci)) / 86400);

exit(json_encode([
    'ok'           => true,
    'venue'        => ['slug' => $venue['slug'], 'name' => $venue['name']],
    'check_in'     => $ci,
    'check_out'    => $co,
    'nights'       => $nights,
    'guests'       => $guests,
    'adults'       => $adults,
    'children'     => $children,
    'singles'      => $cfg['singles'],
    'entire'       => $cfg['entire'],
    'combos'       => $cfg['combos'],
    'max_capacity' => $cfg['max_capacity'],
], JSON_UNESCAPED_SLASHES));
