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

// ── Co-guest branch (opened via a per-guest ?g= link, or a ?ref= carrying a g-token) ──
$gtoken = trim((string)($_GET['g'] ?? $_POST['g'] ?? ''));
if ($gtoken === '' && $ref !== '' && verify_guest_ref($ref) === false) $gtoken = $ref; // internal nav carries the g-token in ref=
$isCoGuest = false;
$me        = null;   // the co-guest's checkin_guests row when one resolved
if (!$holdId && $gtoken !== '' && ($gc = verify_guest_pass_token($gtoken)) !== false) {
    [$gHoldId, $gGuestId] = $gc;
    $gHold = fetch_hold_for_guest($gHoldId);
    $me = null;
    foreach (fetch_checkin_guests($gHoldId) as $row) { if ((int)$row['id'] === $gGuestId) { $me = $row; break; } }
    if ($gHold && $me) {
        $meDone = (!checkin_required($gHold)) || (checkin_guest_passport_complete($me) && checkin_guest_waiver_signed($me));
        // Shared + this co-guest has finished check-in → give them the full portal.
        if (share_reservation_on($gHold) && $meDone) {
            $hold = $gHold; $holdId = $gHoldId; $error = '';   // co-guest gets the full portal — clear the earlier "invalid link" error
            $ref = $gtoken;             // the whole portal threads $ref; a g-token here makes every view work for the co-guest
            $isCoGuest = true;
        } elseif (checkin_required($gHold)) {
            // Not shared yet, or still needs to check in → the check-in screen (unchanged).
            $hold = $gHold; $holdId = $gHoldId;
            // Same share preview as the portal — this is the link a lead sends a
            // co-guest, so it is the one most often pasted into a chat.
            $__vn = trim((string)($gHold['venue_name'] ?? ''));
            $page_title = $__vn !== '' ? 'Check in for ' . $__vn . ' · Tribal Sand' : 'Guest check-in · Tribal Sand';
            $page_desc  = $__vn !== '' ? 'Complete your check-in for ' . $__vn . '.' : 'Complete your check-in.';
            $__sh = venue_share_image(isset($gHold['venue_id']) ? (int)$gHold['venue_id'] : null);
            if ($__sh !== '') $page_image = $__sh;
            $page_url = site_url('booking'); $noindex = true;
            $hide_floating_chat = true; $portal_chrome = true;
            include __DIR__ . '/includes/head.php';
            include __DIR__ . '/includes/app/checkin-guest.php';
            include __DIR__ . '/includes/footer.php';
            exit;
        }
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
if ($hold && !$error && !$isCoGuest) {
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

// ── Who is actually looking at this page? ───────────────────────────────────
// booking.php resolves a BOOKING; until now it never resolved an ACTOR, which is
// why a co-guest was greeted with the lead's name.
// Deliberately NOT resolve_portal_actor(): that calls checkin_ensure_lead_guest_id(),
// which INSERTs. Correct in a write endpoint, wrong on every page view — so this
// reads the lead row if it exists and writes nothing.
$actor = ['guest_id' => null, 'is_lead' => true, 'name' => '', 'first' => ''];
if ($hold) {
    $__leadRow = (!$isCoGuest && checkin_supported()) ? checkin_lead_guest($holdId) : null;
    $actor = portal_actor($__leadRow, $me, $isCoGuest, (string)($hold['guest_name'] ?? ''));
}

// The lead's gate lifts once they've submitted their own part (submitted_at) even
// if co-guests are still pending — the booking still isn't "fully checked in".
$__ci = ($hold && checkin_supported()) ? fetch_checkin($holdId) : null;
// Co-guests who reached the portal have already finished their own check-in, so the
// lead-centric gate never applies to them (it would force them into the lead wizard).
$checkin_gate = $hold && !$isCoGuest && checkin_required($hold) && !checkin_is_complete($hold) && empty($__ci['submitted_at']);

$status     = $hold['status'] ?? '';

// The check-in view is the LEAD's wizard — it renders the lead's passport number
// and signature. A co-guest on a shared booking has finished their own check-in
// via checkin-guest.php and has no business there, so it is not in their view set.
// (Writing was already blocked: the form posts ref=<g-token>, which
// checkin_auth_context() rejects. This closes the read side.)
$__views = ['home','calendar','requests','activities','messages'];
if (!$isCoGuest) $__views[] = 'checkin';
if (share_reservation_on($hold ?: [])) $__views[] = 'bill';
$view = in_array($_GET['view'] ?? '', $__views, true) ? $_GET['view'] : 'home';
// When check-in is outstanding, the portal is a hard gate: only the check-in
// flow and the message thread (escape hatch) are reachable.
if ($checkin_gate && !in_array($view, ['checkin','messages'], true)) $view = 'checkin';

// Share preview (WhatsApp / iMessage / Signal). og:image is served even though
// the page is noindex, so a shared access link gets a real preview — show the
// property the guest is actually staying at, not the site-wide default photo.
// The title used to embed the booking ref, which put the access token itself
// into the preview text of any chat the link was pasted into.
$__venueName = trim((string)($hold['venue_name'] ?? ''));
$page_title = $hold
    ? ($__venueName !== '' ? 'Your stay at ' . $__venueName . ' · Tribal Sand' : 'Your booking · Tribal Sand')
    : 'Your Booking · Tribal Sand';
$page_desc  = $__venueName !== ''
    ? 'View your booking at ' . $__venueName . ' — requests, messages and check-in.'
    : 'View and manage your Tribal Sand booking.';
$__share = $hold ? venue_share_image(isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : '';
if ($__share !== '') $page_image = $__share;   // else head.php's site default
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
.bk-error-card h2 { margin:0 0 12px; font-family:'Inter',system-ui,sans-serif; font-size:26px; font-weight:400; }
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
  font-family:inherit;
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
  font-family:inherit;
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
    // Co-guest-aware name source (portal_actor resolves the right person).
    $__first = $actor['first'] !== '' ? $actor['first'] : 'guest';
    // The greeting stays put on every tab — the header always says "Karibu, <name>".
    $__t = $hold ? ('Karibu, ' . $__first) : 'Your booking';
    $__homeUrl = '/booking.php?ref=' . urlencode($ref) . '&view=home';
  ?>
  <div class="pa-topbar">
    <div class="pa-topbar__inner">
      <?php if ($hold): ?>
      <a class="pa-topbar__brand" href="<?= e($__homeUrl) ?>" aria-label="Go to home">
        <div class="pa-topbar__eyebrow">Tribal Sand</div>
        <div class="pa-topbar__title"><?= e($__t) ?></div>
      </a>
      <?php else: ?>
      <div class="pa-topbar__brand">
        <div class="pa-topbar__eyebrow">Tribal Sand</div>
        <div class="pa-topbar__title"><?= e($__t) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($hold): ?><?php include __DIR__ . '/includes/app/nav.php'; ?><?php endif; ?>
    </div>
  </div>
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

    <?php if (in_array($view, ['home','calendar','requests'], true)): ?>
    <?php include __DIR__ . '/includes/app/status-header.php'; ?>
    <?php endif; ?>

      <?php if ($view === 'home'): ?>
        <?php include __DIR__ . '/includes/app/home.php'; ?>
      <?php elseif ($view === 'calendar'): ?>
        <?php include __DIR__ . '/includes/app/_trip.php'; ?>
      <?php elseif ($view === 'requests'): ?>
        <?php include __DIR__ . '/includes/app/_services.php'; ?>
      <?php elseif ($view === 'activities'): ?>
        <?php include __DIR__ . '/includes/app/activities.php'; ?>
      <?php elseif ($view === 'messages'): ?>
        <?php include __DIR__ . '/includes/app/messages.php'; ?>
      <?php elseif ($view === 'bill'): ?>
        <?php include __DIR__ . '/includes/app/bill.php'; ?>
      <?php elseif ($view === 'checkin'): ?>
        <?php include __DIR__ . '/includes/app/checkin.php'; ?>
      <?php endif; ?>

    <?php endif; ?>

  </div><!-- /pa-wrap -->

  <!-- ── Fixed help footer ── -->
  <div class="pa-help-footer">
    <strong>Questions about your booking?</strong>
    <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a>
    <span class="sep">&middot;</span>
    <a href="tel:+254115115247">+254 115 115 247</a>
  </div>
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
<!-- Non-native dropdowns: reuse the admin's styled <select> enhancer (progressive; native select stays for no-JS). -->
<script src="/admin/assets/admin-select.js?v=<?= @filemtime(__DIR__ . '/admin/assets/admin-select.js') ?: time() ?>" defer></script>

<?php $hide_floating_chat = true; // portal is app-like; suppress the site chat bubble (it overlaps the bottom nav) ?>
<?php $portal_chrome = true;      // suppress marketing footer + cookie banner; keep the success-modal JS ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
