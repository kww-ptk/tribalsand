<?php
// Admin shared layout — include at top of each admin page after require_login()
// Sets: $pageTitle (required), $activeMenu (required)
require_once __DIR__ . '/../includes/icons.php';       // admin_icon() for icon-only buttons
require_once __DIR__ . '/../includes/admin-shell.php'; // no-flicker shell (#18)
require_once __DIR__ . '/../includes/ai.php';          // ai_assistant_supported() — gates the Assistant nav link
require_once __DIR__ . '/../includes/internal-messages.php'; // internal_unread_total() — Team chat nav badge
require_once __DIR__ . '/../includes/submission-notes.php';  // submission_unread_reply_count() — Submissions nav badge
require_once __DIR__ . '/../includes/attendance-clock.php';  // clock_kiosk_enabled() — gates the Clock nav links
require_once __DIR__ . '/../includes/pos-support.php';       // pos_supported() — gates the Point of Sale nav group
require_once __DIR__ . '/../includes/inventory-support.php'; // inv_supported() — gates the Inventory nav group
require_once __DIR__ . '/../includes/companies.php';         // companies_supported() — gates the Accounting nav group
require_once __DIR__ . '/../includes/admin-nav.php';         // the sidebar + tab-strip definition (one place)
$admin = current_admin();

// ── Role / job aware nav visibility ──────────────────────────────────────
// Owner sees everything; manager gets ops surfaces (scoped); staff see only the
// surface for their job (frontdesk → Front Desk, ops → My Work, security → Gate).
$__isOwner          = is_owner();
$__isManager        = is_manager();
$__isReception      = is_reception();             // front of house: Operations + Bookings + Reservations
$__job              = admin_job();               // null for owner/manager/reception; specialty for staff
$__isOps            = job_is_ops($__job);         // housekeeping / maintenance / gardening / driver
$__isSecurity       = ($__job === 'security');
$__isPosStaff       = job_is_pos($__job);          // shop / spa / kite — they work at the till (/pos/)
$__isStorekeeper    = is_staff() && job_is_store($__job);   // storekeeper — the Inventory pages, scoped
$__isFrontdeskStaff = is_frontdesk_staff();        // frontdesk or job-less staff (every other job is back-of-house)

$__navFrontdesk = $__isOwner || $__isManager || $__isReception || $__isFrontdeskStaff;
$__navConcierge = $__isOwner || $__isManager || $__isReception || $__isFrontdeskStaff;
$__navMessages  = $__isOwner || $__isManager || $__isReception || $__isFrontdeskStaff;  // ops & security get no GUEST messaging
$__navInternal  = true;   // internal team chat — every signed-in account, incl. ops & gate staff
$__navTasks     = $__isOwner || $__isManager || $__isReception;
// admin/timetable.php has no role gate beyond require_login() — it locks a
// STAFF person-filter to themselves internally (never a request param), so
// every job type (ops/security/frontdesk), not just $__navTasks' audience,
// can hold a task and needs to see their own week. Same "everyone" gate as
// $__navInternal, not $__navTasks (which excludes ops/security/frontdesk staff).
$__navTimetable = true;
$__navGate      = $__isOwner || $__isManager || $__isReception || $__isSecurity;
$__navMyWork    = $__isOps   || $__isReception;
// Availability/price assistant — same guest-facing audience as messaging, and
// only when a provider key is configured (feature hides itself otherwise).
$__navAssistant = ($__isOwner || $__isManager || $__isReception || $__isFrontdeskStaff) && ai_assistant_supported();
$__navAiSettings = $__isOwner;   // AI tone/knowledge tuning — site-wide config, owner-only (visible even before a key is set, so it can be prepared)
$__navAiGaps     = $__isOwner || $__isManager;   // AI gaps — questions the guest concierge couldn't answer (read-only list)
$__navBookings  = $__isOwner || $__isReception;   // holds / calendar / submissions / conflicts
$__navReports   = $__isOwner || $__isManager;     // financial reports (scoped to their venues)
$__navPos       = ($__isOwner || $__isManager) && pos_supported();   // POS catalogue/stock/sales (managers scoped to their outlets); outlets = owner
$__navPosTill   = $admin && pos_is_seller($admin);                    // "Open till" + own PIN — anyone who can sell
$__navInventory = can_manage_inventory() && inv_supported();   // Inventory & Assets: owner, managers + storekeepers (scoped to their properties)
$__navCount     = !$__navInventory && inv_supported() && inv_actor_role() !== '';   // the stock-count screen for ops staff — never reception / front desk / gate / till
$__navAccounting = $__isOwner && companies_supported();              // legal companies, KRA PINs, bank accounts — owner-only
$__navAcctDocs   = ($__isOwner || $__isManager) && companies_supported() && to_regclass_exists('acct_documents') && to_regclass_exists('acct_ic_entries');   // invoices & payments (managers scoped)
$__navAcctIc     = $__navAcctDocs && to_regclass_exists('acct_ic_entries');                                            // what the companies owe each other

