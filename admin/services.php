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
include __DIR__ . '/_layout.php';
?>

<style>
.svc-list{display:flex;flex-direction:column;gap:8px}
.svc-row{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:10px;padding:8px 10px}
.svc-row.is-off{opacity:.55}
.svc-handle{cursor:grab;color:var(--muted);font-size:16px;user-select:none;padding:0 2px}
.svc-row input[type=text]{flex:1;min-width:120px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px;font:inherit}
.svc-row input[type=number]{width:110px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px;font:inherit}
.svc-cur{color:var(--muted);font-size:12px}
.svc-add{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}
</style>

<div class="page-header">
  <h1>Service pricing</h1>
  <a href="/admin/settings.php" class="btn-outline btn-sm">← Settings</a>
</div>
<p class="text-muted" style="margin:0 0 16px;font-size:13px">Guests see active options with their price when requesting laundry or transfers. A price of 0 shows the label only. Drag to reorder.</p>

<?php foreach ($SERVICES as $svc => $svcLabel):
    $rows = fetch_service_options($svc, false); ?>
<div class="card" style="margin-bottom:18px">
  <div class="card__head"><span class="card__title"><?= e($svcLabel) ?></span></div>
  <div class="card__body">
    <div class="svc-list" id="svc-<?= e($svc) ?>" data-service="<?= e($svc) ?>">
      <?php foreach ($rows as $r): ?>
      <form method="POST" action="/admin/services.php" class="svc-row <?= $r['is_active'] ? '' : 'is-off' ?>" draggable="true" data-id="<?= (int)$r['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <span class="svc-handle" title="Drag to reorder" aria-hidden="true">⠿</span>
        <input type="text" name="label" value="<?= e($r['label']) ?>" required>
        <span class="svc-cur"><?= e($currency) ?></span>
        <input type="number" name="price_amount" value="<?= e(rtrim(rtrim(number_format((float)$r['price_amount'], 2, '.', ''), '0'), '.')) ?>" min="0" step="0.01">
        <label style="font-size:12px;color:var(--muted);white-space:nowrap"><input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>> Active</label>
        <button type="submit" name="action" value="save" class="btn-sm btn-primary">Save</button>
        <button type="submit" name="action" value="delete" class="btn-sm btn-danger" onclick="return confirm('Delete this option?')">Delete</button>
      </form>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p class="text-muted" style="margin:0">No options yet — add one below.</p><?php endif; ?>
    </div>

    <form method="POST" action="/admin/services.php" class="svc-add">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="service" value="<?= e($svc) ?>">
      <input type="text" name="label" placeholder="New <?= e(strtolower($svcLabel)) ?> option" required style="flex:1;min-width:160px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px">
      <span class="svc-cur"><?= e($currency) ?></span>
      <input type="number" name="price_amount" value="0" min="0" step="0.01" style="width:110px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px">
      <button type="submit" class="btn-sm btn-outline">+ Add option</button>
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
      row.addEventListener('dragstart', function(e){ dragged = row; row.style.opacity = '.4'; });
      row.addEventListener('dragend',   function(){ dragged = null; row.style.opacity = ''; save(); });
      row.addEventListener('dragover',  function(e){ e.preventDefault(); });
      row.addEventListener('dragenter', function(e){ e.preventDefault(); if (dragged && dragged !== row) { var k=[].slice.call(list.querySelectorAll('.svc-row')); var di=k.indexOf(dragged), ri=k.indexOf(row); list.insertBefore(dragged, di<ri ? row.nextSibling : row); } });
    });
    // Don't start a drag from inside the text/number inputs.
    list.querySelectorAll('input').forEach(function(i){ i.addEventListener('mousedown', function(e){ e.stopPropagation(); }); });
    function save(){
      var ids = [].slice.call(list.querySelectorAll('.svc-row')).map(function(x){ return x.dataset.id; });
      var fd = new FormData(); fd.append('action','reorder'); fd.append('service', svc); fd.append('order', JSON.stringify(ids)); fd.append('csrf_token', CSRF);
      fetch('/admin/services.php', { method:'POST', body:fd });
    }
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
