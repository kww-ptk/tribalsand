<?php
/**
 * Access by role — includes/access.php (pure parts; no DB, no session).
 *   php tests/access_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/access.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$cond) $failures++;
}

$env = ['ai' => true, 'pos' => true, 'inv' => true, 'invOrders' => true, 'companies' => true, 'acctDocs' => true, 'acctIc' => true,
        'clockOn' => true, 'seller' => false, 'mayaIlai' => false];
$sections = access_sections();
$jobs = ['frontdesk' => 'Front desk', 'housekeeping' => 'Housekeeping', 'storekeeper' => 'Storekeeper (inventory)', 'shop' => 'Shop (POS till)'];

// ── Roles ───────────────────────────────────────────────────────────────────
$roles = access_role_options($jobs);
check('roles: manager, reception and one per job — never the owner', isset($roles['manager'], $roles['reception'], $roles['staff:housekeeping']) && !isset($roles['owner']));
check('roles: "(inventory)" / "(POS till)" hints dropped from the label', $roles['staff:storekeeper'] === 'Storekeeper' && $roles['staff:shop'] === 'Shop');
check('role key: a staff account with no job is front desk', access_role_key('staff', null) === 'staff:frontdesk' && access_role_key('reception', null) === 'reception');
check('role split round-trips', access_role_split('staff:housekeeping') === ['staff', 'housekeeping'] && access_role_split('manager') === ['manager', null]);

// ── Sections ────────────────────────────────────────────────────────────────
check('sections: one per nav tab, keyed by its first page', isset($sections['holds.php'], $sections['inventory.php'], $sections['reports.php']));
check('sections: owner-only config is locked', $sections['settings.php']['kind'] === 'owner' && $sections['staff.php']['kind'] === 'owner' && $sections['companies.php']['kind'] === 'owner');
check('sections: the dashboard and the till follow their own rules', $sections['dashboard.php']['kind'] === 'auto' && $sections['pos']['kind'] === 'auto');
check('sections: POS management is managers-only', $sections['pos-sales.php']['kind'] === 'managers');
check('configurable: a free section for any role', access_configurable($sections['reports.php'], 'reception') && access_configurable($sections['reports.php'], 'staff:housekeeping'));
check('configurable: never an owner-only section', !access_configurable($sections['settings.php'], 'manager'));
check('configurable: a managers-only section only for managers', access_configurable($sections['pos-sales.php'], 'manager') && !access_configurable($sections['pos-sales.php'], 'reception'));
check('page → section: a detail page belongs to its list', access_section_for_page('booking.php')['key'] === 'holds.php' && access_section_for_page('inventory-item.php')['key'] === 'inventory.php');
check('page → section: an API endpoint belongs to none', access_section_for_page('booking-extras.php') === null);

// ── Defaults = what the code gives today ────────────────────────────────────
$rec = access_defaults('reception', $env);
check('defaults: reception has bookings, rates and messages', $rec['holds.php'] && $rec['rates.php'] && $rec['messages.php']);
check('defaults: reception has no stock and no finance', !$rec['inventory.php'] && !$rec['inventory-count.php'] && !$rec['reports.php'] && !$rec['acct-documents.php']);
$store = access_defaults('staff:storekeeper', $env);
check('defaults: a storekeeper has the stock pages only', $store['inventory.php'] && $store['inventory-orders.php'] && !$store['holds.php'] && !$store['messages.php'] && !$store['reports.php']);
$hk = access_defaults('staff:housekeeping', $env);
check('defaults: housekeeping counts stock but sees no stock list', $hk['inventory-count.php'] && !$hk['inventory.php']);
$mgr = access_defaults('manager', $env);
check('defaults: a manager has reports and stock but not the booking desk', $mgr['reports.php'] && $mgr['inventory.php'] && !$mgr['holds.php']);
check('flags: a staff account with no job gets the front desk', admin_nav_flags('staff', null, $env)['frontdesk'] === true);

// ── Overrides ───────────────────────────────────────────────────────────────
$matrix = ['reception' => ['rates.php' => false, 'inventory.php' => true, 'settings.php' => true]];
check('resolve: switched off', access_resolve($matrix, 'reception', $sections['rates.php'], true) === false);
check('resolve: switched on', access_resolve($matrix, 'reception', $sections['inventory.php'], false) === true);
check('resolve: a stored owner-only grant is ignored', access_resolve($matrix, 'reception', $sections['settings.php'], false) === false);
check('resolve: no choice = the default', access_resolve($matrix, 'reception', $sections['holds.php'], true) === true);
check('resolve: the owner is never overridden', access_resolve(['owner' => ['reports.php' => false]], 'owner', $sections['reports.php'], true) === true);
check('resolve: another role is unaffected', access_resolve($matrix, 'manager', $sections['rates.php'], true) === true);

$over = access_overrides_from(['rates.php' => false, 'holds.php' => true, 'inventory.php' => true, 'settings.php' => true, 'pos-sales.php' => true],
                              'reception', $rec, $sections);
check('save: only changes from the default are stored', $over === ['rates.php' => false, 'inventory.php' => true]);
check('save: everything at default stores nothing', access_overrides_from(['holds.php' => true, 'reports.php' => false], 'reception', $rec, $sections) === []);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
