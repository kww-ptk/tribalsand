<?php
/**
 * Admin: one location's stock — every item there with its quantity, the par level
 * it should always have, and what is short. Set par levels (and add an item to
 * the list by giving it a par), then "Restock to par" pulls the shortfall from
 * Main stock in one transaction (inv_restock_to_par()). Owner + manager; the
 * location must be visible, and changes need inv_location_editable() plus the
 * move scope. Quantities change only through the inventory core.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/frontdesk.php';          // frontdesk_today_ymd()
require_login();
require_manager();

$vids      = admin_venue_ids();
$me        = current_admin();
$supported = inv_supported();
$id        = (int)($_GET['id'] ?? $_POST['location_id'] ?? 0);
$loc       = $supported && $id ? inv_fetch_location($id) : false;
$self      = '/admin/inventory-location.php?id=' . $id;

if (!$loc || $loc['kind'] === 'person' || !inv_location_visible($loc, $vids)) {   // a team member's items live on their profile
    http_response_code(404);
    $pageTitle = 'Inventory location'; $activeMenu = 'inventory_location';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Location not found. <a href="/admin/inventory-locations.php">Back to Locations</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}
$editable  = inv_location_editable($loc, $vids);
$open      = inv_bool($loc['is_active']);
$storeName = (string)(inv_fetch_location(inv_store_location_id())['name'] ?? 'Main stock');
$flash = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if (!$editable) throw new InvRefusal('You can only change your own properties’ stock settings.');
        if ($act === 'set_par') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $raw    = trim((string)($_POST['par'] ?? ''));
            if ($raw !== '' && !ctype_digit($raw)) throw new InvRefusal('Par level must be a whole number (blank to clear it).');
            $it = inv_fetch_item($itemId);
            if (!$it) throw new InvRefusal('Pick an item.');
            if ($it['tracking'] !== 'qty') throw new InvRefusal('Serial-tracked items are assigned one unit at a time.');
            if ($raw !== '' && !inv_bool($it['is_active'])) throw new InvRefusal("{$it['name']} is switched off — it takes no par level.");
            // Adding an item to this list means giving it a par; a blank one would add nothing.
            $bal    = db_query('SELECT qty, par_qty FROM inv_balances WHERE item_id = :i AND location_id = :l', [':i' => $itemId, ':l' => (int)$loc['id']])->fetch();
            $listed = $bal && ((int)$bal['qty'] !== 0 || $bal['par_qty'] !== null);
            if (!$listed && $raw === '') throw new InvRefusal('Give it a par level to add it here.');
            inv_set_par($itemId, (int)$loc['id'], $raw === '' ? null : (int)$raw);
            audit_log('inv.par', 'inv_location', (int)$loc['id'], "{$it['name']}: " . ($raw === '' ? 'cleared' : $raw));
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $raw === '' ? "Par level for {$it['name']} cleared." : "{$loc['name']} should always have {$raw} × {$it['name']}."];
        } elseif ($act === 'restock') {
            $store = inv_fetch_location(inv_store_location_id());
            if (!inv_move_in_scope($store, $loc, $vids)) throw new InvRefusal('That restock is outside your properties.');
            $r = inv_restock_to_par((int)$loc['id'], (int)$me['id']);
            $moved   = array_sum($r['moved']);
            $short   = count($r['short']);
            $skipped = count($r['skipped']);
            audit_log('inv.restock', 'inv_location', (int)$loc['id'], "moved {$moved}, short {$short}, skipped {$skipped}");
            $_SESSION['inv_flash'] = ['type' => ($short || $skipped) ? 'info' : 'success', 'msg' =>
                ($moved ? "Moved {$moved} unit" . ($moved === 1 ? '' : 's') . " from {$storeName}." : 'Nothing could be moved.')
                . ($short ? " {$short} item" . ($short === 1 ? ' is' : 's are') . " still short — {$storeName} has run out." : '')
                . ($skipped ? " {$skipped} serial-tracked item" . ($skipped === 1 ? ' was' : 's were') . ' skipped — assign those one unit at a time.' : '')];
        }
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$parentName = $loc['parent_id'] ? (string)(inv_fetch_location((int)$loc['parent_id'])['name'] ?? '') : '';
$stock      = inv_location_stock((int)$loc['id']);
$areas      = $loc['kind'] === 'property' ? inv_child_areas((int)$loc['id']) : [];
// Only switched-on counted items are restocked (inv_restock_to_par()), so only they count as short.
$needs      = array_sum(array_map(fn($r) => ($r['tracking'] === 'qty' && inv_bool($r['is_active'])) ? (int)$r['need'] : 0, $stock));
$values     = inv_sum_by_currency($stock);
$status     = inv_count_status($loc['last_counted_at'], $loc['count_every_days'] !== null ? (int)$loc['count_every_days'] : null, frontdesk_today_ymd());
$listed     = array_map(fn($r) => (int)$r['item_id'], $stock);
$addable    = $editable ? array_values(array_filter(
    db_query("SELECT id, name FROM inv_items WHERE is_active = TRUE AND tracking = 'qty' ORDER BY name")->fetchAll(),
    fn($i) => !in_array((int)$i['id'], $listed, true))) : [];
$everyDays  = $loc['count_every_days'] !== null ? (int)$loc['count_every_days'] : null;
$nextDue    = $everyDays ? inv_count_due_ymd($loc['last_counted_at'], $everyDays) : null;
$STATUS     = ['manual' => ['Counted by hand', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'], 'due' => ['Count due today', 'badge--orange'], 'overdue' => ['Count overdue', 'badge--red']];

$pageTitle  = (string)$loc['name'];
$activeMenu = 'inventory_location';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($parentName !== '' ? "{$parentName} › {$loc['name']}" : (string)$loc['name']) ?></h1>
  <a href="/admin/inventory-locations.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Locations</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:18px">
  <div class="inv-kpis">
    <div class="inv-kpi"><span><?= e(INV_LOCATION_KINDS[$loc['kind']] ?? $loc['kind']) ?></span><strong><?= count($stock) ?> item<?= count($stock) === 1 ? '' : 's' ?></strong></div>
    <div class="inv-kpi"><span>Value here</span><strong><?php if (!$values): ?>—<?php else: foreach ($values as $c => $amt): ?><?= e(inv_money((float)$amt, (string)$c)) ?> <?php endforeach; endif; ?></strong></div>
    <div class="inv-kpi"><span>Short of par</span><strong><?= (int)$needs ?></strong></div>
    <div class="inv-kpi"><span><?= $loc['last_counted_at'] ? 'Last counted ' . e(date('j M', strtotime((string)$loc['last_counted_at']))) : 'Never counted' ?></span><span class="badge <?= e($STATUS[$status][1]) ?>"><?= e($STATUS[$status][0]) ?></span>
      <?php if ($nextDue): ?><span class="inv-sub">Next count due <?= e(date('j M', strtotime($nextDue))) ?></span><?php endif; ?></div>
    <?php if ($editable && $open && $needs > 0 && $loc['kind'] !== 'store'): ?>
    <form method="POST" action="<?= e($self) ?>" style="margin-left:auto;align-self:center">
      <?= csrf_field() ?><input type="hidden" name="action" value="restock"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <button type="submit" class="btn-primary btn-sm" onclick="return confirm(<?= e(json_encode("Move what is short from {$storeName} to here?")) ?>)"><?= admin_icon('arrow-right', 15) ?> Restock to par from <?= e($storeName) ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($areas): ?>
  <div style="padding:10px 18px;font-size:13px">Areas:
    <?php foreach ($areas as $i => $a): ?><?= $i ? ' · ' : '' ?><a href="/admin/inventory-location.php?id=<?= (int)$a['id'] ?>"><?= e((string)$a['name']) ?></a> <span class="text-muted">(<?= (int)$a['units'] ?>)</span><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Stock here</span><span class="text-muted" style="font-size:12.5px">Par = what this place should always have</span></div>
  <?php if (!$stock): ?>
    <?php dt_empty('Nothing here yet. Give an item a par level below, then restock from Main stock — or receive stock on the item page.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Item</th><th class="inv-num">On hand</th><th class="inv-num">Par</th><th class="inv-num">Short</th><th class="inv-num">Value</th></tr></thead>
    <tbody>
    <?php foreach ($stock as $r): $on = inv_bool($r['is_active']); ?>
      <tr>
        <td><a href="/admin/inventory-item.php?id=<?= (int)$r['item_id'] ?>" class="inv-name"><?= inv_thumb_html($r, 32) ?><span><strong><?= e($r['name']) ?></strong><?= $r['category'] ? '<span class="inv-sub">' . e($r['category']) . '</span>' : '' ?></span></a>
          <?php if (!$on): ?><span class="badge badge--grey">Switched off</span><?php endif; ?></td>
        <td class="inv-num"><strong><?= (int)$r['qty'] ?></strong></td>
        <td class="inv-num">
          <?php if ($editable && $r['tracking'] === 'qty'): ?>
          <form method="POST" action="<?= e($self) ?>" class="inv-par">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_par"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>"><input type="hidden" name="item_id" value="<?= (int)$r['item_id'] ?>">
            <input name="par" type="number" class="inp inp--num no-spin" min="0" step="1" value="<?= $r['par_qty'] === null ? '' : (int)$r['par_qty'] ?>" aria-label="Par level for <?= e($r['name']) ?>">
            <button type="submit" class="btn-icon" data-tip="Save par" aria-label="Save par"><?= admin_icon('check', 14) ?></button>
          </form>
          <?php else: ?><?= $r['par_qty'] === null ? '—' : (int)$r['par_qty'] ?><?php endif; ?>
        </td>
        <td class="inv-num"><?= $on && $r['tracking'] === 'qty' && (int)$r['need'] > 0 ? '<span class="badge badge--orange">' . (int)$r['need'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td class="inv-num text-muted"><?= e(inv_money($r['value'], (string)$r['currency'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($editable && $addable): ?>
<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Add an item to this list</span></div>
  <div class="card__body" style="padding:16px 18px">
    <form method="POST" action="<?= e($self) ?>" class="inv-form inv-add">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_par"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <div class="field"><label>Item</label><select name="item_id" class="eselect eselect--block"><?php foreach ($addable as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Should always have</label><input name="par" type="number" class="inp inp--num no-spin" min="0" step="1" required></div>
      <button type="submit" class="btn-primary btn-sm" style="align-self:end;margin-bottom:12px"><?= admin_icon('plus', 15) ?> Add</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.inv-par{display:inline-flex;gap:6px;align-items:center;justify-content:flex-end}
.inv-par .inp{width:72px}
.inv-add{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;gap:0 12px}
@media (max-width:560px){.inv-add{grid-template-columns:1fr}}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
