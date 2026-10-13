<?php
/**
 * Admin: import HR's Excel attendance workbook (Team → Attendance → Import from Excel).
 *
 * Owner + HR. Upload the workbook (.xlsx) → every month in it is read and kept in
 * attendance_imports → pick a month → PREVIEW (writes nothing): who each name is,
 * what happens to each day (added / updated / already the same / kept because it
 * was clocked on the kiosk or typed in by hand), codes the system doesn't know,
 * and a check against the sheet's own TOTAL HRS → IMPORT that month (one
 * transaction, re-checked on the server).
 * Logic: includes/attendance-import.php · test: php tests/attendance_import_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-import.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
if (!is_owner() && !is_hr()) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Importing attendance is for the owner and HR.'];
    header('Location: ' . admin_home_url()); exit;
}
@ini_set('memory_limit', '512M');   // the workbook's time sheets are ~1,000 columns wide

$pageTitle  = 'Import attendance';
$activeMenu = 'attendance';
$ready      = attendance_supported() && hr_staff_supported() && attendance_import_supported();
$self       = '/admin/attendance-import.php';

function aim_back(string $msg, string $type, string $qs = ''): never {
    $_SESSION['aim_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: /admin/attendance-import.php' . $qs); exit;
}
function aim_month_label(string $ym): string { return date('F Y', strtotime($ym . '-01')); }

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$ready) aim_back('Run the add_attendance_import migration first.', 'error');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload') {
        $f = $_FILES['workbook'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            aim_back(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is too large (16 MB at most).' : 'Choose the attendance workbook to upload.', 'error');
        }
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') aim_back('Upload the workbook as .xlsx (in Excel: Save as → Excel Workbook).', 'error');
        try {
            $id = att_imp_store((string)$f['tmp_name'], (string)$f['name'], (int)($_SESSION['admin_id'] ?? 0));
        } catch (Throwable $e) {
            aim_back('Could not read that workbook: ' . $e->getMessage(), 'error');
        }
        audit_log('attendance.import_upload', 'attendance_import', $id, (string)$f['name']);
        aim_back('Workbook read. Pick a month to preview.', 'success', '?import=' . $id);
    }

    if ($action === 'apply') {
        $id = (int)($_POST['import'] ?? 0);
        $ym = (string)($_POST['ym'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) aim_back('Pick a month.', 'error', '?import=' . $id);
        $dec = [];
        foreach ((array)($_POST['person'] ?? []) as $k => $v) $dec[(string)$k] = (string)$v;
        $codes = att_imp_code_map();
        foreach ((array)($_POST['code'] ?? []) as $c => $to) {
            $to = (string)$to;
            if (isset(att_imp_code_targets()[$to])) $codes[(string)$c] = $to;
        }
        set_setting('attendance_import_codes', json_encode($codes, JSON_UNESCAPED_UNICODE));
        try {
            $n = att_imp_apply($id, $ym, $dec, !empty($_POST['replace_manual']), (int)($_SESSION['admin_id'] ?? 0), $codes);
        } catch (Throwable $e) {
            aim_back('Nothing was imported: ' . $e->getMessage(), 'error', '?import=' . $id . '&ym=' . urlencode($ym));
        }
        $kept = $n['kept_kiosk'] + $n['kept_manual'];
        aim_back(aim_month_label($ym) . ': ' . ($n['written'] + $n['updated'] + $n['replaced']) . ' days saved for ' . $n['people'] . ' people'
            . ($n['same'] ? ', ' . $n['same'] . ' already the same' : '') . ($kept ? ', ' . $kept . ' kept as they were' : '')
            . ($n['former'] ? ', ' . $n['former'] . ' former staff added' : '') . '.', 'success', '?import=' . $id);
    }
    aim_back('Unknown action.', 'error');
}

// ── GET ──────────────────────────────────────────────────────────────────────
$flash  = $_SESSION['aim_flash'] ?? null; unset($_SESSION['aim_flash']);
$imp    = $ready && isset($_GET['import']) ? att_imp_fetch((int)$_GET['import']) : null;
$ym     = (string)($_GET['ym'] ?? '');
$replace = !empty($_GET['replace']);
$pv     = null;
if ($imp && preg_match('/^\d{4}-\d{2}$/', $ym) && isset($imp['months'][$ym])) {
    try { $pv = att_imp_preview($imp, $ym, $replace); } catch (Throwable $e) { $flash = ['type' => 'error', 'msg' => $e->getMessage()]; }
}
$staffAll = $pv ? db_query("SELECT s.id, s.full_name, s.status, v.name AS venue FROM hr_staff s LEFT JOIN venues v ON v.id = s.venue_id
                             ORDER BY s.status ASC, s.full_name ASC")->fetchAll() : [];
$codeMap  = $ready ? att_imp_code_map() : [];
$labels   = ['write' => 'new', 'update' => 'updated', 'replace' => 'replaced', 'same' => 'same', 'kiosk' => 'kept: kiosk', 'manual' => 'kept: typed in', 'unmatched' => 'no person'];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Import attendance</h1>
  <div class="actions">
    <?php if ($imp && $pv): ?><a href="<?= $self ?>?import=<?= (int)$imp['id'] ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Months</a><?php endif; ?>
    <a href="/admin/attendance.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Attendance</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$ready): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Run <code>add_attendance_import.sql</code> (Settings → Your account → Migrations) to import attendance workbooks.</p></div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<?php if (!$imp): ?>
<!-- ═══════════ UPLOAD ═══════════ -->
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Upload the attendance workbook</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="<?= $self ?>" enctype="multipart/form-data" class="aim-up" data-help="attendance-import-upload">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <div class="filefield">
        <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('plus', 15) ?> Choose workbook<input type="file" name="workbook" accept=".xlsx" data-file-input required></label>
        <span class="filefield__name" data-file-name>No file chosen</span>
      </div>
      <button class="btn-primary btn-sm"><?= admin_icon('upload', 15) ?> Read workbook</button>
    </form>
    <p class="text-muted" style="font-size:12.5px;margin:10px 0 0">The HR workbook as it is — one time sheet per month (TIME IN · TIME OUT · TIME IN · TIME OUT · STATUS) and its attendance sheet. Summaries and balances are left alone. Nothing is saved until you import a month.</p>
  </div>
</div>
<?php $recent = att_imp_recent(); if ($recent): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Earlier uploads</span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Workbook</th><th>Months</th><th>Imported</th><th>Uploaded</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): $ap = json_decode((string)$r['applied'], true) ?: []; ?>
      <tr><td><?= e($r['filename']) ?></td>
        <td class="text-muted"><?= e(implode(', ', array_map(fn($m) => date('M', strtotime($m . '-01')), array_filter(explode(',', (string)$r['month_list']))))) ?></td>
        <td class="text-muted"><?= $ap ? e(implode(', ', array_map(fn($m) => date('M', strtotime($m . '-01')), array_keys($ap)))) : '—' ?></td>
        <td class="text-muted"><?= e(date('j M Y H:i', strtotime((string)$r['uploaded_at']))) ?><?= $r['uploaded_by_name'] ? ' · ' . e($r['uploaded_by_name']) : '' ?></td>
        <td style="text-align:right"><a class="btn-outline btn-sm" href="<?= $self ?>?import=<?= (int)$r['id'] ?>">Open</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php elseif (!$pv): ?>
<!-- ═══════════ MONTHS IN THE WORKBOOK ═══════════ -->
<div class="card">
  <div class="card__head"><span class="card__title"><?= e($imp['filename']) ?></span><span class="text-muted" style="font-size:12px"><?= count($imp['months']) ?> months · uploaded <?= e(date('j M Y H:i', strtotime((string)$imp['uploaded_at']))) ?></span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Month</th><th>Sheets read</th><th class="aim-num">People</th><th class="aim-num">Days</th><th>Imported</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($imp['months'] as $m => $mo): $done = $imp['applied'][$m] ?? null; ?>
      <tr>
        <td><strong><?= e(aim_month_label($m)) ?></strong></td>
        <td class="text-muted"><?= e(implode(' + ', $mo['sheets'])) ?></td>
        <td class="aim-num"><?= count($mo['people']) ?></td>
        <td class="aim-num"><?= array_sum(array_map(fn($p) => count($p['days']), $mo['people'])) ?></td>
        <td><?= $done ? '<span class="badge badge--green">Imported ' . e(date('j M', strtotime((string)$done['at']))) . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td style="text-align:right"><a class="btn-<?= $done ? 'outline' : 'primary' ?> btn-sm" href="<?= $self ?>?import=<?= (int)$imp['id'] ?>&amp;ym=<?= e($m) ?>"><?= admin_icon('eye', 15) ?> Preview</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php else: $t = $pv['totals']; ?>
<!-- ═══════════ PREVIEW ONE MONTH ═══════════ -->
<form method="POST" action="<?= $self ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="apply"><input type="hidden" name="import" value="<?= (int)$imp['id'] ?>"><input type="hidden" name="ym" value="<?= e($ym) ?>">
  <div class="card" style="margin-bottom:16px">
    <div class="card__head" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <span class="card__title"><?= e(aim_month_label($ym)) ?> · <?= count($pv['people']) ?> people</span>
      <span class="aim-chips">
        <span class="badge badge--green"><?= $t['write'] ?> new days</span>
        <?php if ($t['update'] + $t['replace']): ?><span class="badge badge--blue"><?= $t['update'] + $t['replace'] ?> updated</span><?php endif; ?>
        <?php if ($t['same']): ?><span class="badge badge--grey"><?= $t['same'] ?> already the same</span><?php endif; ?>
        <?php if ($t['kiosk']): ?><span class="badge badge--grey"><?= $t['kiosk'] ?> kept (kiosk)</span><?php endif; ?>
        <?php if ($t['manual']): ?><span class="badge badge--orange"><?= $t['manual'] ?> kept (typed in)</span><?php endif; ?>
        <?php if ($t['unmatched']): ?><span class="badge badge--orange"><?= $t['unmatched'] ?> days with no person</span><?php endif; ?>
      </span>
    </div>
    <div class="card__body" style="padding:12px 18px">
      <p class="text-muted" style="margin:0 0 10px;font-size:13px">Read from <?= e(implode(' + ', $pv['sheets'])) ?>. Nothing is saved yet. Days clocked on the kiosk are never changed.</p>
      <label class="optchip"><input type="checkbox" name="replace_manual" value="1" <?= $replace ? 'checked' : '' ?> onchange="location.href='<?= $self ?>?import=<?= (int)$imp['id'] ?>&ym=<?= e($ym) ?>'+(this.checked?'&replace=1':'')"> Also replace days that were typed into the system by hand</label>
    </div>
  </div>

  <?php if ($pv['unknown']): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Codes the system doesn't know</span></div>
    <div class="card__body card__body--pad">
      <p class="text-muted" style="margin:0 0 10px;font-size:13px">Say what each one means — the answer is remembered for later months.</p>
      <div class="aim-codes">
      <?php foreach ($pv['unknown'] as $code => $n): ?>
        <div class="filter-field"><span><strong><?= e($code) ?></strong> · <?= (int)$n ?> day<?= $n === 1 ? '' : 's' ?></span>
          <select name="code[<?= e($code) ?>]" class="filter-select">
            <?php foreach (att_imp_code_targets() as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($codeMap[$code] ?? 'keep') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
          </select></div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-wrap">
      <table class="data-table aim-table">
        <thead><tr><th>On the sheet</th><th>Person</th><th class="aim-num">Days</th><th class="aim-num">Hours</th><th class="aim-num">Sheet total</th><th>Check</th><th>What happens</th></tr></thead>
        <tbody>
        <?php $lastSection = null; foreach ($pv['people'] as $p):
          if ($p['section'] !== $lastSection): $lastSection = $p['section']; ?>
          <tr class="aim-sec"><td colspan="7"><?= e($p['section'] !== '' ? $p['section'] : 'First block of the sheet') ?></td></tr>
          <?php endif;
          $m = $p['match']; $auto = in_array($m['status'], ['remembered', 'name'], true);
          $fl = $p['flags']; $nflag = count($fl['missing']) + count($fl['reversed']) + count($fl['differs']) + count($fl['timesOnAbsence']); ?>
          <tr class="<?= !$auto ? 'aim-row--decide' : '' ?>">
            <td><strong><?= e($p['name']) ?></strong><div class="text-muted" style="font-size:12px"><?= e($p['position'] ?: '—') ?><?= $p['off'] !== '' ? ' · off ' . e($p['off']) : '' ?></div></td>
            <td>
              <select name="person[<?= e($p['key']) ?>]" class="filter-select aim-person" aria-label="Who is <?= e($p['name']) ?>">
                <?php if ($auto): ?><option value="" selected><?= e($p['staff']['full_name'] ?? '') ?><?= $m['status'] === 'remembered' ? ' (chosen before)' : '' ?></option>
                <?php else: ?><option value="skip" selected>Choose…</option><?php endif; ?>
                <?php foreach ($m['candidates'] as $c): if ($auto && (int)$c['id'] === (int)$m['staff_id']) continue; ?>
                <option value="match:<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= !empty($c['weak']) ? ' (one name in common)' : '' ?></option>
                <?php endforeach; ?>
                <?php if (!$auto): ?>
                <optgroup label="Everyone">
                  <?php foreach ($staffAll as $s): ?><option value="match:<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?><?= $s['venue'] ? ' · ' . e($s['venue']) : '' ?><?= $s['status'] !== 'active' ? ' (left)' : '' ?></option><?php endforeach; ?>
                </optgroup>
                <option value="add">Add to the directory (new — not on a company list yet)</option>
                <option value="former">Add as former staff (has left)</option>
                <?php endif; ?>
                <option value="skip">Leave out</option>
              </select>
              <?php if (!$auto): ?><div class="text-muted" style="font-size:12px;margin-top:3px"><?= e($m['why'] ?: 'No one with this name in the directory.') ?></div><?php endif; ?>
            </td>
            <td class="aim-num"><?= count($p['days']) ?></td>
            <td class="aim-num"><?= number_format($p['hours'], 1) ?></td>
            <td class="aim-num text-muted"><?= $p['xhours'] !== null ? number_format($p['xhours'], 1) : '—' ?></td>
            <td><?php if ($nflag): ?>
              <details class="aim-flags"><summary><span class="badge badge--orange"><?= $nflag ?> to check</span></summary>
                <?php foreach (['missing' => 'Time in without time out', 'reversed' => 'Time out before time in (counted 0 h)', 'differs' => 'Hours differ from the sheet total',
                                'timesOnAbsence' => 'Marked off / sick / leave but had times (counted as the absence, times kept in the note)'] as $fk => $fl2): if (!$fl[$fk]) continue; ?>
                <div style="font-size:12px;margin-top:4px"><strong><?= e($fl2) ?>:</strong> <?= e(implode(', ', array_map(fn($d) => date('j M', strtotime($d)), $fl[$fk]))) ?></div>
                <?php endforeach; ?></details>
              <?php elseif ($p['unknown']): ?><span class="badge badge--orange"><?= e(implode(', ', $p['unknown'])) ?></span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td class="aim-what"><?php foreach ($p['counts'] as $a => $c): ?><span class="badge badge--<?= in_array($a, ['write', 'update', 'replace'], true) ? 'green' : ($a === 'unmatched' || $a === 'manual' ? 'orange' : 'grey') ?>"><?= (int)$c ?> <?= e($labels[$a] ?? $a) ?></span> <?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;align-items:center">
    <button type="submit" class="btn-primary" data-confirm="Import <?= e(aim_month_label($ym)) ?>? Days clocked on the kiosk stay as they are."><?= admin_icon('check', 15) ?> Import <?= e(aim_month_label($ym)) ?></button>
    <span class="text-muted" style="font-size:12.5px">Orange rows with "Choose…" are left out. Your choices are remembered for the other months.</span>
  </div>
</form>
<?php endif; ?>

<style>
.aim-up{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.aim-num{text-align:right;white-space:nowrap}
.aim-chips{display:flex;gap:6px;flex-wrap:wrap}
.aim-codes{display:flex;gap:16px;flex-wrap:wrap}
.aim-table td{vertical-align:top}
.aim-table .aim-person{max-width:280px}
.aim-sec td{background:var(--bg,#f6f3ef);font-weight:700;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:var(--muted,#6b7280)}
.aim-row--decide td{background:#fff8ef}
.aim-flags summary{cursor:pointer;list-style:none}
.aim-flags summary::-webkit-details-marker{display:none}
.aim-what{white-space:normal;min-width:150px}
.aim-what .badge{margin:0 4px 4px 0}
</style>
<script>
(function(){
  document.querySelectorAll('.aim-up [data-file-input]').forEach(function(fi){
    fi.addEventListener('change', function(){
      var out = fi.closest('.filefield').querySelector('[data-file-name]');
      out.textContent = fi.files && fi.files.length ? fi.files[0].name : 'No file chosen';
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
