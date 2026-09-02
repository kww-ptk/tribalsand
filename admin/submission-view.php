<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_bookings();

$id  = (int)($_GET['id'] ?? 0);
$sub = db_query(
    "SELECT s.*, r.name AS room_name, r.slug AS room_slug, t.name AS tour_name, t.slug AS tour_slug
     FROM submissions s
     LEFT JOIN rooms r ON r.id = s.room_id
     LEFT JOIN tours t ON t.id = s.tour_id
     WHERE s.id = :id",
    [':id' => $id]
)->fetch();

// Out of scope reads as "not found" — the gate sits above every POST handler
// below, so status changes, notes, replies and convert are all covered by it.
if (!$sub || !submission_in_scope($id)) {
    http_response_code(404);
    $pageTitle = '404'; $activeMenu = 'submissions';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Submission not found. <a href="/admin/submissions.php">Back to inbox</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}

require_once __DIR__ . '/../includes/booking.php'; // make_manage_url()
require_once __DIR__ . '/../includes/copy-link.php'; // copy_link_control()
require_once __DIR__ . '/../includes/submission-notes.php'; // conversation thread (notes + replies)
require_once __DIR__ . '/../includes/submission-status.php'; // lead status pipeline
require_once __DIR__ . '/../includes/submission-payload.php'; // payload → display rows/sections
require_once __DIR__ . '/../includes/upsells.php';             // booking-flow add-ons
require_once __DIR__ . '/../includes/mail.php'; // send_admin_reply()

// Flash (set by the convert handler on redirect)
$flash = $_SESSION['sub_flash'] ?? null;
unset($_SESSION['sub_flash']);

// Is this submission already converted to a hold?
$linked_hold = fetch_hold_by_submission($id);

// Update lead status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    verify_csrf();
    $new = (string)($_POST['status'] ?? '');
    if (!submission_status_supported()) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Status tracking is unavailable — run the add_submission_status migration.'];
    } elseif (!submission_status_valid($new)) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Pick a valid status.'];
    } else {
        db_query('UPDATE submissions SET status = :st WHERE id = :id', [':st' => $new, ':id' => $id]);
        $_SESSION['sub_flash'] = ['type' => 'success', 'msg' => 'Status updated to “' . submission_status_label($new) . '”.'];
    }
    header('Location: /admin/submission-view?id=' . $id . '#thread');
    exit;
}

