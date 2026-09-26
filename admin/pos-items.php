<?php
/**
 * Admin: POS catalogue — the items one outlet sells.
 * Owner, or a manager for the outlets at their property (pos_manageable_outlet_ids()).
 * Add / edit / hide / delete items, link activities (price read live from the
 * activity), photos, stock tracking, consignment supplier, drag reorder.
 *
 * Stock is NEVER edited here — stock_qty is the cached total of the stock ledger,
 * so every change goes through Admin → POS stock (receive / count), except the
 * opening quantity when an item is created (written as a "receive" move).
 * Every mutation re-checks that the item belongs to an outlet this account manages.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_manager();

$pageTitle  = 'POS catalogue';
$activeMenu = 'pos_items';
$supported  = pos_supported();
$self       = '/admin/pos-items.php';
$me         = current_admin();

$allowed = $supported ? pos_manageable_outlet_ids($me) : [];
$outlets = $supported ? pos_fetch_outlets($allowed, false) : [];
$oid     = (int)($_POST['outlet_id'] ?? $_GET['outlet'] ?? 0);
if (!in_array($oid, $allowed, true)) $oid = $allowed[0] ?? 0;   // a posted id outside the list is ignored
$outlet  = $oid ? pos_fetch_outlet($oid) : false;

function posi_back(int $oid, string $extra = ''): void { header('Location: /admin/pos-items.php?outlet=' . $oid . $extra); exit; }
function posi_flash(string $type, string $msg): void { $_SESSION['posi_flash'] = ['type' => $type, 'msg' => $msg]; }

$flash = $_SESSION['posi_flash'] ?? null;  unset($_SESSION['posi_flash']);
$errs  = $_SESSION['posi_errors'] ?? [];    unset($_SESSION['posi_errors']);
$old   = $_SESSION['posi_old'] ?? null;     unset($_SESSION['posi_old']);

$cats       = $outlet ? pos_fetch_categories($oid) : [];
$tours      = $supported ? pos_linkable_tours() : [];
$consignors = $supported ? pos_fetch_consignors() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    if (!$outlet) { posi_flash('error', 'You do not manage any outlets yet.'); header('Location: ' . $self); exit; }

    $iid  = (int)($_POST['item_id'] ?? 0);
    $item = $iid ? pos_fetch_item($iid) : false;
    // Ownership: the item must belong to THIS outlet, which this account manages.
    if ($iid && (!$item || (int)$item['outlet_id'] !== $oid)) {
        if ($act === 'reorder') { header('Content-Type: application/json'); exit(json_encode(['ok' => false])); }
        posi_flash('error', 'That item is not in this outlet.'); posi_back($oid);
    }

    if ($act === 'save') {
        [$v, $e] = pos_item_from_post($_POST, array_map(fn($c) => (int)$c['id'], $cats), array_keys($tours),
                                      array_map(fn($c) => (int)$c['id'], $consignors));
        $opening = trim((string)($_POST['opening_stock'] ?? ''));
        if (!$iid && $v['track_stock'] && $opening !== '' && !ctype_digit($opening)) $e['opening_stock'] = 'Opening stock must be a whole number.';
        $img = '';
        if (!$e) {
            try { $img = pos_upload_item_image($_FILES['image'] ?? []); }
            catch (PosRefusal $ex) { $e['image'] = $ex->getMessage(); }
        }
        if ($e) {
            $_SESSION['posi_errors'] = $e;
            $_SESSION['posi_old'] = $_POST;
            posi_back($oid, ($iid ? '&edit=' . $iid : '&new=1') . '#form');
        }
        $p = [':c' => $v['category_id'], ':k' => $v['kind'], ':t' => $v['tour_id'], ':n' => $v['name'], ':sku' => $v['sku'],
              ':p' => $v['price'], ':pp' => $v['per_person'] ? 'TRUE' : 'FALSE', ':ts' => $v['track_stock'] ? 'TRUE' : 'FALSE',
              ':low' => $v['low_stock_at'], ':neg' => $v['allow_negative'] ? 'TRUE' : 'FALSE', ':cs' => $v['consignor_id'],
              ':cc' => $v['consignor_cost'], ':a' => $v['is_active'] ? 'TRUE' : 'FALSE'];
        $v2  = pos_v2_supported();
        $pct = $v2 ? ', consign_pct = :cpct' : '';
        if ($v2) $p[':cpct'] = $v['consign_pct'];
        // Moving an item to another outlet this account manages (edit only).
        $moveTo = (int)($_POST['move_to'] ?? $oid);
        $moveTo = ($iid && $moveTo !== $oid && in_array($moveTo, $allowed, true)) ? $moveTo : 0;
        $imgSql = '';
        if ($img !== '')                       { $imgSql = ', image_key = :img'; $p[':img'] = $img; }
        elseif (!empty($_POST['remove_image'])) { $imgSql = ', image_key = NULL'; }
        $newId = pos_tx(function () use ($iid, $oid, $p, $imgSql, $img, $v, $opening, $me, $pct, $v2, $moveTo): int {
            if ($iid) {
                db_query("UPDATE pos_items SET category_id = :c, kind = :k, tour_id = :t, name = :n, sku = :sku, price = :p,
                                 per_person = :pp, track_stock = :ts, low_stock_at = :low, allow_negative = :neg,
                                 consignor_id = :cs, consignor_cost = :cc{$pct}, is_active = :a, updated_at = now(){$imgSql}
                           WHERE id = :id AND outlet_id = :o", $p + [':id' => $iid, ':o' => $oid]);
                if ($moveTo) {
                    // Categories belong to an outlet, so a moved item starts uncategorised
                    // at the end of its new outlet. Its stock and sales history move with it;
                    // past sale lines keep the outlet they were sold for.
                    $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_items WHERE outlet_id = :o', [':o' => $moveTo])->fetchColumn();
                    db_query('UPDATE pos_items SET outlet_id = :t, category_id = NULL, sort_order = :s WHERE id = :id', [':t' => $moveTo, ':s' => $max + 1, ':id' => $iid]);
                }
                return $iid;
            }
            $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_items WHERE outlet_id = :o', [':o' => $oid])->fetchColumn();
            db_query("INSERT INTO pos_items (outlet_id, category_id, kind, tour_id, name, sku, price, per_person, track_stock,
                                             low_stock_at, allow_negative, consignor_id, consignor_cost, is_active, sort_order, image_key" . ($v2 ? ', consign_pct' : '') . ")
                      VALUES (:o, :c, :k, :t, :n, :sku, :p, :pp, :ts, :low, :neg, :cs, :cc, :a, :so, :img" . ($v2 ? ', :cpct' : '') . ")",
                array_diff_key($p, [':img' => 1]) + [':o' => $oid, ':so' => $max + 1, ':img' => $img !== '' ? $img : null]);
            $id = (int) db()->lastInsertId();
            if ($v['track_stock'] && $opening !== '' && (int)$opening > 0) {
                pos_stock_receive($id, (int)$opening, null, 'Opening stock', (int)$me['id']);
            }
            return $id;
        });
        audit_log($iid ? ($moveTo ? 'pos.item_move' : 'pos.item_save') : 'pos.item_add', 'pos_item', $newId, $v['name']);
        if ($moveTo) {
            $toName = (string)(pos_fetch_outlet($moveTo)['name'] ?? 'the other outlet');
            posi_flash('success', "{$v['name']} moved to {$toName}.");
            posi_back($moveTo, '#item-' . $newId);
        }
        posi_flash('success', $iid ? "{$v['name']} saved." : "{$v['name']} added to " . ($outlet['name'] ?? 'this outlet') . '.');
        posi_back($oid, '#item-' . $newId);
    }

    if ($act === 'delete' && $item) {
        if (pos_item_has_sales($iid)) {
            db_query('UPDATE pos_items SET is_active = FALSE, updated_at = now() WHERE id = :id', [':id' => $iid]);
            posi_flash('info', "{$item['name']} has sales history, so it was hidden from the till instead of deleted.");
        } else {
            db_query('DELETE FROM pos_items WHERE id = :id AND outlet_id = :o', [':id' => $iid, ':o' => $oid]);
            posi_flash('success', "{$item['name']} deleted.");
        }
        audit_log('pos.item_delete', 'pos_item', $iid, (string)$item['name']);
        posi_back($oid);
    }

    if ($act === 'toggle' && $item) {
        db_query('UPDATE pos_items SET is_active = NOT is_active, updated_at = now() WHERE id = :id', [':id' => $iid]);
        posi_back($oid, '#item-' . $iid);
    }

    if ($act === 'link_tours') {
        $picked = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['tours'] ?? [])))));
        $cat = (int)($_POST['category_id'] ?? 0);
        $cat = in_array($cat, array_map(fn($c) => (int)$c['id'], $cats), true) ? $cat : null;
        $added = 0;
        pos_tx(function () use ($picked, $tours, $oid, $cat, &$added): void {
            $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_items WHERE outlet_id = :o', [':o' => $oid])->fetchColumn();
            foreach ($picked as $tid) {
                if (!isset($tours[$tid])) continue;
                if (db_query('SELECT 1 FROM pos_items WHERE outlet_id = :o AND tour_id = :t', [':o' => $oid, ':t' => $tid])->fetchColumn()) continue;
                db_query("INSERT INTO pos_items (outlet_id, category_id, kind, tour_id, name, price, per_person, sort_order)
                          VALUES (:o, :c, 'service', :t, :n, NULL, :pp, :s)",
                    [':o' => $oid, ':c' => $cat, ':t' => $tid, ':n' => mb_substr((string)$tours[$tid]['name'], 0, 160),
                     ':pp' => pos_bool($tours[$tid]['price_per_person']) ? 'TRUE' : 'FALSE', ':s' => ++$max]);
                $added++;
            }
        });
        audit_log('pos.item_link_tours', 'pos_outlet', $oid, "{$added} activities");
        posi_flash('success', $added ? "{$added} " . ($added === 1 ? 'activity' : 'activities') . ' added — prices follow the activity.' : 'Those activities are already on this outlet.');
        posi_back($oid);
    }

    if ($act === 'reorder') {
        foreach (array_values(array_map('intval', (array)(json_decode($_POST['order'] ?? '[]', true) ?: []))) as $i => $id) {
            db_query('UPDATE pos_items SET sort_order = :s WHERE id = :id AND outlet_id = :o', [':s' => $i, ':id' => $id, ':o' => $oid]);
        }
        header('Content-Type: application/json'); exit(json_encode(['ok' => true]));
    }
    posi_back($oid);
}

$items   = $outlet ? pos_fetch_items($oid, false, false) : [];
$editId  = (int)($_GET['edit'] ?? 0);
$editing = false;
if ($editId) foreach ($items as $it) if ((int)$it['id'] === $editId) { $editing = $it; break; }
$showForm = $editing || !empty($_GET['new']) || $errs;
$form = $editing ?: ['id' => 0, 'name' => '', 'kind' => 'product', 'category_id' => null, 'tour_id' => null, 'price' => null,
                     'per_person' => false, 'sku' => '', 'track_stock' => false, 'low_stock_at' => null, 'allow_negative' => false,
                     'consignor_id' => null, 'consignor_cost' => null, 'consign_pct' => null, 'is_active' => true, 'image_key' => null];
if ($old) {   // re-show a failed post
    foreach (['name','kind','category_id','tour_id','price','sku','low_stock_at','consignor_id','consignor_cost','consign_pct'] as $k) $form[$k] = $old[$k] ?? ($form[$k] ?? null);
    foreach (['per_person','track_stock','allow_negative','is_active'] as $k) $form[$k] = !empty($old[$k]);
}
$linked   = array_filter(array_map(fn($i) => (int)($i['tour_id'] ?? 0), $items));
$unlinked = array_filter($tours, fn($t, $id) => !in_array($id, $linked, true) && $t['category'] === 'excursion', ARRAY_FILTER_USE_BOTH);
$cur      = $outlet ? strtoupper((string)$outlet['currency']) : 'USD';
$num      = fn($v) => ($v === null || $v === '') ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$err      = fn(string $k) => isset($errs[$k]) ? '<div class="field-error">' . e($errs[$k]) . '</div>' : '';

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>POS catalogue</h1>
  <?php if ($outlet): ?>
  <div class="actions">
    <a href="/admin/pos-stock.php?outlet=<?= $oid ?>" class="btn-outline btn-sm"><?= admin_icon('inbox', 15) ?> Stock</a>
    <a href="<?= $self ?>?outlet=<?= $oid ?>&new=1#form" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add item</a>
  </div>
  <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations) to set up the POS.</div>
<?php elseif (!$outlets): ?>
  <?php dt_empty(is_owner() ? 'No outlets yet — create one under POS outlets first.' : 'You do not manage any POS outlets. Ask the owner to assign you, or to set an outlet to your property.'); ?>
<?php else: ?>

<form method="GET" action="<?= $self ?>" class="posi-pick">
  <span class="text-muted">Outlet</span>
  <select name="outlet" class="eselect" onchange="this.form.submit()" aria-label="Outlet">
    <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $oid ? 'selected' : '' ?>><?= e($o['name']) ?><?= pos_bool($o['is_active']) ? '' : ' (closed)' ?></option><?php endforeach; ?>
  </select>
  <span class="text-muted posi-pick__meta"><?= e($cur) ?><?= $outlet && (float)$outlet['service_charge_pct'] > 0 ? ' · ' . e($num($outlet['service_charge_pct'])) . '% service added at the till' : '' ?></span>
</form>

<?php if ($showForm): ?>
<div class="card posi-formcard" id="form">
  <div class="card__head"><span class="card__title"><?= $editing ? 'Edit ' . e($editing['name']) . ' <span class="posi-in">in ' . e($outlet['name']) . '</span>' : 'Add an item to <span class="posi-in">' . e($outlet['name']) . '</span>' ?></span>
    <a href="<?= $self ?>?outlet=<?= $oid ?>" class="btn-icon" data-tip="Close" aria-label="Close"><?= admin_icon('x', 16) ?></a></div>
  <div class="card__body" style="padding:18px 20px">
    <?php if (!empty($errs)): ?><div class="alert alert--error">Please fix the highlighted fields.</div><?php endif; ?>
    <form method="POST" action="<?= $self ?>" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="outlet_id" value="<?= $oid ?>">
      <input type="hidden" name="item_id" value="<?= (int)$form['id'] ?>">

      <div class="posi-grid">
        <div class="field posi-span2"><label>Name</label>
          <input name="name" class="inp<?= isset($errs['name']) ? ' is-invalid' : '' ?>" maxlength="160" value="<?= e((string)$form['name']) ?>" required><?= $err('name') ?></div>
        <div class="field"><label>Type</label>
          <div class="posi-chips">
            <label class="optchip"><input type="radio" name="kind" value="product" <?= $form['kind'] !== 'service' ? 'checked' : '' ?>>Product</label>
            <label class="optchip"><input type="radio" name="kind" value="service" <?= $form['kind'] === 'service' ? 'checked' : '' ?>>Service</label>
          </div></div>
        <div class="field"><label>Category</label>
          <select name="category_id" class="eselect"><option value="0">No category</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$form['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field posi-span2"><label>Linked activity <span class="text-muted">(optional — the till then uses the activity's price)</span></label>
          <select name="tour_id" class="eselect" id="posiTour"><option value="0">None</option>
            <?php foreach ($tours as $tid => $t): ?><option value="<?= $tid ?>" data-price="<?= e($t['price_amount'] === null ? '' : pos_money((float)$t['price_amount'], $cur)) ?>" <?= (int)$form['tour_id'] === $tid ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['price_amount'] === null ? ' — on request' : ' — ' . e(pos_money((float)$t['price_amount'], $cur)) ?></option><?php endforeach; ?>
          </select><?= $err('tour_id') ?></div>
        <div class="field"><label>Price (<?= e($cur) ?>)</label>
          <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span>
            <input name="price" type="number" class="inp inp--num no-spin<?= isset($errs['price']) ? ' is-invalid' : '' ?>" min="0" step="0.01" value="<?= e($num($form['price'])) ?>" placeholder="blank"></span>
          <div class="posi-help" id="posiPriceHelp">Blank = the activity's price, or staff enter a price at the till.</div><?= $err('price') ?></div>
        <div class="field"><label>SKU <span class="text-muted">(optional)</span></label>
          <input name="sku" class="inp" maxlength="60" value="<?= e((string)$form['sku']) ?>"></div>
      </div>

      <div class="posi-toggles">
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="per_person" value="1" <?= pos_bool($form['per_person']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Priced per person — the quantity is the number of guests</span></label>
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= pos_bool($form['is_active']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>On sale — shows on the till</span></label>
      </div>

      <div class="posi-sub">Stock</div>
      <label class="togglerow"><span class="toggle"><input type="checkbox" name="track_stock" value="1" id="posiTrack" <?= pos_bool($form['track_stock']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Track stock — the till stops selling at zero</span></label><?= $err('track_stock') ?>
      <div class="posi-grid posi-stock" id="posiStock">
        <?php if (!$editing): ?>
        <div class="field"><label>Opening stock</label><input name="opening_stock" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= e((string)($old['opening_stock'] ?? '')) ?>" placeholder="0"><?= $err('opening_stock') ?></div>
        <?php else: ?>
        <div class="field"><label>On hand</label><div class="posi-onhand"><?= (int)$editing['stock_qty'] ?> <a href="/admin/pos-stock.php?outlet=<?= $oid ?>&item=<?= (int)$editing['id'] ?>">receive / count →</a></div></div>
        <?php endif; ?>
        <div class="field"><label>Low-stock alert at</label><input name="low_stock_at" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= e((string)($form['low_stock_at'] ?? '')) ?>" placeholder="off"><?= $err('low_stock_at') ?></div>
        <div class="field posi-span2" style="align-self:end">
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="allow_negative" value="1" <?= pos_bool($form['allow_negative']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Allow selling below zero</span></label></div>
      </div>

      <div class="posi-sub">Consignment</div>
      <div class="posi-grid">
        <div class="field"><label>Supplier <span class="text-muted">(blank = our own stock)</span></label>
          <select name="consignor_id" class="eselect"><option value="0">Our own stock</option>
            <?php foreach ($consignors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$form['consignor_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> — keeps <?= e($num($c['commission_pct']) ?: '0') ?>% by default<?= pos_bool($c['is_active']) ? '' : ' (inactive)' ?></option><?php endforeach; ?>
          </select><?= $err('consignor_id') ?></div>
        <?php if (pos_v2_supported()): ?>
        <div class="field"><label>We keep (%) <span class="text-muted">(blank = supplier default)</span></label>
          <input name="consign_pct" type="number" class="inp inp--num no-spin" min="0" max="100" step="0.01" value="<?= e($num($form['consign_pct'] ?? null)) ?>" placeholder="default"><?= $err('consign_pct') ?></div>
        <?php endif; ?>
        <div class="field"><label>Or: fixed amount we owe per item</label>
          <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span>
            <input name="consignor_cost" type="number" class="inp inp--num no-spin" min="0" step="0.01" value="<?= e($num($form['consignor_cost'])) ?>" placeholder="—"></span><?= $err('consignor_cost') ?></div>
      </div>
      <p class="posi-help">A fixed amount wins over the %. Terms can also be set each time a delivery is received (Stock page). Past sales keep the terms they were sold on.</p>

      <?php if ($editing && count($outlets) > 1): ?>
      <div class="posi-sub">Outlet</div>
      <div class="posi-grid">
        <div class="field"><label>Sold at</label>
          <select name="move_to" class="eselect">
            <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $oid ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="posi-help">Moving an item takes its stock with it and clears its category.</div></div>
      </div>
      <?php endif; ?>

      <div class="posi-sub">Photo</div>
      <div class="posi-photo">
        <?php if (!empty($form['image_key'])): ?><img src="<?= e(storage_url((string)$form['image_key'])) ?>" alt="" class="posi-thumb posi-thumb--lg"><?php endif; ?>
        <label class="filefield">
          <span class="btn-outline btn-sm"><?= admin_icon('image', 13) ?> <?= !empty($form['image_key']) ? 'Replace photo' : 'Add photo' ?></span>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
          <span class="filefield__name"></span>
        </label>
        <?php if (!empty($form['image_key'])): ?><label class="togglerow"><span class="toggle"><input type="checkbox" name="remove_image" value="1"><span class="toggle-slider"></span></span><span>Remove photo</span></label><?php endif; ?>
      </div><?= $err('image') ?>

      <div class="posi-actions">
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $editing ? 'Save item' : 'Add item' ?></button>
        <a href="<?= $self ?>?outlet=<?= $oid ?>" class="btn-outline btn-sm">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title"><?= e($outlet['name'] ?? '') ?> · <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
    <span class="text-muted" style="font-size:12.5px">Drag the handle to set the till order</span></div>
  <?php if (!$items): ?>
    <?php dt_empty('No items yet — add one, or link activities below.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table posi-table">
    <thead><tr><th style="width:30px"></th><th>Item</th><th>Category</th><th class="posi-num">Price</th><th class="posi-num">Stock</th><th>Status</th><th style="width:1%"></th></tr></thead>
    <tbody id="posiRows">
    <?php foreach ($items as $it):
        $price = pos_item_display_price($it);
        $low   = pos_bool($it['track_stock']) && $it['low_stock_at'] !== null && (int)$it['stock_qty'] <= (int)$it['low_stock_at']; ?>
      <tr id="item-<?= (int)$it['id'] ?>" data-id="<?= (int)$it['id'] ?>" draggable="true" class="<?= pos_bool($it['is_active']) ? '' : 'is-off' ?>">
        <td><span class="posi-grip" aria-hidden="true"><?= admin_icon('grip', 16) ?></span></td>
        <td>
          <div class="posi-name">
            <?php if (!empty($it['image_key'])): ?><img src="<?= e(storage_url((string)$it['image_key'])) ?>" alt="" class="posi-thumb" loading="lazy"><?php else: ?><span class="posi-thumb posi-thumb--none"><?= admin_icon('image', 14) ?></span><?php endif; ?>
            <span><strong><?= e($it['name']) ?></strong>
              <span class="posi-tags">
                <?php if (!empty($it['tour_id'])): ?><span class="badge badge--teal" data-tip="Price follows the activity">Activity</span><?php endif; ?>
                <?php if (!empty($it['consignor_id'])): ?><span class="badge badge--purple">Consignment · <?= e($it['consignor_name']) ?></span><?php endif; ?>
                <?php if ($it['kind'] === 'service'): ?><span class="badge badge--grey">Service</span><?php endif; ?>
                <?php if (!empty($it['tour_id']) && !pos_bool($it['tour_published'])): ?><span class="badge badge--orange">Activity unpublished</span><?php endif; ?>
              </span></span>
          </div>
        </td>
        <td><?= e($it['category_name'] ?? '—') ?></td>
        <td class="posi-num"><?= $price === null ? '<span class="text-muted">Open price</span>' : e(pos_money($price, $cur)) . (pos_bool($it['per_person']) || (!empty($it['tour_id']) && $it['price'] === null && pos_bool($it['tour_per_person'])) ? ' <span class="text-muted">pp</span>' : '') ?></td>
        <td class="posi-num"><?php if (pos_bool($it['track_stock'])): ?><?= (int)$it['stock_qty'] ?><?php if ((int)$it['stock_qty'] <= 0): ?> <span class="badge badge--red">Out</span><?php elseif ($low): ?> <span class="badge badge--orange">Low</span><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td>
          <form method="POST" action="<?= $self ?>" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button type="submit" class="posi-status badge <?= pos_bool($it['is_active']) ? 'badge--green' : 'badge--grey' ?>" data-tip="<?= pos_bool($it['is_active']) ? 'Hide from the till' : 'Put on sale' ?>"><?= pos_bool($it['is_active']) ? 'On sale' : 'Hidden' ?></button>
          </form>
        </td>
        <td class="posi-act">
          <a href="<?= $self ?>?outlet=<?= $oid ?>&edit=<?= (int)$it['id'] ?>#form" class="btn-icon" data-tip="Edit" aria-label="Edit <?= e($it['name']) ?>"><?= admin_icon('edit', 15) ?></a>
          <form method="POST" action="<?= $self ?>" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Delete <?= e($it['name']) ?>? An item with sales is hidden instead." data-tip="Delete" aria-label="Delete <?= e($it['name']) ?>"><?= admin_icon('trash', 15) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($unlinked): ?>
<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Add from Activities</span><span class="text-muted" style="font-size:12.5px">Linked, not copied — edit the price on the activity</span></div>
  <div class="card__body" style="padding:16px 20px">
    <form method="POST" action="<?= $self ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="link_tours"><input type="hidden" name="outlet_id" value="<?= $oid ?>">
      <div class="posi-chips posi-tourchips">
        <?php foreach ($unlinked as $tid => $t): ?>
        <label class="optchip"><input type="checkbox" name="tours[]" value="<?= $tid ?>"><?= e($t['name']) ?> <span class="posi-chip-sub"><?= $t['price_amount'] === null ? 'on request' : e(pos_money((float)$t['price_amount'], $cur)) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="ws-addform" style="margin-top:14px">
        <label class="wsf"><span>Into category</span>
          <select name="category_id" class="eselect"><option value="0">No category</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add selected</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.card__head{flex-wrap:wrap;gap:8px 12px}
.posi-pick{display:flex;align-items:center;gap:10px;margin:-6px 0 18px;flex-wrap:wrap;font-size:13px}
.posi-pick__meta{font-size:12.5px}
.posi-formcard{margin-bottom:18px}
.posi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px}
.posi-grid .inp{width:100%}
.posi-span2{grid-column:span 2}
@media (max-width:640px){.posi-span2{grid-column:auto}}
.posi-chips{display:flex;flex-wrap:wrap;gap:8px}
.posi-chip-sub{opacity:.65;font-size:11.5px;margin-left:3px}
.field .inp-money{display:flex;width:100%}.field .inp-money .inp{flex:1;min-width:0;width:auto}
.posi-help{font-size:12px;color:var(--muted);margin-top:5px}
.posi-in{color:var(--brand);font-weight:600}
.posi-toggles{display:flex;flex-direction:column;gap:10px;margin:4px 0}
.posi-sub{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:20px 0 10px}
.posi-stock{margin-top:12px}
.posi-stock.is-hidden{display:none}
.posi-onhand{font-size:14px;padding:9px 0}
.posi-onhand a{font-size:12.5px;margin-left:8px}
.posi-photo{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.posi-actions{display:flex;gap:8px;margin-top:20px}
.posi-table td{vertical-align:middle}
.posi-table tr.is-off td{opacity:.6}
.posi-table tr.is-dragging{opacity:.4}
.posi-grip{color:var(--muted);cursor:grab;display:inline-flex}
.posi-name{display:flex;align-items:center;gap:10px}
.posi-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:3px}
.posi-thumb{width:38px;height:38px;border-radius:8px;object-fit:cover;flex:0 0 auto;background:var(--bg,#f4f2ee)}
.posi-thumb--none{display:inline-flex;align-items:center;justify-content:center;color:var(--muted)}
.posi-thumb--lg{width:84px;height:84px}
.posi-num{text-align:right;white-space:nowrap}
.posi-status{border:0;cursor:pointer;font:inherit;font-size:11.5px}
.posi-act{display:flex;gap:4px;justify-content:flex-end}
</style>
<script>
(function(){
  // Stock fields only matter when stock is tracked.
  var track = document.getElementById('posiTrack'), box = document.getElementById('posiStock');
  function sync(){ if (box) box.classList.toggle('is-hidden', !(track && track.checked)); }
  if (track) { track.addEventListener('change', sync); sync(); }
  // Price hint follows the linked activity.
  var tour = document.getElementById('posiTour'), help = document.getElementById('posiPriceHelp');
  function hint(){ if (!tour || !help) return; var o = tour.options[tour.selectedIndex], p = o ? o.getAttribute('data-price') : null;
    help.textContent = tour.value === '0' ? 'Blank = staff enter a price at the till.'
      : (p ? 'Blank = the activity price (' + p + '), kept in step automatically.' : 'Blank = the activity is on request, so staff enter a price at the till.'); }
  if (tour) { tour.addEventListener('change', hint); hint(); }
  // Filename readout for the styled file field.
  document.querySelectorAll('.filefield input[type=file]').forEach(function(i){ i.addEventListener('change', function(){ var n = i.parentNode.querySelector('.filefield__name'); if (n) n.textContent = i.files[0] ? i.files[0].name : ''; }); });
  // Drag reorder.
  var body = document.getElementById('posiRows'); if (!body) return;
  var CSRF = <?= json_encode(csrf_token()) ?>, dragged = null;
  body.querySelectorAll('tr').forEach(function(row){
    row.addEventListener('dragstart', function(){ dragged = row; row.classList.add('is-dragging'); });
    row.addEventListener('dragend', function(){ if (!dragged) return; row.classList.remove('is-dragging'); dragged = null;
      var fd = new FormData(); fd.append('action','reorder'); fd.append('outlet_id', <?= (int)$oid ?>); fd.append('csrf_token', CSRF);
      fd.append('order', JSON.stringify([].map.call(body.querySelectorAll('tr'), function(r){ return r.dataset.id; })));
      fetch(<?= json_encode($self) ?>, {method:'POST', body:fd, credentials:'same-origin'}); });
    row.addEventListener('dragover', function(e){ if (dragged) e.preventDefault(); });
    row.addEventListener('dragenter', function(e){ if (!dragged || dragged === row) return; e.preventDefault();
      var k=[].slice.call(body.querySelectorAll('tr')), di=k.indexOf(dragged), ri=k.indexOf(row); body.insertBefore(dragged, di<ri ? row.nextSibling : row); });
  });
  body.querySelectorAll('button, a').forEach(function(b){ b.setAttribute('draggable','false'); });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
