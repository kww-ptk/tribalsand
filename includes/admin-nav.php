<?php
/**
 * Admin navigation — ONE definition of the sidebar and the tab strips.
 *
 * The sidebar has two levels: a collapsible GROUP ("Bookings", drawn as a row with
 * an icon; its links sit indented under it along a vertical line) and its ITEMS
 * ("Calendar"). An item that covers several related pages lists them as TABS
 * ("Calendar · Conflicts · Highlights · Import"): the sidebar shows one link, and
 * admin/_layout.php prints the tab strip at the top of every page in that item.
 * The pages themselves are unchanged — a tab is just a link to the existing page.
 *
 * Visibility is decided per TAB with the same rules the old hand-written sidebar
 * used (the flags are computed in admin/_layout.php). An item shows when at least
 * one of its tabs does; with exactly one visible tab it is a plain link labelled
 * with that tab's `solo` name and no strip is drawn. A group shows when at least
 * one of its items does.
 *
 * `pages` lists every admin/*.php file a tab owns, detail pages included
 * (room-edit.php belongs to Rooms). That is what marks the sidebar link and tab
 * active — not $activeMenu, which several pages share. tests/admin_nav_logic.php
 * fails when a page that uses the layout is owned by no tab.
 *
 * admin_nav_definition() and admin_nav_resolve() are pure (no DB, no session).
 */
declare(strict_types=1);

/**
 * The DEFAULT visibility flags for a kind of account — PURE (no DB, no session).
 * admin/_layout.php feeds it the signed-in account; the Staff page's "Access by
 * role" tab feeds it every role, to show each role's defaults. One rule set, so the
 * two can never disagree.
 *
 * @param string  $role owner | manager | reception | staff
 * @param ?string $job  the staff job (NULL for a staff account = front desk)
 * @param array   $env  what is installed / per-account: ai, pos, inv, invOrders, companies,
 *                      acctDocs, acctIc, clockOn (bool) + seller (can sell at a till),
 *                      mayaIlai (a manager scoped to Maya Ilai)
 */
function admin_nav_flags(string $role, ?string $job, array $env): array {
    $e = fn(string $k): bool => !empty($env[$k]);
    $owner = $role === 'owner'; $manager = $role === 'manager'; $reception = $role === 'reception';
    $staff = $role === 'staff';
    $job   = $staff ? ($job ?: 'frontdesk') : null;
    $ops   = in_array($job, ['housekeeping', 'laundry', 'maintenance', 'gardening', 'driver'], true);
    $store = $job === 'storekeeper';
    $security = $job === 'security';
    $till  = in_array($job, ['shop', 'spa', 'kite'], true);
    $deskStaff = $staff && !$ops && !$store && !$security && !$till;
    $guestSide = $owner || $manager || $reception || $deskStaff;
    $inventory = ($owner || $manager || ($staff && $store)) && $e('inv');
    $countable = $owner || $manager || $store || $ops;
    return [
        'owner' => $owner, 'manager' => $manager, 'reception' => $reception,
        'frontdesk' => $guestSide, 'concierge' => $guestSide, 'messages' => $guestSide,
        'internal' => true, 'tasks' => $owner || $manager || $reception, 'timetable' => true,
        'gate' => $owner || $manager || $reception || $security, 'mywork' => $ops || $reception,
        'assistant' => $guestSide && $e('ai'), 'aiSettings' => $owner, 'aiGaps' => $owner || $manager,
        'bookings' => $owner || $reception, 'reports' => $owner || $manager,
        'pos' => ($owner || $manager) && $e('pos'), 'posTill' => $e('seller'),
        'inventory' => $inventory, 'invOrders' => $inventory && $e('invOrders'),
        'count' => !$inventory && $e('inv') && $countable,
        'accounting' => $owner && $e('companies'),
        'acctDocs' => ($owner || $manager) && $e('acctDocs'), 'acctIc' => ($owner || $manager) && $e('acctDocs') && $e('acctIc'),
        'restaurant' => $owner || $manager || $reception,
        'clockOn' => $e('clockOn'), 'clockNav' => $e('clockOn') || $owner,
        'mayaIlai' => $owner || ($manager && $e('mayaIlai')),
    ];
}

/**
 * The whole navigation for one account.
 *
 * @param array $f     visibility flags (see admin/_layout.php)
 * @param array $badge unread counts: messages, team, enquiries, conflicts
 */
