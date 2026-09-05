<?php
declare(strict_types=1);
/**
 * Financial reports — revenue, bookings, nights, occupancy, ADR across the
 * unified bookings ledger (includes/bookings.php). Owner + house manager, scoped
 * by admin_venue_ids() (owner = all venues; manager = their own). Money is never
 * summed across currencies — figures are grouped and rendered per currency.
 *
 * Reads only. A ?export=csv download streams the same rows the page aggregates.
 * Everything is pre-migration-safe: before add_bookings_finance is applied the
 * page renders an empty state instead of a fatal.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/bookings.php';
require_login();
require_manager();

$scope = admin_venue_ids();     // null = owner (all); [] = none; [ids] = manager's

// Venues in scope, for the property filter select.
$venues = $scope === null
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC")->fetchAll()
    : ($scope
        ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ")
                    ORDER BY sort_order ASC, name ASC")->fetchAll()
        : []);
$allowedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

// ── Filters ──────────────────────────────────────────────────────────
// Date range presets (Nairobi-local — date() is set to Africa/Nairobi in db.php).
$range = (string)($_GET['range'] ?? 'last_12m');
$today = date('Y-m-d');
[$from, $toIncl, $rangeLabel] = (function (string $range, string $today): array {
    $y = (int)date('Y'); $m = (int)date('n');
    switch ($range) {
        case 'this_month':
            return [date('Y-m-01'), $today, 'This month'];
        case 'last_month':
            $f = date('Y-m-01', strtotime('first day of last month'));
            $t = date('Y-m-t', strtotime('last day of last month'));
            return [$f, $t, 'Last month'];
        case 'this_year':
            return [sprintf('%04d-01-01', $y), $today, 'This year'];
        case 'last_year':
            return [sprintf('%04d-01-01', $y - 1), sprintf('%04d-12-31', $y - 1), 'Last year'];
        case 'all':
            return ['2000-01-01', $today, 'All time'];
        case 'last_12m':
        default:
            $f = date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01'))));
            return [$f, $today, 'Last 12 months'];
    }
})($range, $today);

// Property filter — validated against the account's own list.
$fVenue = (int)($_GET['venue'] ?? 0);
if ($fVenue && !in_array($fVenue, $allowedVenueIds, true)) $fVenue = 0;
$reportVenueIds = $fVenue ? [$fVenue] : $scope;   // null stays "all" for owner

// Source filter.
$fSource = (string)($_GET['source'] ?? '');
if (!in_array($fSource, ['', 'website', 'ota', 'agent', 'direct'], true)) $fSource = '';

// ── Data ─────────────────────────────────────────────────────────────
$rows      = bookings_in_window($reportVenueIds, $from, $toIncl, $fSource);
$summary   = bookings_summarize($rows);
$activeU   = bookings_active_unit_count($reportVenueIds);
$occ       = bookings_occupancy($rows, $from, $toIncl, $activeU);

// ── CSV export (same window/scope) ───────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $fname = 'bookings_' . $from . '_to_' . $toIncl . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Property', 'Source', 'Guest', 'Agent', 'Check-in', 'Check-out',
                   'Nights', 'Currency', 'Gross', 'Status', 'Reference']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['venue_name'] ?? '', $r['source'] ?? '', $r['guest_name'] ?? '', $r['agent'] ?? '',
            $r['check_in'] ?? '', $r['check_out'] ?? '', (int)($r['nights'] ?? 0),
            $r['currency'] ?? '', number_format((float)($r['gross_amount'] ?? 0), 2, '.', ''),
            $r['status'] ?? '', $r['external_ref'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Reports';
$activeMenu = 'reports';
include __DIR__ . '/_layout.php';

// Preserve current filters on links (e.g. the CSV button).
$qs = fn(array $extra = []) => http_build_query(array_merge(
    ['range' => $range] + ($fVenue ? ['venue' => $fVenue] : []) + ($fSource ? ['source' => $fSource] : []),
    $extra
));

$monthLabel = fn(string $ym): string => $ym === '' ? '—' : date('M Y', strtotime($ym . '-01'));
?>

<style>
.rp-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 8px}
.rp-kpi{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:14px 16px}
.rp-kpi .n{font-size:24px;font-weight:800;color:#102F3A;line-height:1.1}
.rp-kpi .l{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:5px}
.rp-kpi--rev .n{color:#1E5C6B}
.rp-curblock{margin-bottom:22px}
.rp-curblock__cur{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
.rp-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.rp-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 18px}
@media(max-width:900px){.rp-kpis{grid-template-columns:1fr 1fr}.rp-grid{grid-template-columns:1fr}}
</style>

<div class="page-header">
  <h1 style="display:inline-flex;align-items:center;gap:10px"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Reports</h1>
  <?php if (bookings_supported() && $rows): ?>
  <a href="/admin/reports.php?<?= e($qs(['export' => 'csv'])) ?>" class="btn-sm btn-outline" data-tip="Download these bookings as CSV"><?= admin_icon('download', 15) ?> Export CSV</a>
  <?php endif; ?>
</div>

<?php if (!bookings_supported()): ?>
  <div class="alert alert--error">The bookings ledger isn&rsquo;t set up on this database yet — run the <code>add_bookings_finance</code> migration.</div>
  <?php include __DIR__ . '/_layout_end.php'; return; ?>
<?php endif; ?>

<!-- ── Filters ── -->
<form method="GET" class="rp-filters">
  <label class="text-muted" style="font-size:12.5px">Range</label>
  <select name="range" class="eselect" onchange="this.form.submit()">
    <?php foreach ([
      'this_month'=>'This month','last_month'=>'Last month',
      'this_year'=>'This year','last_12m'=>'Last 12 months','last_year'=>'Last year','all'=>'All time',
    ] as $rk => $rl): ?>
    <option value="<?= $rk ?>"<?= $range === $rk ? ' selected' : '' ?>><?= e($rl) ?></option>
    <?php endforeach; ?>
  </select>

  <?php if (count($venues) > 1): ?>
  <label class="text-muted" style="font-size:12.5px">Property</label>
  <select name="venue" class="eselect" onchange="this.form.submit()">
    <option value="0">All properties</option>
    <?php foreach ($venues as $v): ?>
    <option value="<?= (int)$v['id'] ?>"<?= $fVenue === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <label class="text-muted" style="font-size:12.5px">Source</label>
  <select name="source" class="eselect" onchange="this.form.submit()">
    <?php foreach (['' => 'All sources','website'=>'Website','ota'=>'OTA / channel','agent'=>'Travel agent','direct'=>'Direct'] as $sk => $sl): ?>
    <option value="<?= $sk ?>"<?= $fSource === $sk ? ' selected' : '' ?>><?= e($sl) ?></option>
    <?php endforeach; ?>
  </select>

  <span class="text-muted" style="font-size:12px;margin-left:auto"><?= e($rangeLabel) ?>: <?= e(date('j M Y', strtotime($from))) ?> – <?= e(date('j M Y', strtotime($toIncl))) ?></span>
</form>

<?php if (!$rows): ?>
  <div class="card"><div class="card__body" style="padding:32px;text-align:center;color:var(--muted)">
    No confirmed bookings in this range. Website bookings appear here once confirmed; imported bookings once you run an import with amounts.
  </div></div>
  <?php include __DIR__ . '/_layout_end.php'; return; ?>
<?php endif; ?>

<!-- ── Occupancy (currency-independent) ── -->
<div class="rp-kpis">
  <div class="rp-kpi"><div class="n"><?= e(number_format($occ['pct'], 1)) ?>%</div><div class="l">Occupancy</div></div>
  <div class="rp-kpi"><div class="n"><?= (int)$occ['sold'] ?></div><div class="l">Room-nights sold</div></div>
  <div class="rp-kpi"><div class="n"><?= (int)$occ['available'] ?></div><div class="l">Room-nights available</div></div>
  <div class="rp-kpi"><div class="n"><?= count($rows) ?></div><div class="l">Bookings</div></div>
</div>
<p class="text-muted" style="font-size:11.5px;margin:0 0 22px">Occupancy counts individual-room nights within the range (whole-property bookings excluded from both sides). Revenue is attributed to each booking&rsquo;s arrival date.</p>

<!-- ── Money, per currency ── -->
<?php foreach ($summary['currencies'] as $cur => $t):
  $revpar = $occ['available'] > 0 ? $t['revenue'] / $occ['available'] : 0.0; ?>
<div class="rp-curblock">
  <p class="rp-curblock__cur"><?= e($cur) ?></p>
  <div class="rp-kpis">
    <div class="rp-kpi rp-kpi--rev"><div class="n"><?= e(bookings_money($t['revenue'], $cur)) ?></div><div class="l">Revenue</div></div>
    <div class="rp-kpi"><div class="n"><?= e(bookings_money($t['adr'], $cur)) ?></div><div class="l">ADR (per night)</div></div>
    <div class="rp-kpi"><div class="n"><?= e(bookings_money($revpar, $cur)) ?></div><div class="l">RevPAR</div></div>
    <div class="rp-kpi"><div class="n"><?= (int)$t['nights'] ?></div><div class="l">Nights (<?= (int)$t['bookings'] ?> bookings)</div></div>
  </div>
</div>
<?php endforeach; ?>

<div class="rp-grid">
  <!-- By property -->
  <div class="card">
    <div class="card__head"><span class="card__title">By property</span></div>
    <div class="card__body" style="padding:0"><div class="table-wrap">
      <table class="data-table"><thead><tr><th>Property</th><th>Currency</th><th style="text-align:right">Revenue</th><th style="text-align:right">Nights</th><th style="text-align:right">Bookings</th></tr></thead>
      <tbody>
        <?php foreach ($summary['by_property'] as $name => $byCur): foreach ($byCur as $cur => $t): ?>
        <tr><td><strong><?= e($name) ?></strong></td><td><?= e($cur) ?></td>
            <td style="text-align:right"><?= e(bookings_money($t['revenue'], $cur)) ?></td>
            <td style="text-align:right"><?= (int)$t['nights'] ?></td>
            <td style="text-align:right"><?= (int)$t['bookings'] ?></td></tr>
        <?php endforeach; endforeach; ?>
      </tbody></table>
    </div></div>
  </div>

  <!-- By source -->
  <div class="card">
    <div class="card__head"><span class="card__title">By source</span></div>
    <div class="card__body" style="padding:0"><div class="table-wrap">
      <table class="data-table"><thead><tr><th>Source</th><th>Currency</th><th style="text-align:right">Revenue</th><th style="text-align:right">Nights</th><th style="text-align:right">Bookings</th></tr></thead>
      <tbody>
        <?php foreach ($summary['by_source'] as $src => $byCur): foreach ($byCur as $cur => $t): ?>
        <tr><td><strong><?= e(bookings_source_label($src)) ?></strong></td><td><?= e($cur) ?></td>
            <td style="text-align:right"><?= e(bookings_money($t['revenue'], $cur)) ?></td>
            <td style="text-align:right"><?= (int)$t['nights'] ?></td>
            <td style="text-align:right"><?= (int)$t['bookings'] ?></td></tr>
        <?php endforeach; endforeach; ?>
      </tbody></table>
    </div></div>
  </div>
</div>

<!-- By month -->
<div class="card" style="margin-top:16px">
  <div class="card__head"><span class="card__title">By month <span class="text-muted">(by arrival)</span></span></div>
  <div class="card__body" style="padding:0"><div class="table-wrap">
    <table class="data-table"><thead><tr><th>Month</th><th>Currency</th><th style="text-align:right">Revenue</th><th style="text-align:right">Nights</th><th style="text-align:right">Bookings</th></tr></thead>
    <tbody>
      <?php foreach ($summary['by_month'] as $ym => $byCur): foreach ($byCur as $cur => $t): ?>
      <tr><td><strong><?= e($monthLabel($ym)) ?></strong></td><td><?= e($cur) ?></td>
          <td style="text-align:right"><?= e(bookings_money($t['revenue'], $cur)) ?></td>
          <td style="text-align:right"><?= (int)$t['nights'] ?></td>
          <td style="text-align:right"><?= (int)$t['bookings'] ?></td></tr>
      <?php endforeach; endforeach; ?>
    </tbody></table>
  </div></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
