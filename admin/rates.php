<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rates-compare.php';

require_login();

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
    $reCells = [];
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
                    'ranges'  => $runs,
                ];
            }
            $baseOnly = array_map(fn($n) => rc_night_key($n) === null
                ? ['is_override' => true, 'label' => 'Base'] : ['is_override' => false], $m);
            $cell['base'] = rc_season_runs([$baseOnly])['Base'] ?? [];
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
.rc-tl td,.rc-tl th{padding:6px 5px;font-size:11.5px;text-align:center}
.rc-tl th.is-we,.rc-tl td.is-we{box-shadow:inset 0 0 0 999px rgba(0,0,0,.03)}
.rc-tl td:first-child,.rc-tl th:first-child{position:sticky;left:0;background:var(--white);z-index:1;text-align:left;min-width:170px}
.rc-tl .rc-group td:first-child{background:#f5f1ea}
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
    <a class="btn-icon" href="<?= e($url(['month' => $prevMonth])) ?>" data-keep-cur aria-label="Previous month">‹</a>
    <?= e(date('F Y', strtotime($month . '-01'))) ?>
    <a class="btn-icon" href="<?= e($url(['month' => $nextMonth])) ?>" data-keep-cur aria-label="Next month">›</a>
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
              $reAttr = function (?array $pre, string $key, bool $base = false) use ($reOwner, $r, $vid, $c, $year, $runs): string {
                  if (!$reOwner) return '';
                  $ranges = $pre['ranges'] ?? ($base ? [] : ($runs[$key] ?? []));
                  if (!$ranges) return '';
                  $price = $pre['price'] ?? null;
                  return ' data-re-cell tabindex="0" role="button" title="Set rates…"'
                       . ' data-room="' . (int)$r['id'] . '" data-venue="' . (int)$vid . '" data-name="' . e($r['name']) . '"'
                       . ' data-cur="' . e(strtoupper($c)) . '" data-year="' . (int)$year . '" data-key="' . e($key) . '"'
                       . ($base ? ' data-base="1"' : '') . ($key === 'Other rate' ? ' data-other="1"' : '')
                       . ' data-price="' . ($price !== null && $price > 0 ? e(rc_trimz(sprintf('%.2F', $price))) : '') . '"'
                       . ' data-uniform="' . (!empty($pre['uniform']) ? '1' : '0') . '"'
                       . ' data-ranges="' . e(json_encode($ranges)) . '"';
              }; ?>
        <tr>
          <td class="rc-room"><a href="<?= e($calUrl((int)$vid)) ?>" data-keep-cur><?= e($r['name']) ?></a><?php if (empty($r['is_published']) || $r['is_published'] === 'f'): ?><span class="rc-hidden">hidden</span><?php endif; ?></td>
          <?php foreach ($labels as $l): $s = $row['seasons'][$l] ?? null; ?>
          <td<?= $reAttr($reCells[(int)$r['id']]['seasons'][$l] ?? null, $l) ?>><?php if (!$s): ?>—<?php elseif ($s['min'] === $s['max']): ?><?= rc_money_html($s['min'], $c, $cur, $fx) ?><?php else: ?><?= rc_money_html($s['min'], $c, $cur, $fx) ?> – <?= rc_money_html($s['max'], $c, $cur, $fx) ?><?php endif; ?></td>
          <?php endforeach; ?>
          <td<?= $reAttr(['ranges' => $reCells[(int)$r['id']]['base'] ?? [], 'price' => (float)$r['price_amount'], 'uniform' => true], 'Base', true) ?>><?= (float)$r['price_amount'] > 0 ? rc_money_html((float)$r['price_amount'], $c, $cur, $fx) : '—' ?></td>
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
  <div class="rc-wrap">
    <table class="rc-table rc-tl<?= $reOwner ? ' re-tl' : '' ?>"<?= $reOwner ? ' data-re-tl' : '' ?>>
      <thead><tr>
        <th>Room</th>
        <?php foreach ($days as $d): $we = in_array((int)date('N', strtotime($d)), [6, 7], true); ?>
        <th class="<?= $we ? 'is-we' : '' ?>"><?= e(date('D', strtotime($d))[0] . ' ' . date('j', strtotime($d))) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($byVenue as $vid => $vRooms): ?>
        <tr class="rc-group"><td><?= e($venueName[$vid] ?? '') ?></td><td colspan="<?= count($days) ?>"></td></tr>
        <?php foreach ($vRooms as $r): $c = (string)($r['price_currency'] ?: 'USD'); $m = $maps[(int)$r['id']] ?? []; ?>
        <tr<?php if ($reOwner): ?> data-re-row data-room="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>"<?php endif; ?>>
          <td class="rc-room"><a href="<?= e($calUrl((int)$vid)) ?>" data-keep-cur><?= e($r['name']) ?></a></td>
          <?php foreach ($days as $d):
                $night = $m[$d] ?? null;
                $key   = $night ? rc_night_key($night) : null;
                $cls   = rc_season_class($key);
                $we    = in_array((int)date('N', strtotime($d)), [6, 7], true);
                $tip   = $night ? date('D j M', strtotime($d)) . ' · ' . ($key ?? 'Base') . ' · ' . ((float)$night['price'] > 0 ? rc_money_text((float)$night['price'], $c) : 'no price') : ''; ?>
          <td class="rc-<?= e($cls) ?><?= $we ? ' is-we' : '' ?>" title="<?= e($tip) ?>"<?= $reOwner ? ' data-d="' . e($d) . '"' : '' ?>><?= $night && (float)$night['price'] > 0 ? rc_money_html((float)$night['price'], $c, $cur, $fx, true) : '—' ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="rc-legend">
    <span><span class="rc-pill rc-std">Standard</span></span><span><span class="rc-pill rc-mid">Mid</span></span>
    <span><span class="rc-pill rc-peak">Peak</span></span><span><span class="rc-pill rc-other">Other rate</span></span>
    <span>Uncoloured = base price · hover a cell for the full price</span>
    <?php if ($reOwner): ?><span>Click a night to set rates · shift-click to extend · ctrl/⌘-click or drag to add rooms · Esc clears</span><?php endif; ?>
  </div>
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
