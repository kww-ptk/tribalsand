<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rates-compare.php';
require_once __DIR__ . '/../includes/calendar-highlights.php'; // cal_day_map() — public holidays + Calendar highlights, same as the Gantt

require_rates();   // owner, manager, reception — prices are not for ops / till / gate / store staff

// Read-only for everyone but the owner — reception may look but not touch.
// Scoped so a reception account only sees rates for its own properties.
// The OWNER also gets the global rate editor (includes/rate-editor-view.php):
// a "Set rates" button, clickable Rate-card cells, a Timeline selection and the
// change log. None of that markup is rendered for anyone else, and
// api/rate-editor.php re-checks the owner on every call.
//
// Views (each a real URL — bookmarkable, works with JS off):
//   card      every room of every chosen property × its seasons for a year
//   timeline  rooms × days for a month, coloured by season
//   calendar  one property's 3-month calendars (the original page)
// Every figure comes from rates_nightly_maps() — the resolver quotes sum — so
// nothing here can disagree with what a guest is charged. Amounts render in the
// room's own currency and admin-money.js converts them (KES | USD).
$scope = admin_venue_ids();                 // null = owner (every venue)
$reOwner = is_owner();
if ($reOwner) require_once __DIR__ . '/../includes/rate-editor.php';
$venues = $scope === null
    ? db_query('SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC')->fetchAll()
    : ($scope
        ? db_query('SELECT id, name FROM venues WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')
                    ORDER BY sort_order ASC, name ASC')->fetchAll()
        : []);
$venueIds  = array_map(fn($v) => (int)$v['id'], $venues);
$venueName = array_column($venues, 'name', 'id');

// Old bookmarks (?venue=N with no ?view) were the calendar page before the views existed.
$view = in_array($_GET['view'] ?? '', ['card', 'timeline', 'calendar'], true)
    ? (string)$_GET['view']
    : (!isset($_GET['view']) && isset($_GET['venue']) ? 'calendar' : 'card');
$cur  = in_array($_GET['cur'] ?? '', ['KES', 'USD'], true) ? (string)$_GET['cur'] : 'KES';
$fx   = fx_rates()['rates'];

// ?venues=1,4 narrows the comparison. Ids outside the account's own list are
// dropped, never honoured.
$picked = array_values(array_intersect(
    array_map('intval', array_filter(explode(',', (string)($_GET['venues'] ?? '')), 'strlen')),
    $venueIds
));
$allPicked = !$picked || count($picked) === count($venueIds);
if (!$picked) $picked = $venueIds;

$year = (int)($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month) || rates_window_ymd($month . '-01') === null) $month = date('Y-m');

// Calendar view: one property, validated against the account's list.
$venueId = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
if ($venueId && !in_array($venueId, $venueIds, true)) $venueId = 0;
if (!$venueId && $venueIds) $venueId = $venueIds[0];

$qs = [
    'view'   => $view,
    'venues' => $allPicked ? null : implode(',', $picked),
    'year'   => $view === 'card' ? $year : null,
    'month'  => $view === 'timeline' ? $month : null,
    'venue'  => $view === 'calendar' ? $venueId : null,
    'cur'    => $cur,
];
$url = function (array $over) use ($qs): string {
    $q = array_filter(array_merge($qs, $over), fn($v) => $v !== null && $v !== '');
    return '/admin/rates.php' . ($q ? '?' . http_build_query($q) : '');
};
$calUrl = fn(int $vid): string => $url(['view' => 'calendar', 'venue' => $vid, 'year' => null, 'month' => null, 'venues' => null]);

