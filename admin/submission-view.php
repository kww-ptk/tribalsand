<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$sub = db_query(
    "SELECT s.*, r.name AS room_name, r.slug AS room_slug
     FROM submissions s
     LEFT JOIN rooms r ON r.id = s.room_id
     WHERE s.id = :id",
    [':id' => $id]
)->fetch();

if (!$sub) {
    http_response_code(404);
    $pageTitle = '404'; $activeMenu = 'submissions';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Submission not found. <a href="/admin/submissions.php">Back to inbox</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db_query('DELETE FROM submissions WHERE id = :id', [':id' => $id]);
    header('Location: /admin/submissions.php');
    exit;
}

$badge = match($sub['type']) {
    'enquiry' => 'badge--blue',
    'contact' => 'badge--green',
    'agency'  => 'badge--orange',
    default   => 'badge--grey',
};

$payload = json_decode($sub['payload_json'] ?? '{}', true) ?: [];

$pageTitle  = 'Submission #' . $id;
$activeMenu = 'submissions';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Submission #<?= e($id) ?> <span class="badge <?= $badge ?>" style="vertical-align:middle"><?= e($sub['type']) ?></span></h1>
  <div class="actions">
    <a href="/admin/submissions.php" class="btn-outline btn-sm">← Inbox</a>
    <a href="mailto:<?= e($sub['guest_email']) ?>?subject=Re: Your enquiry — Tribal Sand"
       class="btn-primary btn-sm">Reply via Email</a>
  </div>
</div>

<!-- Guest details -->
<div class="card">
  <div class="card__head"><span class="card__title">Guest Details</span></div>
  <div class="card__body" style="padding:20px">
    <div class="detail-grid">
      <div>
        <div class="detail-item__label">Name</div>
        <div class="detail-item__value"><?= e($sub['guest_name'] ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">Email</div>
        <div class="detail-item__value"><a href="mailto:<?= e($sub['guest_email']) ?>"><?= e($sub['guest_email'] ?? '—') ?></a></div>
      </div>
      <div>
        <div class="detail-item__label">Phone</div>
        <div class="detail-item__value"><?= e($sub['guest_phone'] ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">Submitted</div>
        <div class="detail-item__value"><?= e(date('d M Y, H:i', strtotime($sub['created_at']))) ?></div>
      </div>

      <?php if ($sub['room_name']): ?>
      <div>
        <div class="detail-item__label">Room</div>
        <div class="detail-item__value">
          <a href="/room.php?slug=<?= e($sub['room_slug']) ?>" target="_blank"><?= e($sub['room_name']) ?></a>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($sub['check_in']): ?>
      <div>
        <div class="detail-item__label">Check-in</div>
        <div class="detail-item__value"><?= e(date('d M Y', strtotime($sub['check_in']))) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($sub['check_out']): ?>
      <div>
        <div class="detail-item__label">Check-out</div>
        <div class="detail-item__value"><?= e(date('d M Y', strtotime($sub['check_out']))) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($sub['guests_adults'] || $sub['guests_children']): ?>
      <div>
        <div class="detail-item__label">Guests</div>
        <div class="detail-item__value">
          <?= e($sub['guests_adults']) ?> adult<?= $sub['guests_adults'] != 1 ? 's' : '' ?>
          <?php if ($sub['guests_children']): ?>
          · <?= e($sub['guests_children']) ?> child<?= $sub['guests_children'] != 1 ? 'ren' : '' ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php foreach ($payload as $k => $v): ?>
      <div>
        <div class="detail-item__label"><?= e(ucwords(str_replace('_', ' ', $k))) ?></div>
        <div class="detail-item__value"><?= e($v) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($sub['message']): ?>
    <div style="margin-top:20px">
      <div class="detail-item__label" style="margin-bottom:6px">Message</div>
      <div style="background:var(--bg);border-radius:6px;padding:14px 16px;font-size:13.5px;line-height:1.6;white-space:pre-wrap"><?= e($sub['message']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tracking -->
<div class="card">
  <div class="card__head"><span class="card__title">Tracking &amp; Source</span></div>
  <div class="card__body" style="padding:20px">
    <div class="detail-grid">
      <div>
        <div class="detail-item__label">Source Page</div>
        <div class="detail-item__value" style="word-break:break-all;font-size:12.5px"><?= e($sub['source_page'] ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">Referrer</div>
        <div class="detail-item__value" style="word-break:break-all;font-size:12.5px"><?= e($sub['referrer'] ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">UTM Source</div>
        <div class="detail-item__value"><?= e($sub['utm_source']   ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">UTM Medium</div>
        <div class="detail-item__value"><?= e($sub['utm_medium']   ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">UTM Campaign</div>
        <div class="detail-item__value"><?= e($sub['utm_campaign'] ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">UTM Term</div>
        <div class="detail-item__value"><?= e($sub['utm_term']     ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">IP Address</div>
        <div class="detail-item__value"><?= e($sub['ip_address']   ?? '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">User Agent</div>
        <div class="detail-item__value" style="font-size:11.5px;word-break:break-all"><?= e($sub['user_agent'] ?? '—') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Delete -->
<div class="card" style="border:1.5px solid var(--red)">
  <div class="card__head"><span class="card__title" style="color:var(--red)">Danger Zone</span></div>
  <div class="card__body" style="padding:20px">
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Permanently delete this submission. Cannot be undone.</p>
    <form method="POST" action="/admin/submission-view.php?id=<?= $id ?>"
          onsubmit="return confirm('Delete this submission permanently?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn-danger btn-sm">Delete Submission</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
