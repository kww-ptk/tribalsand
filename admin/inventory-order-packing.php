<?php
/**
 * Admin: the packing list of ONE container of an order — every row of the supplier's
 * spreadsheet, in sheet order (item no., description, quantity, boxes, dimensions,
 * weight, cubes) and the order line each row was matched to. Read-only. Same access
 * rule as admin/inventory-order.php: an account that sees no line of the order gets
 * the same "not available" page. Rows come from inv_order_packing (see
 * add_inventory_orders.sql); the sheet's own footer totals are shown separately and
 * never added to the computed ones.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-grid.php';     // inv_grid_css()
require_once __DIR__ . '/../includes/inventory-orders.php';
require_login();

$orderId   = (int)($_GET['id'] ?? 0);
$container = (string)($_GET['container'] ?? '');
$supported = inv_orders_supported();
$vids      = admin_venue_ids();

$order = $supported ? inv_order_fetch($orderId) : false;
if ($supported && (!$order || !inv_order_lines($orderId, $vids))) {   // unknown, or nothing of it is visible to this account
    http_response_code(404);
    $order = false;
}
$summary = $order ? inv_order_packing_summary($orderId) : [];
$current = null;
foreach ($summary as $s) if ($s['container'] === $container) { $current = $s; break; }
if ($current === null && $summary && $container === '') { $current = $summary[0]; $container = $current['container']; }
$rows = $current ? inv_order_packing_rows($orderId, $container, $vids) : [];

$n2 = function (?float $v, int $d): string {
    if ($v === null) return '';
    $t = number_format($v, $d, '.', ',');
    return str_contains($t, '.') ? rtrim(rtrim($t, '0'), '.') : $t;
};
$dims = function (array $r) use ($n2): string {
    $p = [$r['length_m'], $r['width_m'], $r['height_m']];
    if ($p === [null, null, null]) return '';
    return implode(' × ', array_map(fn($v) => $v === null ? '–' : $n2((float)$v, 2), $p));
};

$pageTitle  = $order ? 'Packing list · ' . ($container !== '' ? $container : (string)$order['name']) : 'Packing list';
$activeMenu = 'inventory_orders';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $current ? 'Packing list · ' . e($container) : 'Packing list' ?></h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($order): ?><a href="/admin/inventory-order.php?id=<?= $orderId ?><?= $current ? '&amp;container=' . e(rawurlencode($container)) : '' ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> <?= e((string)$order['name']) ?></a><?php endif; ?>
  </div>
</div>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory_orders.sql</code> migration (Admin → Migrations) to set up orders.</div>
<?php elseif (!$order): ?>
  <?php dt_empty('That order isn’t available to you.'); ?>
<?php elseif (!$summary): ?>
  <?php dt_empty('This order has no packing lists stored. Import the supplier’s Excel to bring them in.'); ?>
<?php elseif (!$current): ?>
  <?php dt_empty('That container isn’t on this order.'); ?>
<?php else: ?>
<p class="text-muted" style="margin:-4px 0 14px;font-size:13px">
  <strong><?= e((string)$order['name']) ?></strong> — every row of the container’s packing list, in the order of the spreadsheet.
  Boxes, weight and cubes are as the supplier listed them.
</p>

<div class="card pk-all"><div class="card__body" style="padding:0">
  <h3 class="pk-h">All containers</h3>
  <div class="ig-wrap pk-scroll"><table class="ig pk-table">
    <thead><tr><th>Container</th><th class="ig-num">Rows</th><th class="ig-num">Boxes</th><th class="ig-num">Weight (kg)</th><th class="ig-num">Cubes (m³)</th></tr></thead>
    <tbody>
    <?php $tb = 0; $tw = 0.0; $tc = 0.0; foreach ($summary as $s): $tb += $s['boxes']; $tw += $s['weight']; $tc += $s['cubes']; ?>
      <tr class="<?= $s['container'] === $container ? 'pk-current' : '' ?>">
        <td><a href="/admin/inventory-order-packing.php?id=<?= $orderId ?>&amp;container=<?= e(rawurlencode($s['container'])) ?>"><?= e($s['container']) ?></a></td>
        <td class="ig-num"><?= $s['rows'] ?></td><td class="ig-num"><?= $s['boxes'] ?></td>
        <td class="ig-num"><?= e($n2($s['weight'], 2)) ?></td><td class="ig-num"><?= e($n2($s['cubes'], 2)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>All containers</th><th class="ig-num"><?= array_sum(array_column($summary, 'rows')) ?></th><th class="ig-num"><?= $tb ?></th>
      <th class="ig-num"><?= e($n2($tw, 2)) ?></th><th class="ig-num"><?= e($n2($tc, 2)) ?></th></tr></tfoot>
  </table></div>
</div></div>

<div class="inv-kpis pk-kpis">
  <div class="inv-kpi"><span>Container</span><strong><?= e($container) ?></strong></div>
  <div class="inv-kpi"><span>Rows</span><strong><?= $current['rows'] ?></strong></div>
  <div class="inv-kpi"><span>Boxes</span><strong><?= $current['boxes'] ?></strong></div>
  <div class="inv-kpi"><span>Weight</span><strong><?= e($n2($current['weight'], 2)) ?> kg</strong></div>
  <div class="inv-kpi"><span>Cubes</span><strong><?= e($n2($current['cubes'], 2)) ?> m³</strong></div>
  <?php if ($current['unmatched'] > 0): ?><div class="inv-kpi"><span>Not on the master list</span><strong><?= $current['unmatched'] ?> row<?= $current['unmatched'] === 1 ? '' : 's' ?></strong></div><?php endif; ?>
</div>

<div class="ig-wrap pk-scroll"><table class="ig pk-table">
  <thead><tr>
    <th>Item no.</th><th>Description</th><th class="ig-num">Qty</th><th class="ig-num">Boxes</th>
    <th>L × W × H (m)</th><th class="ig-num">Weight (kg)</th><th class="ig-num">Cubes (m³)</th><th>Order line</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    if ($r['kind'] === 'total') continue;
    $cont = $r['kind'] === 'continuation'; $note = $r['kind'] === 'note'; ?>
    <tr class="<?= $cont ? 'pk-cont' : ($note ? 'pk-note' : '') ?>">
      <td class="ig-mono"><?= $cont ? '<span class="pk-arrow">↳ extra box</span>' : e((string)($r['code'] ?? '')) ?></td>
      <td class="pk-desc"><?= e((string)($r['description'] ?? '')) ?></td>
      <td class="ig-num"><?= $r['qty'] !== null ? (int)$r['qty'] : '' ?></td>
      <td class="ig-num"><?= $r['boxes'] !== null ? (int)$r['boxes'] : '' ?></td>
      <td class="ig-mono"><?= e($dims($r)) ?></td>
      <td class="ig-num"><?= $r['weight_kg'] !== null ? e($n2((float)$r['weight_kg'], 2)) : '' ?></td>
      <td class="ig-num"><?= $r['cubes_m3'] !== null ? e($n2((float)$r['cubes_m3'], 3)) : '' ?></td>
      <td><?php if ($r['item_id'] !== null): ?><a href="/admin/inventory-item.php?id=<?= (int)$r['item_id'] ?>"><?= e((string)$r['item_name']) ?></a>
          <?php elseif ($note || $r['hidden_line']): ?><span class="text-muted">—</span>
          <?php else: ?><span class="badge badge--orange">Not on the master list</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="3">Total of the rows above</th><th class="ig-num"><?= $current['boxes'] ?></th><th></th>
      <th class="ig-num"><?= e($n2($current['weight'], 2)) ?></th><th class="ig-num"><?= e($n2($current['cubes'], 3)) ?></th><th></th></tr>
    <?php foreach ($rows as $r): if ($r['kind'] !== 'total') continue; ?>
    <tr class="pk-sheet"><td colspan="3">Sheet’s own total (row <?= (int)$r['row_no'] ?>)</td><td class="ig-num"><?= $r['boxes'] !== null ? (int)$r['boxes'] : '' ?></td><td></td>
      <td class="ig-num"><?= $r['weight_kg'] !== null ? e($n2((float)$r['weight_kg'], 2)) : '' ?></td><td class="ig-num"><?= $r['cubes_m3'] !== null ? e($n2((float)$r['cubes_m3'], 3)) : '' ?></td><td></td></tr>
    <?php endforeach; ?>
  </tfoot>
</table></div>
<?php endif; ?>

<?= inv_shared_css() ?>
<?= inv_grid_css() ?>
<style>
.pk-scroll{max-height:none;max-width:100%;overflow-x:auto}
.pk-all{margin-bottom:16px}
.pk-h{margin:0;padding:12px 16px 10px;font-size:14px}
.pk-all .ig-wrap{border:0;border-radius:0}
.pk-kpis{margin-bottom:12px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius)}
.pk-table thead th{cursor:default}
.pk-table th.ig-num,.pk-table td.ig-num{text-align:right}
.pk-table td.pk-desc{white-space:normal;min-width:220px;max-width:420px}
.pk-table tfoot th,.pk-table tfoot td{background:var(--bg);font-size:12.5px;position:static}
.pk-table tr.pk-current td{background:#f7f5f0;font-weight:600}
.pk-table tr.pk-cont td{background:#fbfaf7}
.pk-table tr.pk-cont td:first-child{padding-left:22px}
.pk-arrow{color:var(--muted);font-size:12px}
.pk-table tr.pk-note td{color:var(--muted);font-style:italic}
.pk-table tr.pk-sheet td{color:var(--muted)}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