function admin_nav_definition(array $f, array $badge = []): array {
    $on = fn(string $k): bool => !empty($f[$k]);
    $b  = fn(string $k): int  => (int)($badge[$k] ?? 0);
    $ownerOrManager = $on('owner') || $on('manager');

    // tab(label, page file(s), visible, [solo label], [badge count], [badge class], [badge tooltip])
    $tab = function (string $label, array|string $pages, bool $show, string $solo = '', int $count = 0, string $badgeClass = 'orange', string $tip = ''): array {
        $pages = (array)$pages;
        return ['label' => $label, 'solo' => $solo !== '' ? $solo : $label, 'href' => '/admin/' . $pages[0],
                'pages' => $pages, 'show' => $show, 'badge' => $count, 'badge_class' => $badgeClass, 'badge_tip' => $tip];
    };
    // item(label, icon, tabs) — a single-tab item is a plain sidebar link
    $item = fn(string $label, string $icon, array $tabs): array => ['label' => $label, 'icon' => $icon, 'tabs' => $tabs];

    return [
        ['key' => 'home', 'title' => '', 'items' => [
            $item('Dashboard', 'dashboard', [$tab('Dashboard', 'dashboard.php', true)]),   // everyone's landing page
        ]],

        ['key' => 'today', 'icon' => 'timetable', 'title' => 'Today', 'items' => [
            $item('Front desk', 'frontdesk', [$tab('Front desk', 'frontdesk.php', $on('frontdesk'))]),
            $item('My work', 'mywork', [$tab('My work', 'mywork.php', $on('mywork'))]),
            // Staff and reception count stock from here; owner + manager reach the
            // same screen through Inventory → Counts (the flags are mutually exclusive).
            $item('Stock count', 'inventory-count', [$tab('Stock count', 'inventory-count.php', $on('count'))]),
            $item('Guest requests', 'concierge-desk', [$tab('Guest requests', 'concierge-desk.php', $on('concierge'))]),
            $item('Messages', 'messages', [
                $tab('Customers', 'messages.php', $on('messages'), 'Customer messages', $b('messages')),
                $tab('Team chat', 'internal-messages.php', $on('internal'), 'Team chat', $b('team')),
            ]),
            $item('Gate', 'gate', [$tab('Gate', 'gate.php', $on('gate'))]),
            $item('AI assistant', 'assistant', [$tab('AI assistant', 'assistant.php', $on('assistant'))]),
        ]],

        ['key' => 'bookings', 'icon' => 'holds', 'title' => 'Bookings', 'items' => [
            $item('Bookings', 'holds', [
                $tab('Bookings', ['holds.php', 'booking.php', 'hold-new.php', 'hold-action.php', 'itinerary.php'], $on('bookings')),
            ]),
            $item('Calendar', 'gantt', [
                $tab('Calendar', 'gantt.php', $on('bookings')),
                $tab('Conflicts', 'conflicts.php', $on('bookings'), 'Conflicts', $b('conflicts'), 'red'),
                $tab('Highlights', 'calendar-highlights.php', $ownerOrManager, 'Calendar highlights'),
                $tab('iCal feeds', 'ical-feeds.php', $on('bookings')),
                $tab('Import', 'import-bookings.php', $ownerOrManager, 'Import bookings'),
            ]),
            $item('Enquiries', 'submissions', [
                $tab('Enquiries', ['submissions.php', 'submission-view.php'], $on('bookings'), 'Enquiries', $b('enquiries'), 'red',
                     $b('enquiries') . ' new customer repl' . ($b('enquiries') === 1 ? 'y' : 'ies')),
                $tab('Trends', 'submission-trends.php', $on('bookings'), 'Enquiry trends'),
            ]),
            $item('Rates', 'rates', [
                $tab('Rates', ['rates.php'], $on('bookings')),
                $tab('Maya Ilai', 'maya-ilai-rates.php', $on('mayaIlai'), 'Maya Ilai rates'),
            ]),
            $item('Quote builder', 'quote-builder', [$tab('Quote builder', 'quote-builder.php', $on('bookings'))]),
        ]],

        ['key' => 'team', 'icon' => 'staff', 'title' => 'Team', 'items' => [
            $item('Tasks', 'tasks', [
                $tab('Tasks', 'tasks.php', $on('tasks')),
                $tab('Job timetables', ['task-schedules.php', 'task-schedule-edit.php'], $ownerOrManager),
                $tab('Week view', 'timetable.php', $on('timetable'), 'Timetable'),
            ]),
            $item('Attendance', 'attendance', [
                $tab('Attendance', 'attendance.php', $on('reports')),
                $tab('Clock kiosks', 'attendance-devices.php', $on('reports') && $on('clockNav')),
                $tab('Clock cards', 'attendance-cards.php', $on('reports') && $on('clockOn')),
            ]),
            $item('Staff', 'staff', [$tab('Staff', ['staff.php', 'employee.php'], $on('owner'))]),
        ]],

        ['key' => 'restaurant', 'icon' => 'menus', 'title' => 'Restaurant', 'items' => [
            $item('Reservations', 'reservations', [$tab('Reservations', 'reservations.php', $on('restaurant'))]),
            $item('Menus & setup', 'menus', [
                $tab('Menus', ['menus.php', 'menu-edit.php'], $on('restaurant') && $ownerOrManager),
                $tab('Hours & tables', 'restaurant-setup.php', $on('restaurant') && $ownerOrManager),
            ]),
        ]],

        ['key' => 'pos', 'icon' => 'pos-till', 'title' => 'Point of Sale', 'items' => [
            ['label' => 'Open till', 'icon' => 'pos-till', 'blank' => true, 'tabs' => [
                ['label' => 'Open till', 'solo' => 'Open till', 'href' => '/pos/', 'pages' => [], 'show' => $on('posTill'),
                 'badge' => 0, 'badge_class' => 'orange', 'badge_tip' => ''],
            ]],
            $item('Sales', 'pos-sales', [$tab('Sales', 'pos-sales.php', $on('pos'))]),
            $item('Catalogue & stock', 'pos-items', [
                $tab('Catalogue', 'pos-items.php', $on('pos')),
                $tab('Stock', 'pos-stock.php', $on('pos')),
                $tab('Suppliers', 'pos-consignors.php', $on('pos')),
            ]),
            $item('Setup', 'pos-outlets', [
                $tab('Outlets', 'pos-outlets.php', $on('owner') && $on('pos')),
                $tab('Terminals', 'pos-terminals.php', $on('pos')),
                $tab($on('pos') ? 'Staff PINs' : 'My till PIN', 'pos-pins.php', $on('posTill')),
            ]),
        ]],

        ['key' => 'inventory', 'icon' => 'inventory', 'title' => 'Inventory', 'items' => [
            $item('Inventory', 'inventory', [
                $tab('Inventory', ['inventory.php', 'inventory-item.php', 'inventory-import.php'], $on('inventory')),
                $tab('Locations', ['inventory-locations.php', 'inventory-location.php'], $on('inventory')),
            ]),
            $item('Orders', 'inventory-orders', [
                $tab('Orders', ['inventory-orders.php', 'inventory-order.php', 'inventory-order-packing.php'], $on('inventory') && $on('invOrders')),
            ]),
            $item('Counts', 'inventory-counts', [
                $tab('Counts', array_merge(['inventory-counts.php'], $on('count') ? [] : ['inventory-count.php']), $on('inventory')),
            ]),
        ]],

        ['key' => 'finance', 'icon' => 'reports', 'title' => 'Finance', 'items' => [
            $item('Reports', 'reports', [$tab('Reports', 'reports.php', $on('reports'))]),
            $item('Accounting', 'acct-documents', [
                $tab('Invoices & payments', 'acct-documents.php', $on('acctDocs')),
                $tab('Between companies', 'acct-intercompany.php', $on('acctIc')),
                $tab('Companies', ['companies.php', 'company-edit.php'], $on('accounting')),
            ]),
        ]],

        ['key' => 'website', 'icon' => 'venues', 'title' => 'Website', 'items' => [
            $item('Properties & rooms', 'venues', [
                $tab('Properties', ['venues.php', 'venue-edit.php'], $on('owner')),
                $tab('Rooms', ['rooms.php', 'room-edit.php'], $on('owner')),
                $tab('For sale', ['properties.php', 'property-edit.php'], $on('owner'), 'For Sale Listings'),
            ]),
            $item('Website content', 'pages', [
                $tab('Pages', ['pages.php', 'page-edit.php'], $on('owner')),
                $tab('Site menu', 'nav-menu.php', $on('owner')),
                $tab('Media', 'media.php', $on('owner')),
                $tab('Sustainability', 'sustainability.php', $on('owner')),
            ]),
            $item('Activities & services', 'tours', [
                $tab('Tours', ['tours.php', 'tour-edit.php'], $on('owner')),
                $tab('Service pricing', 'services.php', $on('owner')),
            ]),
            $item('Marketing', 'offers', [
                $tab('Offers', ['offers.php', 'offer-edit.php'], $on('owner')),
                $tab('Reviews', 'reviews.php', $on('owner')),
                $tab('Partners', 'partners.php', $on('owner')),
                $tab('Guest board', 'guest-board.php', $on('owner')),
            ]),
        ]],

        ['key' => 'settings', 'icon' => 'settings', 'title' => 'Settings', 'items' => [
            // Settings, Pre-Check-in and Migrations draw their OWN tab row (admin/settings.php),
            // so this is one plain link that owns all three pages — no second strip above it.
            $item('Settings', 'settings', [$tab('Settings', ['settings.php', 'checkin-settings.php', 'migrate.php'], $on('owner'))]),
            $item('Emails', 'emails', [
                $tab('Emails', ['emails.php', 'email-edit.php', 'email-preview.php'], $on('owner')),
                $tab('Email log', 'email-log.php', $on('reports')),
            ]),
            $item('AI', 'ai-settings', [
                $tab('AI settings', 'ai-settings.php', $on('aiSettings')),
                $tab('AI gaps', 'ai-gaps.php', $on('aiGaps')),
                $tab('Search index', 'reindex.php', $on('owner'), 'AI search index'),
            ]),
            $item('Travel agents', 'agents', [$tab('Travel agents', 'agents.php', $on('owner'))]),
            $item('Zuri sync', 'sync', [$tab('Zuri sync', 'sync.php', $on('owner'))]),
            $item('Audit log', 'audit', [$tab('Audit log', 'audit.php', $on('owner'))]),
        ]],
    ];
}

