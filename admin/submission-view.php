<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$sub = db_query(
    "SELECT s.*, r.name AS room_name, r.slug AS room_slug, t.name AS tour_name, t.slug AS tour_slug
     FROM submissions s
     LEFT JOIN rooms r ON r.id = s.room_id
     LEFT JOIN tours t ON t.id = s.tour_id
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

require_once __DIR__ . '/../includes/booking.php'; // make_manage_url()

// Flash (set by the convert handler on redirect)
$flash = $_SESSION['sub_flash'] ?? null;
unset($_SESSION['sub_flash']);

// Is this submission already converted to a hold?
$linked_hold = fetch_hold_by_submission($id);

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

<?php if ($flash): ?>
<div class="card" style="border-left:4px solid <?= $flash['type']==='error' ? 'var(--red,#dc2626)' : 'var(--green,#16a34a)' ?>;margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($flash['msg']) ?></div>
</div>
<?php endif; ?>

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
          <a href="/<?= e($sub['room_slug']) ?>" target="_blank"><?= e($sub['room_name']) ?></a>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($sub['tour_name'])): ?>
      <div>
        <div class="detail-item__label">Tour / Activity</div>
        <div class="detail-item__value">
          <a href="/activities.php" target="_blank"><?= e($sub['tour_name']) ?></a>
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

<!-- Convert to hold -->
<div class="card">
  <div class="card__head"><span class="card__title">Convert to Hold</span></div>
  <div class="card__body" style="padding:20px">
  <?php if ($linked_hold):
      $lh_code = $linked_hold['access_code'] ?? '';
      $lh_link = make_manage_url((int)$linked_hold['id']);
      $lh_badge = match($linked_hold['status']) {
          'pending' => 'badge--blue', 'confirmed' => 'badge--green',
          'cancelled' => 'badge--red', 'expired' => 'badge--grey', default => 'badge--grey',
      };
  ?>
    <p style="margin:0 0 12px;font-size:14px">
      Converted to
      <a href="/admin/holds.php"><strong>Hold #<?= (int)$linked_hold['id'] ?></strong></a>
      <span class="badge <?= $lh_badge ?>" style="vertical-align:middle"><?= e($linked_hold['status']) ?></span>
      — <?= e($linked_hold['room_name']) ?>,
      <?= e(date('d M Y', strtotime($linked_hold['check_in']))) ?> → <?= e(date('d M Y', strtotime($linked_hold['check_out']))) ?>
    </p>
    <div style="font-size:13px;color:var(--muted)">
      Booking code: <strong style="font-family:monospace;letter-spacing:1px;color:var(--text,#111)"><?= e($lh_code ?: '—') ?></strong>
      <?php if ($lh_link): ?>
      <button type="button" class="copy-link" data-link="<?= e($lh_link) ?>"
              style="margin-left:6px;font-size:11px;padding:1px 7px;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#fff">Copy portal link</button>
      <?php endif; ?>
    </div>
  <?php else:
      $ru_options = fetch_room_unit_options();
      // Preselect the first active unit of the submission's room, if any
      $prefill_unit = 0;
      foreach ($ru_options as $o) { if ((int)$o['room_id'] === (int)$sub['room_id']) { $prefill_unit = (int)$o['unit_id']; break; } }
  ?>
    <?php if (!$ru_options): ?>
      <p style="margin:0;font-size:14px;color:var(--muted)">No availability units are set up yet, so a hold can't be created. Add units to a room first (Rooms admin).</p>
    <?php else: ?>
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Creates a 24h hold from this enquiry, generates a booking code, and blocks the dates. Availability is not checked — you control overlaps.</p>
    <form method="POST" action="/admin/submission-view?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="convert">
      <div class="detail-grid">
        <div>
          <div class="detail-item__label">Room / Unit</div>
          <select name="unit_id" required style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
            <option value="">— select —</option>
            <?php foreach ($ru_options as $o): ?>
            <option value="<?= (int)$o['unit_id'] ?>" <?= (int)$o['unit_id'] === $prefill_unit ? 'selected' : '' ?>>
              <?= e($o['room_name']) ?> — <?= e($o['unit_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="detail-item__label">Check-in</div>
          <input type="date" name="check_in" required value="<?= e($sub['check_in'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Check-out</div>
          <input type="date" name="check_out" required value="<?= e($sub['check_out'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest name</div>
          <input type="text" name="guest_name" required value="<?= e($sub['guest_name'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest email</div>
          <input type="email" name="guest_email" required value="<?= e($sub['guest_email'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
      </div>
      <?php if (!empty($sub['guest_phone']) || !empty($sub['guests_adults']) || !empty($sub['guests_children'])): ?>
      <p style="margin:12px 0 0;font-size:12px;color:var(--muted)">
        For reference (not stored on the hold):
        <?= !empty($sub['guest_phone']) ? 'phone ' . e($sub['guest_phone']) . '; ' : '' ?>
        <?= (int)($sub['guests_adults'] ?? 0) ?> adult(s)<?= (int)($sub['guests_children'] ?? 0) ? ', ' . (int)$sub['guests_children'] . ' child(ren)' : '' ?>
      </p>
      <?php endif; ?>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:16px"
              onclick="return confirm('Create a hold from this enquiry?')">Create Hold</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>

<!-- Delete -->
<div class="card" style="border:1.5px solid var(--red)">
  <div class="card__head"><span class="card__title" style="color:var(--red)">Danger Zone</span></div>
  <div class="card__body" style="padding:20px">
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Permanently delete this submission. Cannot be undone.</p>
    <form method="POST" action="/admin/submission-view?id=<?= $id ?>"
          onsubmit="return confirm('Delete this submission permanently?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn-danger btn-sm">Delete Submission</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.copy-link');
  if (!b) return;
  var link = b.getAttribute('data-link');
  var done = function () { var t = b.textContent; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = t; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(link).then(done).catch(function () { window.prompt('Copy this portal link:', link); });
  } else {
    window.prompt('Copy this portal link:', link);
  }
});
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
