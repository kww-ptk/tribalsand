<?php
/**
 * Admin: import a company's staff list (Team → Staff → Import staff lists).
 *
 * Owner + HR. Pick one of the October 2026 lists shipped with the code (the four
 * companies' .XLS files, converted once into db/seeds/hr_establishment_2026_10.json)
 * or upload a newer list as .xlsx / .csv → PREVIEW (writes nothing): who matches an
 * existing person (by payroll number, else by name), who is new, who needs a
 * decision → CONFIRM (one transaction, re-validated server-side).
 *
 * Under the import: "Not on any company list" — active people with no company,
 * each with Mark inactive. Nobody is ever deleted.
 * Logic: includes/hr-establishment.php · test: php tests/staff_establishment_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr-establishment.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
if (!is_owner() && !is_hr()) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Importing staff lists is for the owner and HR.'];
    header('Location: ' . admin_home_url()); exit;
}

$pageTitle  = 'Import staff lists';
$activeMenu = 'staff';
$ready      = hr_staff_supported() && hr_establishment_supported() && to_regclass_exists_hr('companies');
$bundled    = hr_est_bundled_lists();

/** companies table present? (local helper — companies.php has its own, loaded lazily) */
function to_regclass_exists_hr(string $t): bool {
    try { return (bool) db_query('SELECT to_regclass(:t)', [':t' => 'public.' . $t])->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function sti_back(string $msg, string $type = 'success', string $qs = ''): never {
    $_SESSION['sti_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: /admin/staff-import.php' . $qs); exit;
}

/** The pending preview kept between Preview and Confirm (one at a time). */
function sti_pending(): ?array { return $_SESSION['hr_est_pending'] ?? null; }

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if (!$ready) sti_back('Run the add_hr_staff_establishment migration first.', 'error');

    if ($action === 'preview_bundled') {
        $i = (int)($_POST['list'] ?? -1);
        $l = $bundled[$i] ?? null;
        if (!$l) sti_back('That list is not available.', 'error');
        $_SESSION['hr_est_pending'] = [
            'company_id' => hr_company_find_by_name((string)$l['company']),
            'company'    => (string)$l['company'], 'code' => (string)$l['code'],
            'source'     => (string)$l['source'], 'rows' => array_values($l['rows']),
        ];
        header('Location: /admin/staff-import.php?step=preview'); exit;
    }

    if ($action === 'preview_upload') {
        $coId = (int)($_POST['company_id'] ?? 0);
        $f    = $_FILES['list'] ?? null;
        if (!isset(hr_company_names()[$coId])) sti_back('Pick the company this list belongs to.', 'error');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) sti_back('Choose a file to upload.', 'error');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        try {
            if ($ext === 'csv') {
                $rows = [];
                $h = fopen((string)$f['tmp_name'], 'r');
                while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) $rows[] = array_map(fn($v) => (string)$v, $r);
                fclose($h);
            } elseif ($ext === 'xlsx') {
                $rows = xlsx_read_rows((string)$f['tmp_name']);
            } else {
                sti_back('Upload the list as .xlsx or .csv. An old .xls file: open it in Excel and "Save as" .xlsx first.', 'error');
            }
            $parsed = hr_est_parse_sheet($rows);
        } catch (Throwable $e) {
            sti_back($e->getMessage(), 'error');
        }
        if (!$parsed['rows']) sti_back('No staff rows found under the header row.', 'error');
        $_SESSION['hr_est_pending'] = [
            'company_id' => $coId, 'company' => hr_company_names()[$coId], 'code' => '',
            'source' => (string)$f['name'], 'rows' => $parsed['rows'],
        ];
        header('Location: /admin/staff-import.php?step=preview'); exit;
    }

    if ($action === 'apply') {
        $pend = sti_pending();
        if (!$pend || ($_POST['token'] ?? '') !== substr(sha1(json_encode($pend)), 0, 16)) sti_back('That preview is out of date — preview the list again.', 'error');
        try {
            $coId = $pend['company_id'] ?? null;
            if ($coId === null) {
                $coId = hr_est_ensure_company((string)$pend['company'], (string)$pend['code']);
                audit_log('hr.company_created', 'company', $coId, (string)$pend['company']);
            }
            $dec = is_array($_POST['decision'] ?? null) ? array_map('strval', $_POST['decision']) : [];
            $n = hr_est_apply((int)$coId, $pend['rows'], $dec, (int)($_SESSION['admin_id'] ?? 0));
        } catch (Throwable $e) {
            sti_back('Nothing was imported: ' . $e->getMessage(), 'error', '?step=preview');
        }
        unset($_SESSION['hr_est_pending']);
        sti_back($pend['company'] . ': ' . $n['updated'] . ' updated, ' . $n['created'] . ' added' . ($n['skipped'] ? ', ' . $n['skipped'] . ' left out' : '') . '.');
    }

    if ($action === 'mark_inactive') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        if (!$ids) sti_back('Tick at least one person.', 'error', '#missing');
        $in = implode(',', $ids);
        $n = db_query("UPDATE hr_staff SET status = 'inactive' WHERE id IN ($in) AND status = 'active' AND company_id IS NULL")->rowCount();
        audit_log('hr.mark_inactive', 'hr_staff', 0, $n . ' people not on any company list');
        sti_back($n . ' ' . ($n === 1 ? 'person' : 'people') . ' marked inactive. Their history stays; you can switch them back on in the directory.', 'success', '#missing');
    }
    if ($action === 'cancel') { unset($_SESSION['hr_est_pending']); sti_back('Preview discarded.'); }
    sti_back('Unknown action.', 'error');
}

