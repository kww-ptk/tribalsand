<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

// KPI counts
$today  = db_query("SELECT COUNT(*) AS cnt FROM submissions WHERE created_at::date = CURRENT_DATE")->fetch()['cnt'];
$week   = db_query("SELECT COUNT(*) AS cnt FROM submissions WHERE created_at >= NOW() - INTERVAL '7 days'")->fetch()['cnt'];
$total  = db_query("SELECT COUNT(*) AS cnt FROM submissions")->fetch()['cnt'];

// Recent 10 submissions
$recent = db_query(
    "SELECT s.*, r.name AS room_name
     FROM submissions s
     LEFT JOIN rooms r ON r.id = s.room_id
     ORDER BY s.created_at DESC LIMIT 10"
)->fetchAll();

$form_mode = setting('form_mode', 'enquiry');

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Dashboard</h1>
  <div class="actions">
    <a href="/admin/submissions.php" class="btn-outline btn-sm">All Submissions</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-card__label">New Today</div>
    <div class="kpi-card__value"><?= e($today) ?></div>
    <div class="kpi-card__sub">submissions received today</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__label">This Week</div>
    <div class="kpi-card__value"><?= e($week) ?></div>
    <div class="kpi-card__sub">last 7 days</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__label">Total</div>
    <div class="kpi-card__value"><?= e($total) ?></div>
    <div class="kpi-card__sub">all time</div>
  </div>
</div>

<!-- Form mode notice -->
<div class="alert alert--info" style="margin-bottom:24px">
  <strong>Form mode:</strong> <?= e(ucfirst($form_mode)) ?> —
  <a href="/admin/settings.php">Change in Settings</a>
</div>

<!-- Recent submissions -->
<div class="card">
  <div class="card__head">
    <span class="card__title">Recent Submissions</span>
    <a href="/admin/submissions.php" class="btn-sm btn-outline">View all</a>
  </div>
  <div class="card__body">
    <?php if (empty($recent)): ?>
    <p style="padding:24px;color:var(--muted);text-align:center">No submissions yet.</p>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Name</th>
          <th>Email</th>
          <th>Room</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $row): ?>
        <tr>
          <td class="text-muted"><?= e($row['id']) ?></td>
          <td>
            <?php
            $badge = match($row['type']) {
                'enquiry' => 'badge--blue',
                'contact' => 'badge--green',
                'agency'  => 'badge--orange',
                default   => 'badge--grey',
            };
            ?>
            <span class="badge <?= $badge ?>"><?= e($row['type']) ?></span>
          </td>
          <td><?= e($row['guest_name']) ?></td>
          <td class="text-muted"><?= e($row['guest_email']) ?></td>
          <td class="text-muted"><?= e($row['room_name'] ?? '—') ?></td>
          <td class="text-muted"><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
          <td>
            <a href="/admin/submission-view.php?id=<?= e($row['id']) ?>" class="btn-sm btn-outline">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
