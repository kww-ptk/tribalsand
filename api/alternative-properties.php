<?php
declare(strict_types=1);
/**
 * Other properties with space — the listing page's fallback when its own
 * property has nothing for the dates + party. Public, read-only, JSON.
 *   GET ?venue=<current slug>&check_in&check_out&adults&children
 *   → { ok, check_in, check_out, nights, guests, adults, children,
 *       options:[{slug,name,location,type,hero,from,currency,url}], more, search_url }
 * Uses ts_search_availability() — the /search function — so the list never
 * disagrees with /search. Guards follow api/property-availability.php, plus a
 * past-date and 60-night limit because this checks every property.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/listing-alternatives.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

[$ci, $co, $err] = ts_alternatives_window((string)($_GET['check_in'] ?? ''), (string)($_GET['check_out'] ?? ''), date('Y-m-d'));
if ($err !== null) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => $err])); }

$slug     = substr(trim((string)($_GET['venue'] ?? '')), 0, 80);
$adults   = max(0, min(30, (int)($_GET['adults']   ?? 0)));
$children = max(0, min(20, (int)($_GET['children'] ?? 0)));
$guests   = max(1, $adults + $children);
$nights   = (int) round((strtotime($co . ' 12:00') - strtotime($ci . ' 12:00')) / 86400);

try {
    // Rank "cheapest" in one currency (properties price in KES or USD).
    $alt = ts_alternative_properties(ts_search_availability($ci, $co, $guests), $slug, 3,
        fn(float $a, string $c): float => (float) convert_price($a, $c, 'USD')['amount']);
} catch (Throwable $e) {
    error_log('[alternative-properties] ' . $e->getMessage());
    http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not check other properties right now.']));
}

$q = http_build_query(['checkin' => $ci, 'checkout' => $co, 'adults' => max(1, $adults), 'children' => $children]);
foreach ($alt['options'] as &$o) { $o['url'] = '/' . rawurlencode($o['slug']) . '?' . $q; }
unset($o);

exit(json_encode([
    'ok' => true, 'check_in' => $ci, 'check_out' => $co, 'nights' => $nights,
    'guests' => $guests, 'adults' => $adults, 'children' => $children,
    'options' => $alt['options'], 'more' => $alt['more'], 'search_url' => '/search?' . $q,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
