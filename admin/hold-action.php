<?php
declare(strict_types=1);
/**
 * The Confirm / Decline links in the staff "New Hold Request" email.
 *
 * GET shows a confirmation screen — nothing happens on the click itself (a link
 * scanner or a stray tap must never confirm a booking or email a guest). The
 * screen carries the "Email the guest" choice, defaulting by the same rule as
 * the workspace (off for OTA relay / internal / missing addresses). POST (CSRF +
 * the same HMAC token + property scope) performs the action.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/bookings.php';   // financial ledger snapshot
require_once __DIR__ . '/../includes/acct.php';       // invoice-at-confirmation / credit-on-cancel hooks
require_once __DIR__ . '/../includes/hold-groups.php'; // a multi-room request is confirmed / declined as one
require_once __DIR__ . '/../includes/icons.php';

// Store intended URL so admin lands here after login if session expired
session_init();
if (empty($_SESSION['admin_id'])) {
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: /admin/login.php');
    exit;
}

require_login();
require_bookings();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$src    = $isPost ? $_POST : $_GET;
$id     = (int)($src['id'] ?? 0);
$action = trim((string)($src['action'] ?? ''));
$token  = trim((string)($src['t'] ?? ''));

// Validate inputs
if (!$id || !in_array($action, ['confirm', 'decline'], true) || !$token) {
    http_response_code(400);
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Invalid action link — missing required parameters.'];
    header('Location: /admin/holds.php');
    exit;
}

// Verify HMAC token
if (!verify_hold_token($id, $action, $token)) {
    http_response_code(403);
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Invalid or tampered action link. Please use the buttons in admin instead.'];
    header('Location: /admin/holds.php');
    exit;
}

// Fetch hold with room/unit names for email
$hold = db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, v.name AS venue_name
     FROM holds h
     JOIN units u ON u.id = h.unit_id
     JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
     LEFT JOIN venues v ON v.id = r.venue_id
     WHERE h.id = :id",
    [':id' => $id]
)->fetch();

if (!$hold) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Hold #{$id} not found."];
    header('Location: /admin/holds.php');
    exit;
}

// Property scope — a scoped account (reception) must not act on another
// property's hold by posting a foreign id. Owner passes unconditionally.
if (!staff_can_hold($id)) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Hold #{$id} isn’t one of your properties."];
    header('Location: /admin/holds.php');
    exit;
}

$status  = $hold['status'];
$allowed = $action === 'confirm' ? $status === 'pending' : in_array($status, ['pending', 'confirmed'], true);
if (!$allowed) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Hold #{$id} is already {$status} — no action taken."];
    header('Location: /admin/holds.php');
    exit;
}

if ($isPost) {
    verify_csrf();
    $email = email_guest_choice_posted($hold);
    if ($action === 'confirm') {
        $__g = hold_group_confirm($id, (int)($_SESSION['admin_id'] ?? 0) ?: null);
        $__mail = $__g['mail_row'] ? hold_email_after_action('confirm', $__g['mail_row'], $email, 'confirm_link') : '';
        foreach ($__g['ids'] as $__id) audit_log('hold.confirm', 'hold', $__id, "via email link — {$hold['guest_name']} {$hold['check_in']}→{$hold['check_out']}" . ($email ? '' : ' (guest not emailed)'));
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => (count($__g['ids']) > 1 ? 'All ' . count($__g['ids']) . " rooms of hold #{$id}'s request confirmed" : "Hold #{$id} confirmed")
            . ' — ' . $__mail . $__g['acct']];
    } else {
        $__g = hold_group_cancel($id, (int)($_SESSION['admin_id'] ?? 0) ?: null);
        $__mail = $__g['mail_row'] ? hold_email_after_action('cancel', $__g['mail_row'], $email, 'decline_link') : '';
        foreach ($__g['ids'] as $__id) audit_log('hold.decline', 'hold', $__id, "via email link — {$hold['guest_name']} {$hold['check_in']}→{$hold['check_out']}" . ($email ? '' : ' (guest not emailed)'));
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => (count($__g['ids']) > 1 ? 'All ' . count($__g['ids']) . " rooms of hold #{$id}'s request declined" : "Hold #{$id} declined")
            . ' — dates freed, ' . $__mail . $__g['acct']];
    }
    header('Location: /admin/holds.php');
    exit;
}

// ── GET: the confirmation screen ─────────────────────────────────────────
$rooms     = hold_group_rows($id);
$isConfirm = $action === 'confirm';
$pageTitle  = $isConfirm ? 'Confirm booking' : 'Decline booking';
$activeMenu = 'holds';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1><?= e($pageTitle) ?></h1></div>

<div class="card" style="max-width:640px">
  <div class="card__body" style="padding:22px">
    <div class="detail-grid">
      <div><div class="detail-item__label">Guest</div><div class="detail-item__value"><?= e($hold['guest_name'] ?: '—') ?></div></div>
      <div><div class="detail-item__label">Email</div><div class="detail-item__value"><?= e($hold['guest_email'] ?: '—') ?></div></div>
      <div><div class="detail-item__label">Property</div><div class="detail-item__value"><?= e(trim(($hold['venue_name'] ?? '') . ' · ' . hold_group_label($rooms ?: [$hold]), ' ·')) ?></div></div>
      <div><div class="detail-item__label">Dates</div><div class="detail-item__value"><?= e(date('j M Y', strtotime((string)$hold['check_in']))) ?> → <?= e(date('j M Y', strtotime((string)$hold['check_out']))) ?></div></div>
    </div>
    <?php if (count($rooms) > 1): ?>
    <p class="text-muted" style="font-size:13px;margin:14px 0 0">This is a multi-room request — <?= $isConfirm ? 'confirming' : 'declining' ?> applies to all <?= count($rooms) ?> rooms, with one email.</p>
    <?php endif; ?>

    <form method="POST" action="/admin/hold-action.php" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:14px;align-items:flex-start">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="<?= e($action) ?>">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <?= email_guest_toggle($hold, $isConfirm ? 'confirm' : 'cancel') ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" class="<?= $isConfirm ? 'btn-primary' : 'btn-danger' ?> btn-sm"><?= admin_icon($isConfirm ? 'check' : 'x', 15) ?> <?= $isConfirm ? 'Confirm booking' : 'Decline booking' ?></button>
        <a href="/admin/booking.php?hold=<?= (int)$id ?>" class="btn-outline btn-sm">Open the booking instead</a>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
