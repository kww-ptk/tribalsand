<?php
/**
 * Admin: Front Desk — confirmed reservations grouped Today / Tomorrow / This week,
 * scoped to the viewer's property(ies). Staff landing page; owner sees all + filter.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_login();

$pageTitle  = 'Front desk';
$activeMenu = 'frontdesk';

// ── Scope: owner => null (all); manager/staff => their venue ids (empty => none) ──
$isOwner = is_owner();
$allowed = $isOwner ? null : (admin_venue_ids() ?: []);   // null = all venues

// Venue list for the filter (owner: all; staff: their venues).
if ($allowed === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($allowed) {
    $ph = []; $p = [];
    foreach ($allowed as $i => $v) { $n = ":lv{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}

// Selected venue filter (validated against scope).
$venueFilter = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
$validIds = array_map(fn($v) => (int)$v['id'], $venues);
if ($venueFilter > 0 && in_array($venueFilter, $validIds, true)) {
    $venueIds = [$venueFilter];
} else {
    $venueFilter = 0;
    $venueIds = $allowed;   // null (owner all) or the staff's full set
}

// Tab.
$when = in_array($_GET['when'] ?? '', ['today','tomorrow','week'], true) ? $_GET['when'] : 'today';
$todayYmd = frontdesk_today_ymd();

// Data for the active tab.
$dayData = null; $weekRows = null;
if ($when === 'week') {
    $weekRows = frontdesk_week($venueIds, $todayYmd, 7);
} else {
    $dayYmd  = $when === 'tomorrow' ? frontdesk_tomorrow_ymd() : $todayYmd;
    $dayData = frontdesk_day($venueIds, $dayYmd);
    $dayLabel = (new DateTime($dayYmd))->format('D j M Y');
}

// Preserve the venue filter across tab links.
$q = fn(string $w) => '?when=' . $w . ($venueFilter ? '&venue=' . $venueFilter : '');

// Human label for the current scope (shown in the top line).
$venueNameById = [];
foreach ($venues as $v) { $venueNameById[(int)$v['id']] = $v['name']; }
if ($venueFilter) {
    $scopeName = $venueNameById[$venueFilter] ?? 'Property';
} elseif ($allowed === null) {
    $scopeName = 'All properties';
} elseif (count($venues) === 1) {
    $scopeName = $venues[0]['name'];
} else {
    $scopeName = 'All my properties';
}

/** Render one reservation card. */
$card = function(array $r): string {
    $name  = e($r['guest_name'] !== '' ? $r['guest_name'] : 'Guest');
    $roomOrUnit = ($r['room_name'] ?? '') !== '' ? (string)$r['room_name'] : (string)($r['unit_name'] ?? '');
    $place = e(trim(((string)($r['venue_name'] ?? '')) . ' · ' . $roomOrUnit, ' ·'));
    $nights = max(0, (int) round((strtotime((string)$r['check_out']) - strtotime((string)$r['check_in'])) / 86400));
    $dates = e(date('j M', strtotime((string)$r['check_in'])) . '–' . date('j M', strtotime((string)$r['check_out']))
             . ' (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')');
    $code  = trim((string)($r['access_code'] ?? ''));
    $phone = trim((string)($r['guest_phone'] ?? ''));
    $hid   = (int)$r['id'];
    $reqs  = (int)$r['open_requests'];
    $unread = (int)$r['unread_msgs'];

    ob_start(); ?>
    <div class="fd-card">
      <div class="fd-card__main">
        <div class="fd-card__name"><?= $name ?></div>
        <div class="fd-card__meta"><b><?= $place ?></b> · <?= $dates ?><?php if ($code !== ''): ?> · <span class="fd-code"><?= e($code) ?></span><?php endif; ?></div>
        <div class="fd-card__badges">
          <?php if ($reqs > 0): ?><a class="fd-badge fd-badge--req" href="/admin/booking.php?hold=<?= $hid ?>&tab=requests"><?= $reqs ?> request<?= $reqs === 1 ? '' : 's' ?></a><?php endif; ?>
          <?php if ($unread > 0): ?><a class="fd-badge fd-badge--msg" href="/admin/booking.php?hold=<?= $hid ?>&tab=messages"><?= $unread ?> unread</a><?php endif; ?>
        </div>
      </div>
      <div class="fd-card__side">
        <?php if ($phone !== ''): ?><a class="fd-phone" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php else: ?><span class="fd-phone fd-phone--none">—</span><?php endif; ?>
        <a class="btn-outline btn-sm" href="/admin/booking.php?hold=<?= $hid ?>">Open →</a>
      </div>
    </div>
    <?php return ob_get_clean();
};

/** Render a titled section of cards with an empty state. */
$section = function(string $title, string $dotClass, array $rows, string $empty) use ($card): string {
    ob_start(); ?>
    <div class="fd-sec">
      <div class="fd-sec__h"><span class="fd-dot <?= $dotClass ?>"></span><?= e($title) ?> <span class="fd-sec__n">· <?= count($rows) ?></span></div>
      <?php if (!$rows): ?><p class="fd-empty"><?= e($empty) ?></p>
      <?php else: foreach ($rows as $r) echo $card($r); endif; ?>
    </div>
    <?php return ob_get_clean();
};

