<?php
/**
 * Public "Reserve a Table" request form — per property.
 *   /reserve.php            → choose any published property
 *   /reserve.php?venue=zuri → preselect that property
 *
 * Request model: submitting creates a `pending` reservation (api/submit-reservation.php),
 * emails the guest an acknowledgement and alerts staff. Staff confirm/cancel in
 * /admin/reservations.php. Uses the shared styled datepicker (booking.css + datepicker.js)
 * — never a native date input. No-JS still submits (plain form POST → PRG).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';           // csrf_field(), session
require_once __DIR__ . '/includes/reservations.php';

session_init();

$venues = fetch_reservable_venues();

// Preselect from ?venue=<slug>
$slug        = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['venue'] ?? ''));
$preVenueId  = 0;
foreach ($venues as $v) { if ($v['slug'] === $slug) { $preVenueId = (int)$v['id']; break; } }

// Flash (validation errors + old input from a failed PRG submit)
$flash  = $_SESSION['reserve_flash'] ?? null;
unset($_SESSION['reserve_flash']);
$errors = $flash['errors'] ?? [];
$old    = $flash['old']    ?? [];
if ($preVenueId && empty($old['venue_id'])) $old['venue_id'] = $preVenueId;

$okRef  = isset($_GET['ok']) ? trim((string)$_GET['ok']) : '';
$slots  = reservation_slots();

/** Old value helper. */
$ov = fn(string $k, $default = '') => e((string)($old[$k] ?? $default));

$page_title   = 'Reserve a Table · Tribal Sand Kenya';
$page_desc    = 'Request a table at one of Tribal Sand’s coastal restaurants. Choose your property, date, time and party size — we’ll confirm within 24 hours.';
$page_url     = 'https://tribalsand.com/reserve.php';
$page_image   = asset_url('images/whitelogo11.png');
$page_booking = true;   // loads booking.css (datepicker styling) + datepicker.js
$noindex      = true;   // request form — not a landing page
?>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;}
a{text-decoration:none;color:inherit;}

.rz-hero{background:var(--teal-d);padding:0 6vw;height:250px;display:flex;align-items:flex-end;position:relative;overflow:hidden;}
.rz-hero::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,#1a4a56 0%,#2b6b7a 100%);opacity:.92;}
.rz-hero-in{position:relative;z-index:2;padding-bottom:2.4rem;max-width:760px;}
.rz-eyebrow{font-size:.56rem;letter-spacing:.32em;text-transform:uppercase;color:rgba(232,220,200,.55);margin-bottom:.8rem;}
.rz-hero h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,3.6vw,3rem);font-weight:300;color:#fff;line-height:1.15;}
.rz-hero h1 em{font-style:italic;color:var(--sand-lt);}

.rz-wrap{max-width:640px;margin:0 auto;padding:3rem 5vw 5rem;}
.rz-intro{font-size:.9rem;color:var(--mid);line-height:1.85;font-weight:300;margin-bottom:2.2rem;}

.rz-card{background:var(--white);border:1px solid var(--border);padding:2.4rem 2.2rem;}
.rz-field{margin-bottom:1.5rem;}
.rz-field > label,.rz-lbl{display:block;font-size:.56rem;letter-spacing:.2em;text-transform:uppercase;color:var(--mid);margin-bottom:.5rem;font-weight:500;}
.rz-req{color:var(--teal);}
.rz-input,.rz-select,.rz-textarea{
  width:100%;font-family:'Jost',sans-serif;font-size:.9rem;color:var(--dark);
  background:var(--off);border:1px solid var(--border);padding:.75rem .9rem;outline:none;
  transition:border-color .2s,background .2s;appearance:none;-webkit-appearance:none;
}
.rz-input:focus,.rz-select:focus,.rz-textarea:focus{border-color:rgba(30,92,107,.45);background:var(--white);}
.rz-select{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236B6050' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right .9rem center;padding-right:2.4rem;cursor:pointer;
}
.rz-textarea{resize:vertical;min-height:90px;line-height:1.6;}
.rz-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}

/* Datepicker trigger button (styled like the inputs; behavior from datepicker.js) */
.rz-field .dp-btn{
  width:100%;text-align:left;font-family:'Jost',sans-serif;font-size:.9rem;color:var(--light);
  background:var(--off);border:1px solid var(--border);padding:.75rem .9rem;cursor:pointer;
  transition:border-color .2s,background .2s;
}
.rz-field .dp-btn.dp-btn--active{color:var(--dark);}
.rz-field .dp-btn:hover{border-color:rgba(30,92,107,.35);}

