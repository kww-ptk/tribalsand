<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/turnstile.php';

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
    $hold = db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug
         FROM holds h
         JOIN units u ON u.id = h.unit_id
         JOIN rooms r ON r.id = u.room_id
         WHERE h.id = :id",
        [':id' => $holdId]
    )->fetch();

    if (!$hold) {
        $error = 'Booking not found.';
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
    } elseif (!verify_captcha($_POST['cf-turnstile-response'] ?? '', client_ip())) {
        $lookupError = 'Security check failed. Please try again.';
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

        $hold = db_query(
            "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug
             FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = u.room_id
             WHERE h.id = :id",
            [':id' => $holdId]
        )->fetch();

        send_hold_cancelled($hold, 'cancelled');
        send_admin_guest_cancelled($hold);

        $can_cancel = false;
        $success    = 'Your booking has been cancelled. A confirmation email is on its way.';
    }
}

$status     = $hold['status'] ?? '';
$badge_text = match($status) {
    'pending'   => 'Pending Confirmation',
    'confirmed' => 'Confirmed',
    'cancelled' => 'Cancelled',
    'expired'   => 'Expired',
    default     => ucfirst($status),
};
$badge_color = match($status) {
    'pending'   => '#92400e',
    'confirmed' => '#166534',
    'cancelled' => '#991b1b',
    'expired'   => '#6b7280',
    default     => '#6b7280',
};
$badge_bg = match($status) {
    'pending'   => '#fef3c7',
    'confirmed' => '#dcfce7',
    'cancelled' => '#fee2e2',
    'expired'   => '#f3f4f6',
    default     => '#f3f4f6',
};

$page_title = $hold
    ? 'Booking ' . e($ref) . ' · Tribal Sand'
    : 'Your Booking · Tribal Sand';
$page_desc  = 'View and manage your Tribal Sand booking.';
$page_url   = site_url('booking');
$noindex    = true; // private guest booking page — never index

include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/header.php';
?>

