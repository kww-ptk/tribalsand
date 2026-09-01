<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_owner();

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
require_once __DIR__ . '/../includes/copy-link.php'; // copy_link_control()
require_once __DIR__ . '/../includes/submission-notes.php'; // internal notes thread (#15)
require_once __DIR__ . '/../includes/submission-payload.php'; // payload → display rows/sections

// Flash (set by the convert handler on redirect)
$flash = $_SESSION['sub_flash'] ?? null;
unset($_SESSION['sub_flash']);

// Is this submission already converted to a hold?
$linked_hold = fetch_hold_by_submission($id);

// Add internal note (staff-only conversation log)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    verify_csrf();
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Note is empty.'];
    } elseif (!submission_notes_supported()) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Notes are unavailable — run the add_submission_notes migration.'];
    } elseif (!add_submission_note($id, $_SESSION['admin_id'] ?? null, $body)) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Could not save the note. Please try again.'];
    }
    header('Location: /admin/submission-view?id=' . $id . '#notes');
    exit;
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db_query('DELETE FROM submissions WHERE id = :id', [':id' => $id]);
    header('Location: /admin/submissions.php');
    exit;
}

// Convert enquiry → hold (force-create; no availability check by design)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert') {
    verify_csrf();

    $redirect = '/admin/submission-view?id=' . $id;

    // Guard: don't create a second hold for the same submission
    if (fetch_hold_by_submission($id)) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'This enquiry is already converted to a hold.'];
        header('Location: ' . $redirect); exit;
    }

    $str      = fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $unit_id  = (int)$str($_POST['unit_id'] ?? '');
    $check_in = $str($_POST['check_in']  ?? '');
    $check_out= $str($_POST['check_out'] ?? '');
    $g_name   = $str($_POST['guest_name']  ?? '');
    $g_email  = $str($_POST['guest_email'] ?? '');

    $is_date  = fn($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    $unit_ok  = $unit_id > 0 && db_query("SELECT 1 FROM units WHERE id = :id AND is_active = TRUE", [':id' => $unit_id])->fetchColumn();

    $err = '';
    if (!$unit_ok)                                       $err = 'Please choose a valid room / unit.';
    elseif (!$is_date($check_in) || !$is_date($check_out)) $err = 'Please enter valid check-in and check-out dates.';
    elseif ($check_in >= $check_out)                    $err = 'Check-out must be after check-in.';
    elseif ($g_name === '')                             $err = 'Guest name is required.';
    elseif (!filter_var($g_email, FILTER_VALIDATE_EMAIL)) $err = 'A valid guest email is required.';

    if ($err) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => $err];
        header('Location: ' . $redirect); exit;
    }

    try {
        $hold_id = create_hold_with_block($unit_id, $id, $check_in, $check_out, $g_name, $g_email);
    } catch (Throwable $e) {
        error_log('[convert-to-hold] create failed: ' . $e->getMessage());
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Could not create the hold. Please try again.'];
        header('Location: ' . $redirect); exit;
    }
    // Hold created — an audit-log failure must NOT be reported as a creation failure.
    try {
        audit_log('hold.create_from_submission', 'hold', $hold_id,
                  "from submission #{$id} — {$g_name} {$check_in}→{$check_out}");
    } catch (Throwable $e) {
        error_log('[convert-to-hold] audit failed: ' . $e->getMessage());
    }
    $_SESSION['sub_flash'] = ['type' => 'success', 'msg' => "Hold #{$hold_id} created from this enquiry."];
    header('Location: ' . $redirect); exit;
}

$badge = match($sub['type']) {
    'enquiry' => 'badge--blue',
    'contact' => 'badge--green',
    'agency'  => 'badge--orange',
    'event'   => 'badge--purple',
    default   => 'badge--grey',
};

$payload = json_decode($sub['payload_json'] ?? '{}', true) ?: [];
$notes   = fetch_submission_notes($id);

