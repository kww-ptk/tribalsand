<?php
// Admin shared layout — include at top of each admin page after require_login()
// Sets: $pageTitle (required), $activeMenu (required)
require_once __DIR__ . '/../includes/icons.php';       // admin_icon() for icon-only buttons
require_once __DIR__ . '/../includes/admin-shell.php'; // no-flicker shell (#18)
$admin = current_admin();

// ── Role / job aware nav visibility ──────────────────────────────────────
// Owner sees everything; manager gets ops surfaces (scoped); staff see only the
// surface for their job (frontdesk → Front Desk, ops → My Work, security → Gate).
$__isOwner          = is_owner();
$__isManager        = is_manager();
$__job              = admin_job();               // null for owner/manager; specialty for staff
$__isOps            = job_is_ops($__job);         // housekeeping / maintenance / gardening / driver
$__isSecurity       = ($__job === 'security');
$__isFrontdeskStaff = is_staff() && !$__isOps && !$__isSecurity;   // frontdesk or job-less staff

$__navFrontdesk = $__isOwner || $__isManager || $__isFrontdeskStaff;
$__navConcierge = $__isOwner || $__isManager || $__isFrontdeskStaff;
$__navMessages  = $__isOwner || $__isManager || $__isFrontdeskStaff;  // ops & security get no messaging
$__navTasks     = $__isOwner || $__isManager;
$__navGate      = $__isOwner || $__isManager || $__isSecurity;
$__navMyWork    = $__isOps;

// Chip shown under the logo for non-owner accounts.
$__roleBadge = $__isManager ? 'Manager' : (is_staff() ? ucfirst((string)$__job) : '');

