<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_owner();

$success = '';
$fields = ['stay_wifi' => 'Wi-Fi', 'stay_checkout' => 'Check-out', 'stay_house_rules' => 'House rules', 'stay_area_guide' => 'Area guide'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($fields) as $k) {
        set_setting($k, trim((string)($_POST[$k] ?? '')));
    }
    audit_log('stay_info.save', 'settings', 0, 'stay info updated');
    $success = 'Stay info saved.';
}

$pageTitle  = 'Stay Info';
$activeMenu = 'stay_info';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Stay Info</h1></div>
<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<div class="card">
  <div class="card__body" style="padding:20px">
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Shown to guests in the app under &ldquo;Stay info&rdquo;. Leave a field blank to hide it.</p>
    <form method="POST" action="/admin/stay-info.php">
      <?= csrf_field() ?>
      <?php foreach ($fields as $k => $label): ?>
      <label style="display:block;margin:0 0 14px;font-size:13px;font-weight:500"><?= e($label) ?>
        <textarea name="<?= e($k) ?>" rows="3" style="display:block;width:100%;margin-top:4px;padding:9px;border:1px solid #d1d5db;border-radius:6px;font:inherit"><?= e(setting($k, '')) ?></textarea>
      </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary btn-sm">Save</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
