<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/menu.php';
require_login();
require_manager();   // owner or house manager

$scope = admin_venue_ids();   // null = owner (all venues); array = manager's venues

/** A menu is editable if the user is owner, or its venue is in the manager's scope. */
function menu_editable(?array $menu, ?array $scope): bool {
    if (!$menu) return false;
    return $scope === null || in_array((int)$menu['venue_id'], $scope, true);
}
/** Slug from free text: lowercase, hyphenated, url-safe. */
function menu_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}
/** Move a row up/down among siblings sharing $scopeCol=$scopeVal, then renumber. */
function menu_move(string $table, string $scopeCol, int $scopeVal, int $rowId, int $dir): void {
    $rows = db_query("SELECT id FROM {$table} WHERE {$scopeCol} = :s ORDER BY sort_order, id", [':s' => $scopeVal])
        ->fetchAll(PDO::FETCH_COLUMN);
    $rows = array_map('intval', $rows);
    $idx  = array_search($rowId, $rows, true);
    if ($idx === false) return;
    $swap = $idx + $dir;
    if ($swap < 0 || $swap >= count($rows)) return;
    [$rows[$idx], $rows[$swap]] = [$rows[$swap], $rows[$idx]];
    foreach ($rows as $o => $rid) db_query("UPDATE {$table} SET sort_order = :o WHERE id = :id", [':o' => $o, ':id' => $rid]);
}
/** The 7 badge columns pulled from a posted form. */
function menu_posted_badges(): array {
    $keys = ['is_veg','is_vegan','is_spicy','has_nuts','has_gluten','is_gf','is_signature'];
    $out  = [];
    foreach ($keys as $k) $out[$k] = isset($_POST[$k]) ? 'TRUE' : 'FALSE';
    return $out;
}

$id    = (int)($_GET['id'] ?? 0);
$menu  = $id ? fetch_menu($id) : null;
$isNew = !$menu;

// Guard: editing an existing menu you don't own → bounce.
if ($menu && !menu_editable($menu, $scope)) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'That menu belongs to another property.'];
    header('Location: /admin/menus.php');
    exit;
}

// Venues this user may assign a menu to.
$venueRows = ($scope === null)
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll()
    : ($scope ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ") ORDER BY sort_order, name")->fetchAll() : []);

$success = '';
$error   = '';

/** True if $catId belongs to the menu being edited. */
$catOwned  = fn(int $catId) => $catId > 0 && (int)(db_query("SELECT menu_id FROM menu_categories WHERE id = :c", [':c' => $catId])->fetchColumn() ?: 0) === $id;
/** True if $itemId's category belongs to the menu being edited; returns its category_id or 0. */
$itemCat   = fn(int $itemId) => (int)(db_query("SELECT c.id FROM menu_items i JOIN menu_categories c ON c.id = i.category_id WHERE i.id = :i AND c.menu_id = :m", [':i' => $itemId, ':m' => $id])->fetchColumn() ?: 0);

// ── POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    // ---- Menu meta (create / update) ----
    if ($action === 'save_menu') {
        $title = trim($_POST['title'] ?? '');
        $slug  = menu_slugify($_POST['slug'] ?? $title);
        $venId = (int)($_POST['venue_id'] ?? 0) ?: null;

        // Managers can only attach to a venue in their scope.
        if ($scope !== null && ($venId === null || !in_array($venId, $scope, true))) {
            $error = 'Please choose one of your properties for this menu.';
        } elseif ($title === '' || $slug === '') {
            $error = 'A title and URL slug are required.';
        } else {
            // Slug must be unique across menus.
            $clash = db_query("SELECT id FROM menus WHERE slug = :s AND id <> :id", [':s' => $slug, ':id' => $id])->fetchColumn();
            if ($clash) { $error = 'That URL slug is already used by another menu.'; }
        }

        if (!$error) {
            $data = [
                ':venue' => $venId, ':slug' => $slug, ':title' => $title,
                ':subtitle' => trim($_POST['subtitle'] ?? ''),
                ':tagline'  => trim($_POST['tagline'] ?? ''),
                ':location' => trim($_POST['location_label'] ?? ''),
                ':footer'   => trim($_POST['footer_note'] ?? ''),
                ':cur'      => trim($_POST['currency_label'] ?? 'Kes') ?: 'Kes',
                ':pub'      => isset($_POST['is_published']) ? 'TRUE' : 'FALSE',
            ];
            if ($isNew) {
                db_query(
                    "INSERT INTO menus (venue_id,slug,title,subtitle,tagline,location_label,footer_note,currency_label,is_published,sort_order)
                     VALUES (:venue,:slug,:title,:subtitle,:tagline,:location,:footer,:cur,:pub,
                             (SELECT COALESCE(MAX(sort_order),0)+1 FROM menus))",
                    $data
                );
                $id = (int)db()->lastInsertId();
                audit_log('menu.create', 'menu', $id, $title);
                header("Location: /admin/menu-edit.php?id={$id}&saved=1");
                exit;
            }
            $data[':id'] = $id;
            db_query(
                "UPDATE menus SET venue_id=:venue,slug=:slug,title=:title,subtitle=:subtitle,tagline=:tagline,
                    location_label=:location,footer_note=:footer,currency_label=:cur,is_published=:pub,updated_at=NOW()
                 WHERE id=:id",
                $data
            );
            audit_log('menu.save', 'menu', $id, $title);
            $success = 'Menu details saved.';
        }
    }

    if ($action === 'delete_menu' && !$isNew) {
        db_query('DELETE FROM menus WHERE id = :id', [':id' => $id]);
        audit_log('menu.delete', 'menu', $id, $menu['title'] ?? '');
        header('Location: /admin/menus.php');
        exit;
    }

    // ---- Categories ----
    if ($action === 'add_category' && !$isNew) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            db_query(
                "INSERT INTO menu_categories (menu_id,section,name,tag,icon,sort_order)
                 VALUES (:m,:sec,:n,:tag,:ic,(SELECT COALESCE(MAX(sort_order),0)+1 FROM menu_categories WHERE menu_id=:m))",
                [':m'=>$id, ':sec'=>($_POST['section']==='drinks'?'drinks':'food'), ':n'=>$name,
                 ':tag'=>trim($_POST['tag']??''), ':ic'=>trim($_POST['icon']??'')]
            );
            $success = 'Category added.';
        } else { $error = 'Category name is required.'; }
    }
    if ($action === 'save_category' && $catOwned((int)($_POST['cat_id'] ?? 0))) {
        db_query(
            "UPDATE menu_categories SET section=:sec,name=:n,tag=:tag,icon=:ic,is_visible=:vis WHERE id=:c",
            [':sec'=>($_POST['section']==='drinks'?'drinks':'food'), ':n'=>trim($_POST['name']??''),
             ':tag'=>trim($_POST['tag']??''), ':ic'=>trim($_POST['icon']??''),
             ':vis'=>isset($_POST['is_visible'])?'TRUE':'FALSE', ':c'=>(int)$_POST['cat_id']]
        );
        $success = 'Category saved.';
    }
    if ($action === 'delete_category' && $catOwned((int)($_POST['cat_id'] ?? 0))) {
        db_query('DELETE FROM menu_categories WHERE id = :c', [':c'=>(int)$_POST['cat_id']]);
        $success = 'Category deleted.';
    }
    if (($action === 'cat_up' || $action === 'cat_down') && $catOwned((int)($_POST['cat_id'] ?? 0))) {
        menu_move('menu_categories', 'menu_id', $id, (int)$_POST['cat_id'], $action === 'cat_up' ? -1 : 1);
    }

    // ---- Items ----
    if ($action === 'add_item' && $catOwned((int)($_POST['cat_id'] ?? 0))) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $b = menu_posted_badges();
            db_query(
                "INSERT INTO menu_items (category_id,name,description,price,is_veg,is_vegan,is_spicy,has_nuts,has_gluten,is_gf,is_signature,is_available,sort_order)
                 VALUES (:c,:n,:d,:p,:veg,:vgn,:sp,:nut,:glu,:gf,:sig,:av,(SELECT COALESCE(MAX(sort_order),0)+1 FROM menu_items WHERE category_id=:c))",
                [':c'=>(int)$_POST['cat_id'], ':n'=>$name, ':d'=>trim($_POST['description']??''),
                 ':p'=>($_POST['price']??'')===''?null:(float)$_POST['price'],
                 ':veg'=>$b['is_veg'], ':vgn'=>$b['is_vegan'], ':sp'=>$b['is_spicy'], ':nut'=>$b['has_nuts'],
                 ':glu'=>$b['has_gluten'], ':gf'=>$b['is_gf'], ':sig'=>$b['is_signature'],
                 ':av'=>isset($_POST['is_available'])?'TRUE':'FALSE']
            );
            $success = 'Item added.';
        } else { $error = 'Item name is required.'; }
    }
    if ($action === 'save_item' && $itemCat((int)($_POST['item_id'] ?? 0))) {
        $b = menu_posted_badges();
        db_query(
            "UPDATE menu_items SET name=:n,description=:d,price=:p,is_veg=:veg,is_vegan=:vgn,is_spicy=:sp,
                has_nuts=:nut,has_gluten=:glu,is_gf=:gf,is_signature=:sig,is_available=:av WHERE id=:i",
            [':n'=>trim($_POST['name']??''), ':d'=>trim($_POST['description']??''),
             ':p'=>($_POST['price']??'')===''?null:(float)$_POST['price'],
             ':veg'=>$b['is_veg'], ':vgn'=>$b['is_vegan'], ':sp'=>$b['is_spicy'], ':nut'=>$b['has_nuts'],
             ':glu'=>$b['has_gluten'], ':gf'=>$b['is_gf'], ':sig'=>$b['is_signature'],
             ':av'=>isset($_POST['is_available'])?'TRUE':'FALSE', ':i'=>(int)$_POST['item_id']]
        );
        $success = 'Item saved.';
    }
    if ($action === 'toggle_item' && $itemCat((int)($_POST['item_id'] ?? 0))) {
        db_query('UPDATE menu_items SET is_available = NOT is_available WHERE id = :i', [':i'=>(int)$_POST['item_id']]);
    }
    if ($action === 'delete_item' && $itemCat((int)($_POST['item_id'] ?? 0))) {
        db_query('DELETE FROM menu_items WHERE id = :i', [':i'=>(int)$_POST['item_id']]);
        $success = 'Item deleted.';
    }
    if (($action === 'item_up' || $action === 'item_down')) {
        $cid = $itemCat((int)($_POST['item_id'] ?? 0));
        if ($cid) menu_move('menu_items', 'category_id', $cid, (int)$_POST['item_id'], $action === 'item_up' ? -1 : 1);
    }

    // Reload menu after any mutation (unless we redirected).
    if ($id) $menu = fetch_menu($id);
    $isNew = !$menu;
}

