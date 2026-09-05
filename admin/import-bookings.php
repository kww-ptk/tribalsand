<?php
declare(strict_types=1);
/**
 * Channel-manager booking importer (Ezee sheet → calendar blocks), per property.
 *
 * Upload the channel manager's export (.csv / .tsv / .xlsx), review a mandatory
 * dry-run preview, then commit. Accepted rows become availability_blocks
 * (block_type='blocked'), mirroring the OTA iCal import — they prevent
 * double-booking and appear on the Gantt. Overlaps with existing website holds
 * are recorded as channel_conflicts, never silently overwritten. Idempotent.
 *
 * Owner + house manager, scoped by admin_venue_ids(): the property is chosen up
 * front and validated against the account's own list, and a row whose mapped room
 * belongs to another property is blocked rather than imported.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/booking-import.php';
require_login();
require_manager();               // owner or house manager

// ── Property scoping ─────────────────────────────────────────────────
// The import is per property: one Ezee export covers one property, so the
// venue is chosen up front and every row is resolved against it.
$scope    = admin_venue_ids();     // null = owner (all); array = manager's venues
$venues   = $scope === null
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC")->fetchAll()
    : ($scope
        ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ")
                    ORDER BY sort_order ASC, name ASC")->fetchAll()
        : []);
$allowedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

/** The venue this request acts on — always validated against the account's own list. */
$importVenueId = (int)($_POST['venue_id'] ?? $_GET['venue'] ?? 0);
if ($importVenueId && !in_array($importVenueId, $allowedVenueIds, true)) $importVenueId = 0;
if (!$importVenueId && $allowedVenueIds) $importVenueId = $allowedVenueIds[0];

$canImport   = $importVenueId > 0;
$importVenue = null;
foreach ($venues as $v) if ((int)$v['id'] === $importVenueId) { $importVenue = $v; break; }

$MAX_BYTES = 4 * 1024 * 1024;
$ALLOWED   = ['csv', 'tsv', 'xlsx'];

if (session_status() === PHP_SESSION_NONE) session_start();

$error = $flash = '';
if (isset($_GET['mapsaved'])) $flash = 'Room mapping saved.';

