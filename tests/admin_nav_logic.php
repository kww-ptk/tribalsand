<?php
declare(strict_types=1);
// Admin navigation (includes/admin-nav.php): one definition for the sidebar and
// the tab strips. Pure — no database, no session.
// Run: php tests/admin_nav_logic.php
require_once __DIR__ . '/../includes/db.php';        // e()
require_once __DIR__ . '/../includes/admin-nav.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// Flag presets mirroring admin/_layout.php for each kind of account, with every
// optional module (POS, inventory, accounting, AI key, clock kiosk) switched on.
$none = array_fill_keys(['owner','manager','reception','frontdesk','concierge','messages','internal','tasks','timetable',
    'gate','mywork','assistant','aiSettings','aiGaps','bookings','reports','pos','posTill','inventory','invOrders','count',
    'accounting','acctDocs','acctIc','restaurant','clockOn','clockNav','mayaIlai'], false);
$roles = [
    'owner' => ['owner','frontdesk','concierge','messages','internal','tasks','timetable','gate','assistant','aiSettings','aiGaps',
                'bookings','reports','pos','posTill','inventory','invOrders','accounting','acctDocs','acctIc','restaurant','clockOn','clockNav','mayaIlai'],
    'manager' => ['manager','frontdesk','concierge','messages','internal','tasks','timetable','gate','assistant','aiGaps',
                  'reports','pos','posTill','inventory','invOrders','acctDocs','acctIc','restaurant','clockOn','clockNav'],
    'reception' => ['reception','frontdesk','concierge','messages','internal','tasks','timetable','gate','mywork','assistant','bookings','restaurant','count'],
    'frontdesk' => ['frontdesk','concierge','messages','internal','timetable','assistant','count'],
    'housekeeping' => ['internal','timetable','mywork','count'],
    'security' => ['internal','timetable','gate','count'],
    'shop' => ['internal','timetable','posTill','count'],
];
$nav = function (string $role, string $script = '', array $badges = []) use ($none, $roles): array {
    return admin_nav_resolve(admin_nav_definition(array_fill_keys($roles[$role], true) + $none, $badges), $script);
};
$labels = function (array $n): array {
    $out = [];
    foreach ($n['groups'] as $g) foreach ($g['items'] as $it) $out[] = ($g['title'] !== '' ? $g['title'] . ' › ' : '') . $it['label'];
    return $out;
};

// ── Every page has exactly one home ─────────────────────────────────────────
$owned = [];
foreach (admin_nav_definition(array_fill_keys(array_keys($none), true)) as $g)
    foreach ($g['items'] as $it) foreach ($it['tabs'] as $t) foreach ($t['pages'] as $p) $owned[$p][] = $it['label'] . ' › ' . $t['label'];
// 'count' and 'inventory' never hold together (see _layout.php), so with every flag
// forced on the count screen is claimed once by Today and not by Inventory › Counts.
$twice = array_filter($owned, fn($o) => count($o) > 1);
check('no page is claimed by two tabs' . ($twice ? ' — ' . implode(', ', array_keys($twice)) : ''), !$twice);

$missing = array_filter(array_keys($owned), fn($p) => !is_file(__DIR__ . '/../admin/' . $p));
check('every listed page exists' . ($missing ? ' — ' . implode(', ', $missing) : ''), !$missing);

// Pages that draw the admin layout must belong to a tab, or they open with no
// sidebar link lit and no way back to their area.
$noHome = [];
foreach (glob(__DIR__ . '/../admin/*.php') ?: [] as $f) {
    $name = basename($f);
    if ($name[0] === '_' || !str_contains((string)file_get_contents($f), '_layout.php')) continue;
    if (!isset($owned[$name])) $noHome[] = $name;
}
check('every page using the admin layout belongs to a tab' . ($noHome ? ' — add to includes/admin-nav.php: ' . implode(', ', $noHome) : ''), !$noHome);

$icons = require __DIR__ . '/../includes/admin-nav-icons.php';
$noIcon = [];
foreach (admin_nav_definition($none) as $g) foreach ($g['items'] as $it) if (!isset($icons[$it['icon']])) $noIcon[] = $it['icon'];
check('every item has an icon' . ($noIcon ? ' — ' . implode(', ', $noIcon) : ''), !$noIcon);

