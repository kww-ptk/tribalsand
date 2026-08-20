<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/menu.php';
require_login();
require_manager();   // owner or house manager

$scope = admin_venue_ids();   // null = owner (all); array = manager's venues

// ── POST: publish toggle / delete (scoped) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mid  = (int)($_POST['menu_id'] ?? 0);
    $menu = fetch_menu($mid);
    // A manager may only act on menus for venues in their scope.
    $allowed = $menu && ($scope === null || in_array((int)$menu['venue_id'], $scope, true));

    if ($allowed && isset($_POST['toggle_publish'])) {
        $val = $_POST['is_published'] === '1' ? 'FALSE' : 'TRUE';
        db_query("UPDATE menus SET is_published = {$val}, updated_at = NOW() WHERE id = :id", [':id' => $mid]);
        audit_log('menu.publish', 'menu', $mid, $menu['title']);
    }
    if ($allowed && isset($_POST['delete_menu'])) {
        db_query('DELETE FROM menus WHERE id = :id', [':id' => $mid]);   // cascade removes categories + items
        audit_log('menu.delete', 'menu', $mid, $menu['title']);
    }
    header('Location: /admin/menus.php');
    exit;
}

$menus = fetch_menus($scope);

$pageTitle  = 'Menus';
$activeMenu = 'menus';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Restaurant Menus</h1>
  <a href="/admin/menu-edit.php" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> New Menu</a>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">All Menus</span>
    <span class="text-muted" style="font-size:12px">Edits here are live on the site — hidden categories and unavailable items don’t show publicly.</span>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$menus): ?>
    <div style="text-align:center;padding:2.5rem;color:var(--muted)">
      No menus yet. <a href="/admin/menu-edit.php">Create your first menu →</a>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Menu</th>
            <th>Property</th>
            <th>Categories</th>
            <th>Items</th>
            <th>Published</th>
            <th style="width:1%;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($menus as $m): ?>
          <tr>
            <td>
              <strong><?= e($m['title']) ?></strong>
              <?php if ($m['subtitle']): ?><div class="text-muted" style="font-size:12px"><?= e($m['subtitle']) ?></div><?php endif; ?>
            </td>
            <td class="text-muted"><?= $m['venue_name'] ? e($m['venue_name']) : '<span class="badge badge--orange">unassigned</span>' ?></td>
            <td><?= (int)$m['category_count'] ?></td>
            <td><?= (int)$m['item_count'] ?></td>
            <td>
              <form method="POST" action="/admin/menus.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_publish" value="1">
                <input type="hidden" name="menu_id" value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="is_published" value="<?= $m['is_published'] ? '1' : '0' ?>">
                <button type="submit" class="badge <?= $m['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                  <?= $m['is_published'] ? 'Live' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td style="text-align:right">
              <span class="dt-actions">
                <a href="/admin/menu-edit.php?id=<?= (int)$m['id'] ?>" class="btn-icon btn-icon--outline" title="Edit" aria-label="Edit"><?= admin_icon('edit') ?></a>
                <a href="/menu.php?m=<?= e($m['slug']) ?>" class="btn-icon btn-icon--outline" title="View live" aria-label="View live" target="_blank" rel="noopener"><?= admin_icon('external-link') ?></a>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