// Rooms of the chosen properties, in property then room order.
$rooms = ($view !== 'calendar' && $picked)
    ? db_query('SELECT r.id, r.venue_id, r.name, r.price_amount, r.price_currency, r.is_published
                  FROM rooms r JOIN venues v ON v.id = r.venue_id
                 WHERE r.venue_id IN (' . implode(',', $picked) . ')
                 ORDER BY v.sort_order ASC, v.name ASC, r.sort_order ASC, r.id ASC')->fetchAll()
    : [];
$byVenue = [];
foreach ($rooms as $r) $byVenue[(int)$r['venue_id']][] = $r;
$defaults = [];
foreach ($rooms as $r) $defaults[(int)$r['id']] = (float)$r['price_amount'];

if ($view === 'card') {
    $maps = $defaults ? rates_nightly_maps($defaults, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)) : [];
    $cardRows = [];
    $labelSet = [];
    foreach ($rooms as $r) {
        $row = rc_rate_card_row($maps[(int)$r['id']] ?? [], (float)$r['price_amount']);
        $cardRows[(int)$r['id']] = $row;
        foreach (array_keys($row['seasons']) as $l) $labelSet[$l] = true;
    }
    $labels = rc_sort_labels(array_keys($labelSet));

    // Owner: what a click on a cell pre-fills — per room and season key, the
    // most common price (re_most_common_price(), the editor's own tie rule),
    // whether the price is uniform, and the room's own nights of that season
    // this year as [first, last] runs (rc_season_runs()). Base = the room's
    // nights with no seasonal rate, fed through the same run grouping.
    // Every pre-filled run is clipped to today (Nairobi) — never rewrite past nights;
    // a cell whose nights are all past pre-fills nothing and stays non-clickable.
    $reCells = [];
    $reToday = date('Y-m-d');
    if ($reOwner) {
        foreach ($rooms as $r) {
            $rid = (int)$r['id'];
            $m = $maps[$rid] ?? [];
            $prices = [];
            foreach ($m as $night) {
                $k = rc_night_key($night);
                if ($k !== null) $prices[$k][] = (float)$night['price'];
            }
            $cell = ['seasons' => [], 'base' => []];
            foreach (rc_season_runs([$m]) as $k => $runs) {
                $s = $cardRows[$rid]['seasons'][$k];
                $cell['seasons'][$k] = [
                    'price'   => re_most_common_price($prices[$k] ?? []),
                    'uniform' => $s['min'] === $s['max'],
                    'ranges'  => re_clip_runs_from($runs, $reToday),
                    'clipped' => $runs !== re_clip_runs_from($runs, $reToday),
                ];
            }
            $baseOnly = array_map(fn($n) => rc_night_key($n) === null
                ? ['is_override' => true, 'label' => 'Base'] : ['is_override' => false], $m);
            $baseRuns = rc_season_runs([$baseOnly])['Base'] ?? [];
            $cell['base'] = re_clip_runs_from($baseRuns, $reToday);
            $cell['base_clipped'] = $baseRuns !== $cell['base'];
            $reCells[$rid] = $cell;
        }
    }
} elseif ($view === 'timeline') {
    $tFrom = $month . '-01';
    $tTo   = (new DateTime($tFrom))->modify('+1 month')->format('Y-m-d');
    $maps  = $defaults ? rates_nightly_maps($defaults, $tFrom, $tTo) : [];
    $days  = [];
    for ($d = new DateTime($tFrom); $d->format('Y-m-d') < $tTo; $d->modify('+1 day')) $days[] = $d->format('Y-m-d');
    $prevMonth = (new DateTime($tFrom))->modify('-1 month')->format('Y-m');
    $nextMonth = (new DateTime($tFrom))->modify('+1 month')->format('Y-m');
    // The Gantt's day colouring for the same window, in ONE query: weekends,
    // Kenyan public holidays and Admin → Calendar highlights.
    $calMap   = $days ? cal_day_map($days[0], $days[count($days) - 1]) : [];
    $tToday   = date('Y-m-d');
    $dayClass = [];
    foreach ($days as $d) {
        $dow = (int)date('N', strtotime($d));
        $hl  = cal_day_info($calMap[$d] ?? []);
        $dayClass[$d] = [
            'cls'   => ($d === $tToday ? ' is-today' : '') . ($dow >= 6 ? ' is-weekend' : '') . ($dow === 7 ? ' is-sun' : '')
                     . ($hl['class'] !== '' ? ' ' . $hl['class'] : ''),
            'title' => date('D j M', strtotime($d)) . ($hl['title'] !== '' ? ' · ' . $hl['title'] : ''),
        ];
    }
    $venueLoc = $picked
        ? array_column(db_query('SELECT id, location FROM venues WHERE id IN (' . implode(',', $picked) . ')')->fetchAll(), 'location', 'id')
        : [];
}

