<?php
/**
 * Admin-only migration runner.
 * Lists files in db/migrations/ and runs them against the live DB.
 * Protected by require_login() — only authenticated admins can use it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pageTitle  = 'Migrations';
$activeMenu = '';

$dir   = __DIR__ . '/../db/migrations';
$files = is_dir($dir) ? array_map('basename', glob($dir . '/*.sql')) : [];
sort($files);

$output = '';
$ok     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    verify_csrf();
    $file = basename($_POST['run']); // basename guards against path traversal
    $path = $dir . '/' . $file;

    if (!in_array($file, $files, true) || !file_exists($path)) {
        $ok     = false;
        $output = "File not found in migrations directory: {$file}";
    } else {
        $sql = file_get_contents($path);
        try {
            db()->exec($sql);
            $ok     = true;
            $output = "Migration {$file} completed successfully.";
        } catch (PDOException $e) {
            $ok     = false;
            $output = "Migration {$file} failed:\n\n" . $e->getMessage();
        }
    }
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Migrations</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<?php if ($output): ?>
<div class="alert alert--<?= $ok ? 'success' : 'error' ?>" style="white-space:pre-wrap;font-family:monospace;font-size:13px">
<?= e($output) ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title">Available migrations</span>
    <span class="text-muted" style="font-size:12px">Files in <code>db/migrations/</code></span>
  </div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead>
        <tr><th>File</th><th style="width:140px;text-align:right">Action</th></tr>
      </thead>
      <tbody>
        <?php if (!$files): ?>
        <tr><td colspan="2" style="text-align:center;padding:2rem;color:var(--muted)">No migration files found.</td></tr>
        <?php else: foreach ($files as $f): ?>
        <tr>
          <td><strong><?= e($f) ?></strong></td>
          <td style="text-align:right">
            <form method="POST" style="display:inline" onsubmit="return confirm('Run migration <?= e($f) ?>?\n\nThis will execute SQL against the production database. Migrations are designed to be safe to re-run, but make sure you have a backup if anything important is at stake.')">
              <?= csrf_field() ?>
              <input type="hidden" name="run" value="<?= e($f) ?>">
              <button type="submit" class="btn-primary btn-sm">Run</button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="text-muted" style="margin-top:24px;font-size:13px">
  Migrations use <code>IF NOT EXISTS</code> and <code>ON CONFLICT DO NOTHING</code> where appropriate, so re-running them is safe.
</p>

<?php include __DIR__ . '/_layout_end.php'; ?>