// ── POST handlers ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canImport) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        unset($_SESSION['import_preview'], $_SESSION['import_report']);
        header('Location: /admin/import-bookings.php');
        exit;
    }

    if ($action === 'save_room_map') {
        // Zip the parallel ezee[]/slug[] rows into a map; keep only rows with a
        // name AND a slug that is a real room IN THIS PROPERTY (an unknown or
        // blank slug drops the row, so its Ezee name becomes "unmapped" and
        // blocks its bookings rather than being guessed). Re-querying per venue
        // is also what stops a posted slug from another property being saved.
        $allowed = db_query(
            "SELECT slug FROM rooms WHERE venue_id = :v", [':v' => $importVenueId]
        )->fetchAll(PDO::FETCH_COLUMN);
        $names = (array)($_POST['ezee'] ?? []);
        $slugs = (array)($_POST['slug'] ?? []);
        $map   = [];
        foreach ($names as $i => $nm) {
            $nm = trim((string)$nm);
            $sl = trim((string)($slugs[$i] ?? ''));
            if ($nm !== '' && $sl !== '' && in_array($sl, $allowed, true)) $map[$nm] = $sl;
        }
        import_room_map_save($importVenueId, $map);
        header('Location: /admin/import-bookings.php?venue=' . $importVenueId . '&mapsaved=1');
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
                    $resolved = import_resolve_all($parsed['rows'], $importVenueId);
                    if (!$resolved) {
                        $error = 'No booking rows were found in that file. Is the header row present?';
                    } else {
                        $_SESSION['import_preview'] = [
                            'fields'   => $parsed['fields'],
                            'resolved' => $resolved,
                            'filename' => (string)$f['name'],
                            'venue_id' => $importVenueId,
                        ];
                        unset($_SESSION['import_report']);
                        header('Location: /admin/import-bookings.php?venue=' . $importVenueId . '&step=preview');
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
            // Merge the amounts staff entered/adjusted in the preview (keyed by
            // row index) over the parsed values before committing.
            $postedAmts = (array)($_POST['amount'] ?? []);
            foreach ($pv['resolved'] as $i => &$__row) {
                if (array_key_exists((string)$i, $postedAmts) || array_key_exists($i, $postedAmts)) {
                    $raw = (string)($postedAmts[$i] ?? $postedAmts[(string)$i] ?? '');
                    $__row['amount'] = $raw === '' ? 0.0 : max(0.0, (float)$raw);
                }
            }
            unset($__row);
            $report = import_commit($pv['resolved']);
            audit_log('bookings.import', 'venue', (int)($pv['venue_id'] ?? $importVenueId),
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
    // [.badge modifier, admin_icon name, label] — reuse the house badge component.
    $map = [
        'ok'        => ['green',  'check',       'Will import'],
        'imported'  => ['green',  'check',       'Imported'],
        'duplicate' => ['grey',   'check-check', 'Already imported'],
        'conflict'  => ['orange', 'ban',         'Conflict'],
        'unmapped'  => ['red',    'x',           'Unmapped room'],
        'bad_dates' => ['red',    'x',           'Bad dates'],
    ];
    [$color, $icon, $label] = $map[$s] ?? ['grey', 'variant', $s];
    return '<span style="display:inline-flex;align-items:center;gap:6px">'
         . admin_icon($icon, 14)
         . '<span class="badge badge--' . $color . '">' . e($label) . '</span></span>';
};
?>

<div class="page-header">
  <h1 style="display:inline-flex;align-items:center;gap:10px"><?= admin_icon('download', 22) ?> Import Bookings<?php if ($importVenue): ?> <span class="text-muted" style="font-weight:400">· <?= e($importVenue['name']) ?></span><?php endif; ?></h1>
  <?php if (count($venues) > 1): ?>
  <form method="GET" style="display:flex;gap:8px;align-items:center;margin:0">
    <label class="text-muted" style="font-size:12.5px">Property</label>
    <select name="venue" class="eselect" onchange="this.form.submit()">
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $importVenueId ? ' selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>
<?php if ($importVenue): ?>
<p class="text-muted" style="font-size:12.5px;margin:-6px 0 16px;max-width:70ch">
  One export covers one property. Everything below — the room mapping and the rows you
  import — applies to <strong><?= e($importVenue['name']) ?></strong> only, and a mapping
  that points at another property's room is blocked rather than imported.
</p>
<?php endif; ?>

<?php if ($flash): ?><div class="alert alert--success is-flash"><?= e($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<?php if (!$canImport): ?>
  <div class="alert alert--error">No properties are assigned to your account, so there is nothing to import into.</div>
  <?php include __DIR__ . '/_layout_end.php'; return; ?>
<?php endif; ?>

<?php if ($step === 'done' && $report): ?>
  <!-- ── Report ── -->
  <div class="card">
    <div class="card__head"><span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><?= admin_icon('check-check', 16) ?> Import complete</span></div>
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
        <a href="/admin/import-bookings.php" class="btn-primary"><?= admin_icon('download', 15) ?> Import another file</a>
        <a href="/admin/gantt.php" class="btn-outline"><?= admin_icon('calendar', 15) ?> Open the calendar</a>
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
    <div class="card__head"><span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><?= admin_icon('eye', 16) ?> Preview — <?= e($preview['filename']) ?></span></div>
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
      <p class="text-muted" style="margin:6px 0 0;font-size:13px">
        <?= !empty($preview['fields']['amount'])
              ? 'An amount column was detected — figures are pre-filled below. Adjust any before importing.'
              : 'No amount column was found in the sheet — type each booking&rsquo;s total below (leave 0 to import it unpriced).' ?>
        Amounts feed the <a href="/admin/reports.php">financial reports</a>.
      </p>

      <!-- The amount inputs live INSIDE the commit form so they post with it. -->
      <form method="POST" id="import-commit-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="commit">
      <div style="overflow-x:auto;margin-top:16px">
        <table class="data-table" style="width:100%">
          <thead><tr>
            <th>Guest</th><th>Ezee room</th><th>→ Website room</th>
            <th>Arrival</th><th>Departure</th><th>Agent</th><th>Amount</th><th>Status</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
            <tr>
              <td><?= e($r['guest']) ?></td>
              <td><?= e($r['room_raw']) ?><?php if (!empty($r['unit_label'])): ?> <span class="text-muted">(<?= e($r['unit_label']) ?>)</span><?php endif; ?></td>
              <td><?= $r['room_name'] ? e($r['room_name']) : '<span class="text-muted">—</span>' ?></td>
              <td><?= $r['arrival'] ? e($r['arrival']) : '<span class="text-muted">'.e($r['arrival_raw']).'</span>' ?></td>
              <td><?= $r['dept'] ? e($r['dept']) : '<span class="text-muted">'.e($r['dept_raw']).'</span>' ?></td>
              <td class="text-muted"><?= e($r['agent'] === '-' ? '' : $r['agent']) ?></td>
              <td>
                <?php if ($r['status'] === 'ok'): ?>
                <span style="display:inline-flex;align-items:center;gap:6px">
                  <span class="text-muted" style="font-size:11px"><?= e((string)($r['currency'] ?? 'USD')) ?></span>
                  <input type="number" min="0" step="0.01" class="inp inp--sm" style="width:110px"
                         name="amount[<?= (int)$i ?>]"
                         value="<?= $r['amount'] !== null ? e((string) round((float)$r['amount'], 2)) : '' ?>"
                         placeholder="0">
                </span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= $chip($r['status']) ?><?php if (!empty($r['detail']) && $r['status']!=='ok'): ?><br><span class="text-muted" style="font-size:11px"><?= e($r['detail']) ?></span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
          <button type="submit" class="btn-primary" <?= $willImport ? '' : 'disabled' ?>>
            <?= admin_icon('check', 15) ?> Import <?= $willImport ?> booking<?= $willImport === 1 ? '' : 's' ?>
          </button>
      </form>
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn-outline"><?= admin_icon('x', 15) ?> Cancel</button>
        </form>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ── Upload ── -->
  <div class="card">
    <div class="card__head"><span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><?= admin_icon('download', 16) ?> Upload the Ezee export</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <input type="hidden" name="venue_id" value="<?= (int)$importVenueId ?>">
        <div class="field">
          <label>Bookings file <span class="text-muted">(.csv, .tsv or .xlsx — max 4 MB)</span></label>
          <label class="filefield">
            <span class="btn-outline btn-sm"><?= admin_icon('download', 14) ?> Choose file</span>
            <input type="file" name="sheet" accept=".csv,.tsv,.xlsx" data-import-file>
            <span class="filefield__name" data-import-filename>No file chosen</span>
          </label>
          <span class="field-hint" style="display:block;margin-top:8px">Tip: in Ezee, export the bookings sheet as CSV or XLSX. You'll see a preview before anything is saved.</span>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:14px" data-import-submit disabled><?= admin_icon('eye', 15) ?> Upload &amp; preview</button>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="card__head"><span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><?= admin_icon('settings', 16) ?> How it works</span></div>
    <div class="card__body" style="padding:20px">
      <div style="display:grid;gap:12px;margin:0 0 18px;max-width:70ch">
        <div style="display:flex;gap:10px;align-items:flex-start"><span style="flex:0 0 auto;color:var(--accent)"><?= admin_icon('calendar', 16) ?></span><span class="text-muted">Each accepted booking becomes a calendar <strong>block</strong> on the matching room in this property, so those dates can't be double-booked and show on the Calendar.</span></div>
        <div style="display:flex;gap:10px;align-items:flex-start"><span style="flex:0 0 auto;color:var(--accent)"><?= admin_icon('ban', 16) ?></span><span class="text-muted">Rows that overlap an existing website booking are flagged as <strong>conflicts</strong> and sent to the Conflicts page — never overwritten.</span></div>
        <div style="display:flex;gap:10px;align-items:flex-start"><span style="flex:0 0 auto;color:var(--accent)"><?= admin_icon('check-check', 16) ?></span><span class="text-muted">Re-uploading the same sheet is safe: already-imported rows are detected and skipped.</span></div>
        <div style="display:flex;gap:10px;align-items:flex-start"><span style="flex:0 0 auto;color:var(--accent)"><?= admin_icon('home', 16) ?></span><span class="text-muted">A row mapped to a <strong>whole-property</strong> room blocks every room in that property for its dates.</span></div>
      </div>
      <p style="margin:0 0 4px;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px"><?= admin_icon('link', 14) ?> Room name mapping</p>
      <p class="text-muted" style="margin:0 0 12px;font-size:12.5px;max-width:70ch">
        How each Ezee room name maps to a website room. Leave a row's room set to
        <em>Block (no match)</em> to unmap it — an unmatched Ezee name is never guessed;
        it blocks that booking and is flagged in the preview. Add new rows for names
        that appear in future exports.
      </p>
      <?php
        $__map      = import_room_map($importVenueId);
        $__venueRooms = db_query(
          "SELECT slug, name FROM rooms WHERE venue_id = :v ORDER BY sort_order, name",
          [':v' => $importVenueId]
        )->fetchAll();
        // Existing map rows, then a few blanks to add new names.
        $__rows = [];
        foreach ($__map as $__ezee => $__slug) $__rows[] = ['ezee' => ucwords($__ezee), 'slug' => $__slug];
        for ($__b = 0; $__b < 3; $__b++) $__rows[] = ['ezee' => '', 'slug' => ''];
      ?>
      <form method="post" action="/admin/import-bookings.php" data-shell-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_room_map">
        <input type="hidden" name="venue_id" value="<?= (int)$importVenueId ?>">
        <div class="rmap">
          <?php foreach ($__rows as $__r): ?>
          <div class="rmap__row">
            <input type="text" class="inp inp--sm" name="ezee[]" value="<?= e($__r['ezee']) ?>" placeholder="Ezee room name">
            <span class="rmap__arrow"><?= admin_icon('arrow-right', 15) ?></span>
            <select name="slug[]" aria-label="Website room">
              <option value="">— Block (no match) —</option>
              <?php foreach ($__venueRooms as $__wr): ?>
              <option value="<?= e($__wr['slug']) ?>" <?= $__wr['slug'] === $__r['slug'] ? 'selected' : '' ?>><?= e($__wr['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn-primary btn-sm" style="margin-top:14px"><?= admin_icon('check', 14) ?> Save mapping</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<script>
// Styled file field: reflect the chosen filename + enable the submit button.
(function () {
  var inp  = document.querySelector('[data-import-file]');
  var name = document.querySelector('[data-import-filename]');
  var btn  = document.querySelector('[data-import-submit]');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    if (name) name.textContent = f ? f.name : 'No file chosen';
    if (btn)  btn.disabled = !f;
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