$noGroupIcon = [];
foreach (admin_nav_definition($none) as $g) if (($g['title'] ?? '') !== '' && !isset($icons[$g['icon'] ?? ''])) $noGroupIcon[] = $g['key'];
check('every titled group has an icon' . ($noGroupIcon ? ' — ' . implode(', ', $noGroupIcon) : ''), !$noGroupIcon);

// ── What each role sees ─────────────────────────────────────────────────────
$owner = $labels($nav('owner'));
check('owner: Dashboard is the first link, outside any group', $owner[0] === 'Dashboard');
foreach (array_keys($roles) as $r) check("{$r}: Dashboard is the landing link", $labels($nav($r))[0] === 'Dashboard' && ($nav($r, 'dashboard.php')['active']['label'] ?? '') === 'Dashboard');
check('owner: about 36 links (was 63)', count($owner) >= 33 && count($owner) <= 38);
check('owner: Home + nine titled groups', count($nav('owner')['groups']) === 10);
foreach (['Today › Messages', 'Bookings › Calendar', 'Bookings › Enquiries', 'Team › Tasks', 'Team › Attendance',
          'Point of Sale › Catalogue & stock', 'Point of Sale › Setup', 'Inventory › Inventory', 'Finance › Accounting',
          'Website › Properties & rooms', 'Website › Marketing', 'Settings › AI', 'Settings › Emails'] as $want) {
    check("owner sees {$want}", in_array($want, $owner, true));
}

$mgr = $labels($nav('manager'));
check('manager: Dashboard first; no Website, no Staff', $mgr[0] === 'Dashboard' && !array_filter($mgr, fn($l) => str_starts_with($l, 'Website') || $l === 'Team › Staff'));
check('manager: Bookings group holds only what a manager may open', $nav('manager')['groups'][array_search('bookings', array_column($nav('manager')['groups'], 'key'))]['items'][0]['label'] === 'Calendar'
    && !in_array('Bookings › Bookings', $mgr, true) && !in_array('Bookings › Enquiries', $mgr, true));
$mgrCal = array_column($nav('manager', 'calendar-highlights.php')['active']['tabs'], 'label');
check('manager: Calendar shows Highlights + Import, not the owner/reception tabs', $mgrCal === ['Highlights', 'Import']);
check('manager: a lone tab becomes a plain link with its full name', in_array('Settings › Email log', $mgr, true) && in_array('Settings › AI gaps', $mgr, true));

$rec = $labels($nav('reception'));
check('reception: Bookings + Calendar + Enquiries, no Finance/Settings', in_array('Bookings › Bookings', $rec, true) && in_array('Bookings › Enquiries', $rec, true)
    && !array_filter($rec, fn($l) => str_starts_with($l, 'Finance') || str_starts_with($l, 'Settings') || str_starts_with($l, 'Inventory')));
check('reception: Calendar tabs are Calendar + Conflicts + iCal feeds', array_column($nav('reception', 'gantt.php')['active']['tabs'], 'label') === ['Calendar', 'Conflicts', 'iCal feeds']);
check('reception: counts stock from Today', in_array('Today › Stock count', $rec, true));

$hk = $labels($nav('housekeeping'));
check('housekeeping: Dashboard, My work, Stock count, Team chat, Timetable — nothing else', $hk === ['Dashboard', 'Today › My work', 'Today › Stock count', 'Today › Team chat', 'Team › Timetable']);
check('security: Gate instead of My work', $labels($nav('security')) === ['Dashboard', 'Today › Stock count', 'Today › Team chat', 'Today › Gate', 'Team › Timetable']);
$shop = $labels($nav('shop'));
check('shop staff: Open till + their own PIN', in_array('Point of Sale › Open till', $shop, true) && in_array('Point of Sale › My till PIN', $shop, true)
    && !in_array('Point of Sale › Sales', $shop, true));
foreach (array_keys($roles) as $r) {
    check("{$r}: no empty group", !array_filter($nav($r)['groups'], fn($g) => !$g['items']));
}

