<?php
declare(strict_types=1);
/**
 * Zuri channel-manager booking importer (Ezee sheet → calendar blocks).
 *
 * Upload the channel manager's export (.csv / .tsv / .xlsx), review a mandatory
 * dry-run preview, then commit. Accepted rows become availability_blocks
 * (block_type='blocked'), mirroring the OTA iCal import — they prevent
 * double-booking and appear on the Gantt. Overlaps with existing website holds
 * are recorded as channel_conflicts, never silently overwritten. Idempotent.
 *
 * Owner + house manager only, scoped to Zuri (every mapped room is a Zuri room).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/booking-import.php';
require_login();
require_manager();               // owner or house manager

// ── Zuri scoping ─────────────────────────────────────────────────────
$zuriId = (int) db_query("SELECT id FROM venues WHERE slug = 'zuri' LIMIT 1")->fetchColumn();
$scope  = admin_venue_ids();     // null = owner (all); array = manager's venues
$canImport = ($scope === null) || ($zuriId && in_array($zuriId, array_map('intval', $scope), true));

$MAX_BYTES = 4 * 1024 * 1024;
$ALLOWED   = ['csv', 'tsv', 'xlsx'];

if (session_status() === PHP_SESSION_NONE) session_start();

$error = $flash = '';

// ── POST handlers ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canImport) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        unset($_SESSION['import_preview'], $_SESSION['import_report']);
        header('Location: /admin/import-bookings.php');
        exit;
    }

    if ($action === 'preview') {
        $f = $_FILES['sheet'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please choose a file to upload.';
        } elseif ($f['size'] > $MAX_BYTES) {
            $error = 'File too large (max 4 MB).';
        } else {
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $ALLOWED, true)) {
                $error = 'Unsupported file type — upload a .csv, .tsv or .xlsx export.';
            } else {
                try {
                    $parsed   = import_read_file($f['tmp_name'], $ext);
                    $resolved = import_resolve_all($parsed['rows']);
                    if (!$resolved) {
                        $error = 'No booking rows were found in that file. Is the header row present?';
                    } else {
                        $_SESSION['import_preview'] = [
                            'fields'   => $parsed['fields'],
                            'resolved' => $resolved,
                            'filename' => (string)$f['name'],
                        ];
                        unset($_SESSION['import_report']);
                        header('Location: /admin/import-bookings.php?step=preview');
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('[import-bookings] parse failed: ' . $e->getMessage());
                    $error = 'Could not read that file: ' . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'commit') {
        $pv = $_SESSION['import_preview'] ?? null;
        if (!$pv || empty($pv['resolved'])) {
            $error = 'Nothing to import — please upload a file first.';
        } else {
            $report = import_commit($pv['resolved']);
            audit_log('bookings.import', 'venue', $zuriId,
                sprintf('imported=%d dup=%d conflict=%d unmapped=%d bad=%d',
                    $report['imported'], $report['duplicate'], $report['conflict'],
                    $report['unmapped'], $report['bad_dates']));
            $_SESSION['import_report'] = $report + ['filename' => $pv['filename'] ?? ''];
            unset($_SESSION['import_preview']);
            header('Location: /admin/import-bookings.php?step=done');
            exit;
        }
    }
}

$step      = $_GET['step'] ?? '';
$preview   = $_SESSION['import_preview'] ?? null;
$report    = $_SESSION['import_report'] ?? null;

$pageTitle  = 'Import Bookings';
$activeMenu = 'import_bookings';
include __DIR__ . '/_layout.php';

// status → chip colour
$chip = function (string $s): string {
    $map = [
        'ok'        => ['#137a3f', '#e6f4ec', 'Will import'],
        'imported'  => ['#137a3f', '#e6f4ec', 'Imported'],
        'duplicate' => ['#6b6050', '#f1ede4', 'Already imported'],
        'conflict'  => ['#b45309', '#fdf0dd', 'Conflict'],
        'unmapped'  => ['#b91c1c', '#fbe6e6', 'Unmapped room'],
        'bad_dates' => ['#b91c1c', '#fbe6e6', 'Bad dates'],
    ];
    [$fg, $bg, $label] = $map[$s] ?? ['#333', '#eee', $s];
    return '<span style="display:inline-block;font-size:11px;font-weight:700;color:' . $fg
         . ';background:' . $bg . ';padding:2px 8px;border-radius:10px">' . e($label) . '</span>';
};
?>

<div class="page-header">
  <h1>Import Bookings <span class="text-muted" style="font-weight:400">· Zuri</span></h1>
</div>

<?php if ($error): ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<?php if (!$canImport): ?>
  <div class="alert alert--error">You don't have access to Zuri, so you can't import its bookings.</div>
  <?php include __DIR__ . '/_layout_end.php'; return; ?>
<?php endif; ?>

<?php if ($step === 'done' && $report): ?>
  <!-- ── Report ── -->
  <div class="card">
    <div class="card__head"><span class="card__title">Import complete</span></div>
    <div class="card__body" style="padding:20px">
      <p style="margin:0 0 14px">
        <strong><?= (int)$report['imported'] ?></strong> imported ·
        <strong><?= (int)$report['duplicate'] ?></strong> already present ·
        <strong><?= (int)$report['conflict'] ?></strong> conflicts ·
        <strong><?= (int)$report['unmapped'] ?></strong> unmapped ·
        <strong><?= (int)$report['bad_dates'] ?></strong> bad dates
      </p>
      <?php if ((int)$report['conflict'] > 0): ?>
      <p class="text-muted" style="margin:0 0 14px">Conflicts were recorded on the
        <a href="/admin/conflicts.php">Conflicts</a> page for review — no block was written for those.</p>
      <?php endif; ?>
      <div style="overflow-x:auto">
        <table class="data-table" style="width:100%">
          <thead><tr><th>Guest</th><th>Room</th><th>Outcome</th><th>Detail</th></tr></thead>
          <tbody>
            <?php foreach ($report['rows'] as $r): ?>
            <tr>
              <td><?= e($r['guest']) ?></td>
              <td><?= e($r['room']) ?></td>
              <td><?= $chip($r['outcome']) ?></td>
              <td class="text-muted"><?= e($r['detail']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <a href="/admin/import-bookings.php" class="btn-primary">Import another file</a>
        <a href="/admin/gantt.php" class="btn-outline">Open the calendar</a>
      </div>
    </div>
  </div>

<?php elseif ($step === 'preview' && $preview): ?>
  <!-- ── Dry-run preview ── -->
  <?php
    $rows = $preview['resolved'];
    $counts = ['ok'=>0,'duplicate'=>0,'conflict'=>0,'unmapped'=>0,'bad_dates'=>0];
    foreach ($rows as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }
    $willImport = $counts['ok'];
  ?>
  <div class="card">
    <div class="card__head"><span class="card__title">Preview — <?= e($preview['filename']) ?></span></div>
    <div class="card__body" style="padding:20px">
      <p style="margin:0 0 6px">
        <strong><?= $willImport ?></strong> row(s) will be imported.
        <span class="text-muted">
          <?= (int)$counts['duplicate'] ?> already present ·
          <?= (int)$counts['conflict'] ?> conflicts ·
          <?= (int)$counts['unmapped'] ?> unmapped ·
          <?= (int)$counts['bad_dates'] ?> bad dates — these are skipped.
        </span>
      </p>
      <?php if ((int)$counts['unmapped'] > 0): ?>
      <p class="text-muted" style="margin:6px 0 0;font-size:13px">Unmapped rooms are never guessed — fix the sheet's room name (or the mapping in <code>includes/booking-import.php</code>) and re-upload.</p>
      <?php endif; ?>

      <div style="overflow-x:auto;margin-top:16px">
        <table class="data-table" style="width:100%">
          <thead><tr>
            <th>Guest</th><th>Ezee room</th><th>→ Website room</th>
            <th>Arrival</th><th>Departure</th><th>Agent</th><th>Status</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['guest']) ?></td>
              <td><?= e($r['room_raw']) ?><?php if (!empty($r['unit_label'])): ?> <span class="text-muted">(<?= e($r['unit_label']) ?>)</span><?php endif; ?></td>
              <td><?= $r['room_name'] ? e($r['room_name']) : '<span class="text-muted">—</span>' ?></td>
              <td><?= $r['arrival'] ? e($r['arrival']) : '<span class="text-muted">'.e($r['arrival_raw']).'</span>' ?></td>
              <td><?= $r['dept'] ? e($r['dept']) : '<span class="text-muted">'.e($r['dept_raw']).'</span>' ?></td>
              <td class="text-muted"><?= e($r['agent'] === '-' ? '' : $r['agent']) ?></td>
              <td><?= $chip($r['status']) ?><?php if (!empty($r['detail']) && $r['status']!=='ok'): ?><br><span class="text-muted" style="font-size:11px"><?= e($r['detail']) ?></span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="commit">
          <button type="submit" class="btn-primary" <?= $willImport ? '' : 'disabled' ?>>
            Import <?= $willImport ?> booking<?= $willImport === 1 ? '' : 's' ?>
          </button>
        </form>
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn-outline">Cancel</button>
        </form>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ── Upload ── -->
  <div class="card">
    <div class="card__head"><span class="card__title">Upload the Ezee export</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <div class="field">
          <label>Bookings file <span class="text-muted">(.csv, .tsv or .xlsx — max 4 MB)</span></label>
          <input type="file" name="sheet" accept=".csv,.tsv,.xlsx" required>
          <span class="field-hint">Tip: in Ezee, export the bookings sheet as CSV or XLSX. You'll see a preview before anything is saved.</span>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:14px">Upload &amp; preview</button>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="card__head"><span class="card__title">How it works</span></div>
    <div class="card__body" style="padding:20px">
      <ul class="text-muted" style="margin:0 0 16px;padding-left:18px;line-height:1.8">
        <li>Each accepted booking becomes a calendar <strong>block</strong> on the matching Zuri room, so those dates can't be double-booked and show on the Gantt.</li>
        <li>Rows that overlap an existing website booking are flagged as <strong>conflicts</strong> and sent to the Conflicts page — never overwritten.</li>
        <li>Re-uploading the same sheet is safe: already-imported rows are detected and skipped.</li>
        <li>An <strong>“Entire Retreat Buyout”</strong> row blocks every Zuri suite for its dates.</li>
      </ul>
      <p style="margin:0 0 8px;font-weight:600;font-size:13px">Room name mapping</p>
      <div style="overflow-x:auto">
        <table class="data-table" style="width:auto;font-size:13px">
          <thead><tr><th>Ezee room name</th><th>→ Website room</th></tr></thead>
          <tbody>
            <?php foreach (ZURI_ROOM_MAP as $ezee => $slug):
              $ru = import_room_unit($slug); ?>
            <tr>
              <td><?= e(ucwords($ezee)) ?></td>
              <td><?= $ru ? e($ru['room_name']) : '<span class="text-muted">'.e($slug).' (missing)</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