// Chip shown under the logo for non-owner accounts.
$__roleBadge = $__isManager ? 'Manager' : ($__isReception ? 'Reception' : ($__isStorekeeper ? 'Storekeeper' : (is_staff() ? ucfirst((string)$__job) : '')));

// ── Navigation (includes/admin-nav.php) ──────────────────────────────────
// The flags above are handed to ONE definition that both the sidebar and the
// page's tab strip are rendered from. Clocking in ships dark: the OWNER always
// sees "Clock kiosks" (that page holds the switch); everyone else only once it
// is on. Maya Ilai's rate tool: owner, or a manager scoped to Maya Ilai (venue 6).
$__clockOn = clock_kiosk_enabled();
$__navFlags = [
    'owner' => $__isOwner, 'manager' => $__isManager, 'reception' => $__isReception,
    'frontdesk' => $__navFrontdesk, 'concierge' => $__navConcierge, 'messages' => $__navMessages,
    'internal' => $__navInternal, 'tasks' => $__navTasks, 'timetable' => $__navTimetable,
    'gate' => $__navGate, 'mywork' => $__navMyWork, 'assistant' => $__navAssistant,
    'aiSettings' => $__navAiSettings, 'aiGaps' => $__navAiGaps, 'bookings' => $__navBookings,
    'reports' => $__navReports, 'pos' => $__navPos, 'posTill' => $__navPosTill,
    'inventory' => $__navInventory, 'invOrders' => $__navInventory && inv_orders_supported(), 'count' => $__navCount,
    'accounting' => $__navAccounting, 'acctDocs' => $__navAcctDocs, 'acctIc' => $__navAcctIc,
    'restaurant' => $__isOwner || $__isManager || $__isReception,
    'clockOn' => $__clockOn, 'clockNav' => $__clockOn || $__isOwner,
    'mayaIlai' => $__isOwner || ($__isManager && in_array(6, admin_venue_ids() ?? [], true)),
];
$__navScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
// Unread counts shown on sidebar links and tabs. Each is only queried for an
// account that can see the link. A no-reload page swap (?shell=1) redraws just
// the tab strip, so it asks only for the counts on the strip being shown.
$__navBadges = function (?array $only) use ($__navMessages, $__navInternal, $__navBookings): array {
    $want = fn(string $k): bool => $only === null || in_array($k, $only, true);
    $out = ['messages' => 0, 'team' => 0, 'enquiries' => 0, 'conflicts' => 0];
    if ($want('messages') && $__navMessages && function_exists('count_unread_admin')) $out['messages'] = (int)count_unread_admin(admin_venue_ids());
    if ($want('team') && $__navInternal && function_exists('internal_unread_total')) $out['team'] = (int)internal_unread_total((int)($_SESSION['admin_id'] ?? 0));
    if ($want('enquiries') && $__navBookings && function_exists('submission_unread_reply_count')) $out['enquiries'] = (int)submission_unread_reply_count();
    if ($want('conflicts') && $__navBookings) {
        // Scoped to the account's venues — reception must not see a count covering
        // conflicts it cannot open. null = owner (all venues).
        try {
            $vids = admin_venue_ids();
            if ($vids === null) {
                $out['conflicts'] = (int)db_query("SELECT COUNT(*) FROM channel_conflicts WHERE status='pending'")->fetchColumn();
            } elseif ($vids) {
                $in = implode(',', array_map('intval', $vids));
                $out['conflicts'] = (int)db_query(
                    "SELECT COUNT(*) FROM channel_conflicts c
                       JOIN units u ON u.id = c.unit_id
                       JOIN rooms r ON r.id = u.room_id
                      WHERE c.status='pending' AND r.venue_id IN ($in)"
                )->fetchColumn();
            }
        } catch (\Throwable $e) { /* table may not exist yet on older deploys */ }
    }
    return $out;
};
$__navStripBadges = ['messages.php' => ['messages', 'team'], 'internal-messages.php' => ['messages', 'team'],
                     'gantt.php' => ['conflicts'], 'conflicts.php' => ['conflicts'], 'calendar-highlights.php' => ['conflicts'],
                     'import-bookings.php' => ['conflicts'], 'submissions.php' => ['enquiries'],
                     'submission-view.php' => ['enquiries'], 'submission-trends.php' => ['enquiries']];

