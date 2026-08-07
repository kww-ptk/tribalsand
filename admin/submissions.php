<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

// ── Delete (POST) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    if ($delete_id > 0) {
        db_query('DELETE FROM submissions WHERE id = :id', [':id' => $delete_id]);
    }
    // Preserve filters/page when redirecting back (never carry ajax through a redirect)
    $qs = http_build_query(array_filter($_GET, fn($k) => $k !== 'ajax', ARRAY_FILTER_USE_KEY));
    header('Location: /admin/submissions.php' . ($qs ? '?' . $qs : ''));
    exit;
}

// ── Pagination + search params ───────────────────────────────────
$pg = paginate_params();   // page / per (default 10) / q / offset / ajax

// ── Filters ──────────────────────────────────────────────────────
$type     = $_GET['type']     ?? '';
$room_id  = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$date_from= trim($_GET['date_from'] ?? '');
$date_to  = trim($_GET['date_to']   ?? '');
$export   = isset($_GET['export']);

// A clean query string (minus paging/ajax/export) for the CSV export link.
$preserve_qs = http_build_query(array_filter(
    $_GET,
    fn($k) => !in_array($k, ['page', 'per', 'ajax', 'export'], true),
    ARRAY_FILTER_USE_KEY
));
// The full current view (minus ajax) so a delete redirect lands back where the user was.
$view_qs = http_build_query(array_filter($_GET, fn($k) => $k !== 'ajax', ARRAY_FILTER_USE_KEY));

// ── Build WHERE clause ───────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($type)      { $where[] = 's.type = :type';                    $params[':type']      = $type; }
if ($room_id)   { $where[] = 's.room_id = :room_id';              $params[':room_id']   = $room_id; }
if ($date_from) { $where[] = 's.created_at::date >= :date_from';  $params[':date_from'] = $date_from; }
if ($date_to)   { $where[] = 's.created_at::date <= :date_to';    $params[':date_to']   = $date_to; }
$sw = search_where(['s.guest_name', 's.guest_email', "COALESCE(s.message,'')"], $pg['q'], $params);
if ($sw !== '') $where[] = $sw;

$where_sql = implode(' AND ', $where);

