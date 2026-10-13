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

// $display: what the table renders — NEWEST FIRST, by the order and date each
// file was added (db/migrations/ORDER.txt: "<file>  <YYYY-MM-DD>", appended last).
// File times are useless here: every file gets the image build time on deploy.
// A file not yet listed in ORDER.txt shows at the very top as "Not listed".
$added = [];   // file => ['pos' => n, 'date' => 'YYYY-MM-DD'|'' ]
foreach (@file($dir . '/ORDER.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $parts = preg_split('/\s+/', $line);
    $date  = (isset($parts[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[1])) ? $parts[1] : '';
    $added[$parts[0]] = ['pos' => count($added), 'date' => $date];
}
$display = [];
foreach ($files as $f) {
    $display[] = ['name' => $f, 'pos' => $added[$f]['pos'] ?? PHP_INT_MAX, 'date' => $added[$f]['date'] ?? '', 'listed' => isset($added[$f])];
}
usort($display, fn($a, $b) => ($b['pos'] <=> $a['pos']) ?: strcmp($a['name'], $b['name']));
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));

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
  <div class="actions">
    <a href="/admin/settings.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Settings</a>
  </div>
</div>

<?php if ($output): ?>
<div class="alert alert--<?= $ok ? 'success' : 'error' ?>" style="white-space:pre-wrap;font-family:monospace;font-size:13px">
<?= e($output) ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="card__title">Available migrations <span class="text-muted" id="migCount" style="font-size:12px;font-weight:500"></span></span>
    <?php if ($display): ?>
    <input type="search" id="migSearch" class="inp inp--sm" placeholder="Search migrations…" aria-label="Search migrations" autocomplete="off" style="min-width:240px">
    <?php endif; ?>
  </div>
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead>
        <tr><th>File</th><th style="width:150px">Added</th><th style="width:120px;text-align:right">Action</th></tr>
      </thead>
      <tbody>
        <?php if (!$display): ?>
        <tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--muted)">No migration files found.</td></tr>
        <?php else: foreach ($display as $row): $f = $row['name']; ?>
        <tr data-mig="<?= e(strtolower($f . ' ' . ($row['date'] !== '' ? date('j M Y', strtotime($row['date'])) . ' ' . $row['date'] : ''))) ?>">
          <td><strong><?= e($f) ?></strong>
            <?php if (!$row['listed']): ?> <span class="badge badge--orange">Not listed in ORDER.txt</span>
            <?php elseif ($row['date'] === $today): ?> <span class="badge badge--green">Today</span>
            <?php elseif ($row['date'] !== '' && $row['date'] >= $weekAgo): ?> <span class="badge badge--green">New</span><?php endif; ?>
          </td>
          <td><span class="text-muted" style="font-size:12px"><?= $row['date'] !== '' ? e(date('j M Y', strtotime($row['date']))) : '—' ?></span></td>
          <td style="text-align:right">
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="run" value="<?= e($f) ?>">
              <button type="submit" class="btn-primary btn-sm"
                      data-confirm-title="Run <?= e($f) ?>?"
                      data-confirm="This runs SQL against the live database. Migrations are written to be safe to re-run, but make sure you have a backup if anything important is at stake."
                      data-confirm-label="Run migration">Run</button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        <tr id="migNone" hidden><td colspan="3" style="text-align:center;padding:2rem;color:var(--muted)">No migration matches.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
/* Search the list as you type (file name or date). The text is kept for this visit,
   so it is still there after Run reloads the page. */
(function () {
  var box = document.getElementById('migSearch');
  if (!box || box.dataset.bound) return;
  box.dataset.bound = '1';
  var rows = document.querySelectorAll('tr[data-mig]'), none = document.getElementById('migNone'), count = document.getElementById('migCount');
  var KEY = 'ts_migrate_q';
  function apply() {
    var words = box.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
    rows.forEach(function (tr) {
      var hay = tr.getAttribute('data-mig'), ok = words.every(function (w) { return hay.indexOf(w) !== -1; });
      tr.hidden = !ok; if (ok) shown++;
    });
    if (none) none.hidden = shown !== 0;
    if (count) count.textContent = words.length ? '· ' + shown + ' of ' + rows.length : '· ' + rows.length;
    try { sessionStorage.setItem(KEY, box.value); } catch (e) {}
    if (window.tsTableFit) window.tsTableFit();
  }
  try { box.value = sessionStorage.getItem(KEY) || ''; } catch (e) {}
  box.addEventListener('input', apply);
  box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.value = ''; apply(); } });
  apply();
})();
</script>

<p class="text-muted" style="margin-top:24px;font-size:13px">
  Migrations use <code>IF NOT EXISTS</code> and <code>ON CONFLICT DO NOTHING</code> where appropriate, so re-running them is safe.
</p>

<?php include __DIR__ . '/_layout_end.php'; ?>
