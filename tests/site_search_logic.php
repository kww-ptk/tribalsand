<?php
declare(strict_types=1);
/**
 * Site search — pure ranking/normalising rules (includes/site-search.php).
 * Run: php tests/site_search_logic.php   (no DB needed; the live index is
 * checked only when a DB is reachable).
 */
require_once __DIR__ . '/../includes/site-search.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }

// normalise + terms
ok(site_search_normalize('Somewhere Café!') === 'somewhere cafe', 'accents folded, punctuation dropped');
ok(site_search_normalize('Tea & Cake') === 'tea and cake', '& reads as and');
ok(site_search_terms('the villa in Kilifi') === ['villa', 'kilifi'], 'filler words dropped');
ok(site_search_terms('the') === ['the'], 'a filler-only query still searches');
ok(site_search_word_hit('maya kobe', 'kob'), 'prefix of a word matches');
ok(!site_search_word_hit('maya kobe', 'obe'), 'middle of a word does not match');

$items = [
    ['title' => 'Zuri', 'url' => '/zuri', 'type' => 'stay', 'sub' => 'Watamu', 'keywords' => 'hotel'],
    ['title' => 'Zuri Restaurant', 'url' => '/zuri-restaurant', 'type' => 'dining', 'sub' => 'Watamu', 'keywords' => 'restaurant dinner'],
    ['title' => 'Watamu', 'url' => '/watamu', 'type' => 'page', 'sub' => 'Area guide', 'keywords' => 'beach'],
    ['title' => 'My Amani', 'url' => '/my-amani', 'type' => 'stay', 'sub' => 'Vipingo', 'keywords' => 'villa kilifi', 'boost' => 10],
    ['title' => 'Terms & conditions', 'url' => '/tc', 'type' => 'page', 'sub' => '', 'keywords' => 'cancellation refund'],
];
$r = site_search_rank($items, 'zuri');
ok(count($r) === 2 && $r[0]['url'] === '/zuri', 'exact title first');
$r = site_search_rank($items, 'dinner watamu');
ok(count($r) === 1 && $r[0]['url'] === '/zuri-restaurant', 'every word must match (title/sub/keywords)');
$r = site_search_rank($items, 'watamu');
ok($r[0]['url'] === '/watamu', 'title hit beats subtitle hit');
$r = site_search_rank($items, 'cancel');
ok(count($r) === 1 && $r[0]['url'] === '/tc', 'keywords are searched by prefix');
ok(site_search_rank($items, 'xyzzy') === [], 'no match → empty');
ok(site_search_rank($items, '   ') === [], 'blank query → empty');
ok(!isset($r[0]['keywords']) && !isset($r[0]['boost']), 'internal fields stripped');
ok(count(site_search_rank($items, 'a', 2)) <= 2, 'limit respected');

$g = site_search_group(site_search_rank($items, 'zuri'));
ok(count($g) === 2 && $g[0]['type'] === 'stay' && $g[1]['label'] === 'Restaurants & menus', 'groups in display order');

ok(site_search_clean_query("  sea \n view  ") === 'sea view', 'query cleaned');
ok(mb_strlen(site_search_clean_query(str_repeat('a', 200))) === SITE_SEARCH_MAX_Q, 'query capped');
ok(site_search('a') === [], 'one letter never searches');

// Every static page points at a page that exists.
foreach (site_search_pages() as $p) {
    $path = ltrim(explode('#', explode('?', $p['url'])[0])[0], '/');
    $file = $path === '' ? 'index' : $path;
    ok(is_file(__DIR__ . '/../' . $file . '.php'), "page exists for {$p['url']}");
    ok(isset(SITE_SEARCH_TYPES[$p['type']]), "known type for {$p['url']}");
}

foreach (site_search_fallback_stays() as $p) {
    ok(is_file(__DIR__ . '/../' . ltrim($p['url'], '/') . '.php'), "fallback property page exists for {$p['url']}");
}

echo "site_search_logic: {$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
