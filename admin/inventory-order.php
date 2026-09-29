<?php
/**
 * Admin: one inventory order — the receiving screen. A spreadsheet of what was
 * ordered, with how many arrived and where each batch was put. Saving records
 * receipts through inv_order_receive() (the only writer: inv_move() for counted
 * items, one unit per piece for serial items). Anyone who can see a line of the
 * order may receive it — owner, the manager of the place, or its staff — into
 * places they may move stock into. Only owner/manager can cancel, and only while
 * nothing has been received.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-grid.php';     // inv_grid_css() / inv_grid_js() / inv_grid_ids()
require_once __DIR__ . '/../includes/inventory-orders.php';
require_login();

$self      = '/admin/inventory-order.php';
$orderId   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$supported = inv_orders_supported();
$vids      = admin_venue_ids();
$me        = current_admin();
$meId      = $me ? (int)$me['id'] : null;
$canManage = is_owner() || is_manager();
$flash     = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

$order = $supported ? inv_order_fetch($orderId) : false;
$lines = $order ? inv_order_lines($orderId, $vids) : [];
if ($supported && (!$order || !$lines)) {   // unknown, or nothing of it is visible to this account
    http_response_code(404);
    $order = false; $lines = [];
}
$byLine = [];
foreach ($lines as $l) $byLine[(int)$l['id']] = $l;

// Places this account may put stock into: active, in its scope, never a team member.
$places = [];
if ($supported) {
    foreach (inv_locations_visible($vids) as $l) {
        if ($l['kind'] === 'person' || !inv_move_in_scope(null, $l, $vids)) continue;
        $places[(int)$l['id']] = $l;
    }
}

/** A posted place, re-checked against the account's scope. Returns the location id or 0. */
$okPlace = function (int $id) use ($vids): int {
    $loc = $id > 0 ? inv_fetch_location($id) : false;
    return ($loc && inv_bool($loc['is_active']) && $loc['kind'] !== 'person' && inv_location_visible($loc, $vids) && inv_move_in_scope(null, $loc, $vids)) ? $id : 0;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported && $order) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'cancel') {
            if (!$canManage) throw new InvRefusal('Only the owner or a manager can cancel an order.');
            inv_order_cancel($orderId);
            audit_log('inv.order_cancel', 'inv_order', $orderId, (string)$order['name']);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => 'Order cancelled.'];
        } elseif ($act === 'save' || $act === 'receive_rest') {
            $receipts = [];
            if ($act === 'save') {
                $qtys = (array)($_POST['qty'] ?? []); $locs = (array)($_POST['loc'] ?? []);
                foreach ($qtys as $lineId => $raw) {
                    $raw = trim((string)$raw);
                    if ($raw === '') continue;
                    $l = $byLine[(int)$lineId] ?? null;
                    if (!$l) throw new InvRefusal('That line is not part of this order.');
                    if (!ctype_digit($raw)) throw new InvRefusal("{$l['item_name']}: enter a whole number.");
                    if ((int)$raw === 0) continue;
                    $to = $okPlace((int)($locs[$lineId] ?? 0));
                    if (!$to) throw new InvRefusal("{$l['item_name']}: pick where it goes.");
                    $receipts[(int)$lineId] = ['qty' => (int)$raw, 'location_id' => $to];
                }
                if (!$receipts) throw new InvRefusal('Enter how many arrived on at least one row.');
            } else {
                $putInto = (int)($_POST['put_into'] ?? 0);
                foreach (inv_grid_ids($_POST['ids'] ?? []) as $lineId) {
                    $l = $byLine[$lineId] ?? null;
                    if (!$l) throw new InvRefusal('That line is not part of this order.');
                    if ((int)$l['still_to_come'] < 1) continue;
                    $want = $putInto > 0 ? $putInto : (int)($l['planned_location_id'] ?? 0);
                    $to = $okPlace($want);
                    if (!$to) throw new InvRefusal("{$l['item_name']}: pick where it goes.");
                    $receipts[$lineId] = ['qty' => (int)$l['still_to_come'], 'location_id' => $to];
                }
                if (!$receipts) throw new InvRefusal('Tick at least one row that still has something to come.');
            }
            $r = inv_order_receive($orderId, $receipts, $meId);
            audit_log('inv.order_receive', 'inv_order', $orderId, "{$r['pieces']} pcs on {$r['lines']} line(s)");
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "Received {$r['pieces']} piece" . ($r['pieces'] === 1 ? '' : 's') . " on {$r['lines']} line" . ($r['lines'] === 1 ? '' : 's') . '.'];
        }
    } catch (InvRefusal $ex) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()];
    }
    header('Location: ' . $self . '?id=' . $orderId); exit;
}