<style>
/* ── Booking page ── */
.bk-page { background:#F5F1EB; min-height:70vh; padding:80px 0 100px; }
.bk-page .container { max-width:700px; margin:0 auto; padding:0 1.5rem; }

.bk-card {
  background:#fff;
  border-radius:10px;
  box-shadow:0 2px 16px rgba(16,47,58,.08);
  overflow:hidden;
  margin-bottom:20px;
}
.bk-card__header {
  background:var(--teal-d,#102F3A);
  padding:24px 32px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  flex-wrap:wrap;
  gap:14px;
}
.bk-card__ref-label {
  font-size:11px;
  font-weight:700;
  letter-spacing:.1em;
  text-transform:uppercase;
  color:rgba(212,176,122,.7);
  margin:0 0 4px;
}
.bk-card__ref {
  font-family:monospace;
  font-size:20px;
  font-weight:700;
  color:#fff;
  letter-spacing:.05em;
  margin:0;
}
.bk-badge {
  padding:6px 16px;
  border-radius:20px;
  font-size:13px;
  font-weight:700;
}
.bk-card__body { padding:32px; }
.bk-table { width:100%; border-collapse:collapse; }
.bk-table tr { border-bottom:1px solid #f3f4f6; }
.bk-table tr:last-child { border-bottom:none; }
.bk-table td { padding:13px 0; vertical-align:top; }
.bk-table td:first-child {
  width:120px;
  font-size:12px;
  font-weight:700;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:#9CA3AF;
}
.bk-table td:last-child { font-weight:600; color:#1a1a1a; }
.bk-expires { color:#b45309; }
.bk-confirmed-on { color:#166534; }

.bk-notice {
  padding:16px 32px;
  border-top:1px solid;
  font-size:13px;
  line-height:1.7;
}
.bk-notice--pending  { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.bk-notice--confirmed { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
.bk-notice--expired  { background:#f9fafb; border-color:#e5e7eb; color:#6b7280; }
.bk-notice--cancelled { background:#fef2f2; border-color:#fecaca; color:#991b1b; }

.bk-cancel-card { background:#fff; border-radius:10px; box-shadow:0 2px 16px rgba(16,47,58,.08); padding:28px 32px; margin-bottom:20px; }
.bk-cancel-card h3 { margin:0 0 8px; font-size:16px; font-family:'Cormorant Garamond',serif; font-weight:500; color:#1a1a1a; }
.bk-cancel-card p  { margin:0 0 20px; font-size:14px; color:#6b7280; line-height:1.65; }
.bk-btn-cancel {
  background:#dc2626;
  color:#fff;
  border:none;
  padding:12px 32px;
  font-size:13px;
  font-weight:700;
  letter-spacing:.05em;
  text-transform:uppercase;
  cursor:pointer;
  font-family:'Jost',sans-serif;
  transition:background .2s;
}
.bk-btn-cancel:hover { background:#b91c1c; }

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
  font-size:15px;
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

/* Hero strip */
.bk-hero {
  background:linear-gradient(rgba(16,47,58,.65),rgba(16,47,58,.75)),
             url('/images/Maya-Kobe-1-hero.webp') center/cover no-repeat;
  min-height:200px;
  display:flex;
  align-items:center;
  padding:80px 5vw 40px;
}
.bk-hero__inner { color:#fff; }
.bk-hero__eyebrow {
  font-size:11px;
  letter-spacing:.2em;
  text-transform:uppercase;
  color:#D4B07A;
  margin:0 0 10px;
}
.bk-hero__title {
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,5vw,3rem);
  font-weight:300;
  margin:0;
  color:#fff;
}
</style>

<!-- Hero -->
<div class="bk-hero">
  <div class="bk-hero__inner">
    <p class="bk-hero__eyebrow">Tribal Sand · Booking Management</p>
    <h1 class="bk-hero__title">Your Booking</h1>
  </div>
</div>

<div class="bk-page">
  <div class="container">

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
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:8px 0 4px"></div>
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

    <!-- ── Booking summary card ── -->
    <div class="bk-card">

      <!-- Header -->
      <div class="bk-card__header">
        <div>
          <p class="bk-card__ref-label">Booking Reference</p>
          <p class="bk-card__ref"><?= e($ref) ?></p>
        </div>
        <span class="bk-badge" style="background:<?= e($badge_bg) ?>;color:<?= e($badge_color) ?>">
          <?= e($badge_text) ?>
        </span>
      </div>

      <!-- Details table -->
      <div class="bk-card__body">
        <table class="bk-table">
          <tr>
            <td>Guest</td>
            <td><?= e($hold['guest_name']) ?></td>
          </tr>
          <?php if (!empty($hold['access_code'])): ?>
          <tr>
            <td>Booking code</td>
            <td style="font-family:monospace;letter-spacing:.08em"><?= e($hold['access_code']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td>Property</td>
            <td>
              <?= e($hold['room_name']) ?>
              <?php if ($hold['unit_name'] && $hold['unit_name'] !== 'Unit A'): ?>
              <span style="font-size:12px;color:#9ca3af;margin-left:6px">&middot; <?= e($hold['unit_name']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td>Check-in</td>
            <td><?= date('d M Y', strtotime($hold['check_in'])) ?></td>
          </tr>
          <tr>
            <td>Check-out</td>
            <td><?= date('d M Y', strtotime($hold['check_out'])) ?></td>
          </tr>
          <?php
            $nights = (strtotime($hold['check_out']) - strtotime($hold['check_in'])) / 86400;
          ?>
          <tr>
            <td>Duration</td>
            <td><?= (int)$nights ?> night<?= $nights !== 1 ? 's' : '' ?></td>
          </tr>

          <?php if ($status === 'pending' && $hold['expires_at']): ?>
          <tr>
            <td>Hold expires</td>
            <td class="bk-expires" id="bkCountdown">
              <?php
                $diff = strtotime($hold['expires_at']) - time();
                echo $diff > 0
                  ? gmdate('H\h i\m', $diff)
                  : 'Expiring soon';
              ?>
            </td>
          </tr>
          <?php elseif ($status === 'confirmed' && $hold['confirmed_at']): ?>
          <tr>
            <td>Confirmed</td>
            <td class="bk-confirmed-on"><?= date('d M Y', strtotime($hold['confirmed_at'])) ?></td>
          </tr>
          <?php endif; ?>
        </table>
      </div>

      <!-- Status notice -->
      <?php if ($status === 'pending'): ?>
      <div class="bk-notice bk-notice--pending">
        <strong>Awaiting confirmation</strong> — Our team will confirm or decline your hold within 24 hours. You will receive an email either way.
      </div>
      <?php elseif ($status === 'confirmed'): ?>
      <div class="bk-notice bk-notice--confirmed">
        <strong>Your booking is confirmed!</strong> Our team will be in touch with arrival details and further instructions. We look forward to welcoming you.
      </div>
      <?php elseif ($status === 'expired'): ?>
      <div class="bk-notice bk-notice--expired">
        This hold was not confirmed within the 24-hour window and has expired. Please <a href="/properties" style="color:#1E5C6B">browse our properties</a> to make a new request.
      </div>
      <?php elseif ($status === 'cancelled'): ?>
      <div class="bk-notice bk-notice--cancelled">
        This booking has been cancelled. <a href="/properties" style="color:#1E5C6B">Browse our properties</a> if you would like to make a new request.
      </div>
      <?php endif; ?>

    </div><!-- /bk-card -->

    <!-- ── Manage actions (change requests + add-ons) ── -->
    <?php if (in_array($status, ['pending', 'confirmed'], true)):
      try { $tours = fetch_published_tours(); } catch (Throwable $e) { $tours = []; }
      include __DIR__ . '/includes/booking-manage-actions.php';
    endif; ?>

    <!-- ── Cancel section ── -->
    <?php if ($can_cancel): ?>
    <div class="bk-cancel-card">
      <h3>Need to cancel?</h3>
      <p>If your plans have changed you can cancel now. The dates will be freed and you will receive a cancellation confirmation by email.</p>
      <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking? This cannot be undone.')">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <button type="submit" class="bk-btn-cancel">Cancel My Booking</button>
      </form>
    </div>

    <?php elseif ($cancel_blocked_reason): ?>
    <div class="bk-cancel-card">
      <h3>Need to cancel?</h3>
      <p><?= e($cancel_blocked_reason) ?></p>
      <p style="margin:0;font-size:14px;color:#6b7280">
        Email us at <a href="mailto:reservations@tribalsand.com" style="color:#1E5C6B">reservations@tribalsand.com</a>
        &nbsp;or call <a href="tel:+254115115247" style="color:#1E5C6B">+254 115 115 247</a>
      </p>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <!-- ── Help footer ── -->
    <div class="bk-help">
      <p style="margin:0 0 6px">Questions about your booking?</p>
      <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a>
      <span class="sep">&middot;</span>
      <a href="tel:+254115115247">+254 115 115 247</a>
    </div>

  </div>
</div>

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

<?php include __DIR__ . '/includes/footer.php'; ?>