// ── GET ──────────────────────────────────────────────────────────────────────
$flash = $_SESSION['sti_flash'] ?? null; unset($_SESSION['sti_flash']);
$step  = (string)($_GET['step'] ?? '');
$pend  = $step === 'preview' ? sti_pending() : null;
$pv    = ($ready && $pend) ? hr_est_preview((int)($pend['company_id'] ?? 0), $pend['rows']) : null;
$venueNames = [];
foreach (db_query('SELECT id, name FROM venues')->fetchAll() as $v) $venueNames[(int)$v['id']] = $v['name'];
$coNames = $ready ? hr_company_names() : [];
$coCounts = [];
if ($ready) foreach (db_query('SELECT company_id, COUNT(*) n FROM hr_staff WHERE company_id IS NOT NULL GROUP BY 1')->fetchAll() as $r) $coCounts[(int)$r['company_id']] = (int)$r['n'];
$missing = $ready ? db_query("SELECT id, full_name, position, venue_id, unit_label FROM hr_staff
                               WHERE status = 'active' AND company_id IS NULL ORDER BY venue_id NULLS LAST, full_name")->fetchAll() : [];
$placeOf = function (?int $vid, array $extra, string $label) use ($venueNames): string {
    $names = array_values(array_filter(array_map(fn($v) => $venueNames[$v] ?? null, array_merge($vid ? [$vid] : [], $extra))));
    return $names ? implode(' + ', $names) : ($label !== '' ? $label : '—');
};

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Import staff lists</h1>
  <div class="actions"><a href="/admin/staff.php?tab=directory" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Team</a></div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$ready): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">
  Run <code>add_companies.sql</code> and <code>add_hr_staff_establishment.sql</code> (Settings → Your account → Migrations) to import company staff lists.
</p></div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<?php if ($pv): $token = substr(sha1(json_encode($pend)), 0, 16); $c = $pv['counts']; ?>
<!-- ═══════════ PREVIEW ═══════════ -->
<div class="card" style="margin-bottom:16px">
  <div class="card__head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="card__title"><?= e($pend['company']) ?> · <?= count($pv['rows']) ?> people<?= $pend['company_id'] === null ? ' · company will be created' : '' ?></span>
    <span class="sti-chips">
      <?php if ($c['payroll']): ?><span class="badge badge--grey"><?= $c['payroll'] ?> already filed</span><?php endif; ?>
      <span class="badge badge--green"><?= $c['name'] ?> matched by name</span>
      <span class="badge badge--blue"><?= $c['new'] ?> new</span>
      <?php if ($c['decide']): ?><span class="badge badge--orange"><?= $c['decide'] ?> need a decision</span><?php endif; ?>
    </span>
  </div>
  <div class="card__body" style="padding:12px 18px">
    <p class="text-muted" style="margin:0;font-size:13px">Nothing is saved yet. Check each row, choose for the orange ones, then <strong>Import</strong>. Existing people keep their name, phone, documents and history — the list adds their company and payroll number, and updates position and property.<?= $c['decide'] ? ' Orange rows with no choice are left out.' : '' ?></p>
  </div>
