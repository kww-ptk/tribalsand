<?php
declare(strict_types=1);
/**
 * Admin: Import items from a supplier Excel into the inventory catalogue.
 * Creates items and an ORDER from the list (once per list) — the quantities go
 * ON ORDER and enter stock only when received on the order page
 * (admin/inventory-order.php). Upload the sheet, review a preview (grouped
 * one item per supplier code, matched against the existing catalogue by merge key; each item-code
 * PREFIX mapped to a place whose PAR LEVEL the list quantity becomes), then
 * confirm. Owner + manager (managers may create items and set par levels).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/inventory-item-import.php';
require_once __DIR__ . '/../includes/inventory-orders.php';
require_once __DIR__ . '/../includes/inventory-views.php';   // inv_shared_css()
require_inventory();   // owner, manager or storekeeper (scoped)

$self      = '/admin/inventory-import.php';
$supported = inv_supported();
$vids      = admin_venue_ids();
$flash     = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

$MAX_BYTES = 10 * 1024 * 1024;

// The owner's default location NAME, by prefix, grouped as area-under-property
// (only for the hint shown when that named location doesn't exist yet).
const INVIMP_AREA_UNDER = ['hair salon' => 'Tribal Dunes', 'tribal table' => 'Tribal Dunes'];

/** Keep only the 3 newest previews, and drop any older than 1 hour. */
function invimp_prune(): void {
    $all = $_SESSION['inv_import'] ?? [];
    $now = time();
    $all = array_filter($all, fn($p) => is_array($p) && $now - (int)($p['at'] ?? 0) < 3600);
    uasort($all, fn($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
    $_SESSION['inv_import'] = array_slice($all, 0, 3, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'upload') {
        $f = $_FILES['sheet'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'Please choose a file to upload.'];
        } elseif ((int)$f['size'] > $MAX_BYTES) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'File too large (max 10 MB).'];
        } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'Upload an .xlsx file.'];
        } else {
            try {
                $sheets = xlsx_read_sheets($f['tmp_name']);
                $wb = inv_ship_parse_workbook($sheets);
                if (!$wb['lines']) {
                    $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'No item list found — the file needs a sheet with “Item No”, “Qty” and “Description” columns.'];
                } elseif (count($wb['lines']) > INV_SHIP_MAX_LINES) {
                    $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'That list is too long — split it into smaller files.'];
                } else {
                    invimp_prune();
                    $token = bin2hex(random_bytes(8));
                    $_SESSION['inv_import'][$token] = ['at' => time(), 'filename' => basename((string)$f['name']),
                        'lines' => $wb['lines'], 'skipped' => $wb['skipped'], 'sheets' => $wb['sheets'],
                        'packing' => inv_ship_parse_packing($sheets)];   // container hints, parsed once here
                    header('Location: ' . $self . '?preview=' . $token); exit;
                }
            } catch (RuntimeException $e) {
                $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'Could not read that file: ' . $e->getMessage()];
            }
        }
        header('Location: ' . $self); exit;
    }

    if ($act === 'discard') {
        $token = (string)($_POST['token'] ?? '');
        unset($_SESSION['inv_import'][$token]);
        header('Location: ' . $self); exit;
    }

    if ($act === 'confirm') {
        $token = (string)($_POST['token'] ?? '');
        $data  = $_SESSION['inv_import'][$token] ?? null;
        if (!$data) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'That import can’t be found — upload the file again.'];
            header('Location: ' . $self); exit;
        }
        try {
            // Every posted place is a REQUEST — validate it against this account's
            // own editable places before trusting it, and only accept keys that
            // look like a real prefix (inv_ship_prefix() is uppercase letters only).
            $optIds  = array_column(inv_import_place_options($vids), 'id');
            $posted  = (array)($_POST['place'] ?? []);
            $prefixPlace = [];
            foreach ($posted as $prefix => $val) {
                $prefix = (string)$prefix;
                if (!preg_match('/^[A-Z]+$/', $prefix)) continue;
                $id = (is_numeric($val) && in_array((int)$val, $optIds, true)) ? (int)$val : 0;
                $prefixPlace[$prefix] = $id;
            }

            $me = current_admin();
            $userId = $me ? (int)$me['id'] : null;
            $result = inv_import_list(['lines' => $data['lines']], (array)($data['packing'] ?? []), $prefixPlace, (string)$data['filename'], $userId);

            // Remembered only once the import actually succeeded.
            set_setting(INV_IMPORT_PREFIX_PLACES_SETTING, json_encode($prefixPlace, JSON_UNESCAPED_UNICODE));
            $orderNote = $result['order_id'] ? ", order #{$result['order_id']} created" : ', no order created';
            audit_log('inv.import_items', 'inv_item', 0,
                "{$data['filename']}: {$result['created']} created, {$result['existing']} existing, {$result['pars']} par level(s) set{$orderNote}");
            unset($_SESSION['inv_import'][$token]);
            $msg = "Imported {$result['created']} new items, set {$result['pars']} par levels";
            if ($result['order_id']) {
                $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $msg . " and created order “{$result['order_name']}” — receive it when it arrives."];
                header('Location: /admin/inventory-order.php?id=' . (int)$result['order_id']); exit;
            }
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => $msg . '.'];
            header('Location: /admin/inventory.php'); exit;
        } catch (InvRefusal $e) {
            $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            header('Location: ' . $self . '?preview=' . $token); exit;
        }
    }
}