/**
 * Drop what this account cannot see and mark what is active.
 *
 * @param string $script the running page's file name, e.g. "gantt.php"
 * @return array{groups: array, active: ?array} `active` is the item being viewed.
 */
function admin_nav_resolve(array $definition, string $script, ?callable $access = null): array {
    $groups = []; $active = null;
    foreach ($definition as $g) {
        $items = [];
        foreach ($g['items'] as $it) {
            // $access(tab, defaultShow) lets the owner's "Access by role" choices override a default.
            $tabs = array_values(array_filter($it['tabs'], fn($t) => $access ? (bool)$access($t, !empty($t['show'])) : !empty($t['show'])));
            if (!$tabs) continue;
            $pages = []; $badge = 0; $isActive = false;
            foreach ($tabs as $i => $t) {
                $tabs[$i]['active'] = $script !== '' && in_array($script, $t['pages'], true);
                $isActive = $isActive || $tabs[$i]['active'];
                $pages = array_merge($pages, $t['pages']);
                $badge += (int)$t['badge'];
            }
            $solo = count($tabs) === 1;
            $resolved = [
                'label'  => $solo && count($it['tabs']) > 1 ? $tabs[0]['solo'] : $it['label'],
                'icon'   => $it['icon'],
                'href'   => $tabs[0]['href'],
                'blank'  => !empty($it['blank']),
                'pages'  => $pages,
                'tabs'   => $solo ? [] : $tabs,
                'badge'  => $badge,
                'badge_class' => $solo ? $tabs[0]['badge_class'] : (count(array_filter($tabs, fn($t) => $t['badge'] > 0 && $t['badge_class'] === 'red')) ? 'red' : 'orange'),
                'badge_tip'   => $solo ? $tabs[0]['badge_tip'] : '',
                'active' => $isActive,
                'group'  => $g['key'],
            ];
            if ($isActive) $active = $resolved;
            $items[] = $resolved;
        }
        if ($items) $groups[] = ['key' => $g['key'], 'title' => $g['title'], 'icon' => $g['icon'] ?? '', 'items' => $items];
    }
    return ['groups' => $groups, 'active' => $active];
}