// ── Active link + tab strip ─────────────────────────────────────────────────
$n = $nav('owner', 'conflicts.php', ['conflicts' => 3, 'enquiries' => 2]);
check('conflicts.php lights Calendar', ($n['active']['label'] ?? '') === 'Calendar' && $n['active']['group'] === 'bookings');
check('…with the Conflicts tab active', array_column(array_filter($n['active']['tabs'], fn($t) => $t['active']), 'label') === ['Conflicts']);
check('…and the item carries the tab badges', $n['active']['badge'] === 3 && $n['active']['badge_class'] === 'red');
$html = admin_nav_tabs_html($n);
check('tab strip: five tabs, shell links, count pill', substr_count($html, '<a class="tab-btn') === 5 && substr_count($html, 'data-shell-link') === 5
    && str_contains($html, 'tab-btn__count">3<') && str_contains($html, 'aria-current="page"'));
check('tab strip: every tab carries an icon', substr_count($html, '<svg') === 5);
check('tab icon: named after the page, else a stand-in, else the item icon', admin_nav_tab_icon(['pages' => ['conflicts.php']], 'gantt') === 'conflicts'
    && admin_nav_tab_icon(['pages' => ['submission-trends.php']], 'submissions') === 'reports'
    && admin_nav_tab_icon(['pages' => ['no-such-icon.php']], 'gantt') === 'gantt');
check('detail pages belong to their list: room-edit.php → Rooms tab', array_column(array_filter($nav('owner', 'room-edit.php')['active']['tabs'], fn($t) => $t['active']), 'label') === ['Rooms']);
check('booking.php lights Bookings', ($nav('owner', 'booking.php')['active']['label'] ?? '') === 'Bookings');
check('pages sharing a menu key are told apart: submission-trends.php', array_column(array_filter($nav('owner', 'submission-trends.php')['active']['tabs'], fn($t) => $t['active']), 'label') === ['Trends']);
// Settings, Pre-Check-in and Migrations share the page's own tab row: one link, no strip.
foreach (['settings.php', 'checkin-settings.php', 'migrate.php'] as $pg) {
    $sn = $nav('owner', $pg);
    check("{$pg}: lights Settings and draws no second tab row", ($sn['active']['label'] ?? '') === 'Settings' && admin_nav_tabs_html($sn, $pg) === '');
}
// Edit screens with their own tabs keep the sidebar link lit but drop the area strip.
foreach (ADMIN_NAV_OWN_TABS as $pg) {
    $en = $nav('owner', $pg);
    check("{$pg}: sidebar lit, no area strip over its own tabs", $en['active'] !== null && admin_nav_tabs_html($en, $pg) === ''
        && is_file(__DIR__ . '/../admin/' . $pg) && str_contains((string)file_get_contents(__DIR__ . '/../admin/' . $pg), 'class="tabs'));
}
check('…while their list page still has the strip', admin_nav_tabs_html($nav('owner', 'rooms.php'), 'rooms.php') !== '');
check('a single-tab item draws no strip', admin_nav_tabs_html($nav('owner', 'frontdesk.php')) === '');
check('an unknown page lights nothing', $nav('owner', 'nope.php')['active'] === null && admin_nav_tabs_html($nav('owner', 'nope.php')) === '');
check('owner counts stock through Inventory › Counts', ($nav('owner', 'inventory-count.php')['active']['label'] ?? '') === 'Counts');
check('staff count through Today › Stock count', ($nav('housekeeping', 'inventory-count.php')['active']['label'] ?? '') === 'Stock count');

$side = admin_nav_sidebar_html($nav('reception', 'frontdesk.php'));
check('sidebar: Today + Bookings open by default, the rest closed', str_contains($side, 'data-group="today" open') && str_contains($side, 'data-group="bookings" open')
    && str_contains($side, 'data-group="restaurant">'));
check('sidebar: a group header is icon + name + chevron', (bool)preg_match('~<summary class="navgroup__head"><svg[^>]*>.*?</svg><span>Today</span>~s', $side));
check('sidebar: a link lists every page it stands for', str_contains(admin_nav_sidebar_html($n), 'data-pages="gantt.php conflicts.php calendar-highlights.php ical-feeds.php import-bookings.php"'));
check('sidebar: Open till opens in a new tab', str_contains(admin_nav_sidebar_html($nav('shop')), 'href="/pos/" class="sidebar__link" target="_blank"'));
check('sidebar: the active group opens even when it is closed by default', str_contains(admin_nav_sidebar_html($nav('owner', 'sync.php')), 'data-group="settings" open'));

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