// Trip Builder posts a nested document (guest/trip/departure/special/itinerary);
// every other form posts a flat scalar map. They render differently.
$is_trip_builder = submission_is_trip_builder($payload);
$tb_sections     = $is_trip_builder ? trip_builder_sections($payload)  : [];
$tb_itinerary    = $is_trip_builder ? trip_builder_itinerary($payload) : [];

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
    <a href="/admin/submissions.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Inbox</a>
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

      <?php if (!$is_trip_builder): foreach (submission_payload_rows($payload) as [$pl_label, $pl_value]): ?>
      <div>
        <div class="detail-item__label"><?= e($pl_label) ?></div>
        <div class="detail-item__value"><?= e($pl_value) ?: '—' ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <?php foreach ($tb_sections as $sec): ?>
    <div style="margin-top:22px">
      <div class="detail-item__label" style="margin-bottom:10px;color:var(--teal,#1E5C6B);font-weight:700"><?= e($sec['title']) ?></div>
      <div class="detail-grid">
        <?php foreach ($sec['rows'] as [$sec_label, $sec_value]): ?>
        <div>
          <div class="detail-item__label"><?= e($sec_label) ?></div>
          <div class="detail-item__value" style="white-space:pre-wrap"><?= e($sec_value) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($tb_itinerary): ?>
    <div style="margin-top:22px">
      <div class="detail-item__label" style="margin-bottom:10px;color:var(--teal,#1E5C6B);font-weight:700">Itinerary</div>
      <div style="background:var(--bg);border-radius:6px;padding:14px 16px">
        <?php foreach ($tb_itinerary as $tb_day): ?>
        <div style="margin-bottom:12px">
          <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:5px"><?= e($tb_day['label']) ?></div>
          <?php foreach ($tb_day['items'] as $tb_item): ?>
          <div style="font-size:13.5px;line-height:1.65;padding-left:12px"><?= e($tb_item) ?></div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($sub['message']): ?>
    <div style="margin-top:20px">
      <div class="detail-item__label" style="margin-bottom:6px">Message</div>
      <div style="background:var(--bg);border-radius:6px;padding:14px 16px;font-size:13.5px;line-height:1.6;white-space:pre-wrap"><?= e($sub['message']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Internal notes (staff-only conversation log, #15) -->
<div class="card" id="notes">
  <div class="card__head">
    <span class="card__title">Internal Notes</span>
    <span class="text-muted" style="font-size:12px">Staff only — never shown to the guest<?= $notes ? ' · ' . count($notes) . ' note' . (count($notes) === 1 ? '' : 's') : '' ?></span>
  </div>
  <div class="card__body" style="padding:20px">
    <?php if (!submission_notes_supported()): ?>
      <p class="text-muted" style="margin:0;font-size:13px">Notes are unavailable on this deployment. Run the <code>add_submission_notes.sql</code> migration to enable them.</p>
    <?php else: ?>
      <div class="notes-thread">
        <?php if (!$notes): ?>
          <p class="notes-empty">No notes yet. Add the first one below — useful for logging calls, follow-ups and internal context.</p>
        <?php else: foreach ($notes as $n):
          $author = trim((string)($n['author_name'] ?? '')) ?: (trim((string)($n['author_email'] ?? '')) ?: 'Unknown');
          $initial = strtoupper(mb_substr($author, 0, 1)); ?>
        <div class="note">
          <div class="note__avatar" aria-hidden="true"><?= e($initial) ?></div>
          <div class="note__main">
            <div class="note__head">
              <span class="note__author"><?= e($author) ?></span>
              <span class="note__time"><?= e(date('d M Y, H:i', strtotime($n['created_at']))) ?></span>
            </div>
            <div class="note__body"><?= nl2br(e($n['body'])) ?></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <form method="POST" action="/admin/submission-view?id=<?= $id ?>" class="notes-composer">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_note">
        <textarea name="body" rows="2" class="inp" placeholder="Add an internal note — a call summary, follow-up, or context for the team…" required></textarea>
        <div class="notes-composer__actions">
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add note</button>
        </div>
      </form>
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
    <?php copy_link_control((string)$lh_code, (string)$lh_link); ?>
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
          <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="svDates" data-dp-target="svCheckin" data-dp-placeholder="Select check-in date">Select check-in date</button>
          <input type="hidden" id="svCheckin" name="check_in" value="<?= e($sub['check_in'] ?? '') ?>">
        </div>
        <div>
          <div class="detail-item__label">Check-out</div>
          <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="svDates" data-dp-target="svCheckout" data-dp-placeholder="Select check-out date">Select check-out date</button>
          <input type="hidden" id="svCheckout" name="check_out" value="<?= e($sub['check_out'] ?? '') ?>">
        </div>
        <div>
          <div class="detail-item__label">Guest name</div>
          <input type="text" name="guest_name" required value="<?= e($sub['guest_name'] ?? '') ?>" placeholder="Enter guest name"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest email</div>
          <input type="email" name="guest_email" required value="<?= e($sub['guest_email'] ?? '') ?>" placeholder="Enter guest email"
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

<?php include __DIR__ . '/_layout_end.php'; ?>
