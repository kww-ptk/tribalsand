<?php
// Admin shared layout — include at top of each admin page after require_login()
// Sets: $pageTitle (required), $activeMenu (required)
$admin = current_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Admin') ?> — Tribal Sand Admin</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
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
    <nav class="sidebar__nav">
      <a href="/admin/dashboard.php"    class="sidebar__link <?= ($activeMenu??'')==='dashboard'    ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        Dashboard
      </a>
      <a href="/admin/tours.php"        class="sidebar__link <?= ($activeMenu??'')==='tours'        ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h1m17 0h-1M5.6 5.6l.7.7m11.4-.7-.7.7M12 3v1m0 17v-1M7 17l-2 2m14-2 2 2"/><circle cx="12" cy="12" r="4"/></svg>
        Tours
      </a>
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
      <a href="/admin/gantt.php"         class="sidebar__link <?= ($activeMenu??'')==='gantt'         ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="4" x2="8" y2="10"/><line x1="16" y1="4" x2="16" y2="10"/><line x1="7" y1="15" x2="13" y2="15"/><line x1="7" y1="18" x2="11" y2="18"/></svg>
        Calendar
      </a>
      <a href="/admin/holds.php"        class="sidebar__link <?= ($activeMenu??'')==='holds'        ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Holds
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
      <a href="/admin/audit.php"        class="sidebar__link <?= ($activeMenu??'')==='audit'        ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        Audit Log
      </a>
      <a href="/admin/settings.php"     class="sidebar__link <?= ($activeMenu??'')==='settings'     ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Settings
      </a>
    </nav>
    <div class="sidebar__footer">
      <span><?= e($admin['email'] ?? '') ?></span>
      <a href="/admin/logout.php">Sign out</a>
    </div>
  </aside>

  <!-- Main content -->
  <main class="admin-main">
    <div class="admin-content">
