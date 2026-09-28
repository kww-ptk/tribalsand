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
require_once __DIR__ . '/../includes/inventory-owner.php';    // owner-only corrections (delete a place)
require_once __DIR__ . '/../includes/frontdesk.php';          // frontdesk_today_ymd() — Nairobi "today"
require_login();
require_manager();

$self      = '/admin/inventory-locations.php';
$vids      = admin_venue_ids();
$me        = current_admin();
$supported = inv_supported();
if ($supported) inv_ensure_default_locations();
// ONE venue-name lookup, reused for the "belongs to" pickers, the "for <venues>" row
// label, and the owner/share audit line — never a query per row or per share id.
$venueNames = $supported ? db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$namesFor   = fn(array $ids): string => implode(', ', array_filter(array_map(fn($vid) => $venueNames[$vid] ?? null, $ids)));   // unknown ids are skipped, never "?"
$ownerName  = fn(?int $vid): string => $vid === null ? 'none' : ($venueNames[$vid] ?? 'none');

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
        } elseif ($act === 'add_store') {
            if (!is_owner()) throw new InvRefusal('Only the owner adds stores.');
            $owner = (int)($_POST['venue_id'] ?? 0);
            $newId = inv_create_store((string)($_POST['name'] ?? ''), $owner > 0 ? $owner : null, (array)($_POST['share'] ?? []));
            audit_log('inv.store_add', 'inv_location', $newId, (string)($_POST['name'] ?? ''));
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => 'Store added.'];
        } elseif ($act === 'save_location') {
            $loc = inv_fetch_location((int)($_POST['location_id'] ?? 0));
            if (!$loc || !inv_location_editable($loc, $vids)) throw new InvRefusal('You can only change your own properties’ locations.');
            // A manager of the OWNING property may rename a store and set its schedule, same
            // as an area — only the owner + shares (below) are owner-only.
            $v = ['count_every_days' => (string)($_POST['count_every_days'] ?? ''), 'count_assignee_id' => (int)($_POST['count_assignee_id'] ?? 0)];
            if (in_array($loc['kind'], ['area', 'store'], true) && isset($_POST['name']) && trim((string)$_POST['name']) !== (string)$loc['name']) {
                $v['name'] = (string)$_POST['name'];   // only a real rename (an unchanged name skips the duplicate check)
            }
            if ($loc['kind'] === 'area') $v['is_active'] = !empty($_POST['is_active']);
            // A store's owner + shares are owner business (Main stock has neither). Validate + write
            // the owner change FIRST, in the SAME transaction as the rest of the settings, so the
            // Responsible assignee below is checked against the NEW owning venue and a refusal
            // (e.g. "shares need an owner") saves nothing at all.
            $ownerChange = $loc['kind'] === 'store' && is_owner() && inv_stores_supported() && !inv_bool($loc['is_main'] ?? false) && isset($_POST['venue_id']);
            if ($ownerChange) {
                $oldOwner  = $loc['venue_id'] !== null && $loc['venue_id'] !== '' ? (int)$loc['venue_id'] : null;
                $oldShares = inv_pg_int_array($loc['share_venue_ids'] ?? null);
                $newOwnerPosted = (int)$_POST['venue_id'];
                $newOwner  = $newOwnerPosted > 0 ? $newOwnerPosted : null;
                $newSharesPosted = (array)($_POST['share'] ?? []);
                inv_tx(function () use ($loc, $v, $newOwner, $newSharesPosted): void {
                    inv_update_store_owner((int)$loc['id'], $newOwner, $newSharesPosted);
                    inv_update_location((int)$loc['id'], $v);
                });
                $newShares = inv_clean_share_ids($newSharesPosted, $newOwner);
                if ($newOwner !== $oldOwner || $newShares !== $oldShares) {
                    audit_log('inv.store_owner', 'inv_location', (int)$loc['id'],
                        'owner ' . $ownerName($oldOwner) . ' → ' . $ownerName($newOwner)
                        . '; shares ' . ($namesFor($oldShares) ?: 'none') . ' → ' . ($namesFor($newShares) ?: 'none'));
                }
            } else {
                inv_update_location((int)$loc['id'], $v);
            }
            audit_log('inv.location_save', 'inv_location', (int)$loc['id'], (string)$loc['name']);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $loc['name'] . ' saved.'];
        } elseif ($act === 'delete_location') {
            // Owner-only correction (includes/inventory-owner.php) — this page is its is_owner() gate.
            if (!is_owner()) throw new InvRefusal('Only the owner deletes a place.');
            $loc = inv_fetch_location((int)($_POST['location_id'] ?? 0));
            if (!$loc) throw new InvRefusal('That location no longer exists.');
            $typed = trim((string)($_POST['confirm_name'] ?? ''));
            if (mb_strtolower($typed) !== mb_strtolower((string)$loc['name'])) throw new InvRefusal('Type the name exactly to confirm.');
            $name = (string)$loc['name'];
            inv_delete_location((int)$loc['id'], (int)$me['id']);
            audit_log('inv.location_delete', 'inv_location', (int)$loc['id'], $name);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "Deleted {$name}."];
        }
    } catch (InvRefusal $e) {
        $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$rows      = $supported ? array_values(array_filter(inv_locations_visible($vids, false), fn($l) => $l['kind'] !== 'person')) : [];
$props     = array_values(array_filter($rows, fn($l) => $l['kind'] === 'property' && inv_bool($l['is_active']) && inv_location_editable($l, $vids)));
$today     = frontdesk_today_ymd();
$STATUS    = ['manual' => ['Manual', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'], 'due' => ['Due today', 'badge--orange'], 'overdue' => ['Overdue', 'badge--red']];
$usersFor  = [];   // venue id => [user id => label], cached per venue
$canStores = is_owner() && $supported && inv_stores_supported();
$allVenues = [];
if ($canStores) foreach ($venueNames as $vid => $vname) $allVenues[] = ['id' => $vid, 'name' => $vname];

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
      <thead><tr><th>Location</th><th class="inv-num">Items</th><th>Counted</th><th>Responsible</th></tr></thead>
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
            <?php if ($editable): ?>
            <details class="inv-set"><summary class="btn-icon" data-tip="Settings" aria-label="Settings for <?= e((string)$l['name']) ?>"><?= admin_icon('settings', 15) ?></summary>
              <form method="POST" action="<?= $self ?>" class="inv-form inv-set__form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_location"><input type="hidden" name="location_id" value="<?= (int)$l['id'] ?>">
                <?php if (in_array($l['kind'], ['area', 'store'], true)): ?>
                <div class="field"><label>Name</label><input name="name" class="inp" maxlength="120" value="<?= e((string)$l['name']) ?>"></div>
                <?php endif; ?>
                <?php if ($canStores && $l['kind'] === 'store' && !inv_bool($l['is_main'] ?? false)):
                    $shares   = inv_pg_int_array($l['share_venue_ids'] ?? null);
                    $curOwner = $l['venue_id'] !== null && $l['venue_id'] !== '' ? (int)$l['venue_id'] : null; ?>
                <div class="field"><label>Belongs to</label><select name="venue_id" class="eselect eselect--block" aria-label="Belongs to">
                  <option value="0">No property — shared by all</option>
                  <?php foreach ($allVenues as $vv): ?><option value="<?= (int)$vv['id'] ?>" <?= (int)$l['venue_id'] === (int)$vv['id'] ? 'selected' : '' ?>><?= e((string)$vv['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Also used by</label><div class="inv-chips" role="group" aria-label="Also used by">
                  <?php foreach ($allVenues as $vv): if ((int)$vv['id'] === $curOwner) continue; ?><label class="optchip"><input type="checkbox" name="share[]" value="<?= (int)$vv['id'] ?>" <?= in_array((int)$vv['id'], $shares, true) ? 'checked' : '' ?>><?= e((string)$vv['name']) ?></label><?php endforeach; ?></div></div>
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
                <div class="inv-set__actions">
                  <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save</button>
                  <button type="button" class="btn-outline btn-sm" data-inv-set-cancel>Cancel</button>
                </div>
              </form>
              <?php if (is_owner() && ($l['kind'] === 'area' || ($l['kind'] === 'store' && !inv_bool($l['is_main'] ?? false)))): ?>
              <form method="POST" action="<?= $self ?>" class="inv-form" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_location"><input type="hidden" name="location_id" value="<?= (int)$l['id'] ?>">
                <div class="field"><label>Type the name to confirm</label><input name="confirm_name" class="inp" placeholder="<?= e((string)$l['name']) ?>" autocomplete="off"></div>
                <button type="submit" class="btn-danger btn-sm" data-confirm="Delete <?= e((string)$l['name']) ?>? This can't be undone."><?= admin_icon('trash', 15) ?> Delete this place</button>
              </form>
              <?php endif; ?>
            </details>
            <?php endif; ?>
            <?php $servesIds = $l['kind'] === 'store' ? inv_location_venue_set($l) : [];
                  $serves    = $servesIds ? $namesFor($servesIds) : '';
                  $kindLabel = INV_LOCATION_KINDS[$l['kind']] ?? $l['kind'];
                  if (inv_bool($l['is_main'] ?? false)) { $kindLabel = 'Main stock · shared by all'; }
                  elseif ($l['kind'] === 'store' && ($l['venue_id'] === null || $l['venue_id'] === '')) { $kindLabel .= ' · shared by all'; } ?>
            <span class="inv-sub"><?= e($kindLabel) ?><?= $serves !== '' ? ' · for ' . e($serves) : '' ?><?= $closed ? ' · closed' : '' ?></span></td>
          <td class="inv-num"><?= (int)$l['item_count'] ?></td>
          <td><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span>
            <span class="inv-sub"><?= $l['count_every_days'] ? e(INV_COUNT_EVERY[(string)$l['count_every_days']] ?? 'Every ' . (int)$l['count_every_days'] . ' days') : '' ?><?= $l['last_counted_at'] ? ' · last ' . e(date('j M', strtotime((string)$l['last_counted_at']))) : '' ?></span></td>
          <td><?= e($l['assignee_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="inv-stack">
    <?php if ($canStores): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Add a store</span></div>
      <div class="card__body" style="padding:16px 18px">
        <form method="POST" action="<?= $self ?>" class="inv-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_store">
          <div class="field"><label>Store name</label><input name="name" class="inp" maxlength="120" placeholder="TD Main Stock" required></div>
          <div class="field"><label>Belongs to</label><select name="venue_id" class="eselect eselect--block" aria-label="Belongs to">
            <option value="0">No property — shared by all</option>
            <?php foreach ($allVenues as $vv): ?><option value="<?= (int)$vv['id'] ?>"><?= e((string)$vv['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Also used by</label><div class="inv-chips" role="group" aria-label="Also used by">
            <?php foreach ($allVenues as $vv): ?><label class="optchip"><input type="checkbox" name="share[]" value="<?= (int)$vv['id'] ?>"><?= e((string)$vv['name']) ?></label><?php endforeach; ?></div></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add store</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
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
.inv-set{display:inline-block;vertical-align:middle;margin-left:6px;text-align:left}
.inv-set summary{list-style:none;cursor:pointer}
.inv-set summary::-webkit-details-marker{display:none}
/* A bottom-anchored sheet at every width — fixed, so .table-wrap's overflow never clips it. */
.inv-set__form{position:fixed;left:50%;transform:translateX(-50%);bottom:24px;z-index:60;width:min(360px, calc(100vw - 32px));max-height:calc(100vh - 48px);overflow:auto;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px}
.inv-set__actions{display:flex;gap:8px;flex-wrap:wrap}
</style>
<script>
(function () {
  // One settings sheet at a time; Cancel closes its own.
  var sets = document.querySelectorAll('.inv-set');
  sets.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      sets.forEach(function (o) { if (o !== d) o.open = false; });
    });
  });
  document.querySelectorAll('[data-inv-set-cancel]').forEach(function (b) {
    b.addEventListener('click', function () { var d = b.closest('details'); if (d) d.open = false; });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
