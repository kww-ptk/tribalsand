<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

// ── Delete (POST) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $delete_id = (int)($_POST['id'] ?? 0);
    if ($delete_id > 0) {
        db_query('DELETE FROM submissions WHERE id = :id', [':id' => $delete_id]);
    }
    // Preserve filters/page when redirecting back
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /admin/submissions.php' . ($qs ? '?' . $qs : ''));
    exit;
}

// ── Filters ──────────────────────────────────────────────────────
$type     = $_GET['type']     ?? '';
$room_id  = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$date_from= trim($_GET['date_from'] ?? '');
$date_to  = trim($_GET['date_to']   ?? '');
$search   = trim($_GET['search']    ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$export   = isset($_GET['export']);

// ── Build WHERE clause ───────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($type)      { $where[] = 's.type = :type';                    $params[':type']      = $type; }
if ($room_id)   { $where[] = 's.room_id = :room_id';              $params[':room_id']   = $room_id; }
if ($date_from) { $where[] = 's.created_at::date >= :date_from';  $params[':date_from'] = $date_from; }
if ($date_to)   { $where[] = 's.created_at::date <= :date_to';    $params[':date_to']   = $date_to; }
if ($search)    {
    $where[] = '(s.guest_name ILIKE :search OR s.guest_email ILIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// ── CSV Export ───────────────────────────────────────────────────
if ($export) {
    $rows = db_query(
        "SELECT s.*, r.name AS room_name
         FROM submissions s LEFT JOIN rooms r ON r.id = s.room_id
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
        fputcsv($out, [
            $r['id'], $r['type'], source_label($src), $r['room_name'] ?? '', $r['guest_name'], $r['guest_email'],
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

$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$rows = db_query(
    "SELECT s.*, r.name AS room_name
     FROM submissions s LEFT JOIN rooms r ON r.id = s.room_id
     WHERE {$where_sql}
     ORDER BY s.created_at DESC
     LIMIT {$per_page} OFFSET {$offset}",
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

// Build query string helper (preserves filters when paginating/exporting)
function qs(array $extra = []): string {
    $base = array_filter([
        'type'      => $_GET['type']      ?? '',
        'room_id'   => $_GET['room_id']   ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to']   ?? '',
        'search'    => $_GET['search']    ?? '',
    ]);
    return http_build_query(array_merge($base, $extra));
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Submissions</h1>
  <div class="actions">
    <a href="/admin/submissions.php?<?= qs(['export'=>1]) ?>" class="btn-outline btn-sm">Export CSV</a>
  </div>
</div>

<!-- Filters -->
<form method="GET" action="/admin/submissions.php" class="filters" id="filtersForm">
  <select name="type" class="js-auto-submit">
    <option value="">All types</option>
    <option value="enquiry" <?= $type==='enquiry'?'selected':'' ?>>Enquiry</option>
    <option value="contact" <?= $type==='contact'?'selected':'' ?>>Contact</option>
    <option value="agency"  <?= $type==='agency' ?'selected':'' ?>>Agency</option>
  </select>

  <select name="room_id" class="js-auto-submit">
    <option value="">All rooms</option>
    <?php foreach ($rooms as $r): ?>
    <option value="<?= e($r['id']) ?>" <?= $room_id===$r['id']?'selected':'' ?>><?= e($r['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <input type="text" name="date_from" value="<?= e($date_from) ?>" placeholder="From date" class="js-datepicker" autocomplete="off">
  <input type="text" name="date_to"   value="<?= e($date_to) ?>"   placeholder="To date"   class="js-datepicker" autocomplete="off">
  <input type="text" name="search"    value="<?= e($search) ?>"    placeholder="Search name or email…" style="min-width:200px">

  <button type="submit" class="btn-primary btn-sm">Search</button>
  <?php if ($type || $room_id || $date_from || $date_to || $search): ?>
  <a href="/admin/submissions.php" class="btn-outline btn-sm">Clear</a>
  <?php endif; ?>
</form>

<!-- Results -->
<div class="card">
  <div class="card__head">
    <span class="card__title"><?= e($total_rows) ?> submission<?= $total_rows !== 1 ? 's' : '' ?></span>
  </div>
  <div class="card__body">
    <?php if (empty($rows)): ?>
    <p style="padding:24px;color:var(--muted);text-align:center">No submissions found.</p>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Source</th>
          <th>Name</th>
          <th>Email</th>
          <th>Room</th>
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
          <td class="text-muted"><?= e($row['room_name'] ?? '—') ?></td>
          <td class="text-muted"><?= $row['check_in'] ? e(date('d M Y', strtotime($row['check_in']))) : '—' ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime($row['created_at']))) ?></td>
          <td>
            <div style="display:flex;gap:6px;justify-content:flex-end">
              <a href="/admin/submission-view.php?id=<?= e($row['id']) ?>" class="btn-sm btn-outline">View</a>
              <form method="POST" action="/admin/submissions.php?<?= qs() ?>" style="display:inline"
                    onsubmit="return confirm('Delete submission #<?= e($row['id']) ?>? This cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button type="submit" class="btn-sm btn-outline" style="color:#b00020;border-color:#e5b4bc">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="padding:16px">
      <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?<?= qs(['page' => $page - 1]) ?>">&#8249; Prev</a>
        <?php endif; ?>

        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
        <?php if ($p === $page): ?>
        <span class="is-current"><?= $p ?></span>
        <?php else: ?>
        <a href="?<?= qs(['page' => $p]) ?>"><?= $p ?></a>
        <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
        <a href="?<?= qs(['page' => $page + 1]) ?>">Next &#8250;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
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