function admin_nav_icon_set(): array {
    static $icons = null;
    return $icons ??= require __DIR__ . '/admin-nav-icons.php';
}

/**
 * The icon a tab shows in the strip: the one named after its page ("conflicts.php"
 * → conflicts), a stand-in for the few pages with none, else its item's icon.
 */
function admin_nav_tab_icon(array $tab, string $itemIcon): string {
    $stem = basename((string)($tab['pages'][0] ?? ''), '.php');
    $stem = ['submission-trends' => 'reports', 'reindex' => 'sync'][$stem] ?? $stem;
    return isset(admin_nav_icon_set()[$stem]) ? $stem : $itemIcon;
}

/** One icon as an <svg> (inner paths live in includes/admin-nav-icons.php). */
function admin_nav_icon(string $name): string {
    $icons = admin_nav_icon_set();
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
         . ($icons[$name] ?? '') . '</svg>';
}

function admin_nav_badge_html(int $count, string $class, string $tip = ''): string {
    if ($count <= 0) return '';
    return ' <span class="badge badge--' . e($class) . ' navbadge"' . ($tip !== '' ? ' data-tip="' . e($tip) . '"' : '') . '>' . $count . '</span>';
}

/**
 * The sidebar's groups and links.
 *
 * Groups open by default: the one holding the current page, plus Today and
 * Bookings (reception lands in Today and works in Bookings — with Bookings
 * collapsed they reported having no access to it). Everything else starts closed;
 * the inline script in _layout.php remembers what each person opens or closes.
 */