$previewToken = (string)($_GET['preview'] ?? '');
$preview      = $previewToken !== '' ? ($_SESSION['inv_import'][$previewToken] ?? null) : null;

$groups = $existing = [];
$byCategory = [];
$newCount = $existingCount = $totalPieces = 0;
$prefixesInfo = $placeOptions = $defaultPlaces = $missingHint = [];
$parCount = 0;
$ordersOk = inv_orders_supported();
$orderDone = null;
if ($preview) {
    $orderDone = $ordersOk ? inv_order_open_for(inv_import_list_fingerprint($preview['lines'])) : null;
    $groups   = inv_ship_group($preview['lines']);
    $existing = inv_import_existing_items();
    foreach ($groups as $key => $g) {
        $isExisting = isset($existing[$key]);
        $isExisting ? $existingCount++ : $newCount++;
        $cat = $g['category'] !== '' ? $g['category'] : 'Décor';
        $byCategory[$cat][] = $g + ['is_existing' => $isExisting];
    }
    ksort($byCategory, SORT_STRING);
    $totalPieces = array_sum(array_column($preview['lines'], 'qty'));

    // "Where it goes": one row per item-code prefix, first-seen order.
    foreach ($preview['lines'] as $l) {
        $p = inv_ship_prefix((string)($l['code'] ?? ''));
        if ($p === '') continue;   // no possible place — never shown, never given a par
        if (!isset($prefixesInfo[$p])) $prefixesInfo[$p] = ['lines' => 0, 'sections' => []];
        $prefixesInfo[$p]['lines']++;
        $sec = trim((string)($l['section'] ?? ''));
        if ($sec !== '' && !in_array($sec, $prefixesInfo[$p]['sections'], true)) $prefixesInfo[$p]['sections'][] = $sec;
    }
    $placeOptions = inv_import_place_options($vids);
    $defaultPlaces = inv_import_default_places(array_keys($prefixesInfo), $placeOptions);

    $optNames = array_map(fn($o) => mb_strtolower($o['name']), $placeOptions);
    foreach (array_keys($prefixesInfo) as $prefix) {
        $defName = INV_IMPORT_DEFAULT_PREFIX_PLACES[$prefix] ?? null;
        if ($defName === null || in_array(mb_strtolower($defName), $optNames, true)) continue;
        $under = INVIMP_AREA_UNDER[mb_strtolower($defName)] ?? null;
        $missingHint[$prefix] = $under !== null
            ? "Add a “{$defName}” area under {$under} in Locations first"
            : "Add a “{$defName}” property in Locations first";
    }

    // A par-count ESTIMATE for the confirm button, using each group's own key as
    // a stand-in item id (a group maps 1:1 to an item either way) — no items are
    // created yet at preview time, and serial-tracked groups never take a par.
    $estLineItem = [];
    foreach ($groups as $gkey => $g) {
        if ($g['kind'] === 'serial') continue;
        foreach ($g['lines'] as $i) $estLineItem[$i] = $gkey;
    }
    $parCount = count(inv_import_par_plan($preview['lines'], $estLineItem, $defaultPlaces));
}

$pageTitle  = 'Import items';
$activeMenu = 'inventory';
include __DIR__ . '/_layout.php';
?>
<div class="page-wide" hidden></div>
<div class="page-header">
  <h1>Import items</h1>
  <a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>

