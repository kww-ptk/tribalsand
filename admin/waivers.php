<?php
/**
 * Admin: Team → Signed waivers (owner only).
 *
 * Every signed guest check-in waiver and visitor waiver, by month, property and
 * kind. Each row opens its record (/record.php) and the scan it was signed with.
 * Logic: includes/waiver-archive.php. The list uses the shared data-table
 * toolkit (filters · search · per page · pager, AJAX body swap).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/waiver-archive.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/icons.php';   // admin_icon() — the table body is rendered before _layout.php loads it
require_login();
require_owner();

$ym    = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) && waiver_month_bounds((string)$_GET['month']) ? (string)$_GET['month'] : date('Y-m');
$venue = (int)($_GET['venue'] ?? 0) ?: null;
$kind  = in_array($_GET['kind'] ?? '', ['guests', 'visitors', 'both'], true) ? (string)$_GET['kind'] : 'both';
$pg    = paginate_params(25);
$q     = $pg['q'];
$rows  = waiver_archive_rows($ym, $venue, $kind, $q);

// The month as a ZIP — must stream before the layout sends any output.
if (($_GET['download'] ?? '') === '1') { waiver_archive_download($rows, $ym, $venue, $kind); exit; }

$meta = paginate_meta(count($rows), $pg['page'], $pg['per']);
$page = array_slice($rows, $meta['offset'], $meta['per']);

ob_start(); ?>
<div class="card"><div class="card__body" style="padding:0">
  <?php if (!$rows): dt_empty($q !== '' ? 'No signed waivers match your search.' : 'No signed waivers for this month.', 'inbox'); else: ?>
  <div class="table-wrap"><table class="data-table" id="waiversTbl">
    <thead><tr><th>Signed</th><th>Name</th><th>Property</th><th>Booking / place</th><th>Document</th><th>Reference</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($page as $r):
        $view = $r['kind'] === 'guest' ? '/record.php?hold=' . $r['hold_id'] . '&guest=' . $r['guest_id'] : '/record.php?vid=' . $r['visitor_id'];
        $scan = $r['kind'] === 'guest' ? '/admin/checkin-file.php?hold=' . $r['hold_id'] . '&guest=' . $r['guest_id'] . '&signed=1' : '/admin/visitor-file.php?id=' . $r['visitor_id'] . '&signed=1';
        $ts   = strtotime($r['signed_at']); ?>
      <tr>
        <td style="white-space:nowrap"><?= e(date('j M Y', $ts)) ?><div class="text-muted" style="font-size:12px"><?= e(date('H:i', $ts)) ?></div></td>
        <td><strong><?= e($r['name']) ?></strong>
          <div><span class="badge <?= $r['kind'] === 'guest' ? 'badge--blue' : 'badge--grey' ?>"><?= $r['kind'] === 'guest' ? 'Guest' : 'Visitor' ?></span></div></td>
        <td><?= e($r['venue_name'] !== '' ? $r['venue_name'] : '—') ?></td>
        <td><?= e($r['where']) ?></td>
        <td><?= e($r['doc_label']) ?><div class="text-muted" style="font-size:12px"><?= e($r['doc_number'] !== '' ? $r['doc_number'] : '—') ?></div></td>
        <td style="white-space:nowrap"><code style="font-size:12px"><?= e($r['reference']) ?></code></td>
        <td style="white-space:nowrap;text-align:right">
          <span class="dt-actions">
            <a class="btn-outline btn-sm" href="<?= e($view) ?>" target="_blank" rel="noopener" data-no-shell title="Open the signed record"><?= admin_icon('eye', 14) ?> View</a>
            <?php if ($r['scan_key'] !== ''): ?><a class="btn-outline btn-sm" href="<?= e($scan) ?>" target="_blank" rel="noopener" data-no-shell title="The ID scan it was signed with"><?= admin_icon('image', 14) ?> Scan</a><?php endif; ?>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; dt_pager($meta); ?>
</div></div>
<?php $dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }

$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
$months = [];
for ($i = 0; $i <= 36; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
if (!in_array($ym, $months, true)) { $months[] = $ym; rsort($months); }

$pageTitle  = 'Signed waivers';
$activeMenu = 'waivers';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Signed waivers</h1>
  <span style="display:inline-flex;align-items:center;gap:12px">
    <span class="text-muted" style="font-size:13px"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
    <?php if (class_exists('ZipArchive')): ?>
      <a class="btn-primary btn-sm" data-no-shell data-fetch-download data-download-current data-download-busy="Preparing ZIP…"
         href="/admin/waivers.php?<?= e(http_build_query(['month' => $ym, 'venue' => (int)$venue, 'kind' => $kind, 'q' => $q, 'download' => 1])) ?>"><?= admin_icon('download', 14) ?> Download month (ZIP)</a>
    <?php else: ?>
      <span class="text-muted" style="font-size:13px">ZIP download needs the zip extension on the server.</span>
    <?php endif; ?>
  </span>
</div>

<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/waivers.php" class="filters" data-help="waivers-filters">
      <input type="hidden" name="q"   value="<?= e($q) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field"><span>Month</span>
        <select name="month" class="filter-select" aria-label="Month" onchange="this.form.submit()">
          <?php foreach ($months as $mv): ?><option value="<?= e($mv) ?>"<?= $ym === $mv ? ' selected' : '' ?>><?= e(date('M Y', strtotime($mv . '-01'))) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field"><span>Property</span>
        <select name="venue" class="filter-select" aria-label="Property" onchange="this.form.submit()">
          <option value="0">All properties</option>
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $venue === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field"><span>Kind</span>
        <select name="kind" class="filter-select" aria-label="Kind" onchange="this.form.submit()">
          <?php foreach (['both' => 'Guests and visitors', 'guests' => 'Guests', 'visitors' => 'Visitors'] as $k => $l): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
      </div>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Name, reference or number']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
