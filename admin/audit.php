<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_owner();

$filter_action = trim($_GET['action_filter'] ?? '');
$pg = paginate_params();   // page / per (default 10) / q / offset / ajax

// ── WHERE: action filter + free-text search ──────────────────────────
$params = [];
$conds  = [];
if ($filter_action !== '') { $conds[] = 'l.action = :af'; $params[':af'] = $filter_action; }
$sw = search_where(
    ['l.action', "COALESCE(a.email,'')", "COALESCE(l.target_type,'')", "COALESCE(l.notes,'')"],
    $pg['q'], $params
);
if ($sw !== '') $conds[] = $sw;
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$base  = "FROM admin_audit_log l LEFT JOIN admin_users a ON a.id = l.admin_id $where";
$total = (int) db_query("SELECT COUNT(*) $base", $params)->fetchColumn();
$meta  = paginate_meta($total, $pg['page'], $pg['per']);

$logs = db_query(
    "SELECT l.*, a.email AS admin_email $base
     ORDER BY l.created_at DESC
     LIMIT {$meta['per']} OFFSET {$meta['offset']}",
    $params
)->fetchAll();

$actions = db_query("SELECT DISTINCT action FROM admin_audit_log ORDER BY action ASC")
    ->fetchAll(PDO::FETCH_COLUMN);

// ── Render the swappable body (card + pager) once, reuse for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$logs): ?>
    <p style="padding:32px;text-align:center;color:var(--muted)">No audit events match.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $row): ?>
          <tr>
            <td style="white-space:nowrap;color:var(--muted);font-size:12px"><?= e(date('d M Y H:i', strtotime($row['created_at']))) ?></td>
            <td style="font-size:13px"><?= e($row['admin_email'] ?? '—') ?></td>
            <td><code style="font-size:12px;background:var(--bg);padding:2px 6px;border-radius:4px"><?= e($row['action']) ?></code></td>
            <td style="font-size:13px;color:var(--muted)">
              <?php if ($row['target_type']): ?>
                <?= e($row['target_type']) ?><?= $row['target_id'] ? ' #' . e((string)$row['target_id']) : '' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:13px;max-width:320px;word-break:break-word"><?= e($row['notes'] ?: '—') ?></td>
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

$pageTitle  = 'Audit Log';
$activeMenu = 'audit';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Audit Log</h1>
  <span style="color:var(--muted);font-size:13px"><?= number_format($total) ?> event<?= $total !== 1 ? 's' : '' ?></span>
</div>

<!-- Action filter + search share one aligned control row -->
<div class="dt" data-dt>
  <div class="dt-controls">
<form method="GET" action="/admin/audit.php" class="filters" id="auditFilter">
  <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
  <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
  <div class="filter-field">
    <span>Action</span>
    <select name="action_filter" class="filter-select" aria-label="Filter by action" onchange="this.form.submit()">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?>
      <option value="<?= e($a) ?>" <?= $filter_action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filter_action): ?>
  <a href="/admin/audit.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
  <?php endif; ?>
</form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search action, admin, target or notes…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