// ── No-flicker shell (#18) ────────────────────────────────────────────────
// On a `?shell=1` GET we skip ALL chrome (doctype/head/sidebar/topbar) and just
// buffer the page's content; `_layout_end.php` emits it as a fragment the shell
// swaps into `.admin-content`. Everything above (auth, nav-visibility vars) has
// already run, so the page body renders exactly as it would in a full load.
$__shellFrag = admin_shell_requested();
if ($__shellFrag) { ob_start(); return; }
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
    <div style="padding:8px 12px;font-size:12px;color:#9ca3af"><?= e($admin['name'] ?? ($__isManager ? 'Manager' : 'Staff')) ?> <?php if ($__roleBadge): ?><span class="badge <?= $__isManager ? 'badge--green' : 'badge--blue' ?>" style="font-size:10px"><?= e($__roleBadge) ?></span><?php endif; ?></div>
    <?php endif; ?>
    <nav class="sidebar__nav">
      <?php
      // Collapsible nav groups (#17). Each group's links are buffered; the group
      // header + disclosure is emitted only if ≥1 link is visible for this role,
      // so role-limited accounts never see an empty section. Native <details> for
      // free accessible toggling; a small script below restores collapsed state
      // from localStorage and always keeps the active page's group open.
      $__chev = '<svg class="navgroup__chev" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';
      $__navgroup = function (string $key, string $title, string $items) use ($__chev) {
          if (trim($items) === '') return;
          // Only Operations is open by default; every other group starts closed.
          // (A user can expand/collapse; a collapse of Operations is remembered.)
          $open = ($key === 'operations') ? ' open' : '';
          echo '<details class="navgroup" data-group="' . e($key) . '"' . $open . '>'
             . '<summary class="navgroup__head"><span>' . e($title) . '</span>' . $__chev . '</summary>'
             . '<div class="navgroup__items">' . $items . '</div>'
             . '</details>';
      };
      ?>

      <?php ob_start(); ?>
        <?php if ($__navFrontdesk): ?>
        <a href="/admin/frontdesk.php"    class="sidebar__link <?= ($activeMenu??'')==='frontdesk'    ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 3v3h6V3M8 11h8M8 15h5"/></svg>
          Front desk
        </a>
        <?php endif; ?>
        <?php if ($__navMyWork): ?>
        <a href="/admin/mywork.php"       class="sidebar__link <?= ($activeMenu??'')==='mywork'       ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          My work
        </a>
        <?php endif; ?>
        <?php if ($__navConcierge): ?>
        <a href="/admin/concierge-desk.php" class="sidebar__link <?= ($activeMenu??'')==='concierge_desk' ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h16"/><path d="M5 19v-4a7 7 0 0 1 14 0v4"/><line x1="12" y1="5" x2="12" y2="8"/></svg>
          Concierge desk
        </a>
        <?php endif; ?>
        <?php if ($__navMessages): ?>
        <a href="/admin/messages.php"     class="sidebar__link <?= ($activeMenu??'')==='messages'     ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          Messages<?php $u=function_exists('count_unread_admin')?count_unread_admin(admin_venue_ids()):0; if($u>0): ?> <span class="badge badge--orange" style="margin-left:6px"><?= (int)$u ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if ($__navTasks): ?>
        <a href="/admin/tasks.php"        class="sidebar__link <?= ($activeMenu??'')==='tasks'        ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 13l2 2 4-4"/></svg>
          Tasks
        </a>
        <?php endif; ?>
        <?php if ($__navGate): ?>
        <a href="/admin/gate.php"         class="sidebar__link <?= ($activeMenu??'')==='gate'         ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
          Gate
        </a>
        <?php endif; ?>
      <?php $__navgroup('operations', 'Operations', ob_get_clean()); ?>

      <?php if ($__isOwner): ?>
      <?php ob_start(); ?>
        <a href="/admin/holds.php"        class="sidebar__link <?= ($activeMenu??'')==='holds'        ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Holds
        </a>
        <a href="/admin/gantt.php"         class="sidebar__link <?= ($activeMenu??'')==='gantt'         ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="4" x2="8" y2="10"/><line x1="16" y1="4" x2="16" y2="10"/><line x1="7" y1="15" x2="13" y2="15"/><line x1="7" y1="18" x2="11" y2="18"/></svg>
          Calendar
        </a>
        <a href="/admin/submissions.php"  class="sidebar__link <?= ($activeMenu??'')==='submissions'  ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 9v11"/></svg>
          Submissions
        </a>
        <?php
          $__conflict_count = 0;
          try {
            $__conflict_count = (int)db_query("SELECT COUNT(*) FROM channel_conflicts WHERE status='pending'")->fetchColumn();
          } catch (\Throwable $e) { /* table may not exist yet on older deploys */ }
        ?>
        <a href="/admin/conflicts.php"    class="sidebar__link <?= ($activeMenu??'')==='conflicts'    ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Conflicts<?php if ($__conflict_count > 0): ?> <span style="background:#dc2626;color:#fff;font-size:10px;padding:1px 5px;border-radius:8px;margin-left:4px;font-weight:700"><?= $__conflict_count ?></span><?php endif; ?>
        </a>
      <?php $__navgroup('bookings', 'Bookings', ob_get_clean()); ?>

      <?php ob_start(); ?>
        <a href="/admin/rooms.php"        class="sidebar__link <?= ($activeMenu??'')==='rooms'        ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18V8h13a5 5 0 0 1 5 5v5M3 14h18M3 18v2M21 18v2"/><path d="M6 12h4a2 2 0 0 1 2 2"/></svg>
          Rooms
        </a>
        <a href="/admin/venues.php"       class="sidebar__link <?= ($activeMenu??'')==='venues'       ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Properties
        </a>
        <a href="/admin/properties.php"   class="sidebar__link <?= ($activeMenu??'')==='properties'   ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 21v-6h6v6"/></svg>
          For Sale Listings
        </a>
        <a href="/admin/tours.php"        class="sidebar__link <?= ($activeMenu??'')==='tours'        ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h1m17 0h-1M5.6 5.6l.7.7m11.4-.7-.7.7M12 3v1m0 17v-1M7 17l-2 2m14-2 2 2"/><circle cx="12" cy="12" r="4"/></svg>
          Tours
        </a>
        <a href="/admin/services.php"     class="sidebar__link <?= ($activeMenu??'')==='services'     ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="1.6" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="8" cy="18" r="1.6" fill="currentColor" stroke="none"/></svg>
          Service pricing
        </a>
      <?php $__navgroup('catalog', 'Catalog', ob_get_clean()); ?>

      <?php ob_start(); ?>
        <a href="/admin/dashboard.php"    class="sidebar__link <?= ($activeMenu??'')==='dashboard'    ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          Dashboard
        </a>
        <a href="/admin/staff.php" class="sidebar__link <?= ($activeMenu??'')==='staff' ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Staff
        </a>
        <a href="/admin/settings.php"     class="sidebar__link <?= ($activeMenu??'')==='settings'     ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </a>
        <a href="/admin/audit.php"        class="sidebar__link <?= ($activeMenu??'')==='audit'        ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Audit Log
        </a>
        <a href="/admin/guest-board.php"  class="sidebar__link <?= ($activeMenu??'')==='guest_board'  ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="14" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="7" y1="13" x2="13" y2="13"/></svg>
          Guest board
        </a>
      <?php $__navgroup('admin', 'Admin', ob_get_clean()); ?>
      <?php endif; ?>
    </nav>
    <script>
    /* Nav groups (#17): restore collapsed state; keep the active group open. */
    (function () {
      var KEY = 'ts_nav_v2', saved = {};   // v2: discards the old always-restore state
      try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
      // Default (server-rendered): only Operations open, others closed. On load we
      // honor a remembered COLLAPSE only — expanding another group never persists,
      // so every group but Operations is closed by default on a fresh load.
      document.querySelectorAll('.navgroup[data-group]').forEach(function (g) {
        if (saved[g.getAttribute('data-group')] === true) g.open = false;
      });
      document.addEventListener('toggle', function (e) {
        var g = e.target;
        if (!g.classList || !g.classList.contains('navgroup')) return;
        saved[g.getAttribute('data-group')] = !g.open;   // remember collapsed = true
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
