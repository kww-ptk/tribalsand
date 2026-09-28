<?php
declare(strict_types=1);
/**
 * Admin: Import items from a supplier Excel into the inventory catalogue.
 * ITEMS ONLY — no stock is moved. Upload the sheet, review a preview (grouped
 * by name, matched against the existing catalogue by merge key), then confirm.
 * Owner + manager (managers may create items).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/inventory-item-import.php';
require_once __DIR__ . '/../includes/inventory-views.php';   // inv_shared_css()
require_login();
require_manager();

$self      = '/admin/inventory-import.php';
$supported = inv_supported();
$flash     = $_SESSION['inv_flash'] ?? null; unset($_SESSION['inv_flash']);

$MAX_BYTES = 10 * 1024 * 1024;

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
                $wb = inv_ship_parse_workbook(xlsx_read_sheets($f['tmp_name']));
                if (!$wb['lines']) {
                    $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'No item list found — the file needs a sheet with “Item No”, “Qty” and “Description” columns.'];
                } elseif (count($wb['lines']) > INV_SHIP_MAX_LINES) {
                    $_SESSION['inv_flash'] = ['type' => 'error', 'msg' => 'That list is too long — split it into smaller files.'];
                } else {
                    invimp_prune();
                    $token = bin2hex(random_bytes(8));
                    $_SESSION['inv_import'][$token] = ['at' => time(), 'filename' => basename((string)$f['name']),
                        'lines' => $wb['lines'], 'skipped' => $wb['skipped'], 'sheets' => $wb['sheets']];
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
            $groups = inv_ship_group($data['lines']);
            $res    = inv_import_items($data['lines'], $groups);
            audit_log('inv.import_items', 'inv_item', 0, "{$data['filename']}: {$res['created']} created, {$res['existing']} existing");
            unset($_SESSION['inv_import'][$token]);
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => "Imported {$res['created']} items. No stock was added — receive or count it when it arrives."];
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
if ($preview) {
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
}

$pageTitle  = 'Import items';
$activeMenu = 'inventory';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Import items</h1>
  <a href="/admin/inventory.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inventory</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory.sql</code> migration (Admin → Migrations) to set up inventory.</div>

<?php elseif ($preview): ?>
  <div class="card" style="margin-bottom:72px">
    <div class="card__head">
      <span class="card__title"><?= e($preview['filename']) ?></span>
      <span class="text-muted" style="font-size:12.5px">
        <?= count($groups) ?> item<?= count($groups) === 1 ? '' : 's' ?>
        (<?= $newCount ?> new, <?= $existingCount ?> already in the inventory)
        from <?= count($preview['lines']) ?> line<?= count($preview['lines']) === 1 ? '' : 's' ?>
        · <?= $totalPieces ?> piece<?= $totalPieces === 1 ? '' : 's' ?> on the list</span>
    </div>
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
  <div class="imp-bar">
    <form method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><input type="hidden" name="token" value="<?= e($previewToken) ?>">
      <button type="submit" class="btn-outline"><?= admin_icon('x', 15) ?> Discard</button></form>
    <?php if ($newCount > 0): ?>
    <form method="POST" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= e($previewToken) ?>">
      <button type="submit" class="btn-primary" data-confirm="Create <?= $newCount ?> new item<?= $newCount === 1 ? '' : 's' ?>? No stock is added.">
        <?= admin_icon('check', 15) ?> Import <?= $newCount ?> new item<?= $newCount === 1 ? '' : 's' ?></button></form>
    <?php else: ?>
    <span class="text-muted">Everything is already in the inventory.</span>
    <?php endif; ?>
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
      <p class="text-muted" style="margin:14px 0 0;font-size:12.5px">The sheet needs “Item No”, “Qty” and “Description” columns. Only items are created — no stock is added.</p>
    </div>
  </div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.imp-bar{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:12px 16px;padding-bottom:calc(12px + env(safe-area-inset-bottom));background:var(--white);border-top:1px solid var(--border);box-shadow:var(--shadow)}
@media (min-width:769px){.imp-bar{left:var(--sidebar-w)}}
.imp-bar form{margin:0}
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
