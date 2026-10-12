<?php
/**
 * Admin: POS catalogue — the items one outlet sells.
 * Owner, or a manager for the outlets at their property (pos_manageable_outlet_ids()).
 * Add / edit / hide / delete items, link activities (price read live from the
 * activity), photos, stock tracking, consignment supplier, drag reorder.
 *
 * Stock is NEVER edited here — on-hand stock is the outlet's inventory balance,
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
// A refused save comes back as ?open=item-<id>: the list page leaves the errors for
// the side drawer it reopens, which reads (and clears) them.
$errs  = $_SESSION['posi_errors'] ?? [];
$old   = $_SESSION['posi_old'] ?? null;
if (empty($_GET['open']) || !empty($_GET['drawer'])) { unset($_SESSION['posi_errors'], $_SESSION['posi_old']); }

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
        elseif (!$iid && $v['track_stock'] && $opening !== '' && (int)$opening > INV_MAX_QTY) $e['opening_stock'] = 'Opening stock is too large.';
        if ($iid && $item && pos_bool($item['track_stock']) && !$v['track_stock'] && inv_supported() && (int)$item['stock_on_hand'] !== 0) $e['track_stock'] = 'Count this item to 0 before turning stock tracking off (' . (int)$item['stock_on_hand'] . ' on hand).';
        $img = '';
        if (!$e) {
            try { $img = pos_upload_item_image($_FILES['image'] ?? []); }
            catch (PosRefusal $ex) { $e['image'] = $ex->getMessage(); }
        }
        if ($e) {
            $_SESSION['posi_errors'] = $e;
            $_SESSION['posi_old'] = $_POST;
            posi_back($oid, '&open=item-' . ($iid ?: 'new'));
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
        try {
            $newId = pos_tx(function () use ($iid, $oid, $p, $imgSql, $img, $v, $opening, $me, $pct, $v2, $moveTo): int {
                if ($iid) {
                    db_query("UPDATE pos_items SET category_id = :c, kind = :k, tour_id = :t, name = :n, sku = :sku, price = :p,
                                     per_person = :pp, track_stock = :ts, low_stock_at = :low, allow_negative = :neg,
                                     consignor_id = :cs, consignor_cost = :cc{$pct}, is_active = :a, updated_at = now(){$imgSql}
                               WHERE id = :id AND outlet_id = :o", $p + [':id' => $iid, ':o' => $oid]);
                    if ($v['track_stock'] && inv_supported()) pos_item_sync_inventory($iid);
                    if ($moveTo) {
                        // Categories belong to an outlet, so a moved item starts uncategorised
                        // at the end of its new outlet. Its stock and sales history move with it;
                        // past sale lines keep the outlet they were sold for.
                        if (inv_supported()) pos_item_move_stock($iid, $oid, $moveTo, (int)$me['id']);
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
                if ($v['track_stock'] && inv_supported()) pos_item_sync_inventory($id);
                if ($v['track_stock'] && $opening !== '' && (int)$opening > 0) {
                    pos_stock_receive($id, (int)$opening, null, 'Opening stock', (int)$me['id']);
                }
                return $id;
            });
        } catch (PosRefusal $ex) {
            posi_flash('error', $ex->getMessage());
            $_SESSION['posi_old'] = $_POST;
            posi_back($oid, '&open=item-' . ($iid ?: 'new'));
        }
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
        if (inv_supported() && (int)($item['stock_on_hand'] ?? 0) !== 0) {
            db_query('UPDATE pos_items SET is_active = FALSE, updated_at = now() WHERE id = :id', [':id' => $iid]);
            posi_flash('info', "{$item['name']} still has " . (int)$item['stock_on_hand'] . " on hand, so it was hidden instead of deleted. Count it to 0 on the Stock page first to delete it.");
            audit_log('pos.item_delete', 'pos_item', $iid, (string)$item['name']);
            posi_back($oid);
        }
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
$isDrawer = !empty($_GET['drawer']);
// Without JavaScript (?edit= / ?new= links) the form shows on the page; with it the
// list opens the same form in the side drawer.
$showForm = !$isDrawer && empty($_GET['open']) && ($editing || !empty($_GET['new']) || $errs);
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

/** The add / edit form (drawer fragment, or a card on the page without JS). */
$itemForm = function (bool $inDrawer) use ($form, $editing, $errs, $err, $old, $oid, $outlet, $outlets, $cats, $tours, $consignors, $cur, $num, $self): void {
    $fid = 'posiForm' . (int)$form['id'];
    ?>
    <div class="adrawer__head">
      <h2><?= $editing ? e($editing['name']) : 'Add an item' ?></h2>
      <p><?= $editing ? 'In ' : 'To ' ?><?= e($outlet['name']) ?><?= $editing && !empty($editing['sku']) ? ' · SKU ' . e($editing['sku']) : '' ?></p>
    </div>
    <div class="adrawer__body">
      <?php if (!empty($errs)): ?><div class="alert alert--error" style="margin:0">Please fix the highlighted fields.</div><?php endif; ?>
      <form method="POST" action="<?= $self ?>" enctype="multipart/form-data" novalidate id="<?= $fid ?>" class="posi-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <input type="hidden" name="item_id" value="<?= (int)$form['id'] ?>">

        <div class="posi-grid">
          <div class="field posi-span2"><label>Name</label>
            <input name="name" class="inp<?= isset($errs['name']) ? ' is-invalid' : '' ?>" maxlength="160" value="<?= e((string)$form['name']) ?>" required<?= $editing ? '' : ' autofocus' ?>><?= $err('name') ?></div>
          <div class="field"><label>Type</label>
            <div class="posi-chips">
              <label class="optchip"><input type="radio" name="kind" value="product" <?= $form['kind'] !== 'service' ? 'checked' : '' ?>>Product</label>
              <label class="optchip"><input type="radio" name="kind" value="service" <?= $form['kind'] === 'service' ? 'checked' : '' ?>>Service</label>
            </div></div>
          <div class="field"><label>Category</label>
            <select name="category_id" class="eselect"><option value="0">No category</option>
              <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$form['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field posi-span2"><label>Linked activity <span class="text-muted">(optional — the till then uses its price)</span></label>
            <select name="tour_id" class="eselect" data-posi-tour><option value="0">None</option>
              <?php foreach ($tours as $tid => $t): ?><option value="<?= $tid ?>" data-price="<?= e($t['price_amount'] === null ? '' : pos_activity_price_label($t['price_amount'], $cur)) ?>" <?= (int)$form['tour_id'] === $tid ? 'selected' : '' ?>><?= e($t['name']) ?> — <?= e(pos_activity_price_label($t['price_amount'], $cur)) ?></option><?php endforeach; ?>
            </select><?= $err('tour_id') ?></div>
          <div class="field"><label>Price (<?= e($cur) ?>)</label>
            <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span>
              <input name="price" type="number" class="inp inp--num no-spin<?= isset($errs['price']) ? ' is-invalid' : '' ?>" min="0" step="0.01" value="<?= e($num($form['price'])) ?>" placeholder="blank"></span>
            <div class="posi-help" data-posi-price-help>Blank = the activity's price, or staff enter a price at the till.</div><?= $err('price') ?></div>
          <div class="field"><label>SKU <span class="text-muted">(optional)</span></label>
            <input name="sku" class="inp" maxlength="60" value="<?= e((string)$form['sku']) ?>"></div>
        </div>

        <div class="posi-toggles">
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="per_person" value="1" <?= pos_bool($form['per_person']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Priced per person — the quantity is the number of guests</span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= pos_bool($form['is_active']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>On sale — shows on the till</span></label>
        </div>

        <div class="posi-sub">Stock</div>
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="track_stock" value="1" data-posi-track <?= pos_bool($form['track_stock']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Track stock — the till stops selling at zero</span></label><?= $err('track_stock') ?>
        <div class="posi-grid posi-stock" data-posi-stock>
          <?php if (!$editing): ?>
          <div class="field"><label>Opening stock</label><input name="opening_stock" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= e((string)($old['opening_stock'] ?? '')) ?>" placeholder="0"><?= $err('opening_stock') ?></div>
          <?php else: ?>
          <div class="field"><label>On hand</label><div class="posi-onhand"><?= (int)$editing['stock_on_hand'] ?> <a href="/admin/pos-stock.php?outlet=<?= $oid ?>&open=stock-<?= (int)$editing['id'] ?>">receive / count →</a></div></div>
          <?php endif; ?>
          <div class="field"><label>Low-stock alert at</label><input name="low_stock_at" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= e((string)($form['low_stock_at'] ?? '')) ?>" placeholder="off"><?= $err('low_stock_at') ?></div>
          <div class="field posi-span2">
            <label class="togglerow"><span class="toggle"><input type="checkbox" name="allow_negative" value="1" <?= pos_bool($form['allow_negative']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Allow selling below zero</span></label></div>
        </div>

        <div class="posi-sub">Consignment</div>
        <div class="posi-grid">
          <div class="field posi-span2"><label>Supplier <span class="text-muted">(blank = our own stock)</span></label>
            <select name="consignor_id" class="eselect"><option value="0">Our own stock</option>
              <?php foreach ($consignors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$form['consignor_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> — keeps <?= e($num($c['commission_pct']) ?: '0') ?>% by default<?= pos_bool($c['is_active']) ? '' : ' (inactive)' ?></option><?php endforeach; ?>
            </select><?= $err('consignor_id') ?></div>
          <?php if (pos_v2_supported()): ?>
          <div class="field"><label>We keep (%)</label>
            <input name="consign_pct" type="number" class="inp inp--num no-spin" min="0" max="100" step="0.01" value="<?= e($num($form['consign_pct'] ?? null)) ?>" placeholder="supplier default"><?= $err('consign_pct') ?></div>
          <?php endif; ?>
          <div class="field"><label>Or: we owe per item</label>
            <span class="inp-money"><span class="inp-money__cur"><?= e($cur) ?></span>
              <input name="consignor_cost" type="number" class="inp inp--num no-spin" min="0" step="0.01" value="<?= e($num($form['consignor_cost'])) ?>" placeholder="—"></span><?= $err('consignor_cost') ?></div>
        </div>
        <p class="posi-help">A fixed amount wins over the %. Terms can also be set when a delivery is received (Stock). Past sales keep the terms they were sold on.</p>

        <?php if ($editing && count($outlets) > 1): ?>
        <div class="posi-sub">Outlet</div>
        <div class="field"><label>Sold at</label>
          <select name="move_to" class="eselect">
            <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $oid ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="posi-help">Moving an item takes its stock with it and clears its category.</div></div>
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
      </form>
    </div>
    <div class="adrawer__foot">
      <?php if ($inDrawer): ?><button type="button" class="btn-outline btn-sm" data-adrawer-close>Cancel</button>
      <?php else: ?><a href="<?= $self ?>?outlet=<?= $oid ?>" class="btn-outline btn-sm">Cancel</a><?php endif; ?>
      <button type="submit" form="<?= $fid ?>" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $editing ? 'Save item' : 'Add item' ?></button>
    </div>
    <?php
};

if ($isDrawer) {
    if (!$outlet || (!$editing && empty($_GET['new']))) { echo '<div class="adrawer__head"><h2>Item not found</h2><p>It is not in this outlet.</p></div>'; exit; }
    $itemForm(true);
    exit;
}

// Counts for the summary tiles and filter chips.
$cnt = ['all' => count($items), 'on' => 0, 'low' => 0, 'out' => 0, 'activity' => 0, 'consign' => 0, 'hidden' => 0];
foreach ($items as $it) {
    $tracked = pos_bool($it['track_stock']);
    if (pos_bool($it['is_active'])) $cnt['on']++; else $cnt['hidden']++;
    if ($tracked && (int)$it['stock_on_hand'] <= 0) $cnt['out']++;
    elseif ($tracked && $it['low_stock_at'] !== null && (int)$it['stock_on_hand'] <= (int)$it['low_stock_at']) $cnt['low']++;
    if (!empty($it['tour_id'])) $cnt['activity']++;
    if (!empty($it['consignor_id'])) $cnt['consign']++;
}
$link = fn(string $extra) => $self . '?outlet=' . $oid . $extra;

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Catalogue</h1>
  <?php if ($outlet): ?>
  <div class="actions">
    <?php if (count($outlets) > 1): ?>
    <form method="GET" action="<?= $self ?>" style="display:inline-flex">
      <select name="outlet" class="eselect" onchange="this.form.submit()" aria-label="Outlet">
        <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $oid ? 'selected' : '' ?>><?= e($o['name']) ?><?= pos_bool($o['is_active']) ? '' : ' (closed)' ?></option><?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <?php if ($unlinked): ?><button type="button" class="btn-outline btn-sm" id="posiToursBtn" aria-controls="posiTours" aria-expanded="false"><?= admin_icon('plus', 15) ?> From activities</button><?php endif; ?>
    <a href="<?= e($link('&new=1')) ?>" class="btn-primary btn-sm" data-drawer="<?= e($link('&new=1')) ?>" data-drawer-key="item-new"><?= admin_icon('plus', 15) ?> Add item</a>
  </div>
  <?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Settings → Your account → Migrations) to set up the POS.</div>
<?php elseif (!$outlets): ?>
  <?php dt_empty(is_owner() ? 'No outlets yet — create one under Point of Sale → Setup first.' : 'You do not manage any POS outlets. Ask the owner to assign you, or to set an outlet to your property.'); ?>
<?php else: ?>

<?php if ($unlinked): ?>
<div class="card posi-tours" id="posiTours" data-inline-form hidden>
  <div class="card__head"><span class="card__title">Add from Activities</span></div>
  <div class="card__body" style="padding:16px 20px">
    <form method="POST" action="<?= $self ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="link_tours"><input type="hidden" name="outlet_id" value="<?= $oid ?>">
      <div class="posi-chips">
        <?php foreach ($unlinked as $tid => $t): ?>
        <label class="optchip"><input type="checkbox" name="tours[]" value="<?= $tid ?>"><?= e($t['name']) ?> <span class="posi-chip-sub"><?= e(pos_activity_price_label($t['price_amount'], $cur)) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="ws-addform" style="margin-top:14px">
        <label class="wsf"><span>Into category</span>
          <select name="category_id" class="eselect"><option value="0">No category</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add selected</button>
      </div>
      <p class="posi-help">Linked, not copied — the price follows the activity.</p>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="card posi-formcard" id="form" data-inline-form data-close-href="<?= e($link('')) ?>"><?php $itemForm(false); ?></div>
<?php endif; ?>

<div class="pos-kpis">
  <div class="pos-kpi pos-kpi--dark"><span>On sale</span><b><?= $cnt['on'] ?></b><small>of <?= $cnt['all'] ?> item<?= $cnt['all'] === 1 ? '' : 's' ?> at <?= e($outlet['name']) ?></small></div>
  <div class="pos-kpi<?= $cnt['low'] ? ' pos-kpi--warn' : '' ?>"><span>Low stock</span><b><?= $cnt['low'] ?></b><small>at or under the alert</small></div>
  <div class="pos-kpi<?= $cnt['out'] ? ' pos-kpi--bad' : '' ?>"><span>Out of stock</span><b><?= $cnt['out'] ?></b><small>the till won’t sell these</small></div>
  <div class="pos-kpi"><span>Prices in</span><b><?= e($cur) ?></b><small><?= (float)$outlet['service_charge_pct'] > 0 ? e($num($outlet['service_charge_pct'])) . '% service added at the till' : 'No service charge' ?></small></div>
</div>

<div class="pos-toolbar">
  <div class="pos-chips" id="posiChips">
    <?php foreach (['all' => 'All', 'low' => 'Low stock', 'out' => 'Out', 'activity' => 'Activities', 'consign' => 'Consignment', 'hidden' => 'Hidden'] as $k => $lbl): if ($k !== 'all' && !$cnt[$k]) continue; ?>
    <button type="button" class="pos-chip<?= $k === 'all' ? ' is-on' : '' ?>" data-posi-filter="<?= $k ?>"><?= e($lbl) ?><b><?= $cnt[$k] ?></b></button>
    <?php endforeach; ?>
  </div>
  <span class="sp"></span>
  <span class="dt-search" style="width:260px;max-width:100%"><span class="dt-search__icon" aria-hidden="true"><?= admin_icon('search', 16) ?></span><input type="search" id="posiSearch" class="dt-search__input" placeholder="Search items or SKU…" autocomplete="off" aria-label="Search items"></span>
</div>

<div class="card">
  <?php if (!$items): ?>
    <?php dt_empty('No items yet — press Add item, or link activities.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table posi-table is-phone-fit" id="posiTable" data-paginate="25">
    <thead><tr><th style="width:30px" data-tip="Drag to set the till order"></th><th>Item</th><th>Category</th><th>Type</th><th class="posi-num">Price</th><th class="posi-num">Stock</th><th>Status</th><th style="width:1%"></th></tr></thead>
    <tbody id="posiRows">
    <?php foreach ($items as $it):
        $price = pos_item_display_price($it, $cur);
        $noFx  = pos_item_price_problem($it, $cur);
        $tracked = pos_bool($it['track_stock']);
        $out   = $tracked && (int)$it['stock_on_hand'] <= 0;
        $low   = !$out && $tracked && $it['low_stock_at'] !== null && (int)$it['stock_on_hand'] <= (int)$it['low_stock_at'];
        $tags  = trim(($low ? ' low' : '') . ($out ? ' out' : '') . (!empty($it['tour_id']) ? ' activity' : '') . (!empty($it['consignor_id']) ? ' consign' : '') . (pos_bool($it['is_active']) ? '' : ' hidden'));
        $eUrl  = $link('&edit=' . (int)$it['id']); ?>
      <tr id="item-<?= (int)$it['id'] ?>" data-id="<?= (int)$it['id'] ?>" draggable="true" class="<?= pos_bool($it['is_active']) ? '' : 'is-off' ?>"
          data-drawer="<?= e($eUrl) ?>" data-drawer-key="item-<?= (int)$it['id'] ?>" data-tags="<?= e($tags) ?>" data-find="<?= e(mb_strtolower($it['name'] . ' ' . ($it['sku'] ?? '') . ' ' . ($it['category_name'] ?? ''))) ?>">
        <td><span class="posi-grip" aria-hidden="true"><?= admin_icon('grip', 16) ?></span></td>
        <td>
          <div class="pos-who">
            <?php if (!empty($it['image_key'])): ?><img src="<?= e(storage_url((string)$it['image_key'])) ?>" alt="" class="pos-thumb" loading="lazy"><?php else: ?><span class="pos-thumb"><?= admin_icon('image', 14) ?></span><?php endif; ?>
            <span><a href="<?= e($eUrl) ?>" data-drawer="<?= e($eUrl) ?>" data-drawer-key="item-<?= (int)$it['id'] ?>"><b><?= e($it['name']) ?></b></a>
              <?php if (!empty($it['sku'])): ?><small>SKU <?= e($it['sku']) ?></small><?php endif; ?></span>
          </div>
        </td>
        <td class="text-muted"><?= e($it['category_name'] ?? '—') ?></td>
        <td>
          <?php if (!empty($it['tour_id'])): ?><span class="badge badge--teal" data-tip="Price follows the activity">Activity</span><?php elseif (!empty($it['consignor_id'])): ?><span class="badge badge--purple" data-tip="<?= e($it['consignor_name']) ?>">Consignment</span><?php elseif ($it['kind'] === 'service'): ?><span class="badge badge--grey">Service</span><?php else: ?><span class="badge badge--grey">Ours</span><?php endif; ?>
          <?php if (!empty($it['tour_id']) && !pos_bool($it['tour_published'])): ?><span class="badge badge--orange">Unpublished</span><?php endif; ?>
        </td>
        <td class="posi-num"><?= $noFx !== null ? '<span class="badge badge--orange" data-tip="' . e($noFx) . '">No exchange rate</span>' : ($price === null ? '<span class="text-muted">Open price</span>' : '<b>' . e(pos_money($price, $cur)) . '</b>' . (pos_bool($it['per_person']) || (!empty($it['tour_id']) && $it['price'] === null && pos_bool($it['tour_per_person'])) ? ' <span class="text-muted">pp</span>' : '')) ?></td>
        <td class="posi-num"><?php if ($tracked): ?><?php if ($out): ?><span class="badge badge--red">Out</span><?php elseif ($low): ?><span class="badge badge--orange"><?= (int)$it['stock_on_hand'] ?> · low</span><?php else: ?><span class="badge badge--green"><?= (int)$it['stock_on_hand'] ?></span><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td>
          <form method="POST" action="<?= $self ?>" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button type="submit" class="posi-status badge <?= pos_bool($it['is_active']) ? 'badge--green' : 'badge--grey' ?>" data-tip="<?= pos_bool($it['is_active']) ? 'Hide from the till' : 'Put on sale' ?>"><?= pos_bool($it['is_active']) ? 'On sale' : 'Hidden' ?></button>
          </form>
        </td>
        <td class="posi-act">
          <a href="<?= e($eUrl) ?>" class="btn-icon btn-icon--outline" data-drawer="<?= e($eUrl) ?>" data-drawer-key="item-<?= (int)$it['id'] ?>" data-tip="Edit" aria-label="Edit <?= e($it['name']) ?>"><?= admin_icon('edit', 15) ?></a>
          <form method="POST" action="<?= $self ?>" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button type="submit" class="btn-icon btn-icon--outline btn-icon--danger" data-confirm="Delete <?= e($it['name']) ?>? An item with sales is hidden instead." data-confirm-label="Delete" data-tip="Delete" aria-label="Delete <?= e($it['name']) ?>"><?= admin_icon('trash', 15) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="posi-none text-muted" id="posiNone" hidden>No items match.</p>
  <?php endif; ?>
</div>

<style>
.card__head{flex-wrap:wrap;gap:8px 12px}
.posi-tours,.posi-formcard{margin-bottom:16px}
.posi-formcard{display:flex;flex-direction:column}
.posi-form{display:flex;flex-direction:column;gap:6px}
.posi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0 14px}
.posi-grid .inp{width:100%}
.posi-span2{grid-column:1/-1}
.posi-chips{display:flex;flex-wrap:wrap;gap:8px}
.posi-chip-sub{opacity:.65;font-size:11.5px;margin-left:3px}
.field .inp-money{display:flex;width:100%}.field .inp-money .inp{flex:1;min-width:0;width:auto}
.posi-help{font-size:12px;color:var(--muted);margin:5px 0 0}
.posi-toggles{display:flex;flex-direction:column;gap:10px;margin:4px 0}
.posi-sub{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:16px 0 8px}
.posi-stock{margin-top:12px}
.posi-stock.is-hidden{display:none}
.posi-onhand{font-size:14px;padding:9px 0}
.posi-onhand a{font-size:12.5px;margin-left:8px}
.posi-photo{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.posi-table td{vertical-align:middle}
.posi-table tr.is-off td{opacity:.6}
.posi-table tr.is-dragging{opacity:.4}
.posi-table .pos-who a{color:inherit;text-decoration:none}
.posi-grip{color:var(--muted);cursor:grab;display:inline-flex}
.posi-thumb{width:38px;height:38px;border-radius:8px;object-fit:cover;flex:0 0 auto;background:var(--bg,#f4f2ee)}
.posi-thumb--lg{width:84px;height:84px}
.posi-num{text-align:right;white-space:nowrap}
.posi-status{border:0;cursor:pointer;font:inherit;font-size:11.5px}
.posi-act{white-space:nowrap;text-align:right}
.posi-act > *{display:inline-flex;vertical-align:middle;margin-left:4px}
.posi-none{padding:28px;text-align:center;margin:0}
@media (max-width:640px){#posiTable th:nth-child(1),#posiTable td:nth-child(1),#posiTable th:nth-child(3),#posiTable td:nth-child(3),#posiTable th:nth-child(4),#posiTable td:nth-child(4),#posiTable th:nth-child(7),#posiTable td:nth-child(7){display:none}#posiTable .pos-thumb{display:none}#posiTable .posi-act>*{margin-left:2px}}
</style>
<script>
(function(){
  // The add / edit form's own behaviour — on the page and in the side drawer.
  function formInit(root){
    var track = root.querySelector('[data-posi-track]'), box = root.querySelector('[data-posi-stock]');
    function sync(){ if (box) box.classList.toggle('is-hidden', !(track && track.checked)); }
    if (track && !track.dataset.ready) { track.dataset.ready = '1'; track.addEventListener('change', sync); } sync();
    var tour = root.querySelector('[data-posi-tour]'), help = root.querySelector('[data-posi-price-help]');
    function hint(){ if (!tour || !help) return; var o = tour.options[tour.selectedIndex], p = o ? o.getAttribute('data-price') : null;
      help.textContent = tour.value === '0' ? 'Blank = staff enter a price at the till.'
        : (p ? 'Blank = the activity price: ' + p + '. Kept in step automatically.' : 'Blank = the activity is on request, so staff enter a price at the till.'); }
    if (tour && !tour.dataset.ready) { tour.dataset.ready = '1'; tour.addEventListener('change', hint); } hint();
    root.querySelectorAll('.filefield input[type=file]').forEach(function(i){ if (i.dataset.ready) return; i.dataset.ready = '1'; i.addEventListener('change', function(){ var n = i.parentNode.querySelector('.filefield__name'); if (n) n.textContent = i.files[0] ? i.files[0].name : ''; }); });
  }
  formInit(document);
  if (!window.__posiDrawerBound) {
    window.__posiDrawerBound = true;
    document.addEventListener('adrawer:loaded', function (e) { if (e.detail && e.detail.panel && e.detail.panel.querySelector('.posi-form')) formInit(e.detail.panel); });
  }

  // "From activities" panel.
  var tb = document.getElementById('posiToursBtn'), tp = document.getElementById('posiTours');
  if (tb && tp) tb.addEventListener('click', function(){ var open = tp.hidden; tp.hidden = !open; tb.setAttribute('aria-expanded', open ? 'true' : 'false'); });

  // Filter chips + search (client-side over this outlet's items; the pager pages what is left).
  var rows = [].slice.call(document.querySelectorAll('#posiRows tr')), chips = document.getElementById('posiChips'),
      search = document.getElementById('posiSearch'), none = document.getElementById('posiNone'), want = 'all';
  function apply(){
    var q = (search && search.value || '').trim().toLowerCase(), shown = 0;
    rows.forEach(function(r){
      var ok = (want === 'all' || (' ' + r.dataset.tags + ' ').indexOf(' ' + want + ' ') >= 0) && (!q || r.dataset.find.indexOf(q) >= 0);
      r.hidden = !ok; if (ok) shown++;
    });
    if (none) none.hidden = shown > 0;
    if (window.tsTableFit) window.tsTableFit();
  }
  if (chips) chips.addEventListener('click', function(e){ var b = e.target.closest('[data-posi-filter]'); if (!b) return;
    want = b.dataset.posiFilter; chips.querySelectorAll('.pos-chip').forEach(function(c){ c.classList.toggle('is-on', c === b); }); apply(); });
  if (search) search.addEventListener('input', apply);

  // Drag reorder (the till order).
  var body = document.getElementById('posiRows'); if (!body) return;
  var CSRF = <?= json_encode(csrf_token()) ?>, dragged = null;
  rows.forEach(function(row){
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