if (isset($_GET['saved'])) $success = 'Menu created — now add categories and items below.';

$cats = (!$isNew) ? fetch_menu_categories($id, true) : [];   // admin view: all cats + items

$pageTitle  = $isNew ? 'New Menu' : 'Edit: ' . ($menu['title'] ?? '');
$activeMenu = 'menus';
include __DIR__ . '/_layout.php';

/** Render the badge checkboxes for an item form. $it may be null (new). */
function menu_badge_checkboxes(?array $it): void {
    foreach (menu_badge_defs() as $col => [$cls, $label]) {
        $on = $it && !empty($it[$col]) && $it[$col] !== 'f';
        echo '<label style="display:inline-flex;align-items:center;gap:5px;margin:0 12px 6px 0;font-size:13px">'
           . '<input type="checkbox" name="' . $col . '" value="1"' . ($on ? ' checked' : '') . '> ' . e($label) . '</label>';
    }
}
?>

<div class="page-header">
  <h1><?= $isNew ? 'New Menu' : e($menu['title']) ?></h1>
  <div class="actions">
    <a href="/admin/menus.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Menus</a>
    <?php if (!$isNew): ?>
    <a href="/menu.php?m=<?= e($menu['slug']) ?>" class="btn-outline btn-sm" target="_blank" rel="noopener">View live</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<!-- ── Menu details ── -->
