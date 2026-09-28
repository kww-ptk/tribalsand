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
require_login();
require_manager();

$vids      = admin_venue_ids();
$supported = inv_supported();
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
$run  = fn(int $offset): array => $gone ? inv_gone_moves($f, $vids, $pg['per'], $offset) : inv_central_list($f, $vids, $pg['per'], $offset);
$res  = $supported ? $run((int)$pg['offset']) : ['total' => 0, 'rows' => []];
$meta = paginate_meta((int)$res['total'], $pg['page'], $pg['per']);
if ($supported && $meta['offset'] !== (int)$pg['offset']) $res = $run($meta['offset']);   // page past the end → last page
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
<div class="page-header">
  <h1>Inventory</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/admin/inventory-locations.php" class="btn-outline btn-sm">Locations</a>
    <?php if ($supported): ?><a href="/admin/inventory-item.php?new=1" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add item</a><?php endif; ?>
  </div>
</div>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>
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
<?php endif; ?>
<?= inv_shared_css() ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
