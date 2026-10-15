<?php
/**
 * Admin: Team → Signed waivers (owner only).
 *
 * Every signed guest check-in waiver and visitor waiver, by month, property and
 * kind. Each row opens its record (/record.php) and the scan it was signed with.
 * Logic: includes/waiver-archive.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/waiver-archive.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_owner();

$ym    = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) && waiver_month_bounds((string)$_GET['month']) ? (string)$_GET['month'] : date('Y-m');
$venue = (int)($_GET['venue'] ?? 0) ?: null;
$kind  = in_array($_GET['kind'] ?? '', ['guests', 'visitors', 'both'], true) ? (string)$_GET['kind'] : 'both';
$q     = trim((string)($_GET['q'] ?? ''));
$rows  = waiver_archive_rows($ym, $venue, $kind, $q);

$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
$months = [];
for ($i = -1; $i <= 36; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
if (!in_array($ym, $months, true)) { $months[] = $ym; rsort($months); }

$pageTitle  = 'Signed waivers';
$activeMenu = 'waivers';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Signed waivers</h1></div>
<form method="GET" action="/admin/waivers.php" data-help="waivers-filters" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
  <div class="filter-field"><span>Month</span>
    <select name="month" class="filter-select" onchange="this.form.submit()">
      <?php foreach ($months as $mv): ?><option value="<?= e($mv) ?>"<?= $ym === $mv ? ' selected' : '' ?>><?= e(date('M Y', strtotime($mv . '-01'))) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field"><span>Property</span>
    <select name="venue" class="filter-select" onchange="this.form.submit()">
      <option value="0">All properties</option>
      <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $venue === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field"><span>Kind</span>
    <select name="kind" class="filter-select" onchange="this.form.submit()">
      <?php foreach (['both' => 'Guests and visitors', 'guests' => 'Guests', 'visitors' => 'Visitors'] as $k => $l): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field"><span>Search</span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, reference or number" class="inp">
  </div>
  <button class="btn-outline btn-sm">Show</button>
</form>
<div class="card"><table class="table" id="waiversTbl" data-paginate="50">
  <thead><tr><th>Signed</th><th>Name</th><th>Type</th><th>Property</th><th>Booking / place</th><th>Document</th><th>Reference</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $view = $r['kind'] === 'guest' ? '/record.php?hold=' . $r['hold_id'] . '&guest=' . $r['guest_id'] : '/record.php?vid=' . $r['visitor_id'];
      $scan = $r['kind'] === 'guest' ? '/admin/checkin-file.php?hold=' . $r['hold_id'] . '&guest=' . $r['guest_id'] . '&signed=1' : '/admin/visitor-file.php?id=' . $r['visitor_id'] . '&signed=1'; ?>
    <tr>
      <td><?= e(date('j M Y, H:i', strtotime($r['signed_at']))) ?></td>
      <td><?= e($r['name']) ?></td>
      <td><?= $r['kind'] === 'guest' ? 'Guest' : 'Visitor' ?></td>
      <td><?= e($r['venue_name'] !== '' ? $r['venue_name'] : '—') ?></td>
      <td><?= e($r['where']) ?></td>
      <td><?= e(trim($r['doc_label'] . ' ' . $r['doc_number'])) ?></td>
      <td><code><?= e($r['reference']) ?></code></td>
      <td style="white-space:nowrap;text-align:right">
        <a href="<?= e($view) ?>" target="_blank" rel="noopener" data-no-shell>View</a>
        <?php if ($r['scan_key'] !== ''): ?> · <a href="<?= e($scan) ?>" target="_blank" rel="noopener" data-no-shell>Scan</a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No signed waivers for this month.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php include __DIR__ . '/_layout_end.php'; ?>