function admin_nav_sidebar_html(array $nav): string {
    $chev = '<svg class="navgroup__chev" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
    $out = '';
    foreach ($nav['groups'] as $g) {
        $links = ''; $hasActive = false;
        foreach ($g['items'] as $it) {
            $hasActive = $hasActive || $it['active'];
            $links .= '<a href="' . e($it['href']) . '" class="sidebar__link' . ($it['active'] ? ' is-active' : '') . '"'
                    . ($it['pages'] ? ' data-pages="' . e(implode(' ', $it['pages'])) . '"' : '')
                    . ($it['blank'] ? ' target="_blank" rel="noopener"' : '') . '>'
                    . admin_nav_icon($it['icon']) . e($it['label'])
                    . admin_nav_badge_html($it['badge'], $it['badge_class'], $it['badge_tip'])
                    . '</a>';
        }
        if ($g['title'] === '') { $out .= '<div class="navhome">' . $links . '</div>'; continue; }
        $open = ($hasActive || in_array($g['key'], ['today', 'bookings'], true)) ? ' open' : '';
        $out .= '<details class="navgroup" data-group="' . e($g['key']) . '"' . $open . '>'
              . '<summary class="navgroup__head">' . ($g['icon'] !== '' ? admin_nav_icon($g['icon']) : '')
              . '<span>' . e($g['title']) . '</span>' . $chev . '</summary>'
              . '<div class="navgroup__items">' . $links . '</div></details>';
    }
    return $out;
}

/**
 * Edit screens that draw their own tab row (Details · Content · Gallery …). The
 * area strip is left off them — two rows of tabs on one page reads as clutter —
 * while the sidebar link stays lit and their list page still carries the strip.
 */
const ADMIN_NAV_OWN_TABS = ['venue-edit.php', 'room-edit.php', 'property-edit.php', 'tour-edit.php', 'company-edit.php'];

/** The tab strip for the item being viewed ('' when it has fewer than two tabs, or the page has its own). */
function admin_nav_tabs_html(array $nav, string $script = ''): string {
    $it = $nav['active'] ?? null;
    if (!$it || count($it['tabs']) < 2) return '';
    if ($script !== '' && in_array($script, ADMIN_NAV_OWN_TABS, true)) return '';
    $out = '<nav class="areatabs" aria-label="' . e($it['label']) . '"><div class="tabs areatabs__row">';
    foreach ($it['tabs'] as $t) {
        $out .= '<a class="tab-btn' . ($t['active'] ? ' is-active' : '') . '" href="' . e($t['href']) . '" data-shell-link'
              . ($t['active'] ? ' aria-current="page"' : '') . '>' . admin_nav_icon(admin_nav_tab_icon($t, $it['icon'])) . e($t['label'])
              . ($t['badge'] > 0 ? ' <span class="tab-btn__count">' . (int)$t['badge'] . '</span>' : '')
              . '</a>';
    }
    return $out . '</div></nav>';
}