// Add a thread entry (internal note or a reply logged/sent to the guest)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    verify_csrf();
    $body      = trim((string)($_POST['body'] ?? ''));
    $kind      = ($_POST['kind'] ?? 'note') === 'reply' ? 'reply' : 'note';
    $sendEmail = !empty($_POST['send_email']) && $kind === 'reply';

    if ($body === '') {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'The message is empty.'];
    } elseif (!submission_notes_supported()) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'The thread is unavailable — run the add_submission_notes migration.'];
    } else {
        // Email the guest first (if asked) so a send failure is reflected in what we store + flash.
        $emailed = false; $emailErr = '';
        if ($sendEmail) {
            $res     = send_admin_reply($sub, $body);
            $emailed = !empty($res['ok']);
            $emailErr = (string)($res['error'] ?? '');
        }

        $admin      = current_admin();
        $authorName = $admin ? (trim((string)($admin['name'] ?? '')) ?: (string)($admin['email'] ?? '')) : 'Admin';
        $storedBody = $emailed ? "📧 Emailed to guest:\n" . $body : $body;

        if (!add_submission_note($id, $_SESSION['admin_id'] ?? null, $storedBody, $kind, $authorName)) {
            $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Could not save to the thread. Please try again.'];
        } elseif ($sendEmail && $emailed) {
            $_SESSION['sub_flash'] = ['type' => 'success', 'msg' => 'Reply saved and emailed to ' . ($sub['guest_email'] ?: 'the guest') . '.'];
        } elseif ($sendEmail && !$emailed) {
            $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Reply saved to the thread, but email NOT sent: ' . $emailErr];
        } else {
            $_SESSION['sub_flash'] = ['type' => 'success', 'msg' => $kind === 'reply' ? 'Reply logged to the thread.' : 'Note added.'];
        }
    }
    header('Location: /admin/submission-view?id=' . $id . '#thread');
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
    // Hold created — nothing below may report a failure of the hold itself.
    //
    // Add-ons the guest ticked during the enquiry become addon requests on the
    // new booking, so they show up in the guest portal and on the front desk.
    // Re-validated against what the room's property actually offers, so an
    // activity that has since been unpublished or moved is quietly dropped
    // rather than attached. upsell_attach_to_hold() never throws.
    $addonsMade = 0;
    try {
        $__payload = json_decode($sub['payload_json'] ?? '{}', true) ?: [];
        $__picked  = array_column(is_array($__payload['upsells'] ?? null) ? $__payload['upsells'] : [], 'id');
        if ($__picked) {
            $__venueId = (int)db_query(
                'SELECT r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id WHERE u.id = :u',
                [':u' => $unit_id]
            )->fetchColumn();
            $__items = upsell_validate_ids($__picked, $__venueId ?: null, 'enquiry');
            $addonsMade = upsell_attach_to_hold($hold_id, $__items, max(1, (int)($sub['guests_adults'] ?? 1)));
        }
    } catch (Throwable $e) {
        error_log('[convert-to-hold] upsell attach failed: ' . $e->getMessage());
    }

    try {
        audit_log('hold.create_from_submission', 'hold', $hold_id,
                  "from submission #{$id} — {$g_name} {$check_in}→{$check_out}"
                  . ($addonsMade ? " (+{$addonsMade} add-on" . ($addonsMade === 1 ? '' : 's') . ')' : ''));
    } catch (Throwable $e) {
        error_log('[convert-to-hold] audit failed: ' . $e->getMessage());
    }
    $_SESSION['sub_flash'] = ['type' => 'success', 'msg' => "Hold #{$hold_id} created from this enquiry."
        . ($addonsMade ? " {$addonsMade} add-on" . ($addonsMade === 1 ? '' : 's') . ' carried over.' : '')];
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
$status  = submission_status_supported() ? ((string)($sub['status'] ?? '') ?: submission_status_default()) : '';

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
  <h1>Submission #<?= e($id) ?>
    <span class="badge <?= $badge ?>" style="vertical-align:middle"><?= e($sub['type']) ?></span>
    <?php if ($status !== ''): ?><span class="badge <?= submission_status_badge($status) ?>" style="vertical-align:middle"><?= e(submission_status_label($status)) ?></span><?php endif; ?>
  </h1>
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

    <?php $__ups = submission_upsells($payload); if ($__ups): ?>
    <div style="margin-top:22px">
      <div class="detail-item__label" style="margin-bottom:10px;color:var(--teal,#1E5C6B);font-weight:700">
        Add-ons requested<?= $linked_hold ? '' : ' <span class="text-muted" style="font-weight:400">(attached to the booking when you convert this enquiry)</span>' ?>
      </div>
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($__ups as $__u): ?>
        <li style="margin-bottom:4px"><?= e($__u['name']) ?><?= $__u['price'] !== '' ? ' <span class="text-muted">— ' . e($__u['price']) . '</span>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

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

