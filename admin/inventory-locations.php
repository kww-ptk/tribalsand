<?php
/**
 * Admin: Inventory locations — Main stock, each property and its areas (Kitchen,
 * Villa 3…), and the POS outlets. Add areas; set how often each place is counted
 * and who is responsible. Owner + manager: a manager sees their properties plus
 * shared places, and changes settings only for their own properties
 * (inv_location_editable()). Team members' items live on their profile.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/frontdesk.php';          // frontdesk_today_ymd() — Nairobi "today"
require_login();
require_manager();

$self      = '/admin/inventory-locations.php';
$vids      = admin_venue_ids();
$supported = inv_supported();
if ($supported) inv_ensure_default_locations();

$flash = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'add_area') {
            $parent = inv_fetch_location((int)($_POST['parent_id'] ?? 0));
            if (!$parent || !inv_location_editable($parent, $vids)) throw new InvRefusal('Pick one of your properties.');
            $newId = inv_create_area((int)$parent['id'], (string)($_POST['name'] ?? ''));
            audit_log('inv.area_add', 'inv_location', $newId, (string)($_POST['name'] ?? ''));
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => 'Area added to ' . $parent['name'] . '.'];
        } elseif ($act === 'save_location') {
            $loc = inv_fetch_location((int)($_POST['location_id'] ?? 0));
            if (!$loc || !inv_location_editable($loc, $vids)) throw new InvRefusal('You can only change your own properties’ locations.');
            $v = ['count_every_days' => (string)($_POST['count_every_days'] ?? ''), 'count_assignee_id' => (int)($_POST['count_assignee_id'] ?? 0)];
            if (in_array($loc['kind'], ['area', 'store'], true) && isset($_POST['name'])) $v['name'] = (string)$_POST['name'];
            if ($loc['kind'] === 'area') $v['is_active'] = !empty($_POST['is_active']);
            inv_update_location((int)$loc['id'], $v);
            audit_log('inv.location_save', 'inv_location', (int)$loc['id'], (string)$loc['name']);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $loc['name'] . ' saved.'];
        }
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$rows      = $supported ? array_values(array_filter(inv_locations_visible($vids, false), fn($l) => $l['kind'] !== 'person')) : [];
$props     = array_values(array_filter($rows, fn($l) => $l['kind'] === 'property' && inv_location_editable($l, $vids)));
$today     = frontdesk_today_ymd();
$STATUS    = ['manual' => ['Manual', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'], 'due' => ['Due today', 'badge--orange'], 'overdue' => ['Overdue', 'badge--red']];
$usersFor  = [];   // venue id => [user id => label], cached per venue

$pageTitle  = 'Inventory locations';
$activeMenu = 'inventory_locations';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Locations</h1>
  <a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>
<?php else: ?>

<div class="inv-grid">
  <div class="card">
    <div class="card__head"><span class="card__title">Places</span><span class="text-muted" style="font-size:12.5px">Open one for its stock and par levels</span></div>
    <?php if (!$rows): ?>
      <?php dt_empty('No locations yet.'); ?>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Location</th><th class="inv-num">Items</th><th>Counted</th><th>Responsible</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l):
        $st = inv_count_status($l['last_counted_at'], $l['count_every_days'] !== null ? (int)$l['count_every_days'] : null, $today);
        [$sl, $sc] = $STATUS[$st];
        $editable = inv_location_editable($l, $vids);
        $vKey = $l['venue_id'] !== null ? (int)$l['venue_id'] : 0;
        if ($editable && !isset($usersFor[$vKey])) $usersFor[$vKey] = inv_assignable_users($vKey ?: null);
        $closed = !inv_bool($l['is_active']); ?>
        <tr class="<?= $closed ? 'text-muted' : '' ?>">
          <td style="<?= $l['kind'] === 'area' ? 'padding-left:28px' : '' ?>">
            <a href="/admin/inventory-location.php?id=<?= (int)$l['id'] ?>"><strong><?= e($l['kind'] === 'area' ? (string)$l['name'] : inv_location_label($l)) ?></strong></a>
            <span class="inv-sub"><?= e(INV_LOCATION_KINDS[$l['kind']] ?? $l['kind']) ?><?= $closed ? ' · closed' : '' ?></span></td>
          <td class="inv-num"><?= (int)$l['item_count'] ?></td>
          <td><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
            <span class="inv-sub"><?= $l['count_every_days'] ? e(INV_COUNT_EVERY[(string)$l['count_every_days']] ?? 'Every ' . (int)$l['count_every_days'] . ' days') : '' ?><?= $l['last_counted_at'] ? ' · last ' . e(date('j M', strtotime((string)$l['last_counted_at']))) : '' ?></span></td>
          <td><?= e($l['assignee_name'] ?? '—') ?></td>
          <td style="text-align:right">
            <?php if ($editable): ?>
            <details class="inv-set"><summary class="btn-icon" data-tip="Settings" aria-label="Settings for <?= e((string)$l['name']) ?>"><?= admin_icon('settings', 15) ?></summary>
              <form method="POST" action="<?= $self ?>" class="inv-form inv-set__form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_location"><input type="hidden" name="location_id" value="<?= (int)$l['id'] ?>">
                <?php if (in_array($l['kind'], ['area', 'store'], true)): ?>
                <div class="field"><label>Name</label><input name="name" class="inp" maxlength="120" value="<?= e((string)$l['name']) ?>"></div>
                <?php endif; ?>
                <div class="field"><label>Count</label><select name="count_every_days" class="eselect eselect--block">
                  <?php $cur = $l['count_every_days'] === null ? '' : (string)(int)$l['count_every_days'];
                  $opts = INV_COUNT_EVERY; if ($cur !== '' && !isset($opts[$cur])) $opts[$cur] = 'Every ' . $cur . ' days';
                  foreach ($opts as $k => $lbl): ?><option value="<?= e((string)$k) ?>" <?= (string)$k === $cur ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Responsible</label><select name="count_assignee_id" class="eselect eselect--block">
                  <option value="0">Nobody in particular</option>
                  <?php foreach ($usersFor[$vKey] as $uid => $ulbl): ?><option value="<?= (int)$uid ?>" <?= (int)$l['count_assignee_id'] === $uid ? 'selected' : '' ?>><?= e($ulbl) ?></option><?php endforeach; ?></select></div>
                <?php if ($l['kind'] === 'area'): ?>
                <div class="field"><label class="optchip"><input type="checkbox" name="is_active" value="1" <?= $closed ? '' : 'checked' ?>>Open</label></div>
                <?php endif; ?>
                <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save</button>
              </form>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="inv-stack">
    <?php if ($props): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Add an area</span></div>
      <div class="card__body" style="padding:16px 18px">
        <form method="POST" action="<?= $self ?>" class="inv-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_area">
          <div class="field"><label>Property</label><select name="parent_id" class="eselect eselect--block">
            <?php foreach ($props as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e((string)$pr['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Area name</label><input name="name" class="inp" maxlength="120" placeholder="Kitchen, Villa 3, Pool house…" required></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add area</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <div class="card"><div class="card__body text-muted" style="padding:16px 18px;font-size:13px">
      Counts are done by hand; the schedule only decides when a place shows as due. Items assigned to a team member are on their profile.
    </div></div>
  </div>
</div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.inv-set{display:inline-block;position:relative;text-align:left}
.inv-set summary{list-style:none;cursor:pointer}
.inv-set summary::-webkit-details-marker{display:none}
.inv-set__form{position:absolute;right:0;top:calc(100% + 6px);z-index:20;width:280px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px}
@media (max-width:560px){.inv-set__form{position:fixed;left:16px;right:16px;top:auto;bottom:16px;width:auto}}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
