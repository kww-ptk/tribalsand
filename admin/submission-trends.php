<?php
/**
 * Admin: Submission Smart Trends (Item 5) — read-only analytics over the enquiry
 * inbox. What do we get asked for most: which property, which requested dates,
 * what party sizes? Scoped by venue exactly like admin/submissions.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/submission-trends.php';
require_login();
require_bookings();

$pageTitle  = 'Submission Trends';
$activeMenu = 'submissions';

// ── Venue scope (mirrors admin/submissions.php) ───────────────────
// submissions has no venue column; an enquiry reaches a property via
// room_id → rooms.venue_id. Contact/agency rows carry no property and stay
// visible to every scoped account.
$sVenue = venue_scope_sql('r.venue_id');
$scopeCond = $sVenue === ''
    ? ''
    : "(s.room_id IS NULL OR EXISTS (SELECT 1 FROM rooms r WHERE r.id = s.room_id AND {$sVenue}))";

// ── Filters ───────────────────────────────────────────────────────
$type      = trim($_GET['type'] ?? '');
$valid_type = ['enquiry','trip_builder','contact','agency','availability','event'];
if ($type !== '' && !in_array($type, $valid_type, true)) $type = '';
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
// Default window: the last 90 days (Nairobi-local).
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-90 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

$t = submission_trends($scopeCond, [], $date_from, $date_to, $type);

/** Render a horizontal-bar breakdown. $rows = [['label','count'], …]. */
function trend_bars(array $rows): void {
    if (!$rows) { echo '<p class="text-muted" style="font-size:13px;margin:0">No data in this range.</p>'; return; }
    $max = max(array_map(fn($r) => (int)$r['count'], $rows)) ?: 1;
    echo '<ul class="tb">';
    foreach ($rows as $r) {
        $pct = max(2, round((int)$r['count'] / $max * 100));
        echo '<li class="tb__row">'
           . '<span class="tb__label" title="' . e((string)$r['label']) . '">' . e((string)$r['label']) . '</span>'
           . '<span class="tb__track"><span class="tb__fill" style="width:' . $pct . '%"></span></span>'
           . '<span class="tb__val">' . (int)$r['count'] . '</span>'
           . '</li>';
    }
    echo '</ul>';
}

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Submission Trends</h1>
  <a href="/admin/submissions.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inbox</a>
</div>

<!-- Range + type filters -->
<form method="GET" action="/admin/submission-trends.php" class="filters" id="trFilters" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <select name="type" class="filter-select js-auto-submit" aria-label="Filter by type">
    <option value="">All enquiry types</option>
    <option value="enquiry"      <?= $type==='enquiry'      ?'selected':'' ?>>Enquiry</option>
    <option value="trip_builder" <?= $type==='trip_builder' ?'selected':'' ?>>Trip Builder</option>
    <option value="availability" <?= $type==='availability' ?'selected':'' ?>>Availability search</option>
    <option value="contact"      <?= $type==='contact'      ?'selected':'' ?>>Contact</option>
    <option value="agency"       <?= $type==='agency'       ?'selected':'' ?>>Agency</option>
    <option value="event"        <?= $type==='event'        ?'selected':'' ?>>Event</option>
  </select>
  <button type="button" class="dp-btn" data-dp-target="trFrom" data-dp-placeholder="From date" style="width:150px"><?= e(date('j M Y', strtotime($date_from))) ?></button>
  <input type="hidden" id="trFrom" name="date_from" value="<?= e($date_from) ?>">
  <button type="button" class="dp-btn" data-dp-target="trTo" data-dp-placeholder="To date" style="width:150px"><?= e(date('j M Y', strtotime($date_to))) ?></button>
  <input type="hidden" id="trTo" name="date_to" value="<?= e($date_to) ?>">
  <span class="text-muted" style="font-size:12.5px"><?= e(date('j M', strtotime($date_from))) ?> – <?= e(date('j M Y', strtotime($date_to))) ?></span>
</form>

<!-- KPI summary -->
<div class="tr-kpis">
  <div class="tr-kpi"><div class="tr-kpi__n"><?= number_format($t['total']) ?></div><div class="tr-kpi__l">Enquiries</div></div>
  <div class="tr-kpi"><div class="tr-kpi__n"><?= $t['total'] ? round($t['with_dates'] / $t['total'] * 100) : 0 ?>%</div><div class="tr-kpi__l">Include dates</div></div>
  <div class="tr-kpi"><div class="tr-kpi__n"><?= $t['avg_party'] ?: '—' ?></div><div class="tr-kpi__l">Avg party size</div></div>
  <div class="tr-kpi"><div class="tr-kpi__n"><?= $t['total'] ? round($t['with_children'] / $t['total'] * 100) : 0 ?>%</div><div class="tr-kpi__l">Travel with children</div></div>
  <div class="tr-kpi"><div class="tr-kpi__n" style="font-size:16px"><?= e($t['by_source'][0]['label'] ?? '—') ?></div><div class="tr-kpi__l">Top lead source</div></div>
  <div class="tr-kpi"><div class="tr-kpi__n" style="font-size:16px"><?= e($t['by_property'][0]['label'] ?? '—') ?></div><div class="tr-kpi__l">Most-requested property</div></div>
</div>

<div class="tr-grid">
  <div class="card"><div class="card__head"><span class="card__title">Property requested</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_property']); ?></div></div>

  <div class="card"><div class="card__head"><span class="card__title">Party size</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_party']); ?></div></div>

  <div class="card"><div class="card__head"><span class="card__title">Lead source</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_source']); ?>
      <p class="text-muted" style="font-size:11.5px;margin:10px 0 0">From the campaign link (UTM) when there is one, otherwise the website the guest came from.</p></div></div>

  <div class="card"><div class="card__head"><span class="card__title">Children</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_children']); ?></div></div>

  <div class="card"><div class="card__head"><span class="card__title">Requested stay month</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_month']); ?></div></div>

  <div class="card"><div class="card__head"><span class="card__title">Booking lead time</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_lead_time']); ?></div></div>

  <div class="card"><div class="card__head"><span class="card__title">Enquiry type</span></div>
    <div class="card__body" style="padding:16px 18px"><?php trend_bars($t['by_type']); ?></div></div>
</div>

<style>
.tr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.tr-kpi{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:10px;padding:14px 16px}
.tr-kpi__n{font-size:26px;font-weight:800;color:var(--teal,#1E5C6B);line-height:1.1}
.tr-kpi__l{font-size:12px;color:var(--muted,#6b7280);margin-top:4px}
.tr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.tb{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:9px}
.tb__row{display:grid;grid-template-columns:130px 1fr 34px;align-items:center;gap:10px;font-size:13px}
.tb__label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tb__track{background:#eef2f6;border-radius:6px;height:14px;overflow:hidden}
.tb__fill{display:block;height:100%;background:linear-gradient(90deg,#1E5C6B,#2f8ea6);border-radius:6px}
.tb__val{text-align:right;font-weight:700;color:var(--muted,#374151)}
@media (max-width:520px){ .tb__row{grid-template-columns:96px 1fr 30px} }
</style>

<script>
(function(){
  var f=document.getElementById('trFilters'); if(!f) return;
  f.querySelectorAll('.js-auto-submit').forEach(function(el){ el.addEventListener('change',function(){f.submit();}); });
  ['trFrom','trTo'].forEach(function(id){ var el=document.getElementById(id); if(el) el.addEventListener('change',function(){f.submit();}); });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
