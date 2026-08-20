<?php
/**
 * Admin: Restaurant reservations.
 *
 * Request-only bookings — every row arrives as `pending` and a human confirms.
 * Capacity is deliberately not modelled. See the design spec.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_login();
require_frontdesk();

$pageTitle  = 'Reservations';
$activeMenu = 'restaurant';

if (!restaurant_supported()) {
    require __DIR__ . '/_layout.php';
    echo '<div class="alert alert--info">The reservations table has not been created yet. '
       . 'Run <strong>add_restaurant_reservations.sql</strong> from <a href="/admin/migrate.php">Migrations</a>.</div>';
    require __DIR__ . '/_layout_end.php';
    exit;
}

$flash = $_SESSION['restaurant_flash'] ?? null;
unset($_SESSION['restaurant_flash']);

// ── Venue scope ──
// null = owner (all venues). [] = scoped to nothing → show nothing.
$venueIds = admin_venue_ids();
$scopeSql = '';
$scopeArgs = [];
if ($venueIds !== null) {
    if (!$venueIds) {
        $scopeSql = ' AND FALSE';
    } else {
        $in = [];
        foreach (array_values($venueIds) as $i => $vid) {
            $in[] = ':v' . $i;
            $scopeArgs[':v' . $i] = (int)$vid;
        }
        $scopeSql = ' AND r.venue_id IN (' . implode(',', $in) . ')';
    }
}

// ── Filters ──
// Array-valued GET params (e.g. ?from[]=x) must never reach trim()/(string) —
// that emits an "Array to string conversion" warning above the <!DOCTYPE>,
// leaking the absolute server path with display_errors on in production.
$fStatus = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : '';
$fFrom   = is_string($_GET['from']   ?? null) ? trim($_GET['from'])   : '';
$fTo     = is_string($_GET['to']     ?? null) ? trim($_GET['to'])     : '';
$filterSql  = '';
$filterArgs = [];
if (in_array($fStatus, ['pending', 'confirmed', 'declined', 'cancelled'], true)) {
    $filterSql .= ' AND r.status = :status';
    $filterArgs[':status'] = $fStatus;
}

// A regex-only check accepts calendar-invalid dates (2026-13-45, 2026-02-30,
// 0000-00-00); Postgres throws on those and the uncaught PDOException fires
// before _layout.php runs, so display_errors=on in production dumps the SQL,
// stack trace and container paths straight to the browser. checkdate() closes
// that off — same pattern as admin/hold-new.php. An invalid value is cleared
// rather than kept, so the filter bar reflects the query that actually ran.
$isYmd = static fn(string $d): bool =>
    preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1
    && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
if ($fFrom !== '' && !$isYmd($fFrom)) { $fFrom = ''; }
if ($fTo   !== '' && !$isYmd($fTo))   { $fTo   = ''; }

// An explicit "from" means the manager is deliberately looking back, so it
// replaces (rather than adds to) the default lower bound applied to the
// upcoming list below — otherwise a pending request whose date has already
// passed would be invisible forever (both lists otherwise only look forward).
$hasFrom = $fFrom !== '';
if ($hasFrom)    { $filterSql .= ' AND r.reserved_on >= :from'; $filterArgs[':from'] = $fFrom; }
if ($fTo !== '') { $filterSql .= ' AND r.reserved_on <= :to';   $filterArgs[':to']   = $fTo; }

// r.* is sufficient — venue_name was selected but never displayed, and every
// reservation is Zuri today. Whoever adds a second venue adds the join back.
$SELECT = 'SELECT r.* FROM restaurant_reservations r WHERE TRUE';

// ── Counters ──
$countToday = (int) db_query(
    "SELECT COUNT(*) AS cnt FROM restaurant_reservations r
      WHERE r.reserved_on = CURRENT_DATE AND r.status = 'confirmed'" . $scopeSql,
    $scopeArgs
)->fetch()['cnt'];

$countPending = (int) db_query(
    "SELECT COUNT(*) AS cnt FROM restaurant_reservations r
      WHERE r.status = 'pending' AND r.reserved_on >= CURRENT_DATE" . $scopeSql,
    $scopeArgs
)->fetch()['cnt'];

// ── Lists ──
$today = db_query(
    $SELECT . $scopeSql . " AND r.reserved_on = CURRENT_DATE AND r.status IN ('pending','confirmed')
    ORDER BY r.reserved_at",
    $scopeArgs
)->fetchAll();

// Cap the filtered list. If it truncates, say so rather than silently hiding rows.
// Default view is deliberately forward-looking (reserved_on >= CURRENT_DATE);
// an explicit "from" filter above already supplies its own lower bound, so it
// is not added again here — see $hasFrom.
$LIMIT = 200;
$boundSql = $hasFrom ? '' : ' AND r.reserved_on >= CURRENT_DATE';
$upcoming = db_query(
    $SELECT . $scopeSql . $filterSql . $boundSql . '
    ORDER BY r.reserved_on, r.reserved_at LIMIT ' . ($LIMIT + 1),
    $scopeArgs + $filterArgs
)->fetchAll();
$truncated = count($upcoming) > $LIMIT;
if ($truncated) array_pop($upcoming);

$fmtTime = static fn(string $t) => substr($t, 0, 5);
$fmtDate = static fn(string $d) => date('D j M', strtotime($d));

require __DIR__ . '/_layout.php';
?>

<?php if ($flash): ?>
<div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-card__label">Reservations Today</div>
    <div class="kpi-card__value"><?= e($countToday) ?></div>
    <div class="kpi-card__sub">confirmed for today</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__label">Pending Confirmation</div>
    <div class="kpi-card__value"><?= e($countPending) ?></div>
    <div class="kpi-card__sub">awaiting your decision</div>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Today's Reservations</span></div>
  <div class="card__body">
    <?php if (!$today): ?>
      <p>No reservations today.</p>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Time</th><th>Guest</th><th>Phone</th><th>Party</th><th>Occasion</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($today as $r): ?>
        <tr>
          <td><?= e($fmtTime($r['reserved_at'])) ?></td>
          <td><?= e($r['guest_name']) ?></td>
          <td><?= e($r['guest_phone'] ?: '—') ?></td>
          <td><?= e($r['party_size']) ?></td>
          <td><?= e($r['occasion'] ? ucfirst($r['occasion']) : '—') ?></td>
          <td><span class="badge <?= e(restaurant_status_badge_class($r['status'])) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Upcoming Reservations</span></div>
  <div class="card__body">

    <form method="get" class="filter-bar">
      <div class="filter-field">
        <label for="fStatus">Status</label>
        <select id="fStatus" name="status">
          <option value="">All</option>
          <?php foreach (['pending', 'confirmed', 'declined', 'cancelled'] as $s): ?>
          <option value="<?= e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field"><label for="fFrom">From</label><input type="date" id="fFrom" name="from" value="<?= e($fFrom) ?>"></div>
      <div class="filter-field"><label for="fTo">To</label><input type="date" id="fTo" name="to" value="<?= e($fTo) ?>"></div>
      <button type="submit" class="btn-sm btn-outline">Filter</button>
      <a href="/admin/restaurant.php" class="btn-sm btn-outline">Reset</a>
    </form>

    <?php if ($truncated): ?>
    <div class="alert alert--info">Showing the first <?= (int)$LIMIT ?> matching reservations. Narrow the date range to see more.</div>
    <?php endif; ?>

    <?php if (!$upcoming): ?>
      <p>No upcoming reservations match these filters.</p>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Date</th><th>Time</th><th>Guest</th><th>Phone</th><th>Party</th><th>Ref</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($upcoming as $r): ?>
        <tr>
          <td><?= e($fmtDate($r['reserved_on'])) ?></td>
          <td><?= e($fmtTime($r['reserved_at'])) ?></td>
          <td>
            <?= e($r['guest_name']) ?>
            <?php if ($r['occasion']): ?><br><small><?= e(ucfirst($r['occasion'])) ?></small><?php endif; ?>
            <?php if ($r['notes']): ?><br><small><?= e($r['notes']) ?></small><?php endif; ?>
          </td>
          <td><?= e($r['guest_phone'] ?: '—') ?></td>
          <td><?= e($r['party_size']) ?></td>
          <td><?= e($r['reference']) ?></td>
          <td><span class="badge <?= e(restaurant_status_badge_class($r['status'])) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" action="/admin/restaurant-action.php" style="display:flex;gap:.35rem">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                <button type="submit" name="action" value="confirm" class="btn-sm btn-primary">Confirm</button>
                <button type="submit" name="action" value="decline" class="btn-sm btn-outline" data-confirm="Decline this table request?">Decline</button>
              </form>
            <?php elseif ($r['status'] === 'confirmed'): ?>
              <form method="post" action="/admin/restaurant-action.php" style="display:flex;gap:.35rem">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                <button type="submit" name="action" value="cancel" class="btn-sm btn-outline" data-confirm="Cancel this confirmed table?">Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