$piecesOrdered = $piecesReceived = 0;
foreach ($lines as $l) { $piecesOrdered += (int)$l['qty_ordered']; $piecesReceived += (int)$l['qty_received']; }
$anyReceived = $piecesReceived > 0;
$open        = $order && $order['status'] !== 'cancelled';
$badge       = ['open' => 'badge--grey', 'partial' => 'badge--orange', 'received' => 'badge--green', 'cancelled' => 'badge--grey'];

$pageTitle  = $order ? (string)$order['name'] : 'Order';
$activeMenu = 'inventory_orders';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $order ? e((string)$order['name']) : 'Order' ?></h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/inventory-orders.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Orders</a>
    <?php if ($order && $canManage && $open && !$anyReceived): ?>
    <form method="POST" action="<?= $self ?>" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $orderId ?>"><input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn-danger btn-sm" data-confirm="Cancel this order? Nothing has been received yet."><?= admin_icon('x', 15) ?> Cancel order</button></form>
    <?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory_orders.sql</code> migration (Admin → Migrations) to set up orders.</div>
<?php elseif (!$order): ?>
  <?php dt_empty('That order isn’t available to you.'); ?>
<?php else: ?>
<p class="text-muted" style="margin:-4px 0 14px;font-size:13px">
  <span class="badge <?= e($badge[$order['status']] ?? 'badge--grey') ?>"><?= e(INV_ORDER_STATUSES[$order['status']] ?? (string)$order['status']) ?></span>
  Received <strong><?= $piecesReceived ?></strong> of <strong><?= $piecesOrdered ?></strong> pieces · created <?= e(date('j M Y', strtotime((string)$order['created_at']))) ?><?= $order['created_by_name'] ? ' by ' . e((string)$order['created_by_name']) : '' ?>
  <?php if ($open): ?> — type how many arrived on each row and where they were put, then save. Partial deliveries are fine; come back for the rest.<?php endif; ?>
</p>

