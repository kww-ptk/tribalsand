<?php
/**
 * Admin: Front Desk — confirmed reservations grouped Today / Tomorrow / This week,
 * scoped to the viewer's property(ies). Staff landing page; owner sees all + filter.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';
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

/** Render one reservation card — the whole card links to the booking (F1/F5). */
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
    <div class="fd-card" data-href="/admin/booking.php?hold=<?= $hid ?>" role="link" tabindex="0" aria-label="Open booking for <?= $name ?>">
      <div class="fd-card__name"><?= $name ?></div>
      <div class="fd-card__meta"><b><?= $place ?></b> · <?= $dates ?><?php if ($code !== ''): ?> · <span class="fd-code"><?= e($code) ?></span><?php endif; ?></div>
      <div class="fd-card__badges">
        <?php if ($reqs > 0): ?><a class="fd-badge fd-badge--req" href="/admin/booking.php?hold=<?= $hid ?>&tab=requests"><?= $reqs ?> request<?= $reqs === 1 ? '' : 's' ?></a><?php endif; ?>
        <?php if ($unread > 0): ?><a class="fd-badge fd-badge--msg" href="/admin/booking.php?hold=<?= $hid ?>&tab=messages"><?= $unread ?> unread</a><?php endif; ?>
        <?php $__ci = checkin_badge($r); if ($__ci): ?><span class="ci-badge <?= e($__ci['class']) ?>"><?= e($__ci['label']) ?></span><?php endif; ?>
      </div>
      <?php if ($phone !== ''): ?><a class="fd-phone" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= admin_icon('phone', 13) ?> <?= e($phone) ?></a><?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

include __DIR__ . '/_layout.php';
?>

<style>
/* One control row: tabs + live date + property picker (F3/F4). */
.fd-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 16px}
.fd-seg{display:flex;gap:6px}
.fd-bar__right{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.fd-bar__date{font-size:13px;color:var(--muted);font-weight:600;white-space:nowrap}
.fd-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
.fd-kpi{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:14px 16px}
.fd-kpi .n{font-size:26px;font-weight:800;color:#102F3A;line-height:1}
.fd-kpi .l{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:4px}
.fd-kpi--in .n{color:#166534}.fd-kpi--dep .n{color:#b45309}
/* Kanban board (F1): three columns, cards stack inside. */
.fd-kanban{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;align-items:start}
.fd-col{background:var(--bg-alt,#f6f4f0);border:1px solid var(--border,#e7ded7);border-radius:12px;padding:12px}
.fd-col__h{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 10px}
.fd-col__n{margin-left:auto;color:#b3aa9c;font-weight:600}
.fd-dot{width:9px;height:9px;border-radius:50%}
.fd-dot--arr{background:#2563eb}.fd-dot--in{background:#16a34a}.fd-dot--dep{background:#d97706}
.fd-empty{color:var(--muted);font-size:13px;margin:2px 0}
/* Whole card is the booking link (F1/F5) — no side rule, no Open button. */
.fd-card{display:block;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:10px;padding:11px 13px;margin-bottom:8px;cursor:pointer;text-decoration:none;color:inherit;transition:box-shadow .15s,border-color .15s}
.fd-card:last-child{margin-bottom:0}
.fd-card:hover{border-color:#d8cbb6;box-shadow:0 2px 10px rgba(16,47,58,.09)}
.fd-card:focus-visible{outline:2px solid var(--brand,#B8965A);outline-offset:2px}
.fd-card__name{font-size:15px;font-weight:700}
.fd-card__meta{font-size:12.5px;color:var(--muted);margin-top:2px}
.fd-card__meta b{color:#3a352d;font-weight:600}
.fd-code{letter-spacing:.5px}
.fd-card__badges{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap}
.fd-badge{font-size:11px;font-weight:700;border-radius:999px;padding:3px 9px;text-decoration:none}
.fd-badge--req{background:#ffedd5;color:#9a3412}
.fd-badge--msg{background:#dbeafe;color:#1e40af}
.fd-phone{display:inline-flex;align-items:center;gap:5px;margin-top:8px;font-size:12.5px;color:#1E5C6B;text-decoration:none}
.fd-phone:hover{text-decoration:underline}
/* Week view keeps the by-day list. */
.fd-sec{margin-top:14px}
.fd-sec__h{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 8px}
.fd-sec__n{color:#b3aa9c;font-weight:600}
@media (max-width:820px){.fd-kanban{grid-template-columns:1fr}}
</style>

<div class="page-header">
  <h1>Front desk</h1>
  <?php if ($isOwner): ?><a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a><?php endif; ?>
</div>

<div class="fd-bar">
  <div class="fd-seg">
    <?php foreach (['today'=>'Today','tomorrow'=>'Tomorrow','week'=>'This week'] as $wk => $wl): ?>
    <a href="<?= e($q($wk)) ?>" class="btn-sm <?= $when === $wk ? 'btn-primary' : 'btn-outline' ?>"><?= e($wl) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="fd-bar__right">
    <?php if ($when !== 'week'): ?><span class="fd-bar__date"><?= e($dayLabel) ?></span><?php endif; ?>
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
    <div class="fd-kpi"><div class="n"><?= count($dayData['arriving']) ?></div><div class="l">Arriving</div></div>
    <div class="fd-kpi fd-kpi--in"><div class="n"><?= (int)$dayData['kpi_inhouse'] ?></div><div class="l">In house <?= $when === 'today' ? 'tonight' : 'that night' ?></div></div>
    <div class="fd-kpi fd-kpi--dep"><div class="n"><?= count($dayData['departing']) ?></div><div class="l">Departing</div></div>
  </div>

  <div class="fd-kanban">
    <?php
      $cols = [
        ['Arriving',  'fd-dot--arr', $dayData['arriving'],  $when === 'today' ? 'Nobody arriving today.'  : 'Nobody arriving tomorrow.'],
        ['In house',  'fd-dot--in',  $dayData['inhouse'],   'Nobody staying over.'],
        ['Departing', 'fd-dot--dep', $dayData['departing'], $when === 'today' ? 'Nobody departing today.' : 'Nobody departing tomorrow.'],
      ];
      foreach ($cols as [$title, $dot, $rows, $empty]): ?>
      <div class="fd-col">
        <div class="fd-col__h"><span class="fd-dot <?= $dot ?>"></span><?= e($title) ?><span class="fd-col__n"><?= count($rows) ?></span></div>
        <?php if (!$rows): ?><p class="fd-empty"><?= e($empty) ?></p>
        <?php else: foreach ($rows as $r) echo $card($r); endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<script>
// F1: whole card navigates to the booking, except when an inner link/button
// (request/unread badge, phone) was clicked. Keyboard: Enter on the focused card.
(function () {
  document.querySelectorAll('.fd-card[data-href]').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('a, button')) return;
      window.location.href = card.getAttribute('data-href');
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); window.location.href = card.getAttribute('data-href'); }
    });
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
