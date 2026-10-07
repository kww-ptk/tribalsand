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
require_once __DIR__ . '/../includes/help-guides.php';       // Help & guides — on-page panel + library
$admin = current_admin();

// ── Role / job aware nav visibility ──────────────────────────────────────
// The DEFAULTS come from ONE pure rule set, admin_nav_flags() (includes/admin-nav.php)
// — the same one the Staff page's "Access by role" tab shows. The owner's choices
// there (includes/access.php) then switch individual sections on or off per role,
// applied by $__navAccess below when the sidebar is resolved.
$__isOwner     = is_owner();
$__isManager   = is_manager();
$__isReception = is_reception();
$__job         = admin_job();               // null for owner/manager/reception; specialty for staff
$__isStorekeeper = is_staff() && job_is_store($__job);
$__navFlags    = admin_nav_flags(admin_role(), $__job, access_env($admin ?: []));
$__roleKey     = access_role_key(admin_role(), $__job);
$__navAccess   = $__isOwner ? null : (function () use ($__roleKey): callable {
    $matrix = access_matrix(); $sections = access_sections();
    return function (array $tab, bool $default) use ($matrix, $sections, $__roleKey): bool {
        $s = $sections[access_tab_key($tab)] ?? null;
        return $s ? access_resolve($matrix, $__roleKey, $s, $default) : $default;
    };
})();
// A section's effective visibility for this account (default, or the owner's choice).
$__navOn = function (string $flag, string $section) use ($__navFlags, $__navAccess): bool {
    $default = !empty($__navFlags[$flag]);
    return $__navAccess ? $__navAccess(['pages' => [$section]], $default) : $default;
};
$__navMessages = $__navOn('messages', 'messages.php');
$__navInternal = $__navOn('internal', 'internal-messages.php');
$__navBookings = $__navOn('bookings', 'submissions.php') || $__navOn('bookings', 'gantt.php');

// Chip shown under the logo for non-owner accounts.
$__roleBadge = $__isManager ? 'Manager' : (is_hr() ? 'HR' : ($__isReception ? 'Reception' : ($__isStorekeeper ? 'Storekeeper' : (is_staff() ? ucfirst((string)$__job) : ''))));

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
    $__navScript,
    $__navAccess
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
  <script defer src="/admin/assets/admin-search.js?v=<?= @filemtime(__DIR__ . '/assets/admin-search.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-help.js?v=<?= @filemtime(__DIR__ . '/assets/admin-help.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-password.js?v=<?= @filemtime(__DIR__ . '/assets/admin-password.js') ?: '1' ?>"></script>
  <script defer src="/admin/assets/admin-fit.js?v=<?= @filemtime(__DIR__ . '/assets/admin-fit.js') ?: '1' ?>"></script>
</head>
<body class="admin-body">

<!-- Mobile top bar -->
<div class="admin-topbar" id="adminTopbar">
  <button class="admin-topbar__burger" id="sidebarBurger" aria-label="Toggle menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
  <span class="admin-topbar__title">Tribal Sand Admin</span>
  <button type="button" class="admin-topbar__search" data-navsearch-open aria-label="Search pages"><?= admin_icon('search', 20) ?></button>
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
    <?= admin_nav_search_button_html() ?>
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
  <?php $__helpGuides = help_visible_guides(help_guides(), help_visible_pages($__nav)); ?>
  <?= admin_nav_search_html($__nav, help_search_items($__helpGuides)) ?>
  <?= help_drawer_html($__helpGuides) ?>

  <!-- Main content -->
  <main class="admin-main">
    <div class="admin-content">
<?= $__navTabs ?>