<form method="POST" action="<?= $self ?>" id="igForm">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $orderId ?>">
  <div class="ig-bar">
    <label class="ig-field"><span>Show</span>
      <select id="ioFilter" class="filter-select" aria-label="Show">
        <option value="">All lines</option>
        <option value="left">Still to come</option>
        <option value="done">Received</option>
      </select></label>
    <label class="ig-field ig-search"><span>Search</span><input type="search" id="igSearch" class="inp" placeholder="Name, item no., place…" autocomplete="off"></label>
    <?php if ($open): ?>
    <label class="ig-field"><span>Put into</span>
      <select name="put_into" id="ioPutInto" class="filter-select" aria-label="Put into">
        <option value="0">Planned place</option>
        <?php foreach ($places as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e(inv_location_label($l)) ?></option><?php endforeach; ?>
      </select></label>
    <button type="submit" name="action" value="save" class="btn-primary btn-sm" style="align-self:flex-end"><?= admin_icon('check', 14) ?> Save received</button>
    <?php endif; ?>
  </div>

  <div class="ig-wrap"><table class="ig" id="igTable">
    <thead><tr>
      <th class="ig-check"><input type="checkbox" id="igAll" aria-label="Select all shown"></th>
      <th data-sort="name">Item</th>
      <th data-sort="place">For</th>
      <th data-sort="ordered" class="ig-num">Ordered</th>
      <th data-sort="received" class="ig-num">Received</th>
      <th data-sort="left" class="ig-num">Still to come</th>
      <?php if ($open): ?><th class="ig-num">Receive now</th><th>Into</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l):
      $left = (int)$l['still_to_come']; $planned = (int)($l['planned_location_id'] ?? 0);
      $pre  = isset($places[$planned]) ? $planned : 0;
      $lid  = (int)$l['id']; ?>
      <tr data-name="<?= e(mb_strtolower((string)$l['item_name'] . ' ' . (string)$l['sku'] . ' ' . (string)$l['code'])) ?>" data-place="<?= e(mb_strtolower((string)$l['place_label'])) ?>"
          data-ordered="<?= (int)$l['qty_ordered'] ?>" data-received="<?= (int)$l['qty_received'] ?>" data-left="<?= $left ?>" data-state="<?= $left > 0 ? 'left' : 'done' ?>">
        <td class="ig-check"><?php if ($open && $left > 0): ?><input type="checkbox" name="ids[]" value="<?= $lid ?>" aria-label="Select <?= e((string)$l['item_name']) ?>"><?php endif; ?></td>
        <td><a href="/admin/inventory-item.php?id=<?= (int)$l['item_id'] ?>" class="inv-name"><?= inv_thumb_html($l + ['name' => $l['item_name']], 28) ?><span><?= e((string)$l['item_name']) ?><?= $l['tracking'] === 'serial' ? ' <span class="ig-tag">serial</span>' : '' ?>
          <?php if (!empty($l['sku'])): ?><span class="inv-sub ig-mono"><?= e((string)$l['sku']) ?></span><?php endif; ?></span></a></td>
        <td class="ig-where"><?= $l['place_label'] !== '' ? e((string)$l['place_label']) : '<span class="text-muted">—</span>' ?></td>
        <td class="ig-num"><?= (int)$l['qty_ordered'] ?></td>
        <td class="ig-num"><strong><?= (int)$l['qty_received'] ?></strong>
          <?php foreach ($l['receipts'] as $rc): ?><span class="io-rc"><?= (int)$rc['qty'] ?> → <?= e($rc['location_name']) ?> · <?= e(date('j M', strtotime($rc['created_at']))) ?></span><?php endforeach; ?></td>
        <td class="ig-num"><?= $left > 0 ? $left : '<span class="text-muted">0</span>' ?></td>
        <?php if ($open): ?>
          <?php if ($left > 0): ?>
          <td class="ig-num"><input type="number" name="qty[<?= $lid ?>]" class="inp inp--sm inp--num no-spin io-qty" min="1" max="<?= $left ?>" step="1" inputmode="numeric" aria-label="Received now: <?= e((string)$l['item_name']) ?>"></td>
          <td><select name="loc[<?= $lid ?>]" class="cell-select" aria-label="Into: <?= e((string)$l['item_name']) ?>">
              <option value="0">Pick a place…</option>
              <?php foreach ($places as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $pre === (int)$p['id'] ? 'selected' : '' ?>><?= e(inv_location_label($p)) ?></option><?php endforeach; ?>
            </select></td>
          <?php else: ?>
          <td colspan="2"><span class="badge badge--green">✓ Received</span></td>
          <?php endif; ?>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="text-muted ig-count" id="igCount"></p>

  <div class="ig-bulk" id="igBulk" hidden>
    <strong id="igSel">0 selected</strong>
    <button type="submit" name="action" value="receive_rest" class="btn-primary btn-sm"
      data-confirm="Receive everything still to come on the ticked rows, into the “Put into” place (or each row’s planned place when that is empty)?"><?= admin_icon('check', 14) ?> Receive the rest of the ticked rows</button>
    <span class="text-muted" style="font-size:12.5px">into the “Put into” place, or each row’s planned place when it is empty</span>
    <button type="button" class="btn-outline btn-sm" id="igClear"><?= admin_icon('x', 14) ?> Clear</button>
  </div>
</form>
<?php endif; ?>

<?= inv_shared_css() ?>
<?= inv_grid_css() ?>
<style>
.io-rc{display:block;font-size:11.5px;color:var(--muted);font-weight:400;white-space:nowrap}
.io-qty{width:84px}
.ig .cell-select,.ig .eselect:has(> .cell-select){width:220px;max-width:220px}
.ig td.ig-where{min-width:140px}
</style>
<?php if ($order): ?>
<?= inv_grid_js() ?>
<script>
(function () {
  var table = document.getElementById('igTable'); if (!table) return;
  var search = document.getElementById('igSearch'), state = document.getElementById('ioFilter');
  var g = InvGrid({
    table: table, form: document.getElementById('igForm'), all: document.getElementById('igAll'), bulk: document.getElementById('igBulk'),
    sel: document.getElementById('igSel'), count: document.getElementById('igCount'), clear: document.getElementById('igClear'),
    numeric: ['ordered', 'received', 'left'], noun: 'lines',
    hidden: function (r) {
      var q = (search.value || '').trim().toLowerCase(), s = state.value;
      var hay = r.getAttribute('data-name') + ' ' + r.getAttribute('data-place');
      return (q && hay.indexOf(q) === -1) || (s && r.getAttribute('data-state') !== s);
    }
  });
  search.addEventListener('input', g.filter); state.addEventListener('change', g.filter);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
