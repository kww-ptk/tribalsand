<?php
/**
 * Admin: Inventory orders — the lists an Excel import put ON ORDER. Owner and
 * manager see their orders (a manager only those with a line for their places);
 * staff see the orders with lines for their places, so they can receive what
 * arrives. Read-only: receiving happens on inventory-order.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-orders.php';
require_inventory();   // owner, manager or storekeeper (scoped)

$supported = inv_orders_supported();
$vids      = admin_venue_ids();
$flash     = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);
$orders    = $supported ? inv_orders_list($vids) : [];
$canManage = is_owner() || is_manager();

$badge = ['open' => 'badge--grey', 'partial' => 'badge--orange', 'received' => 'badge--green', 'cancelled' => 'badge--grey'];

$pageTitle  = 'Orders';
$activeMenu = 'inventory_orders';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Orders</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($canManage): ?><a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
    <a href="/admin/inventory-import.php" class="btn-primary btn-sm"><?= admin_icon('download', 15) ?> Import from Excel</a><?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory_orders.sql</code> migration (Admin → Migrations) to set up orders.</div>
<?php elseif (!$orders): ?>
  <?php dt_empty('No orders yet — import a supplier list (Inventory → Import from Excel).'); ?>
<?php else: ?>
<div class="card"><div class="card__body" style="padding:0">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Order</th><th>Created</th><th class="inv-num">Received</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): $ord = (int)$o['pieces_ordered']; $rec = (int)$o['pieces_received']; ?>
      <tr>
        <td><a href="/admin/inventory-order.php?id=<?= (int)$o['id'] ?>" class="inv-name"><strong><?= e((string)$o['name']) ?></strong></a>
          <span class="inv-sub"><?= (int)$o['lines'] ?> line<?= (int)$o['lines'] === 1 ? '' : 's' ?></span></td>
        <td class="text-muted inv-nowrap"><?= e(date('j M Y', strtotime((string)$o['created_at']))) ?></td>
        <td class="inv-num"><?= $rec ?> of <?= $ord ?> pieces</td>
        <td><span class="badge <?= e($badge[$o['status']] ?? 'badge--grey') ?>"><?= e(INV_ORDER_STATUSES[$o['status']] ?? (string)$o['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php endif; ?>
<?= inv_shared_css() ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
