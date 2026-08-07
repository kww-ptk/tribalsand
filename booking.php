<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/turnstile.php';
require_once __DIR__ . '/includes/checkin.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$ref     = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
$holdId  = verify_guest_ref($ref);
$hold    = null;
$error   = '';
$success = '';
$lookupError = '';
$can_cancel = false;
$cancel_blocked_reason = '';

if (!$holdId) {
    $error = 'This booking link is invalid or has expired. Please check your confirmation email.';
} else {
    $hold = fetch_hold_for_guest($holdId);

    if (!$hold) {
        $error = 'Booking not found.';
    }
}

// ── Co-guest self-service branch (opened via a per-guest ?g= link) ──────────
$gtoken = trim((string)($_GET['g'] ?? $_POST['g'] ?? ''));
if (!$holdId && $gtoken !== '' && ($gc = verify_guest_pass_token($gtoken)) !== false) {
    [$gHoldId, $gGuestId] = $gc;
    $gHold = fetch_hold_for_guest($gHoldId);
    $me = null;
    foreach (fetch_checkin_guests($gHoldId) as $row) { if ((int)$row['id'] === $gGuestId) { $me = $row; break; } }
    if ($gHold && checkin_required($gHold) && $me) {
        $hold = $gHold; $holdId = $gHoldId;
        $page_title = 'Guest check-in · Tribal Sand';
        $page_desc  = 'Complete your check-in.';
        $page_url   = site_url('booking');
        $noindex    = true;
        $hide_floating_chat = true; $portal_chrome = true;
        include __DIR__ . '/includes/head.php';
        include __DIR__ . '/includes/app/checkin-guest.php';   // expects $hold, $me, $gtoken
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

// ── Fallback: code-only lookup (when no valid ref resolved a hold) ──
if ((!$holdId || !$hold) && ($_POST['do'] ?? '') === 'lookup') {
    $now = time();
    $stamps = array_filter(
        (array)($_SESSION['bk_lookups'] ?? []),
        fn($t) => ($now - (int)$t) < 600
    );

    if (count($stamps) >= 8) {
        $lookupError = 'Too many attempts. Please wait a few minutes and try again.';
        $_SESSION['bk_lookups'] = array_values($stamps);
    } else {
        $stamps[] = $now;
        $_SESSION['bk_lookups'] = array_values($stamps);

        $found = resolve_booking_by_code_only($_POST['code'] ?? '');
        if ($found) {
            $hold   = $found;
            $holdId = (int)$hold['id'];
            $ref    = make_guest_ref($holdId);
            $error  = '';
        } else {
            $lookupError = 'We couldn’t find a booking with that code.';
        }
    }
}

// Determine cancel eligibility
if ($hold && !$error) {
    $status = $hold['status'];
    if ($status === 'pending') {
        $can_cancel = true;
    } elseif ($status === 'confirmed') {
        $days_until = (strtotime($hold['check_in']) - time()) / 86400;
        if ($days_until >= 7) {
            $can_cancel = true;
        } else {
            $cancel_blocked_reason = 'Online cancellation is only available more than 7 days before check-in. Please contact us directly to discuss your options.';
        }
    }
}

// Handle cancel POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hold && $can_cancel) {
    if (($_POST['action'] ?? '') === 'cancel') {
        db_query(
            "UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id AND status IN ('pending','confirmed')",
            [':id' => $holdId]
        );
        db_query(
            "DELETE FROM availability_blocks WHERE hold_id=:hid",
            [':hid' => $holdId]
        );

        $hold = fetch_hold_for_guest($holdId);

        send_hold_cancelled($hold, 'cancelled');
        send_admin_guest_cancelled($hold);

        $can_cancel = false;
        $success    = 'Your booking has been cancelled. A confirmation email is on its way.';
    }
}

// The lead's gate lifts once they've submitted their own part (submitted_at) even
// if co-guests are still pending — the booking still isn't "fully checked in".
$__ci = ($hold && checkin_supported()) ? fetch_checkin($holdId) : null;
$checkin_gate = $hold && checkin_required($hold) && !checkin_is_complete($hold) && empty($__ci['submitted_at']);

$status     = $hold['status'] ?? '';

$view = in_array($_GET['view'] ?? '', ['home','activities','messages','checkin'], true) ? $_GET['view'] : 'home';
// When check-in is outstanding, the portal is a hard gate: only the check-in
// flow and the message thread (escape hatch) are reachable.
if ($checkin_gate && !in_array($view, ['checkin','messages'], true)) $view = 'checkin';

$page_title = $hold
    ? 'Booking ' . e($ref) . ' · Tribal Sand'
    : 'Your Booking · Tribal Sand';
$page_desc  = 'View and manage your Tribal Sand booking.';
$page_url   = site_url('booking');
$noindex    = true; // private guest booking page — never index

include __DIR__ . '/includes/head.php';
?>

<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/css/portal-app.css') ?: time() ?>">

<style>
/* ── Booking page — error / code-lookup screen (shown when ref/code invalid) ── */
.bk-error-card {
  background:#fff;
  border-radius:10px;
  box-shadow:0 2px 16px rgba(16,47,58,.08);
  padding:48px 40px;
  text-align:center;
}
.bk-error-card .bk-icon  { font-size:48px; margin-bottom:16px; }
.bk-error-card h2 { margin:0 0 12px; font-family:'Cormorant Garamond',serif; font-size:26px; font-weight:400; }
.bk-error-card p  { color:#6b7280; line-height:1.65; margin:0 0 28px; }

.bk-lookup-form { max-width:380px; margin:0 auto; }
.bk-lookup-label {
  display:block;
  font-size:12px;
  font-weight:700;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:#9CA3AF;
  margin:14px 0 6px;
}
.bk-lookup-input {
  width:100%;
  padding:12px 14px;
  border:1px solid #d1d5db;
  border-radius:8px;
  font-size:16px; /* 16px min — prevents iOS focus auto-zoom */
  font-family:'Jost',sans-serif;
  box-sizing:border-box;
}
.bk-lookup-input:focus { outline:none; border-color:var(--teal,#1E5C6B); }
.bk-lookup-btn {
  width:100%;
  margin-top:12px;
  background:var(--teal-d,#102F3A);
  color:#fff;
  border:none;
  padding:13px 32px;
  font-size:13px;
  font-weight:700;
  letter-spacing:.08em;
  text-transform:uppercase;
  cursor:pointer;
  font-family:'Jost',sans-serif;
  border-radius:8px;
  transition:background .2s;
}
.bk-lookup-btn:hover { background:#1E5C6B; }
.bk-lookup-error {
  max-width:380px;
  margin:0 auto 16px;
  background:#fef2f2;
  border:1px solid #fecaca;
  color:#991b1b;
  border-radius:8px;
  padding:12px 16px;
  font-size:13px;
  line-height:1.5;
}

.bk-alert-success {
  background:#dcfce7;
  border:1px solid #86efac;
  border-radius:8px;
  padding:16px 20px;
  margin-bottom:24px;
  display:flex;
  align-items:flex-start;
  gap:12px;
  font-size:14px;
  color:#166534;
  line-height:1.6;
}

.bk-help {
  text-align:center;
  margin-top:36px;
  font-size:13px;
  color:#9ca3af;
}
.bk-help a { color:var(--teal,#1E5C6B); font-weight:600; }
.bk-help .sep { color:#d1d5db; margin:0 8px; }
</style>

<div class="pa-app">
  <?php
    $__first = trim((string)($hold['guest_name'] ?? ''));
    $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest';
    $__titles = ['home'=>'Karibu, ' . $__first, 'activities'=>'Activities', 'messages'=>'Messages', 'checkin'=>'Check-in'];
    $__t = $hold ? ($__titles[$view] ?? ('Karibu, ' . $__first)) : 'Your booking';
  ?>
  <div class="pa-topbar"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title"><?= e($__t) ?></div></div>
  <div class="pa-wrap" style="padding-top:16px">

    <?php if ($error): ?>
    <!-- ── Invalid ref / code lookup ── -->
    <div class="bk-error-card" style="text-align:left">
      <div style="text-align:center">
        <div class="bk-icon">&#128274;</div>
        <h2>Find your booking</h2>
        <p style="margin:0 0 28px">We couldn&rsquo;t open your booking from that link. Enter the booking code from your confirmation.</p>
      </div>

      <?php if ($lookupError): ?>
      <div class="bk-lookup-error"><?= e($lookupError) ?></div>
      <?php endif; ?>

      <form method="post" class="bk-lookup-form">
        <input type="hidden" name="do" value="lookup">
        <label class="bk-lookup-label" for="bkCode">Booking code</label>
        <input id="bkCode" type="text" name="code" required autocomplete="off"
               placeholder="e.g. K7QM2P4T" value="<?= e($_POST['code'] ?? '') ?>"
               style="text-transform:uppercase" class="bk-lookup-input">
        <button type="submit" class="bk-lookup-btn">Find my booking</button>
      </form>

      <p style="margin:24px 0 0;font-size:13px;color:#9ca3af;text-align:center">
        Need help?&nbsp;
        <a href="mailto:reservations@tribalsand.com" style="color:#1E5C6B">reservations@tribalsand.com</a>
      </p>
    </div>

    <?php elseif ($hold): ?>

    <?php if ($success): ?>
    <div class="bk-alert-success">
      <span style="font-size:20px;flex-shrink:0">&#10003;</span>
      <span><?= e($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($view === 'home'): ?>
    <?php include __DIR__ . '/includes/app/status-header.php'; ?>
    <?php endif; ?>

      <?php if ($view === 'home'): ?>
        <?php include __DIR__ . '/includes/app/home.php'; ?>
        <?php if ($can_cancel): ?>
        <div class="pa-card" style="padding:16px">
          <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
          <p style="margin:0 0 20px;font-size:14px;color:var(--pa-muted);line-height:1.65">If your plans have changed you can cancel now. The dates will be freed and you will receive a cancellation confirmation by email.</p>
          <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking? This cannot be undone.')">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="ref" value="<?= e($ref) ?>">
            <button type="submit" class="pa-btn pa-btn--danger">Cancel My Booking</button>
          </form>
        </div>
        <?php elseif ($cancel_blocked_reason): ?>
        <div class="pa-card" style="padding:16px">
          <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
          <p style="margin:0;font-size:14px;color:var(--pa-muted)"><?= e($cancel_blocked_reason) ?></p>
        </div>
        <?php endif; ?>
      <?php elseif ($view === 'activities'): ?>
        <?php include __DIR__ . '/includes/app/activities.php'; ?>
      <?php elseif ($view === 'messages'): ?>
        <?php include __DIR__ . '/includes/app/messages.php'; ?>
      <?php elseif ($view === 'checkin'): ?>
        <?php include __DIR__ . '/includes/app/checkin.php'; ?>
      <?php endif; ?>

    <?php endif; ?>

    <!-- ── Help footer ── -->
    <div class="bk-help">
      <p style="margin:0 0 6px">Questions about your booking?</p>
      <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a>
      <span class="sep">&middot;</span>
      <a href="tel:+254115115247">+254 115 115 247</a>
    </div>

  </div><!-- /pa-wrap -->
  <?php if ($hold): ?><?php include __DIR__ . '/includes/app/nav.php'; ?><?php endif; ?>
</div><!-- /pa-app -->

<?php if ($status === 'pending' && !empty($hold['expires_at'])): ?>
<script>
(function() {
  var expires = <?= strtotime($hold['expires_at']) * 1000 ?>;
  var el = document.getElementById('bkCountdown');
  if (!el) return;
  function tick() {
    var diff = Math.floor((expires - Date.now()) / 1000);
    if (diff <= 0) { el.textContent = 'Expiring…'; return; }
    var h = Math.floor(diff / 3600);
    var m = Math.floor((diff % 3600) / 60);
    var s = diff % 60;
    el.textContent = h + 'h ' + String(m).padStart(2,'0') + 'm ' + String(s).padStart(2,'0') + 's';
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
<?php endif; ?>

<script src="/js/booking-manage.js?v=<?= @filemtime(__DIR__ . '/js/booking-manage.js') ?: time() ?>" defer></script>

<?php $hide_floating_chat = true; // portal is app-like; suppress the site chat bubble (it overlaps the bottom nav) ?>
<?php $portal_chrome = true;      // suppress marketing footer + cookie banner; keep the success-modal JS ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
