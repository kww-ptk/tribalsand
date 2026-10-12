<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/menu.php';
require_once __DIR__ . '/../includes/menu-sync.php';   // outbox hooks + soft deletes (Zuri sync)
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
        menu_sync_tx(function () use ($val, $mid) {
            db_query("UPDATE menus SET is_published = {$val}, updated_at = NOW() WHERE id = :id", [':id' => $mid]);
            menu_sync_emit('menu', $mid, 'update');
        });
        audit_log('menu.publish', 'menu', $mid, $menu['title']);
    }
    if ($allowed && isset($_POST['delete_menu'])) {
        menu_delete_menu($mid);   // soft delete once synced; hard delete (cascade) pre-migration
        audit_log('menu.delete', 'menu', $mid, $menu['title']);
    }
    header('Location: /admin/menus.php');
    exit;
}

$menus = fetch_menus($scope);

// Inline "New menu" form (posts to menu-edit.php's create path, which opens the new
// menu for its categories and items; a refused create comes back here with the error).
$newFlash = $_SESSION['menu_new_flash'] ?? null;
unset($_SESSION['menu_new_flash']);
$newOld   = is_array($newFlash['old'] ?? null) ? $newFlash['old'] : [];
$newOpen  = isset($_GET['new']) || $newFlash;
$venueRows = ($scope === null)
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll()
    : ($scope ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ") ORDER BY sort_order, name")->fetchAll() : []);

$pageTitle  = 'Menus';
$activeMenu = 'menus';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Restaurant Menus</h1>
  <button type="button" class="btn-primary btn-sm" id="newMenuBtn" aria-controls="newMenu" aria-expanded="<?= $newOpen ? 'true' : 'false' ?>"><?= admin_icon('plus', 15) ?> New Menu</button>
</div>

<?php if (!empty($newFlash['error'])): ?><div class="alert alert--error is-flash"><?= e($newFlash['error']) ?></div><?php endif; ?>

<div class="card" id="newMenu" data-inline-form style="margin-bottom:20px"<?= $newOpen ? '' : ' hidden' ?>>
  <div class="card__head"><span class="card__title">New menu</span></div>
  <div class="card__body" style="padding:20px">
    <form method="POST" action="/admin/menu-edit.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_menu">
      <div class="form-row">
        <div class="field">
          <label for="nmTitle">Title</label>
          <input type="text" id="nmTitle" name="title" value="<?= e((string)($newOld['title'] ?? '')) ?>" required placeholder="e.g. Zuri">
        </div>
        <div class="field">
          <label for="nmSlug">URL slug <span class="text-muted">(/menu.php?m=…)</span></label>
          <input type="text" id="nmSlug" name="slug" value="<?= e((string)($newOld['slug'] ?? '')) ?>" placeholder="Made from the title if left blank">
        </div>
      </div>
      <div class="form-row" style="margin-top:14px">
        <div class="field">
          <label for="nmVenue">Property</label>
          <select id="nmVenue" name="venue_id">
            <?php if ($scope === null): ?><option value="">— none —</option><?php endif; ?>
            <?php foreach ($venueRows as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (int)($newOld['venue_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="nmSub">Subtitle</label>
          <input type="text" id="nmSub" name="subtitle" value="<?= e((string)($newOld['subtitle'] ?? '')) ?>" placeholder="e.g. Boutique Hotel · Watamu">
        </div>
      </div>
      <label class="toggle-row" style="margin-top:14px">
        <input type="checkbox" name="is_published" value="1" <?= ($newFlash === null || !empty($newOld['is_published'])) ? 'checked' : '' ?>>
        <span>Published (visible on the site)</span>
      </label>
      <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button type="submit" class="btn-primary btn-sm">Create menu</button>
        <span class="text-muted" style="font-size:12.5px">Next you add its categories and dishes.</span>
      </div>
    </form>
  </div>
</div>
<script>
  (function () {
    var b = document.getElementById('newMenuBtn'), c = document.getElementById('newMenu');
    if (!b || !c || b.dataset.ready) return; b.dataset.ready = '1';
    b.addEventListener('click', function () {
      var open = c.hidden;
      c.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var t = document.getElementById('nmTitle'); if (t) t.focus(); }
    });
  })();
</script>

<div class="card">
  <div class="card__head">
    <span class="card__title">All Menus</span>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$menus): ?>
    <div style="text-align:center;padding:2.5rem;color:var(--muted)">
      No menus yet. Press <strong>New Menu</strong> to create the first one.
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
