<?php
/**
 * Admin: Service pricing — owner-editable laundry & transfer catalogs.
 * Add / rename / price / toggle active / delete / drag-reorder each option.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services.php';
require_login();
require_owner();

$SERVICES = ['laundry' => 'Laundry', 'transfer' => 'Transfer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $svc   = $_POST['service'] ?? '';
        $label = trim($_POST['label'] ?? '');
        $price = (float)($_POST['price_amount'] ?? 0);
        if (isset($SERVICES[$svc]) && $label !== '' && $price >= 0) {
            $max = (int)db_query("SELECT COALESCE(MAX(sort_order),0) AS m FROM service_options WHERE service=:s", [':s'=>$svc])->fetch()['m'];
            db_query("INSERT INTO service_options (service,label,price_amount,is_active,sort_order) VALUES (:s,:l,:p,TRUE,:o)",
                [':s'=>$svc, ':l'=>$label, ':p'=>$price, ':o'=>$max+1]);
            audit_log('service_option.add', 'service_option', (int)db()->lastInsertId(), "{$svc}: {$label}");
        }
        header('Location: /admin/services.php'); exit;
    }

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $price = (float)($_POST['price_amount'] ?? 0);
        $active = isset($_POST['is_active']) ? 'TRUE' : 'FALSE';
        if ($id && $label !== '' && $price >= 0) {
            db_query("UPDATE service_options SET label=:l, price_amount=:p, is_active=:a WHERE id=:id",
                [':l'=>$label, ':p'=>$price, ':a'=>$active, ':id'=>$id]);
            audit_log('service_option.save', 'service_option', $id);
        }
        header('Location: /admin/services.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { db_query("DELETE FROM service_options WHERE id=:id", [':id'=>$id]); audit_log('service_option.delete', 'service_option', $id); }
        header('Location: /admin/services.php'); exit;
    }

    if ($action === 'reorder') {
        $svc = $_POST['service'] ?? '';
        if (isset($SERVICES[$svc])) {
            foreach ((array)(json_decode($_POST['order'] ?? '[]', true) ?: []) as $o => $iid) {
                db_query("UPDATE service_options SET sort_order=:o WHERE id=:id AND service=:s", [':o'=>(int)$o, ':id'=>(int)$iid, ':s'=>$svc]);
            }
            audit_log('service_option.reorder', 'service_option', 0, $svc);
        }
        header('Content-Type: application/json'); exit(json_encode(['ok'=>true]));
    }
}

$pageTitle  = 'Service pricing';
$activeMenu = 'services';
$currency   = setting('site_currency', 'USD');

/** Trim a stored NUMERIC to a tidy editable value: 12.50 → "12.5", 0.00 → "0". */
$fmt_price = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') ?: '0';

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Service pricing</h1>
  <a href="/admin/settings.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Settings</a>
</div>
<p class="text-muted" style="margin:0 0 20px;font-size:13px">Guests see active options with their price when requesting laundry or transfers. A price of 0 shows the label only. Drag the handle to reorder.</p>

<?php foreach ($SERVICES as $svc => $svcLabel):
    $rows     = fetch_service_options($svc, false);
    $active   = array_sum(array_map(fn($r) => $r['is_active'] ? 1 : 0, $rows));
    $unpriced = array_sum(array_map(fn($r) => is_priced($r['price_amount']) ? 0 : 1, $rows)); ?>
<div class="card svc-card">
  <div class="card__head">
    <span class="card__title"><?= e($svcLabel) ?></span>
    <span class="svc-count"><?= (int)$active ?> active · <?= count($rows) ?> total<?php if ($unpriced > 0): ?> · <span class="badge badge--orange"><?= (int)$unpriced ?> unpriced</span><?php endif; ?></span>
  </div>
  <div class="card__body">
    <div class="svc-list" id="svc-<?= e($svc) ?>" data-service="<?= e($svc) ?>">
      <?php if (!$rows): ?>
        <?php dt_empty('No ' . strtolower($svcLabel) . ' options yet — add one below.'); ?>
      <?php else: foreach ($rows as $r): ?>
      <form method="POST" action="/admin/services.php" class="svc-row <?= $r['is_active'] ? '' : 'is-off' ?>" draggable="true" data-id="<?= (int)$r['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <span class="svc-grip" data-tip="Drag to reorder" aria-hidden="true"><?= admin_icon('grip', 18) ?></span>
        <input type="text" name="label" class="inp svc-label" value="<?= e($r['label']) ?>" required placeholder="Option label">
        <span class="inp-money svc-money">
          <span class="inp-money__cur"><?= e($currency) ?></span>
          <input type="number" name="price_amount" class="inp inp--num no-spin svc-price" value="<?= e($fmt_price($r['price_amount'])) ?>" min="0" step="0.01" placeholder="0">
        </span>
        <?php if (!is_priced($r['price_amount'])): ?><span class="badge badge--orange" data-tip="A price of 0 shows the label only — guests see no price">no price</span><?php endif; ?>
        <label class="toggle svc-toggle" data-tip="<?= $r['is_active'] ? 'Active — guests can pick this' : 'Hidden from guests' ?>">
          <input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
        <span class="svc-row__actions">
          <button type="submit" name="action" value="save" class="btn-icon btn-icon--primary" data-tip="Save changes" aria-label="Save changes"><?= admin_icon('check') ?></button>
          <button type="submit" name="action" value="delete" class="btn-icon btn-icon--danger" data-confirm="Delete this option?" data-tip="Delete" aria-label="Delete option"><?= admin_icon('trash') ?></button>
        </span>
      </form>
      <?php endforeach; endif; ?>
    </div>

    <form method="POST" action="/admin/services.php" class="ws-addform svc-add">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="service" value="<?= e($svc) ?>">
      <label class="wsf wsf--grow">
        <span>New option</span>
        <input type="text" name="label" class="inp" placeholder="New <?= e(strtolower($svcLabel)) ?> option" required>
      </label>
      <label class="wsf">
        <span>Price (<?= e($currency) ?>)</span>
        <input type="number" name="price_amount" class="inp inp--num no-spin" value="0" min="0" step="0.01" style="width:120px">
      </label>
      <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add option</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('.svc-list').forEach(function(list){
    var svc = list.dataset.service, dragged = null;
    list.querySelectorAll('.svc-row').forEach(function(row){
      row.addEventListener('dragstart', function(e){ dragged = row; row.classList.add('is-dragging'); });
      row.addEventListener('dragend',   function(){ dragged = null; row.classList.remove('is-dragging'); save(); });
      row.addEventListener('dragover',  function(e){ e.preventDefault(); });
      row.addEventListener('dragenter', function(e){ e.preventDefault(); if (dragged && dragged !== row) { var k=[].slice.call(list.querySelectorAll('.svc-row')); var di=k.indexOf(dragged), ri=k.indexOf(row); list.insertBefore(dragged, di<ri ? row.nextSibling : row); } });
    });
    // Don't start a drag from inside the text/number inputs or the toggle.
    list.querySelectorAll('input, .svc-toggle').forEach(function(i){ i.addEventListener('mousedown', function(e){ e.stopPropagation(); }); });
    function save(){
      var ids = [].slice.call(list.querySelectorAll('.svc-row')).map(function(x){ return x.dataset.id; });
      var fd = new FormData(); fd.append('action','reorder'); fd.append('service', svc); fd.append('order', JSON.stringify(ids)); fd.append('csrf_token', CSRF);
      fetch('/admin/services.php', { method:'POST', body:fd, credentials:'same-origin' });
    }
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
