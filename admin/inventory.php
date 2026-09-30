<?php
/**
 * Admin: Inventory — one list of everything the company owns or sells, across
 * Main stock, properties (and their areas), POS outlets and team members.
 * Owner + manager; a manager sees their properties plus shared locations (Main
 * stock, shared outlets). Read-only: quantities change on the item page, via
 * inv_move(). "Sold" and "Lost / written off" switch to the movement ledger.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-owner.php';   // owner-only corrections (reset all inventory)
require_once __DIR__ . '/../includes/inventory-grid.php';    // the spreadsheet list + bulk actions
require_once __DIR__ . '/../includes/inventory-orders.php';  // the "On order" column
require_login();
require_manager();

$vids      = admin_venue_ids();
$supported = inv_supported();
$ordersOk  = inv_orders_supported();

$flash = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act  = (string)($_POST['action'] ?? '');
    $back = '/admin/inventory.php' . (((int)($_POST['place'] ?? 0)) ? '?place=' . (int)$_POST['place'] : '');
    $me   = (int)current_admin()['id'];
    $ids  = inv_grid_ids($_POST['ids'] ?? []);
    // Bulk actions on ticked rows. Every posted place is re-checked against the account's scope.
    if (in_array($act, ['bulk_move', 'bulk_category', 'bulk_delete'], true)) {
        try {
            if ($act === 'bulk_move') {
                $from = inv_fetch_location((int)($_POST['place'] ?? 0));
                $to   = inv_fetch_location((int)($_POST['to_id'] ?? 0));
                if (!$from || !inv_location_visible($from, $vids)) throw new InvRefusal('Pick the place to move from first (the Place menu at the top).');
                if (!$to || !inv_location_visible($to, $vids) || !inv_bool($to['is_active'])) throw new InvRefusal('Pick an open place to move to.');
                if (!inv_move_in_scope($from, $to, $vids)) throw new InvRefusal('That move is outside your properties.');
                $r = inv_grid_bulk_move($ids, (int)$from['id'], (int)$to['id'], $me);
                audit_log('inv.bulk_move', 'inv_location', (int)$from['id'], "{$r['items']} items, {$r['pieces']} pcs → {$to['name']}");
                $_SESSION['inv_flash'] = ['type' => $r['skipped'] ? 'info' : 'success', 'msg' =>
                    "Moved {$r['pieces']} piece" . ($r['pieces'] === 1 ? '' : 's') . " of {$r['items']} item" . ($r['items'] === 1 ? '' : 's') . " to {$to['name']}."
                    . ($r['skipped'] ? " {$r['skipped']} skipped (none here, or tracked by serial number — move those on the item page)." : '')];
            } elseif ($act === 'bulk_category') {
                $n = inv_grid_bulk_category($ids, (string)($_POST['category'] ?? ''));
                audit_log('inv.bulk_category', 'inventory', 0, "{$n} items → " . (string)($_POST['category'] ?? ''));
                $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "Category set on {$n} item" . ($n === 1 ? '' : 's') . '.'];
            } else {
                if (!is_owner()) throw new InvRefusal('Only the owner can delete items.');
                $n = inv_grid_bulk_delete($ids, $me);
                audit_log('inv.bulk_delete', 'inventory', 0, "{$n} items");
                $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "Deleted {$n} item" . ($n === 1 ? '' : 's') . '.'];
            }
        } catch (InvRefusal $ex) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()];
        }
        header('Location: ' . $back); exit;
    }
    // Owner-only correction (includes/inventory-owner.php) — this page is its is_owner() gate.
    if ($act === 'reset_all') {
        if (!is_owner()) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'Only the owner can reset inventory.'];
        } elseif (trim((string)($_POST['confirm_text'] ?? '')) !== 'RESET') {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'Type RESET exactly to confirm.'];
        } else {
            try {
                $res = inv_reset_all((int)current_admin()['id']);
                audit_log('inv.reset', 'inventory', 0, "{$res['items']} items, {$res['moves']} moves");
                $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "Inventory reset — {$res['items']} items and {$res['moves']} movements deleted."];
            } catch (InvRefusal $ex) {
                $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $ex->getMessage()];
            }
        }
    }
    header('Location: /admin/inventory.php'); exit;
}

$pg        = paginate_params();
$f = [
    'q'        => $pg['q'],
    'type'     => (string)($_GET['type'] ?? ''),
    'venue'    => (int)($_GET['venue'] ?? 0),
    'location' => (int)($_GET['location'] ?? 0),
    'person'   => (int)($_GET['person'] ?? 0),
    'status'   => (string)($_GET['status'] ?? ''),
    'from'     => (string)($_GET['from'] ?? ''),
    'to'       => (string)($_GET['to'] ?? ''),
];
if (!isset(INV_STATUS_FILTERS[$f['status']])) $f['status'] = '';
if (!isset(INV_TYPES[$f['type']])) $f['type'] = '';

// Filters are requests: anything outside the account's scope is dropped, never honoured.
$locations = $supported ? inv_locations_visible($vids, false) : [];
$byId = []; $people = [];
foreach ($locations as $l) {
    $byId[(int)$l['id']] = $l;
    if ($l['kind'] === 'person' && $l['hr_staff_id'] !== null) $people[(int)$l['hr_staff_id']] = (string)$l['name'];
}
if ($f['location'] && !isset($byId[$f['location']])) $f['location'] = 0;
if ($f['person'] && !isset($people[$f['person']])) $f['person'] = 0;
$venues = $supported ? inv_visible_venues($vids) : [];
if ($f['venue'] && !isset($venues[$f['venue']])) $f['venue'] = 0;

$gone = in_array($f['status'], ['sold', 'written_off'], true);
// The spreadsheet view (everything except the Sold / Lost ledger): one "place" at a time, or all places.
$place = (int)($_GET['place'] ?? 0);
if ($place && !isset($byId[$place])) $place = 0;
$placeRow = $place ? $byId[$place] : null;
$grid = (!$gone && $supported) ? inv_grid_rows($vids, $place, $f['type']) : [];
$onOrder = ($ordersOk && !$gone) ? inv_on_order_by_item() : [];
$gridCats = [];
foreach ($grid as $gr) if ($gr['category'] !== null && $gr['category'] !== '') $gridCats[(string)$gr['category']] = true;
ksort($gridCats, SORT_NATURAL | SORT_FLAG_CASE);
$run  = fn(int $offset): array => $gone ? inv_gone_moves($f, $vids, $pg['per'], $offset) : inv_central_list($f, $vids, $pg['per'], $offset);
$res  = ($supported && $gone) ? $run((int)$pg['offset']) : ['total' => 0, 'rows' => []];
$meta = paginate_meta((int)$res['total'], $pg['page'], $pg['per']);
if ($supported && $gone && $meta['offset'] !== (int)$pg['offset']) $res = $run($meta['offset']);   // page past the end → last page
$filtered = $f['q'] !== '' || $f['type'] || $f['venue'] || $f['location'] || $f['person'] || $f['status'];

ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
  <?php if (!$res['rows']): ?>
    <?php dt_empty($gone ? 'Nothing recorded for these filters in this period.' : ($filtered ? 'No items match these filters.' : 'No items yet — add the first one.')); ?>
  <?php elseif ($gone): ?>
    <?php if (!empty($res['totals'])): ?>
    <div class="inv-kpis">
      <div class="inv-kpi"><span><?= e(INV_STATUS_FILTERS[$f['status']]) ?> · <?= e(date('j M', strtotime($res['from']))) ?> – <?= e(date('j M Y', strtotime($res['to']))) ?></span>
        <?php foreach ($res['totals'] as $cur => $amt): ?><strong><?= e(inv_money((float)$amt, (string)$cur)) ?></strong> <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>When</th><th>Item</th><th>From</th><th>What</th><th class="inv-num">Qty</th><th class="inv-num">Value</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($res['rows'] as $m): [$lbl, $cls] = INV_REASON_LABELS[$m['reason']] ?? [$m['reason'], 'badge--grey']; ?>
        <tr>
          <td class="text-muted inv-nowrap"><?= e(date('j M Y, H:i', strtotime((string)$m['created_at']))) ?></td>
          <td><a href="/admin/inventory-item.php?id=<?= (int)$m['item_id'] ?>" class="inv-name"><?= inv_thumb_html(['image_key' => $m['image_key'], 'icon' => $m['icon'], 'name' => $m['item_name']], 28) ?><span><?= e($m['item_name']) ?></span></a></td>
          <td><?= e($m['location_name']) ?></td>
          <td><span class="badge <?= e($cls) ?>"><?= e($lbl) ?></span><?php if (!empty($m['sale_reference'])): ?> <span class="text-muted"><?= e($m['sale_reference']) ?></span><?php endif; ?>
            <?php if (!empty($m['note'])): ?><div class="text-muted inv-note"><?= e($m['note']) ?></div><?php endif; ?></td>
          <td class="inv-num"><?= (int)$m['qty'] ?></td>
          <td class="inv-num"><?= e(inv_money($m['value'] !== null ? (float)$m['value'] : null, (string)$m['currency'])) ?></td>
          <td class="text-muted"><?= e($m['user_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th>Where</th><th class="inv-num">Qty</th><th class="inv-num">Each</th><th class="inv-num">Total value</th></tr></thead>
      <tbody>
      <?php foreach ($res['rows'] as $r): $q = (int)$r['qty']; $low = $r['low_stock_at'] !== null && $q <= (int)$r['low_stock_at']; ?>
        <tr>
          <td><a href="/admin/inventory-item.php?id=<?= (int)$r['id'] ?>" class="inv-name"><?= inv_thumb_html($r, 36) ?>
            <span><strong><?= e($r['name']) ?></strong>
              <span class="inv-sub"><?= e(INV_TYPES[$r['item_type']] ?? (string)$r['item_type']) ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?><?= $r['tracking'] === 'serial' ? ' · by serial' : '' ?></span></span></a></td>
          <td class="inv-where"><?= $r['breakdown'] ? e(inv_breakdown_label($r['breakdown'])) : '<span class="text-muted">None in stock</span>' ?></td>
          <td class="inv-num"><strong><?= $q ?></strong><?php if ($low): ?> <span class="badge badge--orange">Low</span><?php endif; ?></td>
          <td class="inv-num text-muted"><?= e(inv_money($r['replacement_value'] !== null ? (float)$r['replacement_value'] : null, (string)$r['currency'])) ?></td>
          <td class="inv-num"><?= e(inv_money($r['value'], (string)$r['currency'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }

$pageTitle  = 'Inventory';
$activeMenu = 'inventory';
include __DIR__ . '/_layout.php';
?>
<?php /* Styles first: the table below is long, and CSS printed after it left the page unstyled while it loaded. */ ?>
<?= inv_grid_css() ?>