// ── CSV Export ───────────────────────────────────────────────────
if ($export) {
    $rows = db_query(
        "SELECT s.*, r.name AS room_name, t.name AS tour_name
         FROM submissions s
         LEFT JOIN rooms r ON r.id = s.room_id
         LEFT JOIN tours t ON t.id = s.tour_id
         WHERE {$where_sql} ORDER BY s.created_at DESC",
        $params
    )->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="submissions-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Type','Source','Room','Name','Email','Phone','Message','Check-in','Check-out','Adults','Children','Source Page','UTM Source','UTM Medium','UTM Campaign','Date']);
    foreach ($rows as $r) {
        $pl  = json_decode($r['payload_json'] ?? '{}', true) ?: [];
        $src = $pl['submitted_from'] ?? $r['source_page'] ?? '';
        $subject = $r['room_name'] ?: ($r['tour_name'] ? 'Tour: ' . $r['tour_name'] : '');
        fputcsv($out, [
            $r['id'], $r['type'], source_label($src), $subject, $r['guest_name'], $r['guest_email'],
            $r['guest_phone'], $r['message'], $r['check_in'], $r['check_out'],
            $r['guests_adults'], $r['guests_children'],
            $r['source_page'], $r['utm_source'], $r['utm_medium'], $r['utm_campaign'],
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Count + paginate ─────────────────────────────────────────────
$total_rows = (int)db_query(
    "SELECT COUNT(*) AS cnt FROM submissions s WHERE {$where_sql}", $params
)->fetch()['cnt'];

$meta = paginate_meta($total_rows, $pg['page'], $pg['per']);

$rows = db_query(
    "SELECT s.*, r.name AS room_name, t.name AS tour_name
     FROM submissions s
     LEFT JOIN rooms r ON r.id = s.room_id
     LEFT JOIN tours t ON t.id = s.tour_id
     WHERE {$where_sql}
     ORDER BY s.created_at DESC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

// ── Room list for filter dropdown ────────────────────────────────
$rooms = db_query('SELECT id, name FROM rooms ORDER BY sort_order')->fetchAll();

$pageTitle  = 'Submissions';
$activeMenu = 'submissions';

// Derive a friendly "Source" label from a URL (HTTP_REFERER or source_page)
function source_label(?string $url): string {
    if (!$url) return '—';
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $page = basename($path) ?: 'index.php';
    return match (true) {
        str_starts_with($page, 'index')   => 'Homepage',
        str_starts_with($page, 'spa')     => 'Spa page',
        str_starts_with($page, 'tours')   => 'Tours page',
        str_starts_with($page, 'tour')    => 'Tour page',
        str_starts_with($page, 'room')    => 'Room page',
        str_starts_with($page, 'contact') => 'Contact page',
        str_starts_with($page, 'agency')  => 'Travel Agency',
        str_starts_with($page, 'about')   => 'About page',
        str_starts_with($page, 'dining')  => 'Dining page',
        default                           => $page,
    };
}

// ── Swappable body (results card + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (empty($rows)): ?>
    <p style="padding:24px;color:var(--muted);text-align:center">No submissions found.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Source</th>
          <th>Name</th>
          <th>Email</th>
          <th>Room / Tour</th>
          <th>Check-in</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
          $payload   = json_decode($row['payload_json'] ?? '{}', true) ?: [];
          $sourceUrl = $payload['submitted_from'] ?? $row['source_page'] ?? '';
        ?>
        <tr>
          <td class="text-muted"><?= e($row['id']) ?></td>
          <td>
            <?php $badge = match($row['type']) {
              'enquiry' => 'badge--blue',
              'contact' => 'badge--green',
              'agency'  => 'badge--orange',
              default   => 'badge--grey',
            }; ?>
            <span class="badge <?= $badge ?>"><?= e($row['type']) ?></span>
          </td>
          <td class="text-muted"><?= e(source_label($sourceUrl)) ?></td>
          <td><strong><?= e($row['guest_name']) ?></strong></td>
          <td class="text-muted"><?= e($row['guest_email']) ?></td>
          <td class="text-muted"><?= e($row['room_name'] ?: ($row['tour_name'] ? 'Tour: ' . $row['tour_name'] : '—')) ?></td>
          <td class="text-muted"><?= $row['check_in'] ? e(date('d M Y', strtotime($row['check_in']))) : '—' ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime($row['created_at']))) ?></td>
          <td>
            <div class="row-actions" style="justify-content:flex-end">
              <a href="/admin/submission-view.php?id=<?= e($row['id']) ?>" class="btn-icon btn-icon--outline" title="View" aria-label="View submission"><?= admin_icon('message') ?></a>
              <form method="POST" action="/admin/submissions.php<?= $view_qs ? '?' . e($view_qs) : '' ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button type="submit" class="btn-icon btn-icon--danger" title="Delete" aria-label="Delete submission"
                        data-confirm="Delete submission #<?= e($row['id']) ?>? This cannot be undone."><?= admin_icon('trash') ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

// AJAX fragment: emit only the swappable body and stop.
if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Submissions</h1>
  <div class="actions">
    <a href="/admin/submissions.php?<?= e($preserve_qs ? $preserve_qs . '&export=1' : 'export=1') ?>" class="btn-outline btn-sm"><?= admin_icon('download', 15) ?> Export CSV</a>
  </div>
</div>

<!-- Filters + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
<form method="GET" action="/admin/submissions" class="filters" id="filtersForm">
  <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
  <input type="hidden" name="per" value="<?= (int)$meta['per'] ?>">
  <select name="type" class="filter-select js-auto-submit" aria-label="Filter by type">
    <option value="">All types</option>
    <option value="enquiry" <?= $type==='enquiry'?'selected':'' ?>>Enquiry</option>
    <option value="contact" <?= $type==='contact'?'selected':'' ?>>Contact</option>
    <option value="agency"  <?= $type==='agency' ?'selected':'' ?>>Agency</option>
  </select>

  <select name="room_id" class="filter-select js-auto-submit" aria-label="Filter by room">
    <option value="">All rooms</option>
    <?php foreach ($rooms as $r): ?>
    <option value="<?= e($r['id']) ?>" <?= $room_id===$r['id']?'selected':'' ?>><?= e($r['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <input type="text" name="date_from" value="<?= e($date_from) ?>" placeholder="From date" class="js-datepicker" autocomplete="off">
  <input type="text" name="date_to"   value="<?= e($date_to) ?>"   placeholder="To date"   class="js-datepicker" autocomplete="off">

  <button type="submit" class="btn-primary btn-sm"><?= admin_icon('filter', 15) ?> Apply</button>
  <?php if ($type || $room_id || $date_from || $date_to || $pg['q']): ?>
  <a href="/admin/submissions.php" class="btn-outline btn-sm"><?= admin_icon('x', 14) ?> Clear</a>
  <?php endif; ?>
</form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, email or message…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
  (function () {
    var form = document.getElementById('filtersForm');
    if (!form) return;

    // Auto-submit on dropdown change
    form.querySelectorAll('.js-auto-submit').forEach(function (el) {
      el.addEventListener('change', function () { form.submit(); });
    });

    // Date pickers — auto-submit when a date is picked or cleared
    flatpickr('.js-datepicker', {
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd M Y',
      allowInput: true,
      onChange: function () { form.submit(); },
    });
  })();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