// ── No-flicker shell (#18) ────────────────────────────────────────────────
// On a `?shell=1` GET we skip ALL chrome (doctype/head/sidebar/topbar) and just
// buffer the page's content; `_layout_end.php` emits it as a fragment the shell
// swaps into `.admin-content`. Everything above (auth, nav-visibility vars) has
// already run, so the page body renders exactly as it would in a full load.
$__shellFrag = admin_shell_requested();
$__nav = admin_nav_resolve(
    admin_nav_definition($__navFlags, $__navBadges($__shellFrag ? ($__navStripBadges[$__navScript] ?? []) : null)),
    $__navScript
);
$__navTabs = admin_nav_tabs_html($__nav, $__navScript);
if ($__shellFrag) { ob_start(); echo $__navTabs; return; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Admin') ?> — Tribal Sand Admin</title>
  <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?: '1' ?>">
  <link rel="stylesheet" href="/css/datepicker.css?v=<?= @filemtime(__DIR__ . '/../css/datepicker.css') ?: '1' ?>">
  <script defer src="/admin/assets/admin-select.js?v=<?= @filemtime(__DIR__ . '/assets/admin-select.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-table.js?v=<?= @filemtime(__DIR__ . '/assets/admin-table.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-dt-drag.js?v=<?= @filemtime(__DIR__ . '/assets/admin-dt-drag.js') ?: '1' ?>"></script>
  <script defer src="/js/datepicker.js?v=<?= @filemtime(__DIR__ . '/../js/datepicker.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-tip.js?v=<?= @filemtime(__DIR__ . '/assets/admin-tip.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-copy.js?v=<?= @filemtime(__DIR__ . '/assets/admin-copy.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-chat.js?v=<?= @filemtime(__DIR__ . '/assets/admin-chat.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-gallery.js?v=<?= @filemtime(__DIR__ . '/assets/admin-gallery.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-nav.js?v=<?= @filemtime(__DIR__ . '/assets/admin-nav.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-fit.js?v=<?= @filemtime(__DIR__ . '/assets/admin-fit.js') ?: '1' ?>"></script>
</head>
<body class="admin-body">

<!-- Mobile top bar -->
<div class="admin-topbar" id="adminTopbar">
  <button class="admin-topbar__burger" id="sidebarBurger" aria-label="Toggle menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
  <span class="admin-topbar__title">Tribal Sand Admin</span>
</div>

<!-- Sidebar overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="admin-wrap">

  <!-- Sidebar -->
  <aside class="sidebar" id="adminSidebar">
    <div class="sidebar__logo">
      <img src="/images/whitelogo11.png" alt="Tribal Sand">
    </div>
    <?php if (!$__isOwner && ($admin || $__roleBadge)): ?>
    <div style="padding:8px 12px;font-size:12px;color:#9ca3af"><?= e($admin['name'] ?? ($__isManager ? 'Manager' : ($__isReception ? 'Reception' : 'Staff'))) ?> <?php if ($__roleBadge): ?><span class="badge <?= $__isManager ? 'badge--green' : ($__isReception ? 'badge--orange' : 'badge--blue') ?>" style="font-size:10px"><?= e($__roleBadge) ?></span><?php endif; ?></div>
    <?php endif; ?>
    <nav class="sidebar__nav">
      <?= admin_nav_sidebar_html($__nav) ?>
    </nav>
    <script>
    /* Nav groups: remember what each person opened or closed; the group holding
       the current page is always open so the page is never hidden in a closed one. */
    (function () {
      // Bump the key whenever the groups or their defaults change, so nobody keeps
      // a remembered state for a layout that no longer exists.
      var KEY = 'ts_nav_v4', saved = {};
      try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
      var restoring = true;
      document.querySelectorAll('.navgroup[data-group]').forEach(function (g) {
        if (g.querySelector('.sidebar__link.is-active')) { g.open = true; return; }
        var k = g.getAttribute('data-group');
        if (typeof saved[k] === 'boolean') g.open = saved[k];
      });
      setTimeout(function () { restoring = false; }, 0);   // toggle events from the restore above must not be saved
      document.addEventListener('toggle', function (e) {
        var g = e.target;
        if (restoring || !g.classList || !g.classList.contains('navgroup')) return;
        if (g.dataset.autoOpen) { delete g.dataset.autoOpen; return; }   // opened by navigation, not by the person
        saved[g.getAttribute('data-group')] = g.open;
        try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
      }, true);
    })();
    </script>
    <div class="sidebar__footer">
      <span><?= e($admin['email'] ?? '') ?></span>
      <a href="/admin/logout.php">Sign out</a>
    </div>
  </aside>

  <!-- Main content -->
  <main class="admin-main">
    <div class="admin-content">
<?= $__navTabs ?>
