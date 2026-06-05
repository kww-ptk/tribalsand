<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$filter_action = trim($_GET['action_filter'] ?? '');
$page          = max(1, (int)($_GET['p'] ?? 1));
$per_page      = 50;
$offset        = ($page - 1) * $per_page;

$where  = $filter_action ? "WHERE l.action = :af" : '';
$params = $filter_action ? [':af' => $filter_action] : [];

$total = (int)db_query(
    "SELECT COUNT(*) FROM admin_audit_log l $where",
    $params
)->fetchColumn();

$logs = db_query(
    "SELECT l.*, a.email AS admin_email
     FROM admin_audit_log l
     LEFT JOIN admin_users a ON a.id = l.admin_id
     $where
     ORDER BY l.created_at DESC
     LIMIT $per_page OFFSET $offset",
    $params
)->fetchAll();

$actions = db_query(
    "SELECT DISTINCT action FROM admin_audit_log ORDER BY action ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$pages = (int)ceil($total / $per_page);

$pageTitle  = 'Audit Log';
$activeMenu = 'audit';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Audit Log</h1>
  <span style="color:var(--muted);font-size:13px"><?= number_format($total) ?> event<?= $total !== 1 ? 's' : '' ?></span>
</div>

<!-- Filter bar -->
<form method="GET" action="/admin/audit.php" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <select name="action_filter" style="height:36px;padding:0 10px;border:1px solid var(--border);border-radius:5px;font-size:13px;min-width:180px">
    <option value="">All actions</option>
    <?php foreach ($actions as $a): ?>
    <option value="<?= e($a) ?>" <?= $filter_action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-secondary" style="height:36px;padding:0 16px">Filter</button>
  <?php if ($filter_action): ?>
  <a href="/admin/audit.php" style="font-size:13px;color:var(--muted)">&#10005; Clear</a>
  <?php endif; ?>
</form>

<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$logs): ?>
    <p style="padding:32px;text-align:center;color:var(--muted)">No audit events yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Admin</th>
            <th>Action</th>
            <th>Target</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $row): ?>
          <tr>
            <td style="white-space:nowrap;color:var(--muted);font-size:12px"><?= e(date('d M Y H:i', strtotime($row['created_at']))) ?></td>
            <td style="font-size:13px"><?= e($row['admin_email'] ?? '—') ?></td>
            <td><code style="font-size:12px;background:var(--surface);padding:2px 6px;border-radius:4px"><?= e($row['action']) ?></code></td>
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
  </div>
</div>

<?php if ($pages > 1): ?>
<div style="display:flex;gap:8px;margin-top:20px;align-items:center;flex-wrap:wrap">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?p=<?= $i ?><?= $filter_action ? '&action_filter=' . urlencode($filter_action) : '' ?>"
     style="padding:6px 12px;border:1px solid <?= $i===$page?'#0b6273':'var(--border)' ?>;border-radius:5px;font-size:13px;color:<?= $i===$page?'#0b6273':'inherit' ?>;text-decoration:none;font-weight:<?= $i===$page?'700':'400' ?>">
    <?= $i ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