<!-- Lead status + conversation thread (staff notes and replies to the guest) -->
<div class="card" id="thread">
  <div class="card__head">
    <span class="card__title">Lead Status &amp; Conversation</span>
    <span class="text-muted" style="font-size:12px"><?= $notes ? count($notes) . ' entr' . (count($notes) === 1 ? 'y' : 'ies') : 'No entries yet' ?></span>
  </div>
  <div class="card__body" style="padding:20px">

    <!-- Status selector -->
    <?php if (!submission_status_supported()): ?>
      <p class="text-muted" style="margin:0 0 18px;font-size:13px">Lead status is unavailable. Run the <code>add_submission_status.sql</code> migration to enable the pipeline.</p>
    <?php else: ?>
    <form method="POST" action="/admin/submission-view?id=<?= $id ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:22px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_status">
      <label class="detail-item__label" style="margin:0">Status</label>
      <select name="status" class="inp" style="min-width:190px;max-width:220px">
        <?php foreach (submission_statuses() as $slug => $label): ?>
        <option value="<?= e($slug) ?>" <?= $status === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary btn-sm">Update status</button>
    </form>
    <?php endif; ?>

    <?php if (!submission_notes_supported()): ?>
      <p class="text-muted" style="margin:0;font-size:13px">The conversation thread is unavailable. Run the <code>add_submission_notes.sql</code> migration to enable it.</p>
    <?php else: ?>
      <div class="detail-item__label" style="margin-bottom:10px">Conversation &amp; Notes</div>
      <?php if (!$notes): ?>
        <p class="text-muted" style="font-size:13px;margin:0 0 18px">No entries yet. Leave a note for the team, or log / send a reply to the guest.</p>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px">
        <?php foreach ($notes as $n):
          [$nColor, $nBadge, $nLabel, $nGuest] = match ($n['kind'] ?? 'note') {
              'reply'       => ['var(--green,#16a34a)', 'badge--green', 'reply',       false], // staff → guest
              'guest_reply' => ['#0b6273',              'badge--blue',  'guest reply', true],  // guest → us
              default       => ['var(--border,#e5e7eb)', 'badge--grey', 'note',        false],
          };
          $author = trim((string)($n['frozen_author'] ?? ''))
                 ?: (trim((string)($n['author_name'] ?? ''))
                 ?: (trim((string)($n['author_email'] ?? '')) ?: ($nGuest ? 'Guest' : 'Admin')));
        ?>
        <div style="background:<?= $nGuest ? '#f0f9fa' : 'var(--bg,#f9fafb)' ?>;border-radius:6px;padding:12px 14px;border-left:3px solid <?= $nColor ?>">
          <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:6px;font-size:12px;color:var(--muted)">
            <span>
              <strong style="color:var(--text,#222)"><?= e($author) ?></strong>
              <span class="badge <?= $nBadge ?>" style="margin-left:6px"><?= e($nLabel) ?></span>
            </span>
            <span style="white-space:nowrap"><?= e(date('d M Y, H:i', strtotime($n['created_at']))) ?></span>
          </div>
          <div style="font-size:13.5px;line-height:1.6;white-space:pre-wrap"><?= e($n['body']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="/admin/submission-view?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_note">
        <textarea name="body" rows="4" class="inp inp--area" required
                  style="width:100%;box-sizing:border-box;min-height:104px;resize:vertical"
                  placeholder="Add a note for the team, or paste / write the reply to send the guest…"></textarea>
        <div style="display:flex;gap:16px;align-items:center;margin-top:10px;flex-wrap:wrap">
          <label style="font-size:13px;display:flex;align-items:center;gap:6px">
            <input type="radio" name="kind" value="note" checked id="kindNote"> Internal note
          </label>
          <label style="font-size:13px;display:flex;align-items:center;gap:6px">
            <input type="radio" name="kind" value="reply" id="kindReply"> Reply sent to guest
          </label>
          <label style="font-size:13px;display:flex;align-items:center;gap:6px;color:var(--muted)" id="sendEmailLabel">
            <input type="checkbox" name="send_email" value="1" id="sendEmail" disabled>
            📧 Also email this reply to <?= e($sub['guest_email'] ?: 'the guest') ?>
          </label>
          <button type="submit" class="btn-primary btn-sm" style="margin-left:auto"><?= admin_icon('plus', 15) ?> Add to thread</button>
        </div>
      </form>

      <script>
        // The "email the guest" checkbox only applies to a reply, so enable it
        // only when "Reply sent to guest" is selected.
        (function () {
          var note = document.getElementById('kindNote');
          var reply = document.getElementById('kindReply');
          var cb = document.getElementById('sendEmail');
          var lbl = document.getElementById('sendEmailLabel');
          if (!note || !reply || !cb || !lbl) return;
          function sync() {
            var on = reply.checked;
            cb.disabled = !on;
            if (!on) cb.checked = false;
            lbl.style.opacity = on ? '1' : '0.5';
          }
          note.addEventListener('change', sync);
          reply.addEventListener('change', sync);
          sync();
        })();
      </script>
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