<div class="page-header">
  <h1>Inventory</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/inventory-locations.php" class="btn-outline btn-sm">Locations</a>
    <?php if ($ordersOk): ?><a href="/admin/inventory-orders.php" class="btn-outline btn-sm">Orders</a><?php endif; ?>
    <?php if ($supported): ?><a href="/admin/inventory-import.php" class="btn-outline btn-sm"><?= admin_icon('download', 15) ?> Import from Excel</a><?php endif; ?>
    <?php if ($supported): ?><a href="/admin/inventory-item.php?new=1" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add item</a><?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>
<?php if ($gone): ?>
<a href="/admin/inventory.php" class="btn-outline btn-sm" style="margin-bottom:12px"><?= admin_icon('arrow-left', 14) ?> Back to the list</a>
<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/inventory.php" class="filters">
      <input type="hidden" name="q" value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field"><span>Type</span>
        <select name="type" class="filter-select" aria-label="Filter by type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach (INV_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <?php if (count($venues) > 1): ?>
      <div class="filter-field"><span>Property</span>
        <select name="venue" class="filter-select" aria-label="Filter by property" onchange="this.form.submit()">
          <option value="0">All properties</option>
          <?php foreach ($venues as $vid => $vname): ?><option value="<?= (int)$vid ?>" <?= $f['venue'] === $vid ? 'selected' : '' ?>><?= e($vname) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="filter-field"><span>Location</span>
        <select name="location" class="filter-select" aria-label="Filter by location" onchange="this.form.submit()">
          <option value="0">All locations</option>
          <?php foreach ($locations as $l): if ($l['kind'] === 'person') continue; ?>
          <option value="<?= (int)$l['id'] ?>" <?= $f['location'] === (int)$l['id'] ? 'selected' : '' ?>><?= e(inv_location_label($l)) ?><?= inv_bool($l['is_active']) ? '' : ' (closed)' ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if ($people): ?>
      <div class="filter-field"><span>Team member</span>
        <select name="person" class="filter-select" aria-label="Filter by team member" onchange="this.form.submit()">
          <option value="0">Anyone</option>
          <?php foreach ($people as $sid => $pname): ?><option value="<?= (int)$sid ?>" <?= $f['person'] === $sid ? 'selected' : '' ?>><?= e($pname) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="filter-field"><span>Status</span>
        <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
          <?php foreach (INV_STATUS_FILTERS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <?php if ($gone): $fromVal = inv_ymd_or($f['from'], date('Y-m-d', strtotime('-30 days'))); $toVal = inv_ymd_or($f['to'], date('Y-m-d')); ?>
      <div class="filter-field"><span>From</span>
        <button type="button" class="dp-btn" data-dp-target="invFrom" data-dp-past data-dp-placeholder="From" style="width:140px"><?= e(date('j M Y', strtotime($fromVal))) ?></button>
        <input type="hidden" id="invFrom" name="from" value="<?= e($fromVal) ?>"></div>
      <div class="filter-field"><span>To</span>
        <button type="button" class="dp-btn" data-dp-target="invTo" data-dp-past data-dp-placeholder="To" style="width:140px"><?= e(date('j M Y', strtotime($toVal))) ?></button>
        <input type="hidden" id="invTo" name="to" value="<?= e($toVal) ?>"></div>
      <button type="submit" class="btn-outline btn-sm" style="align-self:flex-end">Apply</button>
      <?php endif; ?>
      <?php if ($f['type'] || $f['venue'] || $f['location'] || $f['person'] || $f['status']): ?>
      <a href="/admin/inventory.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, SKU or category…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>
<?php else: $isOwner = is_owner(); $showPlace = $placeRow !== null; ?>
<form method="GET" action="/admin/inventory.php" class="ig-bar">
  <label class="ig-field"><span>Place</span>
    <select name="place" class="filter-select" aria-label="Place" onchange="this.form.submit()">
      <option value="0">All places</option>
      <?php foreach ($locations as $l): if (!inv_bool($l['is_active'])) continue; ?>
      <option value="<?= (int)$l['id'] ?>" <?= $place === (int)$l['id'] ? 'selected' : '' ?>><?= e(inv_location_label($l)) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label class="ig-field"><span>Type</span>
    <select name="type" class="filter-select" aria-label="Type" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach (INV_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
    </select></label>
  <label class="ig-field"><span>Category</span>
    <select id="igCat" class="filter-select" aria-label="Category">
      <option value="">All categories</option>
      <?php foreach (array_keys($gridCats) as $c): ?><option value="<?= e(mb_strtolower((string)$c)) ?>"><?= e((string)$c) ?></option><?php endforeach; ?>
    </select></label>
  <label class="ig-field ig-search"><span>Search</span><input type="search" id="igSearch" class="inp" placeholder="Name, SKU / item no., category…" autocomplete="off"></label>
  <label class="ig-field"><span>History</span>
    <select name="status" class="filter-select" aria-label="History" onchange="this.form.submit()">
      <option value="">—</option>
      <option value="sold">Sold</option>
      <option value="written_off">Lost / written off</option>
    </select></label>
</form>

<?php if (!$grid): ?>
  <?php dt_empty($showPlace ? 'Nothing is listed at ' . inv_location_label($placeRow) . ' yet.' : 'No items yet — add the first one or import a list.'); ?>
<?php else: ?>
<form method="POST" action="/admin/inventory.php" id="igForm">
  <?= csrf_field() ?><input type="hidden" name="place" value="<?= (int)$place ?>">
  <div class="ig-wrap"><table class="ig" id="igTable" data-paginate="50">
    <thead><tr>
      <th class="ig-check"><input type="checkbox" id="igAll" aria-label="Select all shown"></th>
      <th data-sort="name">Item</th>
      <th data-sort="sku">SKU / item no.</th>
      <th data-sort="cat">Category</th>
      <th data-sort="type">Type</th>
      <th data-sort="qty" class="ig-num"><?= $showPlace ? 'Here' : 'In stock' ?></th>
      <?php if ($ordersOk): ?><th data-sort="onorder" class="ig-num" title="Ordered and not yet received">On order</th><?php endif; ?>
      <?php if ($showPlace): ?><th data-sort="par" class="ig-num">Should have</th><th data-sort="short" class="ig-num">Short</th>
      <?php else: ?><th>Where</th><?php endif; ?>
      <th data-sort="value" class="ig-num">Value</th>
    </tr></thead>
    <tbody>
    <?php foreach ($grid as $r):
      $qty = (int)($showPlace ? $r['qty_here'] : $r['qty_all']);
      $par = $r['par_here'] !== null ? (int)$r['par_here'] : null;
      $val = $r['replacement_value'] !== null ? (float)$r['replacement_value'] * $qty : null;
      $ord = (int)($onOrder[(int)$r['id']] ?? 0);
      $typeLbl = INV_TYPES[$r['item_type']] ?? (string)$r['item_type']; ?>
      <tr data-name="<?= e(mb_strtolower((string)$r['name'])) ?>" data-sku="<?= e(mb_strtolower((string)$r['sku'])) ?>" data-cat="<?= e(mb_strtolower((string)$r['category'])) ?>"
          data-type="<?= e($typeLbl) ?>" data-qty="<?= $qty ?>" data-onorder="<?= $ord ?>" data-par="<?= $par ?? -1 ?>" data-short="<?= (int)$r['short'] ?>" data-value="<?= $val ?? -1 ?>">
        <td class="ig-check"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select <?= e((string)$r['name']) ?>"></td>
        <td><a href="/admin/inventory-item.php?id=<?= (int)$r['id'] ?>" class="inv-name"><?= inv_thumb_html($r, 28) ?><span><?= e((string)$r['name']) ?><?= $r['tracking'] === 'serial' ? ' <span class="ig-tag">serial</span>' : '' ?></span></a></td>
        <td class="ig-mono"><?= e((string)($r['sku'] ?? '')) ?></td>
        <td><?= $r['category'] ? '<span class="ig-pill">' . e((string)$r['category']) . '</span>' : '' ?></td>
        <td class="text-muted"><?= e($typeLbl) ?></td>
        <td class="ig-num"><strong><?= $qty ?></strong></td>
        <?php if ($ordersOk): ?><td class="ig-num"><?= $ord > 0 ? $ord : '' ?></td><?php endif; ?>
        <?php if ($showPlace): ?>
        <td class="ig-num"><?= $par === null ? '<span class="text-muted">—</span>' : $par ?></td>
        <td class="ig-num"><?= $r['short'] ? '<span class="badge badge--orange">' . (int)$r['short'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
        <?php else: ?>
        <td class="ig-where"><?= $r['breakdown'] ? e(inv_breakdown_label($r['breakdown'], 3)) : '<span class="text-muted">—</span>' ?></td>
        <?php endif; ?>
        <td class="ig-num text-muted"><?= $val !== null ? e(inv_money($val, (string)$r['currency'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="text-muted ig-count" id="igCount"></p>

  <div class="ig-bulk" id="igBulk" hidden>
    <strong id="igSel">0 selected</strong>
    <div class="ig-bulk__group">
      <select name="to_id" class="filter-select" aria-label="Move to">
        <option value="0">Move to…</option>
        <?php foreach ($locations as $l): if (!inv_bool($l['is_active']) || (int)$l['id'] === $place) continue; ?>
        <option value="<?= (int)$l['id'] ?>"><?= e(inv_location_label($l)) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="action" value="bulk_move" class="btn-primary btn-sm" <?= $showPlace ? '' : 'disabled title="Pick a place at the top first — the stock moves from there"' ?>
        data-confirm="Move ALL the stock of the ticked items<?= $showPlace ? ' at ' . e(inv_location_label($placeRow)) : '' ?> to the chosen place?"><?= admin_icon('arrow-right', 14) ?> Move</button>
    </div>
    <div class="ig-bulk__group">
      <input name="category" class="inp inp--sm" maxlength="60" placeholder="Category" list="igCats" aria-label="Category">
      <datalist id="igCats"><?php foreach (array_keys($gridCats) as $c): ?><option value="<?= e((string)$c) ?>"><?php endforeach; ?></datalist>
      <button type="submit" name="action" value="bulk_category" class="btn-outline btn-sm"><?= admin_icon('check', 14) ?> Set category</button>
    </div>
    <?php if ($isOwner): ?>
    <button type="submit" name="action" value="bulk_delete" class="btn-danger btn-sm" data-confirm="Delete the ticked items with their stock and history? This can't be undone."><?= admin_icon('trash', 14) ?> Delete</button>
    <?php endif; ?>
    <button type="button" class="btn-outline btn-sm" id="igClear"><?= admin_icon('x', 14) ?> Clear</button>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if (is_owner()): ?>
<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Start over</span></div>
  <div class="card__body" style="padding:16px 18px">
    <p class="text-muted" style="font-size:13px;margin:0 0 12px">Deletes ALL items, stock, counts and history. Places and stores stay. Shop shelves (POS items that track stock) also go to 0 — receive stock again before selling. Use this before going live to clear test data.</p>
    <form method="POST" action="/admin/inventory.php" class="inv-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_all">
      <div class="field"><label>Type RESET to confirm</label><input name="confirm_text" class="inp" placeholder="RESET" autocomplete="off"></div>
      <button type="submit" class="btn-danger btn-sm" data-confirm="Reset ALL inventory data? Items, stock, counts and history are deleted. This can't be undone."><?= admin_icon('trash', 15) ?> Reset inventory</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?= inv_shared_css() ?>
<?= inv_grid_js() ?>
<script>
(function () {
  var table = document.getElementById('igTable'); if (!table) return;
  var search = document.getElementById('igSearch'), cat = document.getElementById('igCat');
  var g = InvGrid({
    table: table, form: document.getElementById('igForm'), all: document.getElementById('igAll'), bulk: document.getElementById('igBulk'),
    sel: document.getElementById('igSel'), count: document.getElementById('igCount'), clear: document.getElementById('igClear'),
    numeric: ['qty', 'onorder', 'par', 'short', 'value'], noun: 'items',
    hidden: function (r) {
      var q = (search.value || '').trim().toLowerCase(), c = cat.value;
      var hay = r.getAttribute('data-name') + ' ' + r.getAttribute('data-sku') + ' ' + r.getAttribute('data-cat');
      return (q && hay.indexOf(q) === -1) || (c && r.getAttribute('data-cat') !== c);
    }
  });
  search.addEventListener('input', g.filter); cat.addEventListener('change', g.filter);
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
