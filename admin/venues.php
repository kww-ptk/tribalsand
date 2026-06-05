<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$venues = db_query("SELECT v.*, (SELECT count(*) FROM rooms r WHERE r.venue_id = v.id) AS room_count FROM venues v ORDER BY sort_order")->fetchAll();

$pageTitle  = 'Properties';
$activeMenu = 'venues';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Properties</h1>
  <a href="/admin/venue-edit.php" class="btn-primary btn-sm">+ New Property</a>
</div>

<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$venues): ?>
    <p style="padding:32px;text-align:center;color:var(--muted)">No properties found.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Slug</th>
            <th>Location</th>
            <th>Rooms</th>
            <th>Published</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($venues as $v): ?>
          <tr>
            <td><?= e($v['name']) ?></td>
            <td><code style="font-size:12px;background:var(--surface);padding:2px 6px;border-radius:4px"><?= e($v['slug']) ?></code></td>
            <td style="color:var(--muted);font-size:13px"><?= e($v['location'] ?? '—') ?></td>
            <td><?= (int)$v['room_count'] ?></td>
            <td><?= $v['is_published'] ? '<span class="badge badge--green">Yes</span>' : '<span class="badge badge--grey">No</span>' ?></td>
            <td style="white-space:nowrap">
              <a href="/admin/venue-edit.php?id=<?= (int)$v['id'] ?>" class="btn-sm btn-outline">Edit</a>
              <a href="/<?= e($v['slug']) ?>" class="btn-sm btn-outline" target="_blank">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