<?php elseif ($preview): ?>
  <div class="imp-bar">
    <div class="imp-bar__text">
      <strong>Ready to import <?= e($preview['filename']) ?></strong>
      <span class="text-muted"><?= count($groups) ?> item<?= count($groups) === 1 ? '' : 's' ?> (<?= $newCount ?> new) · check where each group goes below, then import.</span>
    </div>
    <div class="imp-bar__actions">
    <form method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><input type="hidden" name="token" value="<?= e($previewToken) ?>">
      <button type="submit" class="btn-outline imp-btn"><?= admin_icon('x', 16) ?> Discard</button></form>
    <?php if ($newCount > 0 || $parCount > 0 || ($ordersOk && $orderDone === null)): ?>
    <form id="imp-confirm-form" method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= e($previewToken) ?>">
      <?php if ($orderDone !== null): ?>
      <span class="text-muted imp-stock-note">An order from this list already exists (<?= e((string)$orderDone['name']) ?>, <?= e(date('j M Y', strtotime((string)$orderDone['created_at']))) ?>) — importing again only updates items and what each place should have.</span>
      <?php elseif (!$ordersOk): ?>
      <span class="text-muted imp-stock-note">Orders aren’t set up yet (run <code>add_inventory_orders.sql</code>) — only items and what each place should have are imported.</span>
      <?php endif; ?>
      <button type="submit" class="btn-primary imp-btn"
        data-confirm="Create <?= $newCount ?> new item<?= $newCount === 1 ? '' : 's' ?>, set what each place should have<?= ($ordersOk && $orderDone === null) ? ', and put the quantities on order' : '' ?>?">
        <?= admin_icon('check', 15) ?> Import</button></form>
    <?php else: ?>
    <span class="text-muted">Everything is already in the inventory.</span>
    <?php endif; ?>
    </div>
  </div>
  <?php if ($prefixesInfo): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Where it goes</span></div>
    <div class="card__body" style="padding:14px 18px">
      <div class="imp-prefixes">
        <?php foreach ($prefixesInfo as $prefix => $info): ?>
        <div class="imp-prefix-row">
          <div class="imp-prefix-label">
            <strong><?= e($prefix) ?></strong>
            <span class="text-muted"><?= $info['sections'] ? e(implode(', ', array_slice($info['sections'], 0, 2))) . ' · ' : '' ?><?= (int)$info['lines'] ?> line<?= $info['lines'] === 1 ? '' : 's' ?></span>
            <?php if (isset($missingHint[$prefix])): ?><span class="imp-hint"><?= e($missingHint[$prefix]) ?></span><?php endif; ?>
          </div>
          <select name="place[<?= e($prefix) ?>]" form="imp-confirm-form" class="eselect eselect--block" aria-label="Where <?= e($prefix) ?> items go">
            <option value="0">Nowhere — item only</option>
            <?php foreach ($placeOptions as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= (int)($defaultPlaces[$prefix] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="text-muted" style="margin:12px 0 0;font-size:12.5px">The list quantity becomes what each place should have, and the quantities go ON ORDER for that place; receive them on the order page when they arrive. Serial-numbered items (fridges, appliances) are ordered too — their units are added when received.</p>
    </div>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="card__head">
      <span class="card__title"><?= e($preview['filename']) ?></span>
      <span class="text-muted" style="font-size:12.5px">
        <?= count($groups) ?> item<?= count($groups) === 1 ? '' : 's' ?>
        (<?= $newCount ?> new, <?= $existingCount ?> already in the inventory)
        from <?= count($preview['lines']) ?> line<?= count($preview['lines']) === 1 ? '' : 's' ?>
        · <?= $totalPieces ?> piece<?= $totalPieces === 1 ? '' : 's' ?> on the list</span>
    </div>
    <?php if (!empty($preview['packing'])): ?><p class="text-muted" style="margin:12px 18px 0;font-size:12.5px">Packing lists found for <?= count($preview['packing']) ?> container<?= count($preview['packing']) === 1 ? '' : 's' ?> (<?= e(implode(', ', array_column($preview['packing'], 'container'))) ?>) — the order will show which lines are in which container, so you can receive it container by container. The quantities on the list stay the truth.</p><?php endif; ?>
    <p class="text-muted" style="margin:12px 18px 0;font-size:12.5px">One item per supplier code — the same name under two codes gets the code added, e.g. “Side Table (V007)”.</p>
    <?php if (!empty($preview['skipped'])): ?>
    <div class="alert alert--info" style="margin:14px 18px 0">
      <?= count($preview['skipped']) ?> row<?= count($preview['skipped']) === 1 ? '' : 's' ?> could not be read:
      <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach (array_slice($preview['skipped'], 0, 10) as $s): ?>
        <li><?= e((string)$s['sheet']) ?> row <?= (int)$s['row'] ?>: <?= e((string)$s['text']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <div class="table-wrap" style="margin-top:14px">
      <table class="data-table">
        <thead><tr><th>Item</th><th class="inv-num">Qty on list</th><th>Kind</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byCategory as $cat => $items): ?>
          <tr><td colspan="4" style="background:var(--bg);font-weight:600;font-size:12.5px"><?= e($cat) ?></td></tr>
          <?php foreach ($items as $g):
            $codes = [];
            foreach ($g['lines'] as $i) { $c = trim((string)($preview['lines'][$i]['code'] ?? '')); if ($c !== '' && !in_array($c, $codes, true)) $codes[] = $c; } ?>
          <tr>
            <td><strong><?= e($g['name']) ?></strong><?php if ($codes): ?><span class="inv-sub"><?= e(implode(', ', $codes)) ?></span><?php endif; ?></td>
            <td class="inv-num"><?= (int)$g['qty'] ?></td>
            <td><?php if ($g['kind'] === 'serial'): ?><span class="badge badge--grey">Serial numbers</span>
                <?php elseif ($g['kind'] === 'spare'): ?><span class="badge badge--grey">Spare</span><?php endif; ?></td>
            <td><?php if ($g['is_existing']): ?><span class="badge badge--grey">Already in inventory</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <?php if ($previewToken !== ''): ?><div class="alert alert--error is-flash">That import can’t be found — upload the file again.</div><?php endif; ?>
  <div class="card">
    <div class="card__head"><span class="card__title">Import from Excel</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" action="<?= $self ?>" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <div class="field">
          <label class="filefield">
            <span class="btn-outline btn-sm"><?= admin_icon('download', 14) ?> Choose file</span>
            <input type="file" name="sheet" accept=".xlsx" required>
            <span class="filefield__name">No file chosen</span>
          </label>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:14px"><?= admin_icon('eye', 15) ?> Read the list</button>
      </form>
      <p class="text-muted" style="margin:14px 0 0;font-size:12.5px">The sheet needs “Item No”, “Qty” and “Description” columns. The quantities go on order; you receive them on the order page when they arrive.</p>
    </div>
  </div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.imp-bar{position:sticky;top:12px;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:14px 20px;flex-wrap:wrap;margin-bottom:16px;padding:16px 20px;background:#eef6f7;border:1px solid var(--brand);border-left:5px solid var(--brand);border-radius:12px;box-shadow:var(--shadow)}
.imp-bar__text{display:flex;flex-direction:column;gap:3px;min-width:0;flex:1 1 320px}
.imp-bar__text strong{font-size:15px;overflow-wrap:anywhere}
.imp-bar__text .text-muted{font-size:13px}
.imp-bar__actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.imp-bar form{margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end}
.imp-btn{padding:11px 22px;font-size:14.5px;font-weight:600}
.imp-stock-note{font-size:12px;max-width:420px;text-align:right}
@media (max-width:640px){.imp-bar{position:static}.imp-bar__actions,.imp-bar form{justify-content:stretch;width:100%}.imp-stock-note{text-align:left;max-width:none}}
.imp-prefixes{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));gap:10px 18px}
.imp-prefix-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,240px);gap:8px 14px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--white)}
@media (max-width:560px){.imp-prefix-row{grid-template-columns:minmax(0,1fr)}}
.imp-prefix-label{display:flex;flex-direction:column;gap:2px;min-width:0}
.imp-prefix-label strong{font-size:14px}
.imp-hint{font-size:11.5px;color:#e65100}
</style>
<script>
(function () {
  var inp  = document.querySelector('.filefield input[type=file]');
  var name = document.querySelector('.filefield__name');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    if (name) name.textContent = f ? f.name : 'No file chosen';
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
