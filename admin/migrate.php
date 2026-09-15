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
require_owner();

$pageTitle  = 'Migrations';
$activeMenu = '';

$dir   = __DIR__ . '/../db/migrations';
$paths = is_dir($dir) ? (glob($dir . '/*.sql') ?: []) : [];

// $files: the flat basename list the POST handler validates against (unchanged).
$files = array_map('basename', $paths);
sort($files);

// $display: what the table renders — MOST RECENTLY ADDED FIRST (by file
// modified time), name as a stable tiebreak. A pull that adds a migration
// touches only that file, so the newest ones rise to the top where they are
// easy to find. On a fresh clone every file shares the checkout time and the
// name tiebreak keeps the order deterministic.
$display = [];
foreach ($paths as $p) $display[] = ['name' => basename($p), 'mtime' => (int)(@filemtime($p) ?: 0)];
usort($display, fn($a, $b) => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['name'], $b['name']));
$newest = $display ? $display[0]['mtime'] : 0;   // to badge the freshest batch

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
            // A migration may open its OWN transaction in SQL (BEGIN ... COMMIT),
            // which PDO knows nothing about. When such a migration aborts — most
            // usefully when one of its own safety gates RAISEs — Postgres leaves
            // this connection in the aborted state, and every later query on the
            // request dies with 25P02. That includes current_admin() in the admin
            // layout below, so the page fatals and the gate's message — the whole
            // point of the gate — never reaches the screen.
            //
            // Roll back explicitly so the connection is usable again and the real
            // error can be rendered. Nothing is lost: the failed migration's work
            // was already discarded by Postgres the moment it aborted.
            try {
                if (db()->inTransaction()) db()->rollBack();
                else                       db()->exec('ROLLBACK');
            } catch (Throwable $ignored) {
                // Nothing useful to do — keep the original error, which is the
                // one worth showing.
            }
            $ok     = false;
            $output = "Migration {$file} failed:\n\n" . $e->getMessage();
        }
    }
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Migrations</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>

<?php if ($output): ?>
<div class="alert alert--<?= $ok ? 'success' : 'error' ?>" style="white-space:pre-wrap;font-family:monospace;font-size:13px">
<?= e($output) ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title">Available migrations</span>
    <span class="text-muted" style="font-size:12px">Newest first · files in <code>db/migrations/</code></span>
  </div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead>
        <tr><th>File</th><th style="width:150px">Added</th><th style="width:120px;text-align:right">Action</th></tr>
      </thead>
      <tbody>
        <?php if (!$display): ?>
        <tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--muted)">No migration files found.</td></tr>
        <?php else: foreach ($display as $row): $f = $row['name']; $isNew = $row['mtime'] > 0 && $row['mtime'] === $newest; ?>
        <tr>
          <td><strong><?= e($f) ?></strong><?php if ($isNew): ?> <span class="badge badge--green">Recent</span><?php endif; ?></td>
          <td><span class="text-muted" style="font-size:12px"><?= $row['mtime'] ? e(date('j M Y, H:i', $row['mtime'])) : '—' ?></span></td>
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
