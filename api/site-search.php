<?php
declare(strict_types=1);
/**
 * GET /api/site-search.php?q=… — "find anything" on the website (JSON).
 * Read-only, public; powers the header search overlay and the homepage bar
 * (js/site-search.js). Logic: includes/site-search.php.
 *
 * → {"q":"…","results":[{"title","url","type","sub","score"}],"groups":[{"type","label","items"}]}
 */
require_once __DIR__ . '/../includes/site-search.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Robots-Tag: noindex');

$q = site_search_clean_query((string)($_GET['q'] ?? ''));
$limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));

try {
    $results = site_search($q, $limit);
} catch (Throwable $e) {
    error_log('site-search: ' . $e->getMessage());
    $results = [];
}

echo json_encode([
    'q'       => $q,
    'results' => $results,
    'groups'  => site_search_group($results),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
