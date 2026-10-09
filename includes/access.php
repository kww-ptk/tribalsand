<?php
/**
 * Access by role — the owner's per-role choices of which admin sections each kind
 * of account can open (Team → Staff → "Access by role", admin/staff.php?tab=access).
 *
 * A SECTION is one tab of the admin navigation (includes/admin-nav.php), keyed by
 * its first page ("holds.php"). Every role has DEFAULTS — exactly what the code
 * gives it (admin_nav_flags()). The owner's changes are stored as overrides in the
 * `access_matrix` setting: {"reception": {"inventory.php": true, "reports.php": false}}.
 * Only cells that differ from the default are stored, so with no setting nothing
 * changes for anyone.
 *
 * What an override does:
 *   false (switched OFF) — the section leaves the sidebar AND its pages refuse the
 *                          role (checked in require_login()).
 *   true  (switched ON)  — the section joins the sidebar and the page's own role gate
 *                          lets the role in (require_manager() & co. ask
 *                          access_page_granted()). The page still limits the person
 *                          to their own properties (admin_venue_ids()), and actions
 *                          the page keeps for the owner stay owner-only.
 *
 * Never configurable: the owner (always everything), the Dashboard (everyone's
 * landing page), owner-only configuration (ACCESS_OWNER_ONLY), and the till
 * sections that follow outlet assignment rather than role.
 *
 * Pure helpers take the matrix + env as arguments; the I/O wrappers read the
 * setting once per request. Test: php tests/access_logic.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/admin-nav.php';

/** Sections only the owner can ever open — site-wide configuration and money settings. */
const ACCESS_OWNER_ONLY = ['settings.php', 'emails.php', 'ai-settings.php', 'reindex.php', 'agents.php', 'sync.php', 'audit.php',
    'staff.php', 'venues.php', 'rooms.php', 'properties.php', 'pages.php', 'nav-menu.php', 'booking-widgets.php', 'media.php', 'sustainability.php',
    'tours.php', 'services.php', 'offers.php', 'reviews.php', 'partners.php', 'guest-board.php', 'pos-outlets.php', 'companies.php'];

/** Sections that follow something other than role (always on, or the person's till outlets). */
const ACCESS_AUTOMATIC = ['dashboard.php' => 'Everyone’s home page', 'help.php' => 'Everyone — shows only guides for pages the person can open', 'pos' => 'Follows the person’s till outlets',
                          'pos-pins.php' => 'Follows the person’s till outlets', 'maya-ilai-rates.php' => 'Follows the manager’s properties'];

/** Sections that only work for a manager (their pages show nothing to anyone else), so only managers can be given them. */
const ACCESS_MANAGERS_ONLY = ['pos-sales.php', 'pos-items.php', 'pos-stock.php', 'pos-consignors.php', 'pos-terminals.php'];

/** Every configurable kind of account: key => label. Owner is never listed (always everything). */
function access_role_options(array $jobs): array {
    $out = ['manager' => 'Manager', 'reception' => 'Reception', 'hr' => 'HR'];
    foreach ($jobs as $k => $label) $out['staff:' . $k] = preg_replace('/\s*\(.*\)$/', '', (string)$label);
    return $out;
}

/** "staff:housekeeping" → ['staff', 'housekeeping']; "manager" → ['manager', null]. */
function access_role_split(string $key): array {
    return str_starts_with($key, 'staff:') ? ['staff', substr($key, 6) ?: 'frontdesk'] : [$key, null];
}

/** The role key of an account: owner | manager | reception | staff:<job> (NULL job = front desk). */
function access_role_key(string $role, ?string $job): string {
    return $role === 'staff' ? 'staff:' . ($job ?: 'frontdesk') : $role;
}

/** The key a nav tab is stored under: its first page, or "pos" for the till link. */
function access_tab_key(array $tab): string {
    return (string)($tab['pages'][0] ?? '') !== '' ? (string)$tab['pages'][0] : 'pos';
}

/**
 * Every section, in sidebar order — PURE. Each: key, label (Item › Tab), group,
 * pages, kind = free | owner | auto | managers.
 */
function access_sections(): array {
    $all = array_fill_keys(['owner', 'manager', 'reception', 'hr', 'frontdesk', 'concierge', 'messages', 'internal', 'tasks', 'timetable',
        'gate', 'mywork', 'assistant', 'aiSettings', 'aiGaps', 'bookings', 'reports', 'pos', 'posTill', 'inventory', 'invOrders',
        'count', 'accounting', 'acctDocs', 'acctIc', 'restaurant', 'clockOn', 'clockNav', 'mayaIlai'], true);
    $out = [];
    foreach (admin_nav_definition($all) as $g) {
        foreach ($g['items'] as $it) {
            foreach ($it['tabs'] as $t) {
                $key = access_tab_key($t);
                if (isset($out[$key])) continue;
                $label = $t['solo'] === $it['label'] || count($it['tabs']) === 1 ? $t['solo'] : $it['label'] . ' › ' . $t['label'];
                if ($key === 'pos-pins.php') $label = 'Point of Sale › Till PINs';
                $kind = in_array($key, ACCESS_OWNER_ONLY, true) ? 'owner'
                      : (isset(ACCESS_AUTOMATIC[$key]) ? 'auto' : (in_array($key, ACCESS_MANAGERS_ONLY, true) ? 'managers' : 'free'));
                $out[$key] = ['key' => $key, 'label' => $label, 'group' => $g['title'] !== '' ? $g['title'] : 'Home',
                              'pages' => $t['pages'], 'kind' => $kind, 'note' => ACCESS_AUTOMATIC[$key] ?? ''];
            }
        }
    }
    return $out;
}