</div>

<form method="POST" action="/admin/staff-import.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="apply">
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="card">
    <div class="table-wrap">
      <table class="data-table sti-table">
        <thead><tr><th>Payroll</th><th>On the list</th><th>Surname · first names</th><th>Position</th><th>Works at</th><th>Match</th><th>Do</th></tr></thead>
        <tbody>
        <?php foreach ($pv['rows'] as $i => $r):
          $m = $r['match']; $cur = $r['current']; $plan = $r['plan'];
          $cls = ['payroll' => 'grey', 'name' => 'green', 'new' => 'blue', 'decide' => 'orange'][$m['status']]; ?>
          <tr class="sti-row sti-row--<?= e($m['status']) ?>">
            <td><code><?= e($r['payroll_no']) ?></code></td>
            <td><?= e($r['name']) ?></td>
            <td><strong><?= e($r['parsed']['surname']) ?></strong> · <?= e($r['parsed']['first_names']) ?>
              <?php if ($r['parsed']['unsure']): ?><span class="badge badge--orange" data-tip="This row has two capitalised words, so the surname is a guess (the last word). Check it.">check surname</span><?php endif; ?></td>
            <td><?= e($r['position']) ?> <span class="badge <?= e(hr_department_badge(hr_department($r['position']))) ?>"><?= e(hr_department($r['position'])) ?></span></td>
            <td><?= e($placeOf($plan['venue_id'], $plan['extra_ids'], $plan['unit_label'])) ?>
              <?php if ($cur && $cur['venue_id'] !== null && $plan['venue_id'] !== null && (int)$cur['venue_id'] !== $plan['venue_id']): ?>
              <div class="text-muted" style="font-size:12px">was <?= e($venueNames[(int)$cur['venue_id']] ?? '—') ?></div><?php endif; ?></td>
            <td><span class="badge badge--<?= $cls ?>"><?= e(['payroll' => 'Already filed', 'name' => 'Same person', 'new' => 'New', 'decide' => 'Decide'][$m['status']]) ?></span>
              <?php if ($cur): ?><div style="font-size:12.5px;margin-top:3px"><a href="/admin/employee.php?id=<?= (int)$cur['id'] ?>" target="_blank" rel="noopener"><?= e($cur['full_name']) ?></a></div>
              <?php if ($r['new_name'] !== $cur['full_name']): ?><div class="text-muted" style="font-size:12px">name becomes <?= e($r['new_name']) ?></div><?php endif; ?><?php endif; ?>
              <?php if ($m['status'] === 'decide'): ?><div class="text-muted" style="font-size:12px;margin-top:3px"><?= e($m['why']) ?></div><?php endif; ?></td>
            <td>
              <select name="decision[<?= (int)$i ?>]" class="filter-select" aria-label="What to do with <?= e($r['name']) ?>">
                <?php if ($m['status'] === 'decide'): ?><option value="skip">Choose…</option><?php endif; ?>
                <?php if ($cur): ?><option value="" selected>Update <?= e($cur['full_name']) ?></option><?php endif; ?>
                <?php foreach ($m['candidates'] as $cd): if ($cur && $cd['id'] === (int)$cur['id']) continue; ?>
                <option value="match:<?= (int)$cd['id'] ?>">Same person as <?= e($cd['name']) ?><?= $cd['note'] !== '' ? ' (' . e($cd['note']) . ')' : '' ?></option>
                <?php endforeach; ?>
                <option value="new" <?= $m['status'] === 'new' ? 'selected' : '' ?>>Add as a new person</option>
                <option value="skip">Leave out</option>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
    <button type="submit" class="btn-primary"><?= admin_icon('check', 15) ?> Import <?= e($pend['company']) ?></button>
  </div>
</form>
<form method="POST" action="/admin/staff-import.php" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn-outline btn-sm">Discard preview</button></form>