<form method="POST" action="/admin/menu-edit.php<?= $id ? "?id={$id}" : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_menu">
  <div class="card">
    <div class="card__head"><span class="card__title">Menu details</span></div>
    <div class="card__body" style="padding:20px">
      <div class="form-row">
        <div class="field">
          <label>Title</label>
          <input type="text" name="title" value="<?= e($menu['title'] ?? '') ?>" required placeholder="e.g. Zuri">
        </div>
        <div class="field">
          <label>URL slug <span class="text-muted">(/menu.php?m=…)</span></label>
          <input type="text" name="slug" value="<?= e($menu['slug'] ?? '') ?>" placeholder="e.g. zuri" <?= $isNew ? '' : 'required' ?>>
          <span class="field-hint">Lowercase, hyphens. Changing this breaks old links/QR codes.</span>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Property</label>
          <select name="venue_id">
            <option value="">— none —</option>
            <?php foreach ($venueRows as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (int)($menu['venue_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Subtitle</label>
          <input type="text" name="subtitle" value="<?= e($menu['subtitle'] ?? '') ?>" placeholder="e.g. Boutique Hotel · Watamu">
        </div>
      </div>
      <div class="field" style="margin-top:14px">
        <label>Tagline <span class="text-muted">(italic line under the title)</span></label>
        <input type="text" name="tagline" value="<?= e($menu['tagline'] ?? '') ?>" placeholder="A short line about the kitchen.">
      </div>
      <div class="form-row" style="margin-top:14px">
        <div class="field">
          <label>Location label</label>
          <input type="text" name="location_label" value="<?= e($menu['location_label'] ?? '') ?>" placeholder="📍 Watamu · Kenya's North Coast">
        </div>
        <div class="field">
          <label>Currency label <span class="text-muted">(after the price)</span></label>
          <input type="text" name="currency_label" value="<?= e($menu['currency_label'] ?? 'Kes') ?>" style="max-width:120px" placeholder="Kes">
        </div>
      </div>
      <div class="field" style="margin-top:14px">
        <label>Footer note</label>
        <textarea name="footer_note" rows="2" placeholder="Optional line shown in the footer."><?= e($menu['footer_note'] ?? '') ?></textarea>
      </div>
      <label class="toggle-row" style="margin-top:14px">
        <input type="checkbox" name="is_published" value="1" <?= ($menu['is_published'] ?? true) ? 'checked' : '' ?>>
        <span>Published (visible on the site)</span>
      </label>
    </div>
  </div>
  <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn-primary"><?= $isNew ? 'Create menu' : 'Save details' ?></button>
  </div>
</form>

<?php if (!$isNew): ?>

<!-- ── Add a category ── -->
<div class="card" style="margin-top:22px">
  <div class="card__head"><span class="card__title">Add a category</span></div>
  <div class="card__body" style="padding:20px">
    <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_category">
      <div class="form-row">
        <div class="field"><label>Name</label><input type="text" name="name" required placeholder="e.g. Starter Soups"></div>
        <div class="field"><label>Section</label>
          <select name="section">
            <option value="food">Food</option>
            <option value="drinks">Drinks</option>
          </select>
        </div>
      </div>
      <div class="form-row" style="margin-top:12px">
        <div class="field"><label>Tag <span class="text-muted">(small label)</span></label><input type="text" name="tag" placeholder="e.g. First Course / All 500 Kes"></div>
        <div class="field"><label>Icon <span class="text-muted">(emoji)</span></label><input type="text" name="icon" maxlength="8" style="max-width:90px" placeholder="🍲"></div>
      </div>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:14px"><?= admin_icon('plus', 14) ?> Add category</button>
    </form>
  </div>
</div>

<!-- ── Categories + items ── -->
<?php if (!$cats): ?>
<div class="card" style="margin-top:16px"><div class="card__body" style="text-align:center;padding:2rem;color:var(--muted)">No categories yet — add one above.</div></div>
<?php else: foreach ($cats as $ci => $c): ?>
<div class="card" style="margin-top:16px">
  <div class="card__head" style="gap:10px;flex-wrap:wrap">
    <span class="card__title">
      <?= $c['icon'] ? e($c['icon']) . ' ' : '' ?><?= e($c['name']) ?>
      <span class="badge <?= $c['section']==='drinks'?'badge--blue':'badge--green' ?>" style="margin-left:6px"><?= e(ucfirst($c['section'])) ?></span>
      <?php if (!$c['is_visible']): ?><span class="badge badge--red" style="margin-left:4px">Hidden</span><?php endif; ?>
      <?php if ($c['tag']): ?><span class="text-muted" style="font-size:12px;margin-left:6px"><?= e($c['tag']) ?></span><?php endif; ?>
    </span>
    <span class="dt-actions" style="display:inline-flex;gap:4px">
      <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="cat_up"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>"><button class="btn-icon btn-icon--outline" title="Move up" <?= $ci===0?'disabled':'' ?>><?= '↑' ?></button></form>
      <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="cat_down"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>"><button class="btn-icon btn-icon--outline" title="Move down" <?= $ci===count($cats)-1?'disabled':'' ?>><?= '↓' ?></button></form>
    </span>
  </div>
  <div class="card__body" style="padding:0">

    <!-- Items table -->
    <?php if ($c['items']): ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Item</th><th style="width:110px">Price</th><th style="width:90px">Status</th><th style="width:1%;text-align:right">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($c['items'] as $ii => $it): ?>
          <tr>
            <td>
              <strong><?= e($it['name']) ?></strong>
              <?php if (trim((string)$it['description']) !== ''): ?><div class="text-muted" style="font-size:12px;max-width:520px"><?= e($it['description']) ?></div><?php endif; ?>
              <div style="margin-top:3px"><?php foreach (menu_badge_defs() as $col=>[$cls,$lbl]) { if (!empty($it[$col]) && $it[$col]!=='f') echo '<span class="badge badge--grey" style="font-size:10px;margin-right:3px">'.e($lbl).'</span>'; } ?></div>
            </td>
            <td><?= $it['price']!==null ? e(menu_price_label($it['price'], $menu['currency_label'])) : '<span class="text-muted">—</span>' ?></td>
            <td>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <button class="badge <?= ($it['is_available']&&$it['is_available']!=='f')?'badge--green':'badge--red' ?>" style="border:none;cursor:pointer"><?= ($it['is_available']&&$it['is_available']!=='f')?'Available':'Hidden' ?></button>
              </form>
            </td>
            <td style="text-align:right">
              <span class="dt-actions" style="display:inline-flex;gap:4px">
                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="item_up"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn-icon btn-icon--outline" title="Move up" <?= $ii===0?'disabled':'' ?>><?= '↑' ?></button></form>
                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="item_down"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn-icon btn-icon--outline" title="Move down" <?= $ii===count($c['items'])-1?'disabled':'' ?>><?= '↓' ?></button></form>
                <details class="menu-inline"><summary class="btn-icon btn-icon--outline" title="Edit" style="list-style:none"><?= admin_icon('edit',15) ?></summary>
                  <div class="menu-inline-form">
                    <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>">
                      <?= csrf_field() ?><input type="hidden" name="action" value="save_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <div class="form-row">
                        <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($it['name']) ?>" required></div>
                        <div class="field"><label>Price <span class="text-muted">(<?= e($menu['currency_label']) ?>, blank = none)</span></label><input type="number" step="0.01" min="0" name="price" value="<?= $it['price']!==null?e($it['price']):'' ?>" style="max-width:140px"></div>
                      </div>
                      <div class="field" style="margin-top:10px"><label>Description</label><textarea name="description" rows="2"><?= e($it['description']) ?></textarea></div>
                      <div style="margin-top:10px"><?php menu_badge_checkboxes($it); ?></div>
                      <label class="toggle-row" style="margin-top:6px"><input type="checkbox" name="is_available" value="1" <?= ($it['is_available']&&$it['is_available']!=='f')?'checked':'' ?>><span>Available (shown on the menu)</span></label>
                      <div style="margin-top:12px;display:flex;gap:8px">
                        <button type="submit" class="btn-primary btn-sm">Save item</button>
                      </div>
                    </form>
                    <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>" style="margin-top:8px" onsubmit="return confirm('Delete this item?')">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <button type="submit" class="btn-danger btn-sm">Delete item</button>
                    </form>
                  </div>
                </details>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-muted" style="padding:14px 20px">No items in this category yet.</div>
    <?php endif; ?>

    <!-- Add item + edit category -->
    <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap">
      <details class="menu-inline">
        <summary class="btn-primary btn-sm" style="list-style:none;display:inline-flex;align-items:center;gap:6px"><?= admin_icon('plus',14) ?> Add item</summary>
        <div class="menu-inline-form">
          <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_item"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
            <div class="form-row">
              <div class="field"><label>Name</label><input type="text" name="name" required placeholder="e.g. Zuri Garden Velouté"></div>
              <div class="field"><label>Price <span class="text-muted">(<?= e($menu['currency_label']) ?>, blank = none)</span></label><input type="number" step="0.01" min="0" name="price" style="max-width:140px" placeholder="600"></div>
            </div>
            <div class="field" style="margin-top:10px"><label>Description</label><textarea name="description" rows="2" placeholder="A short description."></textarea></div>
            <div style="margin-top:10px"><?php menu_badge_checkboxes(null); ?></div>
            <label class="toggle-row" style="margin-top:6px"><input type="checkbox" name="is_available" value="1" checked><span>Available (shown on the menu)</span></label>
            <div style="margin-top:12px"><button type="submit" class="btn-primary btn-sm">Add item</button></div>
          </form>
        </div>
      </details>

      <details class="menu-inline">
        <summary class="btn-outline btn-sm" style="list-style:none;display:inline-flex;align-items:center;gap:6px"><?= admin_icon('edit',14) ?> Edit category</summary>
        <div class="menu-inline-form">
          <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_category"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
            <div class="form-row">
              <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($c['name']) ?>" required></div>
              <div class="field"><label>Section</label>
                <select name="section">
                  <option value="food" <?= $c['section']==='food'?'selected':'' ?>>Food</option>
                  <option value="drinks" <?= $c['section']==='drinks'?'selected':'' ?>>Drinks</option>
                </select>
              </div>
            </div>
            <div class="form-row" style="margin-top:10px">
              <div class="field"><label>Tag</label><input type="text" name="tag" value="<?= e($c['tag']) ?>"></div>
              <div class="field"><label>Icon</label><input type="text" name="icon" maxlength="8" style="max-width:90px" value="<?= e($c['icon']) ?>"></div>
            </div>
            <label class="toggle-row" style="margin-top:10px"><input type="checkbox" name="is_visible" value="1" <?= $c['is_visible']?'checked':'' ?>><span>Visible on the menu</span></label>
            <div style="margin-top:12px;display:flex;gap:8px">
              <button type="submit" class="btn-primary btn-sm">Save category</button>
            </div>
          </form>
          <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>" style="margin-top:8px" onsubmit="return confirm('Delete this category and all its items?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn-danger btn-sm">Delete category</button>
          </form>
        </div>
      </details>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<!-- ── Delete menu ── -->
<div class="card" style="margin-top:22px">
  <div class="card__body" style="padding:20px">
    <form method="POST" action="/admin/menu-edit.php?id=<?= $id ?>" onsubmit="return confirm('Delete this entire menu, its categories and items? This cannot be undone.')">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_menu">
      <button type="submit" class="btn-danger">Delete menu permanently</button>
    </form>
  </div>
</div>

<style>
.menu-inline{position:relative;display:inline-block}
.menu-inline > summary{cursor:pointer}
.menu-inline > summary::-webkit-details-marker{display:none}
.menu-inline-form{margin-top:10px;padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--surface,#fff);min-width:min(560px,86vw)}
/* Inline edit panels inside the actions column pop over to the right so the table doesn't jump. */
td .menu-inline[open]{position:static}
td .menu-inline[open] .menu-inline-form{position:absolute;right:20px;z-index:20;box-shadow:0 18px 50px rgba(0,0,0,.18)}
</style>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