/* Party-size stepper */
.rz-step{display:flex;align-items:center;gap:0;border:1px solid var(--border);background:var(--off);width:max-content;}
.rz-step button{width:44px;height:44px;border:none;background:transparent;font-size:1.2rem;color:var(--teal);cursor:pointer;line-height:1;}
.rz-step button:hover{background:rgba(30,92,107,.06);}
.rz-step span{min-width:52px;text-align:center;font-size:1rem;color:var(--dark);}

.rz-err{display:block;color:#9B3B2A;font-size:.72rem;margin-top:.4rem;}
.rz-field--bad .rz-input,.rz-field--bad .rz-select,.rz-field--bad .dp-btn{border-color:#c9603f;}
.rz-alert{background:rgba(155,59,42,.06);border:1px solid rgba(155,59,42,.25);border-left:3px solid #9B3B2A;padding:.9rem 1.1rem;font-size:.82rem;color:#7a2f22;line-height:1.6;margin-bottom:1.6rem;}

.rz-submit{width:100%;font-family:'Jost',sans-serif;font-size:.58rem;letter-spacing:.24em;text-transform:uppercase;font-weight:500;padding:1rem 2rem;background:var(--teal);color:#fff;border:none;cursor:pointer;transition:background .22s;margin-top:.5rem;}
.rz-submit:hover{background:#1a4a56;}
.rz-foot{margin-top:1rem;text-align:center;font-size:.7rem;color:var(--light);line-height:1.7;}
.rz-hp{display:none !important;}

@media(max-width:560px){
  .rz-card{padding:1.8rem 1.4rem;}
  .rz-row{grid-template-columns:1fr;}
}
</style>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<section class="rz-hero">
  <div class="rz-hero-in">
    <div class="rz-eyebrow">Restaurant Reservations · Tribal Sand Kenya</div>
    <h1>Reserve a Table · <em>We Confirm Within 24 Hours</em></h1>
  </div>
</section>

<div class="rz-wrap">
  <?php if (!$venues): ?>
    <p class="rz-intro">Table reservations aren’t available online just yet. Please contact us on
      <a href="tel:+254115115247" style="color:var(--teal)">+254 115 115 247</a> or
      <a href="https://wa.me/254115115247" target="_blank" rel="noopener" style="color:var(--teal)">WhatsApp</a> to book.</p>
  <?php else: ?>

  <p class="rz-intro">Tell us where and when you’d like to dine and we’ll confirm your table. This is a request — a member of our team will be in touch within 24 hours to confirm availability. No payment is taken at this stage.</p>

  <div class="rz-card">
    <?php if (!empty($errors['general'])): ?>
      <div class="rz-alert"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <form method="POST" action="/api/submit-reservation.php" id="reserve-form" novalidate>
      <?= csrf_field() ?>
      <input type="text" name="website" class="rz-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

      <div class="rz-field <?= isset($errors['venue_id']) ? 'rz-field--bad' : '' ?>">
        <label for="rzVenue">Property <span class="rz-req">*</span></label>
        <select class="rz-select" id="rzVenue" name="venue_id" data-nice required>
          <option value="">Choose a property…</option>
          <?php foreach ($venues as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= (int)($old['venue_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
            <?= e($v['name']) ?><?= $v['location'] ? ' — ' . e($v['location']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['venue_id'])): ?><span class="rz-err"><?= e($errors['venue_id']) ?></span><?php endif; ?>
      </div>

      <div class="rz-row">
        <div class="rz-field <?= isset($errors['reservation_date']) ? 'rz-field--bad' : '' ?>">
          <span class="rz-lbl">Date <span class="rz-req">*</span></span>
          <button type="button" class="dp-btn" data-dp-target="rzDate" data-dp-placeholder="Select date">Select date</button>
          <input type="hidden" id="rzDate" name="reservation_date" value="<?= $ov('reservation_date') ?>" required>
          <?php if (isset($errors['reservation_date'])): ?><span class="rz-err"><?= e($errors['reservation_date']) ?></span><?php endif; ?>
        </div>
        <div class="rz-field <?= isset($errors['reservation_time']) ? 'rz-field--bad' : '' ?>">
          <label for="rzTime">Time <span class="rz-req">*</span></label>
          <select class="rz-select" id="rzTime" name="reservation_time" data-nice required>
            <option value="">Select time…</option>
            <?php foreach ($slots as $val => $label): ?>
            <option value="<?= e($val) ?>" <?= ($old['reservation_time'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['reservation_time'])): ?><span class="rz-err"><?= e($errors['reservation_time']) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="rz-field <?= isset($errors['party_size']) ? 'rz-field--bad' : '' ?>">
        <span class="rz-lbl">Party size <span class="rz-req">*</span></span>
        <div class="rz-step" role="group" aria-label="Party size">
          <button type="button" data-rz-step="-1" aria-label="Fewer guests">&minus;</button>
          <span data-rz-count>1</span>
          <button type="button" data-rz-step="1" aria-label="More guests">+</button>
        </div>
        <input type="hidden" name="party_size" id="rzParty" value="<?= (int)($old['party_size'] ?? 0) > 0 ? (int)$old['party_size'] : 1 ?>">
        <?php if (isset($errors['party_size'])): ?><span class="rz-err"><?= e($errors['party_size']) ?></span><?php endif; ?>
      </div>

      <div class="rz-row">
        <div class="rz-field <?= isset($errors['guest_name']) ? 'rz-field--bad' : '' ?>">
          <label for="rzName">Your name <span class="rz-req">*</span></label>
          <input type="text" class="rz-input" id="rzName" name="guest_name" value="<?= $ov('guest_name') ?>" placeholder="Full name" required autocomplete="name">
          <?php if (isset($errors['guest_name'])): ?><span class="rz-err"><?= e($errors['guest_name']) ?></span><?php endif; ?>
        </div>
        <div class="rz-field <?= isset($errors['guest_phone']) ? 'rz-field--bad' : '' ?>">
          <label for="rzPhone">Phone / WhatsApp <span class="rz-req">*</span></label>
          <input type="tel" class="rz-input" id="rzPhone" name="guest_phone" value="<?= $ov('guest_phone') ?>" placeholder="+254 …" required autocomplete="tel">
          <?php if (isset($errors['guest_phone'])): ?><span class="rz-err"><?= e($errors['guest_phone']) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="rz-field <?= isset($errors['guest_email']) ? 'rz-field--bad' : '' ?>">
        <label for="rzEmail">Email <span style="text-transform:none;letter-spacing:0;color:var(--light)">(optional — for your confirmation)</span></label>
        <input type="email" class="rz-input" id="rzEmail" name="guest_email" value="<?= $ov('guest_email') ?>" placeholder="you@example.com" autocomplete="email">
        <?php if (isset($errors['guest_email'])): ?><span class="rz-err"><?= e($errors['guest_email']) ?></span><?php endif; ?>
      </div>

      <div class="rz-field">
        <label for="rzNotes">Anything we should know?</label>
        <textarea class="rz-textarea" id="rzNotes" name="notes" placeholder="Dietary requirements, a special occasion, high chair…"><?= $ov('notes') ?></textarea>
      </div>

      <?php if (captcha_site_key()): ?>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin-bottom:1rem"></div>
      <?php endif; ?>

      <button type="submit" class="rz-submit">Request Table →</button>
      <p class="rz-foot">We confirm within 24 hours · No payment required · Call us on <a href="tel:+254115115247" style="color:var(--teal)">+254 115 115 247</a></p>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
// Party-size stepper (1–30)
(function(){
  var count = document.querySelector('[data-rz-count]');
  var input = document.getElementById('rzParty');
  if (!count || !input) return;
  var n = Math.max(1, Math.min(30, parseInt(input.value, 10) || 1));
  var render = function(){ count.textContent = n; input.value = n; };
  render();
  document.querySelectorAll('[data-rz-step]').forEach(function(b){
    b.addEventListener('click', function(){
      n = Math.max(1, Math.min(30, n + parseInt(b.getAttribute('data-rz-step'), 10)));
      render();
    });
  });
})();
</script>

<?php if ($okRef !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (typeof window.showSuccessModal === 'function') {
    window.showSuccessModal(
      'Table Request Received!',
      'Thank you — your request is <strong>pending confirmation</strong>. Our team will be in touch within 24 hours to confirm your table.<?php if ($okRef !== '1'): ?><br><br>Your reference: <strong style="letter-spacing:1px"><?= e($okRef) ?></strong><?php endif; ?>',
      false
    );
  } else {
    alert('Table request received — pending confirmation. We\'ll be in touch within 24 hours.');
  }
  // Clean the ?ok= param so a refresh doesn't re-show the modal.
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, document.title, '/reserve.php');
  }
});
</script>
<?php endif; ?>

</body>
</html>