<?php else: ?>
<!-- ═══════════ PICK A LIST ═══════════ -->
<?php if ($bundled): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Company staff lists · 6 Oct 2026</span><span class="text-muted" style="font-size:12px"><?= array_sum(array_map(fn($l) => count($l['rows']), $bundled)) ?> people</span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Company</th><th>On the list</th><th>In the system</th><th style="text-align:right"></th></tr></thead>
    <tbody>
    <?php foreach ($bundled as $i => $l): $cid = hr_company_find_by_name((string)$l['company']); ?>
      <tr>
        <td><strong><?= e($l['company']) ?></strong><?php if (!empty($l['manager_note'])): ?> <span class="text-muted" style="font-size:12px">· <?= e($l['manager_note']) ?></span><?php endif; ?></td>
        <td><?= count($l['rows']) ?></td>
        <td class="text-muted"><?= $cid === null ? 'Company not created yet' : (($coCounts[$cid] ?? 0) . ' filed') ?></td>
        <td style="text-align:right">
          <form method="POST" action="/admin/staff-import.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="preview_bundled"><input type="hidden" name="list" value="<?= (int)$i ?>">
            <button class="btn-outline btn-sm"><?= admin_icon('eye', 15) ?> Preview</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Upload a newer list</span></div>
  <div class="card__body card__body--pad">
    <?php if (!$coNames): ?>
    <p class="text-muted" style="margin:0">Import one of the lists above first — that creates the companies.</p>
    <?php else: ?>
    <form method="POST" action="/admin/staff-import.php" enctype="multipart/form-data" class="sti-up">
      <?= csrf_field() ?><input type="hidden" name="action" value="preview_upload">
      <div class="filter-field"><span>Company</span>
        <select name="company_id" class="filter-select" required>
          <option value="">Choose…</option>
          <?php foreach ($coNames as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select></div>
      <div class="filefield">
        <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('plus', 15) ?> Choose file<input type="file" name="list" accept=".xlsx,.csv" data-file-input required></label>
        <span class="filefield__name" data-file-name>No file chosen</span>
      </div>
      <button class="btn-primary btn-sm"><?= admin_icon('eye', 15) ?> Preview</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin:10px 0 0">The same layout as the companies' lists: a header row with <strong>Payroll number</strong> (or Staff no.) and <strong>Name</strong>, then Department / Description and Unit. Save old .xls files as .xlsx first.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════ NOT ON ANY LIST ═══════════ -->
<?php if (!$pv): ?>
<div class="card" id="missing">
  <div class="card__head"><span class="card__title">Not on any company list</span><span class="text-muted" style="font-size:12px"><?= count($missing) ?> active</span></div>
  <?php if (!$missing): ?>
  <div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Everyone active belongs to a company.</p></div>
  <?php else: ?>
  <form method="POST" action="/admin/staff-import.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="mark_inactive">
    <div class="card__body" style="padding:10px 18px"><p class="text-muted" style="margin:0;font-size:13px">Active people who are on none of the imported lists — usually someone who left, or the same person under another spelling (then import that list row as <em>Same person as …</em>). Mark the ones who left inactive; nothing is deleted.</p></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th style="width:34px"></th><th>Name</th><th>Position</th><th>Works at</th></tr></thead>
      <tbody>
      <?php foreach ($missing as $p): ?>
        <tr><td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" aria-label="Tick <?= e($p['full_name']) ?>"></td>
          <td><a href="/admin/employee.php?id=<?= (int)$p['id'] ?>"><?= e($p['full_name']) ?></a></td>
          <td class="text-muted"><?= e($p['position'] ?: '—') ?></td>
          <td class="text-muted"><?= e($placeOf($p['venue_id'] !== null ? (int)$p['venue_id'] : null, [], (string)($p['unit_label'] ?? ''))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="card__body" style="padding:12px 18px"><button class="btn-outline btn-sm" data-confirm="Mark the ticked people inactive? They disappear from attendance and clock cards; their history stays."><?= admin_icon('ban', 15) ?> Mark ticked inactive</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.sti-chips{display:flex;gap:6px;flex-wrap:wrap}
.sti-table td{vertical-align:top}
.sti-row--decide td{background:#fff8ef}
.sti-table select{max-width:260px}
.sti-up{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
</style>
<script>
(function(){
  document.querySelectorAll('.sti-up [data-file-input]').forEach(function(fi){
    fi.addEventListener('change', function(){
      var out = fi.closest('.filefield').querySelector('[data-file-name]');
      out.textContent = fi.files && fi.files.length ? fi.files[0].name : 'No file chosen';
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