/** Can the owner change this section for this role? — PURE. */
function access_configurable(array $section, string $roleKey): bool {
    return match ($section['kind']) {
        'free'     => true,
        'managers' => $roleKey === 'manager',
        default    => false,
    };
}

/** The role's default for every section: [section key => bool] — PURE (no overrides). */
function access_defaults(string $roleKey, array $env): array {
    [$role, $job] = access_role_split($roleKey);
    $out = [];
    foreach (admin_nav_definition(admin_nav_flags($role, $job, $env)) as $g) {
        foreach ($g['items'] as $it) {
            foreach ($it['tabs'] as $t) {
                $k = access_tab_key($t);
                $out[$k] = ($out[$k] ?? false) || !empty($t['show']);
            }
        }
    }
    return $out;
}

/** Resolve one section: the owner's override when it is allowed, else the default — PURE. */
function access_resolve(array $matrix, string $roleKey, array $section, bool $default): bool {
    if ($roleKey === 'owner' || !access_configurable($section, $roleKey)) return $default;
    $o = $matrix[$roleKey][$section['key']] ?? null;
    return is_bool($o) ? $o : $default;
}

/**
 * Clean a posted set of choices into overrides for ONE role — PURE. $posted is
 * [section key => bool] for the sections shown; only configurable sections whose
 * choice differs from the default are kept.
 */
function access_overrides_from(array $posted, string $roleKey, array $defaults, array $sections): array {
    $out = [];
    foreach ($sections as $key => $s) {
        if (!access_configurable($s, $roleKey) || !array_key_exists($key, $posted)) continue;
        $want = (bool)$posted[$key];
        if ($want !== (bool)($defaults[$key] ?? false)) $out[$key] = $want;
    }
    return $out;
}

// ── I/O ─────────────────────────────────────────────────────────────────────

/** The stored overrides (setting `access_matrix`). [] when unset or unreadable — never breaks a page. */
function access_matrix(bool $fresh = false): array {
    static $m = null;
    if ($m !== null && !$fresh) return $m;
    try { $raw = setting('access_matrix', ''); } catch (Throwable $e) { $raw = ''; }
    $d = json_decode($raw, true);
    $m = [];
    if (is_array($d)) {
        foreach ($d as $role => $cells) {
            if (!is_array($cells)) continue;
            foreach ($cells as $k => $v) if (is_bool($v)) $m[(string)$role][(string)$k] = $v;
        }
    }
    return $m;
}

/** Save one role's overrides (replacing what that role had). */
function access_save_role(string $roleKey, array $overrides): void {
    $m = access_matrix(true);
    if ($overrides) $m[$roleKey] = $overrides; else unset($m[$roleKey]);
    set_setting('access_matrix', json_encode($m, JSON_UNESCAPED_SLASHES));
    access_matrix(true);
}

/** What is installed — the env admin_nav_flags() needs (seller / mayaIlai are per account). */
function access_env(array $account = []): array {
    $has = fn(string $t): bool => function_exists('to_regclass_exists') && to_regclass_exists($t);
    return [
        'ai'        => function_exists('ai_assistant_supported') && ai_assistant_supported(),
        'pos'       => function_exists('pos_supported') && pos_supported(),
        'inv'       => function_exists('inv_supported') && inv_supported(),
        'invOrders' => function_exists('inv_orders_supported') && inv_orders_supported(),
        'companies' => function_exists('companies_supported') && companies_supported(),
        'acctDocs'  => function_exists('companies_supported') && companies_supported() && $has('acct_documents') && $has('acct_ic_entries'),
        'acctIc'    => $has('acct_ic_entries'),
        'clockOn'   => function_exists('clock_kiosk_enabled') && clock_kiosk_enabled(),
        'seller'    => $account && function_exists('pos_is_seller') && pos_is_seller($account),
        'mayaIlai'  => ($account['role'] ?? '') === 'manager' && in_array(6, admin_venue_ids() ?? [], true),
    ];
}

/** The section a page belongs to, or null (API endpoints, print pages, pages in no section). */
function access_section_for_page(string $page): ?array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (access_sections() as $s) foreach ($s['pages'] as $p) $map[$p] ??= $s;
    }
    return $map[$page] ?? null;
}

/** The current page's override for the signed-in account: true / false / null (no choice made). */
function access_page_override(?string $page = null): ?bool {
    if (!function_exists('current_admin')) return null;
    $a = current_admin();
    if (!$a || ($a['role'] ?? '') === 'owner') return null;
    $page ??= basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $s = access_section_for_page($page);
    if (!$s) return null;
    $roleKey = access_role_key((string)$a['role'], ($a['role'] ?? '') === 'staff' ? ((string)($a['job_type'] ?? '') ?: null) : null);
    if (!access_configurable($s, $roleKey)) return null;
    $o = access_matrix()[$roleKey][$s['key']] ?? null;
    return is_bool($o) ? $o : null;
}

/** True when the owner switched section $key ON for the signed-in account's role (any page). */
function access_section_granted(string $key): bool {
    if (!function_exists('current_admin')) return false;
    $a = current_admin();
    if (!$a || ($a['role'] ?? '') === 'owner') return false;
    $roleKey = access_role_key((string)$a['role'], ($a['role'] ?? '') === 'staff' ? ((string)($a['job_type'] ?? '') ?: null) : null);
    return (access_matrix()[$roleKey][$key] ?? null) === true;
}

/** True when the owner switched this page's section ON for the signed-in account's role. */
function access_page_granted(?string $page = null): bool { return access_page_override($page) === true; }

/** True when the owner switched this page's section OFF for the signed-in account's role. */
function access_page_blocked(?string $page = null): bool { return access_page_override($page) === false; }
