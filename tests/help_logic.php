<?php
declare(strict_types=1);
// Help & guides (includes/help-guides.php): the guide registry, who may read what,
// and the on-page panel's index. Pure — no database, no session.
// Run: php tests/help_logic.php
require_once __DIR__ . '/../includes/db.php';        // e()
require_once __DIR__ . '/../includes/admin-nav.php';
require_once __DIR__ . '/../includes/help-guides.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$guides = help_guides();
$none = array_fill_keys(['owner','manager','reception','frontdesk','concierge','messages','internal','tasks','timetable',
    'gate','mywork','assistant','aiSettings','aiGaps','bookings','reports','pos','posTill','inventory','invOrders','count',
    'accounting','acctDocs','acctIc','restaurant','clockOn','clockNav','mayaIlai'], false);
$nav = fn(array $flags) => admin_nav_resolve(admin_nav_definition(array_fill_keys($flags, true) + $none), '');
$navOwner = $nav(['owner','frontdesk','concierge','messages','internal','tasks','timetable','gate','assistant','aiSettings','aiGaps',
                  'bookings','reports','pos','posTill','inventory','invOrders','accounting','acctDocs','acctIc','restaurant','clockOn','clockNav','mayaIlai']);
$navReception = $nav(['reception','frontdesk','concierge','messages','internal','tasks','timetable','gate','mywork','assistant','bookings','restaurant','count']);
$navHk = $nav(['internal','timetable','mywork','count']);

// ── Registry shape ──
$slugs = array_column($guides, 'slug');
check('at least 20 guides', count($guides) >= 20);
check('slugs are unique', count($slugs) === count(array_unique($slugs)));
check('slugs are url-safe', !array_filter($slugs, fn($s) => !preg_match('/^[a-z0-9-]+$/', $s)));
$navPages = help_visible_pages($navOwner);
$bad = [];
foreach ($guides as $g) {
    if (!isset(HELP_AREAS[$g['area']])) $bad[] = $g['slug'] . ': area';
    if (trim($g['title']) === '' || trim($g['summary']) === '' || !$g['steps']) $bad[] = $g['slug'] . ': empty';
    if ((int)$g['minutes'] < 1) $bad[] = $g['slug'] . ': minutes';
    foreach ($g['pages'] as $p) {
        if (!is_file(__DIR__ . '/../admin/' . $p)) $bad[] = "{$g['slug']}: no page {$p}";
        elseif (!in_array($p, $navPages, true)) $bad[] = "{$g['slug']}: {$p} is not in the nav";
    }
    foreach ($g['steps'] as $s) if (trim((string)($s['text'] ?? '')) === '') $bad[] = $g['slug'] . ': empty step';
}
check('every guide is well formed, on real pages that are in the nav' . ($bad ? ' — ' . implode('; ', $bad) : ''), !$bad);

// Walkthrough targets written as [data-help="x"] must exist on a page. The registry
// itself mentions every name, so it is left out of the search.
$pagesOnly = '';
foreach (array_merge(glob(__DIR__ . '/../admin/*.php'), glob(__DIR__ . '/../includes/*.php')) as $f) {
    if (realpath($f) === realpath(__DIR__ . '/../includes/help-guides.php')) continue;
    $pagesOnly .= file_get_contents($f);
}
$missing = [];
foreach ($guides as $g) foreach ($g['steps'] as $s) foreach (['target', 'open'] as $k) {
    if (!empty($s[$k]) && preg_match_all('/data-help="([a-z0-9-]+)"/', $s[$k], $m)) {
        foreach ($m[1] as $name) if (!str_contains($pagesOnly, 'data-help="' . $name . '"')) $missing[] = $name;
    }
}
check('every data-help walkthrough target exists on a page' . ($missing ? ' — missing: ' . implode(', ', array_unique($missing)) : ''), !$missing);

// ── Who may read what: follows the resolved nav ──
$own = array_column(help_visible_guides($guides, help_visible_pages($navOwner)), 'slug');
$rec = array_column(help_visible_guides($guides, help_visible_pages($navReception)), 'slug');
$hk  = array_column(help_visible_guides($guides, help_visible_pages($navHk)), 'slug');
check('owner reads every guide', count($own) === count($guides));
check('reception reads convert-to-hold and reply-enquiry', in_array('convert-to-hold', $rec, true) && in_array('reply-enquiry', $rec, true));
check('reception does not read owner-only guides (website holds, extras setup, emails)', !array_intersect(['website-holds', 'guest-extras-setup', 'emails'], $rec));
check('housekeeping reads getting-around and stock-count, nothing about bookings', in_array('getting-around', $hk, true) && in_array('stock-count', $hk, true)
    && !array_intersect(['convert-to-hold', 'confirm-booking', 'set-rates'], $hk));
check('guides come in library order', array_values(array_unique(array_map(fn($s) => help_guides()[array_search($s, $slugs, true)]['area'], $own)))
    === array_values(array_intersect(array_keys(HELP_AREAS), array_unique(array_column($guides, 'area')))));
check('guides for a page', array_column(help_guides_for_page($guides, 'submission-view.php'), 'slug') !== []
    && in_array('convert-to-hold', array_column(help_guides_for_page($guides, 'submission-view.php'), 'slug'), true)
    && help_guides_for_page($guides, 'nope.php') === []);

// ── Rendering ──
check('help_rich escapes and bolds', help_rich('<b>x</b> **Create Hold**') === '&lt;b&gt;x&lt;/b&gt; <strong>Create Hold</strong>');
$p = help_guide_payload($guides[0]);
check('payload carries slug, pages and rendered steps', $p['s'] === $guides[0]['slug'] && $p['p'] === $guides[0]['pages'] && str_contains($p['st'][0]['h'], '<strong>'));
$si = help_search_items(help_visible_guides($guides, help_visible_pages($navReception)));
check('Ctrl+K items: one per readable guide, under Help, linking to the library', count($si) === count($rec)
    && !array_filter($si, fn($i) => $i['g'] !== 'Help' || !str_starts_with($i['h'], '/admin/help.php?g=')));
$html = help_drawer_html($guides);
check('drawer: the index JSON cannot break out of its script tag', substr_count($html, '</script>') === 1 && str_ends_with($html, '</script>'));
check('drawer: floating Help button + panel', str_contains($html, 'class="helpfab" data-help-open') && str_contains($html, 'id="helpDrawer" hidden'));
$sh = admin_nav_search_html($navReception, $si);
check('search index includes the guides', str_contains($sh, '"g":"Help"') && str_contains($sh, '"help"'));

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