include __DIR__ . '/_layout.php';
?>

<style>
.fd-topline{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.fd-seg{display:flex;gap:6px;margin:4px 0 14px}
.fd-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:6px}
.fd-kpi{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:14px 16px}
.fd-kpi .n{font-size:26px;font-weight:800;color:#102F3A;line-height:1}
.fd-kpi .l{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:4px}
.fd-kpi--in .n{color:#166534}.fd-kpi--dep .n{color:#b45309}
.fd-sec{margin-top:14px}
.fd-sec__h{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 8px}
.fd-sec__n{color:#b3aa9c;font-weight:600}
.fd-dot{width:9px;height:9px;border-radius:50%}
.fd-dot--arr{background:#2563eb}.fd-dot--in{background:#16a34a}.fd-dot--dep{background:#d97706}
.fd-empty{color:var(--muted);font-size:14px;margin:0 0 8px}
.fd-card{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:12px 14px;margin-bottom:8px}
.fd-card__main{flex:1;min-width:0}
.fd-card__name{font-size:15px;font-weight:700}
.fd-card__meta{font-size:12.5px;color:var(--muted);margin-top:2px}
.fd-card__meta b{color:#3a352d;font-weight:600}
.fd-code{font-family:monospace;letter-spacing:.5px}
.fd-card__badges{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap}
.fd-badge{font-size:11px;font-weight:700;border-radius:999px;padding:3px 9px;text-decoration:none}
.fd-badge--req{background:#ffedd5;color:#9a3412}
.fd-badge--msg{background:#dbeafe;color:#1e40af}
.fd-card__side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;white-space:nowrap}
.fd-phone{font-size:12.5px;color:#1E5C6B;text-decoration:none}
.fd-phone--none{color:var(--muted)}
</style>

<div class="page-header">
  <h1>Front desk</h1>
  <?php if ($isOwner): ?><a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a><?php endif; ?>
</div>

<div class="fd-topline">
  <div class="text-muted" style="font-size:13px">
    <strong><?= e($scopeName) ?></strong>
    <?php if ($when !== 'week'): ?> · <?= e($dayLabel) ?><?php endif; ?>
  </div>
  <?php if (count($venues) > 1): ?>
  <form method="get" style="margin:0">
    <input type="hidden" name="when" value="<?= e($when) ?>">
    <select name="venue" onchange="this.form.submit()" class="btn-outline btn-sm" style="padding:6px 10px">
      <option value="0"><?= $allowed === null ? 'All properties' : 'All my properties' ?></option>
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>" <?= $venueFilter === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<div class="fd-seg">
  <?php foreach (['today'=>'Today','tomorrow'=>'Tomorrow','week'=>'This week'] as $wk => $wl): ?>
  <a href="<?= e($q($wk)) ?>" class="btn-sm <?= $when === $wk ? 'btn-primary' : 'btn-outline' ?>"><?= e($wl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($when === 'week'): ?>

  <?php if (!$weekRows): ?>
    <div class="card"><div class="card__body"><p class="fd-empty" style="margin:0">No arrivals in the next 7 days.</p></div></div>
  <?php else:
    $byDay = [];
    foreach ($weekRows as $r) { $byDay[(string)$r['check_in']][] = $r; }
    foreach ($byDay as $ymd => $rows): ?>
    <div class="fd-sec">
      <div class="fd-sec__h"><span class="fd-dot fd-dot--arr"></span><?= e((new DateTime($ymd))->format('D j M')) ?> <span class="fd-sec__n">· <?= count($rows) ?> arriving</span></div>
      <?php foreach ($rows as $r) echo $card($r); ?>
    </div>
  <?php endforeach; endif; ?>

<?php else: ?>

  <div class="fd-kpis">
    <div class="fd-kpi fd-kpi--in"><div class="n"><?= (int)$dayData['kpi_inhouse'] ?></div><div class="l">In house <?= $when === 'today' ? 'tonight' : 'that night' ?></div></div>
    <div class="fd-kpi"><div class="n"><?= count($dayData['arriving']) ?></div><div class="l">Arriving</div></div>
    <div class="fd-kpi fd-kpi--dep"><div class="n"><?= count($dayData['departing']) ?></div><div class="l">Departing</div></div>
  </div>

  <?= $section('Arriving',  'fd-dot--arr', $dayData['arriving'],  $when === 'today' ? 'Nobody arriving today.'  : 'Nobody arriving tomorrow.') ?>
  <?= $section('In house',  'fd-dot--in',  $dayData['inhouse'],   'Nobody staying over.') ?>
  <?= $section('Departing', 'fd-dot--dep', $dayData['departing'], $when === 'today' ? 'Nobody departing today.' : 'Nobody departing tomorrow.') ?>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