$pageTitle  = 'Rates';
$activeMenu = 'rates';
include __DIR__ . '/_layout.php';
?>
<style>
.rc-bar{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;margin:0 0 14px}
.rc-seg{display:inline-flex;border:1.5px solid var(--border);border-radius:10px;overflow:hidden;background:var(--white)}
.rc-seg a{padding:7px 14px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none}
.rc-seg a.is-on{background:var(--brand);color:#fff}
.rc-chips{display:flex;flex-wrap:wrap;gap:6px}
.rc-chip{padding:5px 12px;font-size:12.5px;text-decoration:none}
.rc-chip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
.rc-period{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13.5px}
.rc-spacer{flex:1}
.rc-wrap{overflow-x:auto;background:var(--white);border:1px solid var(--border);border-radius:12px}
.rc-table{width:100%;border-collapse:collapse;font-size:13px}
.rc-table th,.rc-table td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap}
.rc-table th:first-child,.rc-table td:first-child{text-align:left}
.rc-table thead th{font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#faf8f5}
.rc-group td{background:#f5f1ea;font-weight:700;text-align:left!important}
.rc-table:not(.rc-tl) .rc-group td{white-space:normal}
.rc-dates{font-weight:400;color:var(--muted);font-size:12px;margin-left:10px}
.rc-room a{color:inherit;text-decoration:none}.rc-room a:hover{text-decoration:underline}
.rc-hidden{font-size:11px;color:var(--muted);margin-left:6px}
.rc-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:none;letter-spacing:0}
.rc-std{background:#eaf3de;color:#27500a}.rc-mid{background:#faeeda;color:#633806}.rc-peak{background:#fcebeb;color:#791f1f}
.rc-other{background:#e6eef5;color:#1f4460}.rc-base{background:transparent;color:inherit}
/* Timeline — drawn like the Availability Calendar (admin/gantt.php): dark month
   band, weekday letter over the date, today in blue, weekends grey, public
   holidays red, Calendar highlights in their colour, collapsible property rows. */
.tl-outer{overflow:auto;max-height:calc(100vh - 140px);min-height:320px;border-radius:var(--radius)}
.rc-tl{width:auto;min-width:100%;border-collapse:separate;border-spacing:0}
.rc-tl td,.rc-tl th{padding:0 3px;height:36px;min-width:44px;font-size:11px;text-align:center;border-bottom:1px solid var(--border);border-right:1px solid #eef0f2}
.rc-tl thead th{position:sticky;z-index:3;text-transform:none;letter-spacing:0;padding:0}
.rc-tl .tl-mrow th{top:0;height:20px;background:var(--sidebar-bg);color:#fff;font-size:10px;font-weight:700;border-right:1px solid rgba(255,255,255,.15);border-bottom:1px solid var(--border)}
.rc-tl .tl-mrow .tl-month{text-align:left}
.rc-tl .tl-month span{position:sticky;left:202px;padding:0 10px}
.rc-tl .tl-corner{left:0;z-index:5!important;vertical-align:middle;text-align:left!important;padding:0 10px!important;font-size:10px;text-transform:uppercase;letter-spacing:.04em}
.rc-tl .tl-dh{top:20px;height:38px;background:#f9fafb;color:#334155;font-size:11px;font-weight:600;line-height:1;border-bottom:2px solid var(--border)}
.rc-tl .gd-dow{display:block;font-size:9px;font-weight:600;color:#94a3b8;margin-bottom:2px}
.rc-tl .tl-dh.is-weekend{background:#dde3ea;color:#0f172a;font-weight:700}
.rc-tl .tl-dh.is-weekend .gd-dow{color:#475569;font-weight:700}
.rc-tl .tl-dh.is-holiday{background:#fbd5d5;color:#9f1239;font-weight:800}
.rc-tl .tl-dh.is-holiday .gd-dow{color:#be123c;font-weight:700}
.rc-tl .tl-dh.is-hl--amber{background:#fde7b0;color:#7a4b00;font-weight:800}
.rc-tl .tl-dh.is-hl--red{background:#fbd5d5;color:#9f1239;font-weight:800}
.rc-tl .tl-dh.is-hl--green{background:#cdeccf;color:#1b5e20;font-weight:800}
.rc-tl .tl-dh.is-hl--blue{background:#d3e4fb;color:#1e3a8a;font-weight:800}
.rc-tl .tl-dh.is-hl--purple{background:#e6d9f7;color:#5b21b6;font-weight:800}
.rc-tl .tl-dh.is-hl .gd-dow{color:inherit;opacity:.75}
.rc-tl .tl-dh.is-today{background:#1d4ed8;color:#fff;font-weight:800}
.rc-tl .tl-dh.is-today .gd-dow{color:#dbe7ff}
.rc-tl .is-sun{border-right:1px solid #cbd5e1}
/* Day tint on base-price nights (seasonal nights keep their season colour). */
.rc-tl td.rc-base.is-weekend{background:#eef1f5}
.rc-tl td.rc-base.is-holiday,.rc-tl td.rc-base.is-hl--red{background:#fdeeee}
.rc-tl td.rc-base.is-hl--amber{background:#fff6e0}
.rc-tl td.rc-base.is-hl--green{background:#edf8ee}
.rc-tl td.rc-base.is-hl--blue{background:#eef4fd}
.rc-tl td.rc-base.is-hl--purple{background:#f5effc}
.rc-tl td.rc-base{color:#64748b}
.rc-tl td.is-today{box-shadow:inset 2px 0 0 #1d4ed8,inset -2px 0 0 #1d4ed8}
.rc-tl tbody td:first-child,.rc-tl thead .tl-corner{position:sticky;left:0;z-index:2;text-align:left;min-width:190px;max-width:240px;padding:4px 10px;border-right:2px solid var(--border);white-space:normal;line-height:1.25}
.rc-tl tbody td:first-child{background:#fff;font-weight:500;font-size:11.5px}
.rc-tl .rc-room small{display:block;font-size:10px;color:var(--muted);font-weight:400}
.rc-tl .tl-grow td{background:#eaf0f3;height:30px;border-top:2px solid var(--border);cursor:pointer;user-select:none}
.rc-tl .tl-grow td:first-child{background:#eaf0f3;font-weight:700;font-size:12px;color:#102F3A}
.rc-tl .tl-grow:hover td{background:#dfe8ed}
.rc-tl .tl-grow:focus-visible{outline:2px solid #0369a1;outline-offset:-2px}
.rc-tl .tl-grow__bar{text-align:left!important;font-size:10.5px;color:var(--muted);padding-left:10px!important}
.rc-tl .tl-grow__loc{color:#102F3A;font-weight:600}
.rc-tl .tl-grow__caret{display:inline-flex;vertical-align:-2px;margin-right:5px;color:#4a6b78;transition:transform .15s ease}
.rc-tl .tl-grow__hint{display:none;font-style:italic}
.rc-tl tbody.is-collapsed .tl-row{display:none}
.rc-tl tbody.is-collapsed .tl-grow__caret{transform:rotate(-90deg)}
.rc-tl tbody.is-collapsed .tl-grow__hint{display:inline}
.rc-period__label{font-size:15px;font-weight:700;margin-left:6px}
.tl-legend{align-items:center;gap:6px 16px;margin:0 0 12px}
.tl-legend span{display:inline-flex;align-items:center;gap:6px}
.tl-legend .gl{width:13px;height:13px;border-radius:3px;flex:none;border:1px solid rgba(0,0,0,.08)}
.tl-legend .gl--today{background:#eff5ff;box-shadow:inset 2px 0 0 #1d4ed8,inset -2px 0 0 #1d4ed8;border-color:#1d4ed8}
.tl-legend .gl--weekend{background:#dde3ea;border-color:#c5ced9}
.tl-legend .gl--holiday{background:#fbd5d5;border-color:#f1a9b1}
.tl-legend .gl--hl-amber{background:#fde7b0;border-color:#f3c96a}.tl-legend .gl--hl-red{background:#fbd5d5;border-color:#f1a9b1}
.tl-legend .gl--hl-green{background:#cdeccf;border-color:#94cf98}.tl-legend .gl--hl-blue{background:#d3e4fb;border-color:#9dbff0}
.tl-legend .gl--hl-purple{background:#e6d9f7;border-color:#c4a8ea}
.tl-legend .gl--base{background:#fff}
.tl-legend .gl-sep{width:1px;height:14px;background:var(--border);border:0}
.tl-legend .gl-manage{color:var(--brand);font-weight:600;text-decoration:none}
.tl-legend .gl-manage:hover{text-decoration:underline}
.rc-legend{display:flex;gap:10px;flex-wrap:wrap;margin:10px 2px 0;font-size:12px;color:var(--muted)}
.rc-foot{margin:8px 2px 0;font-size:12px;color:var(--muted)}
@media (max-width:640px){.rc-spacer{display:none}.rc-bar{gap:8px}}
</style>

<div class="page-header">
  <h1>Rates</h1>
  <?php if ($reOwner): ?><div class="re-headbtns">
    <button type="button" class="btn-primary btn-sm" data-re-open><?= admin_icon('edit', 14) ?> Set rates</button>
  <?php endif; ?>
    <a class="btn-outline btn-sm" href="/admin/quote-builder.php" data-keep-cur>Build a quote <?= admin_icon('chevron-right', 14) ?></a>
  <?php if ($reOwner): ?></div><?php endif; ?>
</div>

<?php if (!$venues): ?>
<div class="alert alert--info">No properties are assigned to your account.</div>
<?php else: ?>

<?php if ($reOwner) include __DIR__ . '/../includes/buyout-check-view.php'; ?>

<div class="rc-bar">
  <nav class="rc-seg" aria-label="Rates view">
    <a href="<?= e($url(['view' => 'card', 'month' => null, 'venue' => null, 'year' => $year])) ?>" data-keep-cur class="<?= $view === 'card' ? 'is-on' : '' ?>">Rate card</a>
    <a href="<?= e($url(['view' => 'timeline', 'year' => null, 'venue' => null, 'month' => $month])) ?>" data-keep-cur class="<?= $view === 'timeline' ? 'is-on' : '' ?>">Timeline</a>
    <a href="<?= e($calUrl($venueId)) ?>" data-keep-cur class="<?= $view === 'calendar' ? 'is-on' : '' ?>">Calendar</a>
  </nav>

  <?php if ($view === 'card'): ?>
  <span class="rc-period">
    <a class="btn-icon" href="<?= e($url(['year' => $year - 1])) ?>" data-keep-cur aria-label="Previous year">‹</a>
    <?= $year ?>
    <a class="btn-icon" href="<?= e($url(['year' => $year + 1])) ?>" data-keep-cur aria-label="Next year">›</a>
  </span>
  <?php elseif ($view === 'timeline'): ?>
  <span class="rc-period">
    <a class="btn-outline btn-sm" href="<?= e($url(['month' => $prevMonth])) ?>" data-keep-cur><?= admin_icon('chevron-left', 15) ?> Prev</a>
    <a class="btn-outline btn-sm" href="<?= e($url(['month' => date('Y-m')])) ?>" data-keep-cur>Today</a>
    <a class="btn-outline btn-sm" href="<?= e($url(['month' => $nextMonth])) ?>" data-keep-cur>Next <?= admin_icon('chevron-right', 15) ?></a>
    <span class="rc-period__label"><?= e(date('F Y', strtotime($month . '-01'))) ?></span>
  </span>
  <?php endif; ?>

  <span class="rc-spacer"></span>
  <?php if ($view !== 'calendar'): /* the calendar partial prints own-currency figures, not .mny spans */ ?>
  <?php $ms_cur = $cur; include __DIR__ . '/../includes/money-switch.php'; ?>
  <?php endif; ?>
</div>

<?php if ($view !== 'calendar' && count($venues) > 1): ?>
<div class="rc-chips" style="margin:-4px 0 14px">
  <?php foreach ($venues as $v):
        $vid = (int)$v['id'];
        $on  = in_array($vid, $picked, true);
        $set = $on ? array_values(array_diff($picked, [$vid])) : array_merge($picked, [$vid]);
        if (!$set) $set = [$vid];                       // never an empty selection
        $href = $url(['venues' => count($set) === count($venueIds) ? null : implode(',', $set)]); ?>
  <a class="optchip rc-chip<?= $on ? ' is-on' : '' ?>" href="<?= e($href) ?>" data-keep-cur><?= e($v['name']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($view === 'card'): ?>
  <?php if (!$rooms): ?>
  <div class="alert alert--info">These properties have no rooms yet.</div>
  <?php else: ?>
  <div class="rc-wrap">
    <table class="rc-table<?= $reOwner ? ' re-card' : '' ?>">
      <thead><tr>
        <th>Room</th>
        <?php foreach ($labels as $l): ?><th><span class="rc-pill rc-<?= e(rc_season_class($l)) ?>"><?= e($l) ?></span></th><?php endforeach; ?>
        <th>Base</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byVenue as $vid => $vRooms):
            $vMaps = [];
            foreach ($vRooms as $r) $vMaps[] = $maps[(int)$r['id']] ?? [];
            $runs = rc_season_runs($vMaps); ?>
        <tr class="rc-group"><td colspan="<?= count($labels) + 2 ?>">
          <?= e($venueName[$vid] ?? '') ?>
          <?php foreach ($runs as $l => $rs): ?>
          <span class="rc-dates"><span class="rc-pill rc-<?= e(rc_season_class($l)) ?>"><?= e(preg_replace('/\s*season$/i', '', $l)) ?></span>
            <?= e(implode(' · ', array_map('rc_run_label', $rs))) ?></span>
          <?php endforeach; ?>
        </td></tr>
        <?php foreach ($vRooms as $r):
              $row = $cardRows[(int)$r['id']];
              $c   = (string)($r['price_currency'] ?: 'USD');
              // Owner: the cell carries what the editor pre-fills (room, season, price,
              // nights). An empty cell pre-fills the PROPERTY's nights of that season.
              $reAttr = function (?array $pre, string $key, bool $base = false) use ($reOwner, $r, $vid, $c, $year, $runs, $reToday): string {
                  if (!$reOwner) return '';
                  $full   = $pre['ranges'] ?? ($base ? [] : ($runs[$key] ?? []));
                  $ranges = re_clip_runs_from($full, $reToday);   // idempotent for already-clipped runs
                  if (!$ranges) return '';
                  $clipped = !empty($pre['clipped']) || $ranges !== $full;
                  $price = $pre['price'] ?? null;
                  return ' data-re-cell tabindex="0" role="button" title="Set rates…"'
                       . ' data-room="' . (int)$r['id'] . '" data-venue="' . (int)$vid . '" data-name="' . e($r['name']) . '"'
                       . ' data-cur="' . e(strtoupper($c)) . '" data-year="' . (int)$year . '" data-key="' . e($key) . '"'
                       . ($base ? ' data-base="1"' : '') . ($key === 'Other rate' ? ' data-other="1"' : '')
                       . ' data-price="' . ($price !== null && $price > 0 ? e(rc_trimz(sprintf('%.2F', $price))) : '') . '"'
                       . ' data-uniform="' . (!empty($pre['uniform']) ? '1' : '0') . '"'
                       . ($clipped ? ' data-clipped="1"' : '')
                       . ' data-ranges="' . e(json_encode($ranges)) . '"';
              }; ?>
        <tr>
          <td class="rc-room"><a href="<?= e($calUrl((int)$vid)) ?>" data-keep-cur><?= e($r['name']) ?></a><?php if (empty($r['is_published']) || $r['is_published'] === 'f'): ?><span class="rc-hidden">hidden</span><?php endif; ?></td>
          <?php foreach ($labels as $l): $s = $row['seasons'][$l] ?? null; ?>
          <td<?= $reAttr($reCells[(int)$r['id']]['seasons'][$l] ?? null, $l) ?>><?php if (!$s): ?>—<?php elseif ($s['min'] === $s['max']): ?><?= rc_money_html($s['min'], $c, $cur, $fx) ?><?php else: ?><?= rc_money_html($s['min'], $c, $cur, $fx) ?> – <?= rc_money_html($s['max'], $c, $cur, $fx) ?><?php endif; ?></td>
          <?php endforeach; ?>
          <td<?= $reAttr(['ranges' => $reCells[(int)$r['id']]['base'] ?? [], 'clipped' => !empty($reCells[(int)$r['id']]['base_clipped']), 'price' => (float)$r['price_amount'], 'uniform' => true], 'Base', true) ?>><?= (float)$r['price_amount'] > 0 ? rc_money_html((float)$r['price_amount'], $c, $cur, $fx) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="rc-foot">Seasons are the rate labels set on each room. <strong>Base</strong> is the room's own price, used on any night without a seasonal rate. Dates are last nights.<?php if ($reOwner): ?> Click a price to change it.<?php endif; ?></p>
  <?php endif; ?>

<?php elseif ($view === 'timeline'): ?>
  <?php if (!$rooms): ?>
  <div class="alert alert--info">These properties have no rooms yet.</div>
  <?php else: ?>
  <div class="rc-legend tl-legend" aria-label="Timeline legend">
    <span><i class="gl gl--today"></i> Today</span>
    <span><i class="gl gl--weekend"></i> Weekend</span>
    <span><i class="gl gl--holiday"></i> Public holiday</span>
    <?php foreach (cal_map_custom_legend($calMap) as $lg): ?>
    <span><i class="gl gl--hl-<?= e($lg['color']) ?>"></i> <?= e($lg['label']) ?></span>
    <?php endforeach; ?>
    <?php if (is_owner() || is_manager()): ?>
    <a href="/admin/calendar-highlights.php" class="gl-manage" data-tip="Mark school holidays, events or any other dates — they show here and on the Calendar">+ Highlight dates</a>
    <?php endif; ?>
    <span class="gl-sep" aria-hidden="true"></span>
    <span><i class="gl rc-std"></i> Standard</span>
    <span><i class="gl rc-mid"></i> Mid</span>
    <span><i class="gl rc-peak"></i> Peak / High</span>
    <span><i class="gl rc-other"></i> Other rate</span>
    <span><i class="gl gl--base"></i> Base price</span>
  </div>
  <div class="rc-wrap tl-outer">
    <table class="rc-table rc-tl<?= $reOwner ? ' re-tl' : '' ?>"<?= $reOwner ? ' data-re-tl data-today="' . e($tToday) . '"' : '' ?>>
      <thead>
        <tr class="tl-mrow">
          <th class="tl-corner" rowspan="2">Room</th>
          <th class="tl-month" colspan="<?= count($days) ?>"><span><?= e(date('F Y', strtotime($month . '-01'))) ?></span></th>
        </tr>
        <tr class="tl-drow">
        <?php foreach ($days as $d): ?>
          <th class="tl-dh<?= $dayClass[$d]['cls'] ?>" title="<?= e($dayClass[$d]['title']) ?>"><span class="gd-dow"><?= e(date('D', strtotime($d))[0]) ?></span><?= date('j', strtotime($d)) ?></th>
        <?php endforeach; ?>
        </tr>
      </thead>
      <?php foreach ($byVenue as $vid => $vRooms): $nR = count($vRooms); $loc = trim((string)($venueLoc[$vid] ?? '')); ?>
      <tbody data-tl-venue="<?= (int)$vid ?>">
        <tr class="rc-group tl-grow" tabindex="0" role="button" aria-expanded="true" aria-label="Collapse or expand <?= e($venueName[$vid] ?? '') ?>">
          <td><span class="tl-grow__caret" aria-hidden="true"><?= admin_icon('chevron-down', 13) ?></span><?= e($venueName[$vid] ?? '') ?></td>
          <td colspan="<?= count($days) ?>" class="tl-grow__bar"><?php if ($loc !== ''): ?><span class="tl-grow__loc"><?= e($loc) ?></span> · <?php endif; ?><?= $nR ?> room<?= $nR === 1 ? '' : 's' ?><span class="tl-grow__hint"> · collapsed</span></td>
        </tr>
        <?php foreach ($vRooms as $r): $c = (string)($r['price_currency'] ?: 'USD'); $m = $maps[(int)$r['id']] ?? []; ?>
        <tr class="tl-row"<?php if ($reOwner): ?> data-re-row data-room="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>"<?php endif; ?>>
          <td class="rc-room"><a href="<?= e($calUrl((int)$vid)) ?>" data-keep-cur><?= e($r['name']) ?></a><small><?= e($c) ?> / night</small></td>
          <?php foreach ($days as $d):
                $night = $m[$d] ?? null;
                $key   = $night ? rc_night_key($night) : null;
                $cls   = rc_season_class($key);
                $tip   = $dayClass[$d]['title'] . ($night ? ' · ' . ($key ?? 'Base') . ' · ' . ((float)$night['price'] > 0 ? rc_money_text((float)$night['price'], $c) : 'no price') : ''); ?>
          <td class="rc-<?= e($cls) ?><?= $dayClass[$d]['cls'] ?>" title="<?= e($tip) ?>"<?= $reOwner ? ' data-d="' . e($d) . '"' : '' ?>><?= $night && (float)$night['price'] > 0 ? rc_money_html((float)$night['price'], $c, $cur, $fx, true) : '—' ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="rc-foot">Coloured cells carry a seasonal rate; white cells use the room's base price. Weekends, public holidays and Calendar highlights are marked in the date row, as on the Calendar — hover a day for its name.<?php if ($reOwner): ?> Click a night to set rates · shift-click to extend · ⌘/ctrl-click or drag to add rooms · past days can't be selected · Esc clears.<?php endif; ?></p>
  <script>
  (function () {
    var KEY = 'ts_rates_tl_collapsed';
    function load() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
    function save(a) { try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {} }
    var closed = load();
    document.querySelectorAll('tbody[data-tl-venue]').forEach(function (tb) {
      var id = tb.getAttribute('data-tl-venue'), head = tb.querySelector('.tl-grow');
      function set(c) { tb.classList.toggle('is-collapsed', c); head.setAttribute('aria-expanded', c ? 'false' : 'true'); }
      set(closed.indexOf(id) !== -1);
      function toggle() {
        var c = !tb.classList.contains('is-collapsed'); set(c);
        closed = closed.filter(function (x) { return x !== id; }); if (c) closed.push(id); save(closed);
      }
      head.addEventListener('click', toggle);
      head.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    });
  })();
  </script>
  <?php endif; ?>

<?php else: /* calendar */ ?>
  <div style="display:flex;gap:8px;align-items:center;margin:0 0 14px">
    <form method="GET" style="margin:0">
      <input type="hidden" name="view" value="calendar">
      <select name="venue" class="eselect" onchange="this.form.submit()">
        <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $venueId ? ' selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php
    $calRooms = $venueId
        ? db_query('SELECT id, name, price_amount, price_currency FROM rooms
                     WHERE venue_id = :v ORDER BY sort_order ASC, id ASC', [':v' => $venueId])->fetchAll()
        : [];
    $rateMonth = isset($_GET['rate_month']) && strtotime($_GET['rate_month'] . '-01')
        ? substr((string)$_GET['rate_month'], 0, 7)
        : date('Y-m');
  ?>
  <?php if (!$calRooms): ?>
  <div class="alert alert--info">This property has no rooms yet.</div>
  <?php else: foreach ($calRooms as $r): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card__head">
      <span class="card__title"><?= e($r['name']) ?></span>
      <?php if (is_owner()): ?>
      <a class="btn-sm btn-outline" href="/admin/room-edit.php?id=<?= (int)$r['id'] ?>">Edit rates <?= admin_icon('chevron-right', 14) ?></a>
      <?php endif; ?>
    </div>
    <div class="card__body" style="padding:20px">
      <?php
        $rc_room_id       = (int)$r['id'];
        $rc_default_price = (float)$r['price_amount'];
        $rc_currency      = (string)$r['price_currency'];
        $rc_month         = $rateMonth;
        $rc_base_url      = '/admin/rates.php?view=calendar&venue=' . $venueId;
        include __DIR__ . '/../includes/rate-calendar.php';
      ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
<?php endif; ?>

<?php if ($reOwner) include __DIR__ . '/../includes/rate-editor-view.php'; ?>

<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
