<?php
/**
 * Admin: one inventory item — details, where it is, its serial units, its
 * history, and the everyday actions: receive (or add a unit), move / assign /
 * return, report a loss, replace. Owner + manager. Every action goes through
 * inv_apply_item_action(), which re-checks scope and uses the inventory core.
 * ?new=1 creates an item. A POS-linked item's name, SKU, photo, currency, alert
 * and tracking are owned by its POS listing and edited there.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/pos.php';               // pos_upload_item_image() (shared photo pipeline)
require_login();
require_manager();

$self      = '/admin/inventory-item.php';
$vids      = admin_venue_ids();
$me        = current_admin();
$supported = inv_supported();
$id        = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);
$isNew     = !$id && isset($_GET['new']);
$item      = $id ? inv_fetch_item($id) : false;

function inv_item_go(string $url): never { header('Location: ' . $url); exit; }

$flash  = $_SESSION['inv_flash'] ?? null;  unset($_SESSION['inv_flash']);
$errors = $_SESSION['inv_errors'] ?? [];   unset($_SESSION['inv_errors']);
$old    = $_SESSION['inv_old'] ?? null;    unset($_SESSION['inv_old']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        if ($id && !$item) { $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'That item no longer exists.']; inv_item_go('/admin/inventory.php'); }
        $listings  = $item ? inv_item_pos_listings((int)$item['id']) : [];
        $posLinked = (bool)$listings;
        // A POS-linked item's POS-owned fields are not on the form — keep the stored ones.
        $in = $posLinked ? array_merge($_POST, ['name' => $item['name'], 'sku' => (string)$item['sku'], 'currency' => $item['currency'],
                                                'low_stock_at' => (string)$item['low_stock_at'], 'tracking' => $item['tracking']]) : $_POST;
        [$v, $e] = inv_item_from_post($in);
        $img = null;
        if (!$e && !$posLinked) {
            try { $up = pos_upload_item_image($_FILES['image'] ?? []); if ($up !== '') $img = $up; }
            catch (PosRefusal $ex) { $e['image'] = $ex->getMessage(); }
        }
        if ($e) {
            $_SESSION['inv_errors'] = $e; $_SESSION['inv_old'] = $_POST;
            inv_item_go($item ? "{$self}?id={$id}#details" : "{$self}?new=1");
        }
        try {
            if ($item) {
                if ($img !== null) $v['image_key'] = $img;
                elseif (!empty($_POST['remove_image']) && !$posLinked) $v['image_key'] = null;
                inv_update_item($id, $v, $posLinked, is_owner());   // managers can't change an existing item's value, currency or switch it off
                audit_log('inv.item_save', 'inv_item', $id, $v['name']);
                $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "{$item['name']} saved."];
                inv_item_go("{$self}?id={$id}");
            }
            $newId = inv_create_item($v + ['image_key' => $img]);
            audit_log('inv.item_add', 'inv_item', $newId, $v['name']);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "{$v['name']} added. Receive its stock below."];
            inv_item_go("{$self}?id={$newId}#actions");
        } catch (InvRefusal $ex) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()]; $_SESSION['inv_old'] = $_POST;
            inv_item_go($item ? "{$self}?id={$id}#details" : "{$self}?new=1");
        }
    }
    if (!$item) { $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'That item no longer exists.']; inv_item_go('/admin/inventory.php'); }
    try {
        $msg = inv_apply_item_action($_POST, $item, $vids, (int)$me['id']);
        audit_log('inv.' . preg_replace('/[^a-z_]/', '', $act), 'inv_item', $id, $msg);
        $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $msg];
    } catch (InvRefusal $ex) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()];
        $_SESSION['inv_old'] = $_POST;
    }
    inv_item_go("{$self}?id={$id}#actions");
}

if (!$isNew && !$item) {
    http_response_code(404);
    $pageTitle = 'Inventory'; $activeMenu = 'inventory';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Item not found. <a href="/admin/inventory.php">Back to Inventory</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

$locs      = $supported ? array_values(array_filter(inv_locations_visible($vids), fn($l) => $l['kind'] !== 'person')) : [];
$where     = $item ? inv_item_locations($id, $vids) : [];
$serial    = $item && $item['tracking'] === 'serial';
$units     = $serial ? inv_item_units($id, $vids) : [];
$active    = array_values(array_filter($units, fn($u) => $u['status'] === 'active'));
$history   = $item ? inv_item_history($id, $vids, 100) : [];
$staff     = $supported ? inv_assignable_staff($vids) : [];
$listings  = $item ? inv_item_pos_listings($id) : [];
$posLinked = (bool)$listings;
$hasMoves  = $item ? inv_item_has_moves($id) : false;
$ownerOnly = $item && !is_owner();   // an existing item's value, currency and on/off switch are owner business
$holding   = array_values(array_filter($where, fn($w) => (int)$w['qty'] > 0));
$totalQty  = array_sum(array_map(fn($w) => (int)$w['qty'], $where));
// The details form refills from old input only after a refused SAVE — a refused stock
// action's post carries none of the details, and would blank them on the next Save.
$saveOld   = (($old['action'] ?? '') === 'save') ? $old : null;
$form      = $saveOld ?? ($item ?: ['item_type' => 'operational', 'tracking' => 'qty', 'currency' => INV_DEFAULT_CURRENCY, 'is_active' => true, 'unit_label' => 'pcs']);
$val       = fn(string $k): string => (string)($form[$k] ?? '');
$err       = fn(string $k): string => isset($errors[$k]) ? '<div class="inv-err">' . e($errors[$k]) . '</div>' : '';
$cur       = $item ? (string)$item['currency'] : INV_DEFAULT_CURRENCY;
$oldAct    = (string)($old['action'] ?? '');
$panel     = in_array($oldAct, ['receive', 'add_unit', 'transfer', 'loss', 'replace'], true) ? $oldAct : ($serial ? 'add_unit' : 'receive');
// Single-ended actions (receive / add a unit / loss / replace) need a place the account
// OWNS: inv_move_in_scope() only lets a manager use a shared place (Main stock, a
// venue-less outlet) as the other end of a move touching their own property. Transfer
// keeps the full visible lists.
$ownLocs     = array_values(array_filter($locs, fn($l) => inv_location_editable($l, $vids)));
$ownHolding  = array_values(array_filter($holding, fn($w) => inv_location_editable($w, $vids)));
$ownActive   = array_values(array_filter($active, fn($u) => inv_location_editable(['venue_id' => $u['venue_id'], 'kind' => $u['kind']], $vids)));
$eachValue   = $item && $item['replacement_value'] !== null ? inv_money((float)$item['replacement_value'], $cur) : null;
$eachLine    = '<p class="text-muted" style="font-size:13px;margin:0 0 12px">' . ($eachValue !== null ? 'Each: ' . e($eachValue) : 'Value not set') . '</p>';
$lossConfirm = 'Record this loss? It is written to the history' . ($eachValue !== null ? " at {$eachValue} each." : ' with no value (none is set).');
$noOpts      = fn(string $msg): string => '<p class="text-muted" style="font-size:13px;margin:0 0 12px">' . e($msg) . '</p>';
$locOpts   = function (array $list, int $selected = 0): string {
    $h = '';
    foreach ($list as $l) $h .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selected ? ' selected' : '') . '>' . e(inv_location_label($l)) . '</option>';
    return $h;
};
$holdOpts  = function (array $list): string {
    $h = '';
    foreach ($list as $w) $h .= '<option value="' . (int)$w['id'] . '">' . e(inv_location_label($w)) . ' — ' . (int)$w['qty'] . '</option>';
    return $h;
};
$unitOpts  = function (array $list): string {
    $h = '';
    foreach ($list as $u) $h .= '<option value="' . (int)$u['id'] . '">'
        . e(($u['serial'] ?: 'Unit #' . $u['id']) . ' — ' . inv_location_label(['kind' => $u['kind'], 'name' => $u['location_name'], 'parent_name' => $u['parent_name']])) . '</option>';
    return $h;
};

$pageTitle  = $item ? (string)$item['name'] : 'New item';
$activeMenu = 'inventory_item';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $item ? e($item['name']) : 'New item' ?></h1>
  <a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>

<div class="inv-grid">
  <div class="inv-stack">
    <?php if ($item): ?>
    <div class="card">
      <div class="inv-kpis">
        <div class="inv-name"><?= inv_thumb_html($item, 44) ?><span><strong><?= e(INV_TYPES[$item['item_type']] ?? $item['item_type']) ?></strong>
          <span class="inv-sub"><?= $item['category'] ? e($item['category']) . ' · ' : '' ?><?= $serial ? 'Tracked by serial number' : 'Counted' ?><?= $item['sku'] ? ' · SKU ' . e($item['sku']) : '' ?></span></span></div>
        <div class="inv-kpi"><span>Total you can see</span><strong><?= (int)$totalQty ?> <?= e((string)$item['unit_label']) ?></strong></div>
        <div class="inv-kpi"><span>Each</span><strong><?= e(inv_money($item['replacement_value'] !== null ? (float)$item['replacement_value'] : null, $cur)) ?></strong></div>
        <div class="inv-kpi"><span>Total value</span><strong><?= e(inv_money($item['replacement_value'] !== null ? round((float)$item['replacement_value'] * $totalQty, 2) : null, $cur)) ?></strong></div>
      </div>
      <?php if ($listings && !is_owner()): ?>
      <div style="padding:10px 18px;font-size:13px" class="text-muted">Sold at the till (<?= count($listings) ?> outlet<?= count($listings) === 1 ? '' : 's' ?>)</div>
      <?php elseif ($listings): ?>
      <div style="padding:10px 18px;font-size:13px" class="text-muted">Sold at the till: <?php foreach ($listings as $i => $pl): ?><?= $i ? ', ' : '' ?><a href="/admin/pos-items.php?outlet=<?= (int)$pl['outlet_id'] ?>&edit=<?= (int)$pl['id'] ?>#form"><?= e($pl['outlet_name']) ?></a><?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__head"><span class="card__title">Where it is</span></div>
      <?php if (!$where): ?>
        <?php dt_empty('None anywhere you can see yet. Receive stock to get started.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Location</th><th class="inv-num">Qty</th><th class="inv-num">Should have</th></tr></thead>
        <tbody>
        <?php foreach ($where as $w): $q = (int)$w['qty']; ?>
          <tr>
            <td><?php if ($w['kind'] !== 'person'): ?><a href="/admin/inventory-location.php?id=<?= (int)$w['id'] ?>"><?= e(inv_location_label($w)) ?></a><?php else: ?><?= e(inv_location_label($w)) ?><?php endif; ?></td>
            <td class="inv-num"><strong class="<?= $q < 0 ? 'text-danger' : '' ?>"><?= $q ?></strong></td>
            <td class="inv-num text-muted"><?= $w['par_qty'] === null ? '—' : (int)$w['par_qty'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <?php if ($serial): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Units</span><span class="text-muted" style="font-size:12.5px"><?= count($active) ?> in use</span></div>
      <?php if (!$units): ?>
        <?php dt_empty('No units yet. Add each one with its serial number.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Serial / tag</th><th>Where</th><th>Condition</th><th class="inv-num">Bought for</th></tr></thead>
        <tbody>
        <?php foreach ($units as $u): ?>
          <tr>
            <td><strong><?= e($u['serial'] ?: 'Unit #' . $u['id']) ?></strong><?= $u['tag'] ? ' <span class="text-muted">· ' . e($u['tag']) . '</span>' : '' ?></td>
            <td><?php if ($u['status'] === 'active'): ?><?= e(inv_location_label(['kind' => $u['kind'], 'name' => $u['location_name'], 'parent_name' => $u['parent_name']])) ?>
                <?php else: ?><span class="badge <?= $u['status'] === 'sold' ? 'badge--blue' : 'badge--red' ?>"><?= $u['status'] === 'sold' ? 'Sold' : 'Written off' ?></span><?php endif; ?></td>
            <td><?= e(INV_CONDITION_LABELS[$u['condition']] ?? $u['condition']) ?></td>
            <td class="inv-num text-muted"><?= e(inv_money($u['purchase_value'] !== null ? (float)$u['purchase_value'] : null, $cur)) ?><?= $u['purchase_date'] ? '<div class="inv-sub">' . e(date('j M Y', strtotime((string)$u['purchase_date']))) . '</div>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card" id="history">
      <div class="card__head"><span class="card__title">History</span></div>
      <?php if (!$history): ?>
        <?php dt_empty('No movements yet.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>When</th><th>What</th><th class="inv-num">Qty</th><th>From → to</th><th class="inv-num">Value</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($history as $m): [$lbl, $cls] = INV_REASON_LABELS[$m['reason']] ?? [$m['reason'], 'badge--grey']; ?>
          <tr>
            <td class="text-muted inv-nowrap"><?= e(date('j M Y, H:i', strtotime((string)$m['created_at']))) ?></td>
            <td><span class="badge <?= e($cls) ?>"><?= e($lbl) ?></span><?= $m['serial'] ? ' <span class="text-muted">' . e($m['serial']) . '</span>' : '' ?>
              <?php if (!empty($m['note']) || !empty($m['sale_reference'])): ?><div class="text-muted inv-note"><?= e((string)($m['note'] ?: $m['sale_reference'])) ?></div><?php endif; ?></td>
            <td class="inv-num"><?= (int)$m['qty'] ?></td>
            <td><?= e($m['from_name'] ?? '—') ?> → <?= e($m['to_name'] ?? ($m['reason'] === 'sale' ? 'sold' : '—')) ?></td>
            <td class="inv-num text-muted"><?= e(inv_money($m['value'] !== null ? (float)$m['value'] : null, (string)$m['currency'])) ?></td>
            <td class="text-muted"><?= e($m['user_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="inv-stack">
    <?php if ($item): ?>
    <div class="card" id="actions">
      <div class="card__head"><span class="card__title">Move stock</span></div>
      <div class="card__body" style="padding:16px 18px">
        <div class="inv-chips" role="radiogroup" aria-label="Stock action">
          <?php $tabs = $serial ? ['add_unit' => 'Add a unit', 'transfer' => 'Move / assign', 'loss' => 'Report a loss']
                                : ['receive' => 'Receive', 'transfer' => 'Move / assign', 'loss' => 'Report a loss', 'replace' => 'Replace'];
          foreach ($tabs as $k => $lbl): ?>
          <label class="optchip"><input type="radio" name="inv_panel" value="<?= e($k) ?>" <?= $panel === $k ? 'checked' : '' ?>><?= e($lbl) ?></label>
          <?php endforeach; ?>
        </div>

        <?php if (!$serial): ?>
        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="receive" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="receive">
          <div class="inv-row2">
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
            <div class="field"><label>Unit cost <span class="text-muted">(optional)</span></label><input name="unit_cost" type="number" class="inp inp--num no-spin" min="0" step="0.01" placeholder="<?= e($cur) ?>"></div>
          </div>
          <?php if ($ownLocs): ?>
          <div class="field"><label>Arrived at</label><select name="to_id" class="eselect eselect--block" required><?= $locOpts($ownLocs) ?></select></div>
          <?php else: ?><?= $noOpts('No places you manage.') ?><?php endif; ?>
          <div class="field"><label>Note <span class="text-muted">(supplier, invoice #)</span></label><input name="note" class="inp" maxlength="500"></div>
          <?php if ($ownLocs): ?><button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Receive</button><?php endif; ?>
        </form>
        <?php else: ?>
        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="add_unit" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="add_unit">
          <div class="inv-row2">
            <div class="field"><label>Serial number</label><input name="serial" class="inp" maxlength="80"></div>
            <div class="field"><label>Tag <span class="text-muted">(optional)</span></label><input name="tag" class="inp" maxlength="40"></div>
          </div>
          <div class="inv-row2">
            <div class="field"><label>Condition</label><select name="condition" class="eselect eselect--block"><?php foreach (INV_CONDITION_LABELS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $k === 'good' ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Bought for <span class="text-muted">(optional)</span></label><input name="purchase_value" type="number" class="inp inp--num no-spin" min="0" step="0.01" placeholder="<?= e($cur) ?>"></div>
          </div>
          <div class="field"><label>Bought on <span class="text-muted">(optional)</span></label>
            <button type="button" class="dp-btn" data-dp-target="invBought" data-dp-past data-dp-placeholder="Pick a date" style="width:100%">Pick a date</button>
            <input type="hidden" id="invBought" name="purchase_date" value=""></div>
          <?php if ($ownLocs): ?>
          <div class="field"><label>Where it is</label><select name="to_id" class="eselect eselect--block" required><?= $locOpts($ownLocs) ?></select></div>
          <?php else: ?><?= $noOpts('No places you manage.') ?><?php endif; ?>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500"></div>
          <?php if ($ownLocs): ?><button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add unit</button><?php endif; ?>
        </form>
        <?php endif; ?>

        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="transfer" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="transfer">
          <?php if ($serial): ?>
          <div class="field"><label>Which unit</label><select name="asset_id" class="eselect eselect--block" required><?= $unitOpts($active) ?></select></div>
          <?php else: ?>
          <div class="inv-row2">
            <div class="field"><label>From</label><select name="from_id" class="eselect eselect--block" required><?= $holdOpts($holding) ?></select></div>
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
          </div>
          <?php endif; ?>
          <div class="field"><label>To</label>
            <select name="to" class="eselect eselect--block" required>
              <optgroup label="Places"><?php foreach ($locs as $l): ?><option value="loc:<?= (int)$l['id'] ?>"><?= e(inv_location_label($l)) ?></option><?php endforeach; ?></optgroup>
              <?php if ($staff): ?><optgroup label="Team members"><?php foreach ($staff as $sid => $sname): ?><option value="staff:<?= (int)$sid ?>"><?= e($sname) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500"></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('arrow-right', 15) ?> Move</button>
        </form>

        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="loss" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="loss">
          <?= $eachLine ?>
          <?php $lossOk = $serial ? (bool)$ownActive : (bool)$ownHolding; ?>
          <?php if (!$lossOk): ?>
          <?= $noOpts($serial ? 'No units in stock you manage.' : 'Nothing in stock at your places — receive first.') ?>
          <?php elseif ($serial): ?>
          <div class="field"><label>Which unit</label><select name="asset_id" class="eselect eselect--block" required><?= $unitOpts($ownActive) ?></select></div>
          <?php else: ?>
          <div class="inv-row2">
            <div class="field"><label>Where</label><select name="from_id" class="eselect eselect--block" required><?= $holdOpts($ownHolding) ?></select></div>
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
          </div>
          <?php endif; ?>
          <div class="field"><label>What happened</label>
            <div class="inv-chips"><?php foreach (INV_LOSS_LABELS as $k => $lbl): ?><label class="optchip"><input type="radio" name="reason" value="<?= e($k) ?>" <?= $k === 'broken' ? 'checked' : '' ?>><?= e($lbl) ?></label><?php endforeach; ?></div></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500" placeholder="What happened, who reported it"></div>
          <?php if ($lossOk): ?><button type="submit" class="btn-primary btn-sm" onclick="return confirm(<?= e(json_encode($lossConfirm)) ?>)"><?= admin_icon('check', 15) ?> Record loss</button><?php endif; ?>
        </form>

        <?php if (!$serial): ?>
        <form method="POST" action="<?= $self ?>" class="inv-form" data-inv-panel="replace" novalidate>
          <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $id ?>"><input type="hidden" name="action" value="replace">
          <p class="text-muted" style="font-size:13px;margin:0 0 12px">Records the loss and brings the same number in from Main stock, in one step.</p>
          <?= $eachLine ?>
          <?php if ($ownHolding): ?>
          <div class="inv-row2">
            <div class="field"><label>Where</label><select name="at_id" class="eselect eselect--block" required><?= $holdOpts($ownHolding) ?></select></div>
            <div class="field"><label>Quantity</label><input name="qty" type="number" class="inp inp--num no-spin" min="1" step="1" required></div>
          </div>
          <?php else: ?><?= $noOpts('Nothing in stock at your places — receive first.') ?><?php endif; ?>
          <div class="field"><label>What happened</label>
            <div class="inv-chips"><?php foreach (INV_LOSS_LABELS as $k => $lbl): ?><label class="optchip"><input type="radio" name="reason" value="<?= e($k) ?>" <?= $k === 'broken' ? 'checked' : '' ?>><?= e($lbl) ?></label><?php endforeach; ?></div></div>
          <div class="field"><label>Note</label><input name="note" class="inp" maxlength="500"></div>
          <?php if ($ownHolding): ?><button type="submit" class="btn-primary btn-sm"><?= admin_icon('rotate', 15) ?> Replace</button><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card" id="details">
      <div class="card__head"><span class="card__title"><?= $item ? 'Details' : 'Add an item' ?></span></div>
      <div class="card__body" style="padding:16px 18px">
        <form method="POST" action="<?= $self ?><?= $item ? '?id=' . $id : '' ?>" enctype="multipart/form-data" class="inv-form" novalidate>
          <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($item): ?><input type="hidden" name="item_id" value="<?= $id ?>"><?php endif; ?>
          <?php if ($posLinked): ?><p class="text-muted" style="font-size:13px;margin:0 0 12px">Name, SKU, photo, currency and the low-stock alert come from the POS catalogue.</p><?php endif; ?>
          <?php if (!$posLinked): ?>
          <div class="field"><label>Name</label><input name="name" class="inp" maxlength="160" value="<?= e($val('name')) ?>" required><?= $err('name') ?></div>
          <?php endif; ?>
          <div class="inv-row2">
            <div class="field"><label>Type</label><select name="item_type" class="eselect eselect--block">
              <?php foreach (INV_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $val('item_type') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select><?= $err('item_type') ?></div>
            <div class="field"><label>Category</label><input name="category" class="inp" maxlength="60" list="invCats" value="<?= e($val('category')) ?>" placeholder="Kitchen, Linen, Electronics…"><?= $err('category') ?>
              <datalist id="invCats"><?php foreach (inv_categories() as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
          </div>
          <?php if ($ownerOnly): ?>
          <div class="field"><label>Replacement value <span class="text-muted">(each)</span></label>
            <div><strong><?= e(inv_money($item['replacement_value'] !== null ? (float)$item['replacement_value'] : null, $cur)) ?></strong>
              <span class="text-muted" style="font-size:12px">· set by the owner</span></div></div>
          <?php else: ?>
          <div class="inv-row2">
            <div class="field"><label>Replacement value <span class="text-muted">(each)</span></label><input name="replacement_value" type="number" class="inp inp--num no-spin" min="0" step="0.01" value="<?= e($val('replacement_value')) ?>"><?= $err('replacement_value') ?></div>
            <?php if (!$posLinked): ?>
            <div class="field"><label>Currency</label><select name="currency" class="eselect eselect--block">
              <?php foreach (array_keys(TS_CURRENCIES) as $c): ?><option value="<?= e($c) ?>" <?= strtoupper($val('currency') ?: INV_DEFAULT_CURRENCY) === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select><?= $err('currency') ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="inv-row2">
            <div class="field"><label>Icon <span class="text-muted">(one emoji)</span></label><input name="icon" class="inp" maxlength="8" value="<?= e($val('icon')) ?>" placeholder="🍽️"><?= $err('icon') ?></div>
            <div class="field"><label>Unit</label><input name="unit_label" class="inp" maxlength="20" value="<?= e($val('unit_label')) ?>" placeholder="pcs"></div>
          </div>
          <?php if (!$posLinked): ?>
          <div class="field"><label>Tracking</label>
            <div class="inv-chips">
              <label class="optchip"><input type="radio" name="tracking" value="qty" <?= $val('tracking') !== 'serial' ? 'checked' : '' ?> <?= $hasMoves ? 'disabled' : '' ?>>Counted (plates, towels)</label>
              <label class="optchip"><input type="radio" name="tracking" value="serial" <?= $val('tracking') === 'serial' ? 'checked' : '' ?> <?= $hasMoves ? 'disabled' : '' ?>>By serial number (laptops, phones)</label>
            </div>
            <?php if ($hasMoves): ?><input type="hidden" name="tracking" value="<?= e((string)$item['tracking']) ?>"><div class="text-muted" style="font-size:12px">Fixed once the item has stock history.</div><?php endif; ?></div>
          <div class="inv-row2">
            <div class="field"><label>SKU <span class="text-muted">(optional)</span></label><input name="sku" class="inp" maxlength="60" value="<?= e($val('sku')) ?>"></div>
            <div class="field"><label>Low-stock alert <span class="text-muted">(optional)</span></label><input name="low_stock_at" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= e($val('low_stock_at')) ?>"><?= $err('low_stock_at') ?></div>
          </div>
          <div class="field"><label>Photo</label>
            <div class="filefield">
              <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('image', 15) ?> Choose photo<input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-file-input hidden></label>
              <span class="filefield__name" data-file-name><?= $item && $item['image_key'] ? 'Current photo kept' : 'No photo' ?></span>
            </div>
            <?php if ($item && $item['image_key']): ?><label class="optchip" style="margin-top:8px"><input type="checkbox" name="remove_image" value="1">Remove photo</label><?php endif; ?>
            <?= $err('image') ?></div>
          <?php endif; ?>
          <?php if ($ownerOnly): ?>
          <?php if (inv_bool($form['is_active'] ?? true)): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
          <p class="text-muted" style="font-size:12px;margin:0 0 12px">Only the owner can change this item’s value or currency, or switch it off.</p>
          <?php elseif ($item): ?>
          <div class="field"><label class="optchip"><input type="checkbox" name="is_active" value="1" <?= inv_bool($form['is_active'] ?? true) ? 'checked' : '' ?>>In use (shows in lists)</label></div>
          <?php endif; ?>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $item ? 'Save' : 'Add item' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<script>
(function () {
  // One action panel at a time.
  var chips = document.querySelectorAll('input[name=inv_panel]');
  function show() {
    var on = document.querySelector('input[name=inv_panel]:checked');
    document.querySelectorAll('[data-inv-panel]').forEach(function (f) { f.hidden = !on || f.getAttribute('data-inv-panel') !== on.value; });
  }
  chips.forEach(function (c) { c.addEventListener('change', show); });
  show();
  // Show the chosen photo's file name.
  document.querySelectorAll('#details [data-file-input]').forEach(function (fi) {
    fi.addEventListener('change', function () {
      var box = fi.closest('.filefield'), out = box ? box.querySelector('[data-file-name]') : null;
      if (out && fi.files && fi.files[0]) out.textContent = fi.files[0].name;
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
