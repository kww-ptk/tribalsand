<?php
/**
 * Zuri Restaurant — public landing page.
 *
 * A branded, per-property restaurant page: hero + info (open to the public, by
 * reservation only), two CTAs (View Menu / Book a Table), a photo gallery and an
 * embedded reservation form pre-locked to Zuri. The form posts to the shared
 * api/submit-reservation.php with a `redirect` back here (PRG → ?ok=<ref>), so
 * this page is a drop-in template for other properties: copy it, swap the slug.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';          // csrf_field(), session
require_once __DIR__ . '/includes/reservations.php';
require_once __DIR__ . '/includes/page-content.php'; // editable slots (Admin → Page Content)

session_init();

const ZR_SLUG = 'zuri';
const ZR_MENU = 'zuri';   // menu.php?m=<slug>

// Resolve the Zuri venue (for venue_id + a real "reservable" check).
$venue = null;
foreach (fetch_reservable_venues() as $v) {
    if ($v['slug'] === ZR_SLUG) { $venue = $v; break; }
}

// Flash (validation errors + old input from a failed PRG submit) — shared key.
$flash  = $_SESSION['reserve_flash'] ?? null;
unset($_SESSION['reserve_flash']);
$errors = $flash['errors'] ?? [];
$old    = $flash['old']    ?? [];

$okRef  = isset($_GET['ok']) ? trim((string)$_GET['ok']) : '';
$slots  = reservation_slots();
$ov     = fn(string $k, $d = '') => e((string)($old[$k] ?? $d));

/* ── Gallery (placeholder photos — swap freely) ── */
// Gallery tiles are editable in Admin → Page Content (Zuri Restaurant).
// Slot keys, not paths — page_image() resolves each one. Clearing a field in
// admin RESTORES that tile's default photo (it does not remove the tile); the
// empty-string guard in the loop below is only a safety net for a slot that
// resolves to nothing, so the page never renders a broken image.
$gallery = [
    ['gal_1', 'Beachfront dining at Zuri, Watamu'],
    ['gal_2', 'Poolside terrace at Zuri'],
    ['gal_3', 'Aerial view of Zuri on Garoda Beach'],
    ['gal_4', 'Garden dining setting at Zuri'],
    ['gal_5', 'Indian Ocean shoreline at Zuri'],
    ['gal_6', 'Evening ambience at Zuri restaurant'],
];

$page_title   = 'Zuri Restaurant · Beachfront Dining · Garoda Beach Watamu · Tribal Sand';
$page_desc    = 'Zuri is now open to the public, by reservation only. Coastal à la carte dining on Garoda Beach, Watamu. View the menu and request your table.';
$page_url     = 'https://tribalsand.com/zuri-restaurant.php';
$page_image   = page_image('zuri-restaurant','og_image');
$page_schema  = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Restaurant","name":"Zuri Restaurant","servesCuisine":["Coastal","Swahili"],"address":{"@type":"PostalAddress","addressLocality":"Watamu","addressRegion":"Kilifi County","addressCountry":"KE"},"telephone":"+254115115247","url":"https://tribalsand.com/zuri-restaurant","acceptsReservations":"True","image":"' . asset_url('images/hero-zuri.jpg') . '","parentOrganization":{"@type":"Organization","name":"Tribal Sand","url":"https://tribalsand.com"}}</script>';
$page_booking = true;   // loads booking.css (datepicker styling) + datepicker.js
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
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;overflow-x:hidden;}
a{text-decoration:none;color:inherit;}
img{display:block;object-fit:cover;}

/* ── HERO ── */
.zr-hero{position:relative;min-height:70vh;display:flex;align-items:flex-end;padding:0 6vw 3.5rem;overflow:hidden;}
.zr-hero-bg{position:absolute;inset:0;}
.zr-hero-bg img{width:100%;height:100%;}
.zr-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,rgba(16,47,58,.35) 0%,rgba(16,47,58,.55) 60%,rgba(16,47,58,.88) 100%);}
.zr-hero-in{position:relative;z-index:2;max-width:760px;}
.zr-badge{display:inline-flex;align-items:center;gap:.5rem;font-size:.5rem;letter-spacing:.34em;text-transform:uppercase;color:var(--sand-lt);border:1px solid rgba(212,176,122,.5);padding:.45rem 1.1rem;margin-bottom:1.4rem;}
.zr-badge::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--sand-lt);animation:zrp 2s ease-in-out infinite;}
@keyframes zrp{0%,100%{opacity:1}50%{opacity:.3}}
.zr-eyebrow{font-size:.58rem;letter-spacing:.3em;text-transform:uppercase;color:rgba(232,220,200,.7);margin-bottom:.9rem;}
.zr-hero h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.6rem,6vw,4.6rem);font-weight:300;color:#fff;line-height:1;}
.zr-hero h1 em{font-style:italic;color:var(--sand-lt);}
.zr-hero-sub{margin-top:1.1rem;font-size:1rem;color:rgba(212,196,172,.92);line-height:1.7;font-weight:300;max-width:560px;}
.zr-cta{display:flex;flex-wrap:wrap;gap:.8rem;margin-top:2rem;}
.zr-btn{display:inline-flex;align-items:center;gap:.6rem;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;font-weight:500;padding:1rem 1.8rem;transition:all .2s;cursor:pointer;border:1px solid transparent;}
.zr-btn--gold{background:var(--sand);color:var(--teal-d);}
.zr-btn--gold:hover{background:var(--sand-lt);}
.zr-btn--ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.5);}
.zr-btn--ghost:hover{border-color:#fff;background:rgba(255,255,255,.08);}

/* ── INFO STRIP ── */
.zr-info{max-width:900px;margin:0 auto;padding:4rem 5vw 2rem;text-align:center;}
.zr-info-eyebrow{font-size:.56rem;letter-spacing:.3em;text-transform:uppercase;color:var(--teal);margin-bottom:1rem;font-weight:500;}
.zr-info h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.8rem,3.4vw,2.6rem);font-weight:400;line-height:1.2;margin-bottom:1.2rem;}
.zr-info h2 em{font-style:italic;color:var(--sand);}
.zr-info p{font-size:.95rem;color:var(--mid);line-height:1.9;font-weight:300;max-width:640px;margin:0 auto;}
.zr-facts{display:flex;flex-wrap:wrap;justify-content:center;gap:.5rem;margin-top:2rem;}
.zr-fact{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;padding:.5rem 1.1rem;border:1px solid var(--border);color:var(--mid);}

/* ── GALLERY ── */
.zr-gallery{max-width:1200px;margin:0 auto;padding:3rem 5vw 4rem;}
.zr-gallery-head{text-align:center;margin-bottom:2rem;}
.zr-gallery-head .zr-info-eyebrow{margin-bottom:.6rem;}
.zr-gallery-head h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.6rem,3vw,2.2rem);font-weight:400;}
.zr-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem;}
.zr-grid a{position:relative;overflow:hidden;aspect-ratio:4/3;background:var(--sand-pale);}
.zr-grid img{width:100%;height:100%;transition:transform .5s ease;}
.zr-grid a:hover img{transform:scale(1.06);}

/* ── RESERVE FORM ── */
.zr-reserve{background:var(--teal-d);padding:4.5rem 5vw 5.5rem;position:relative;}
.zr-reserve-in{max-width:620px;margin:0 auto;}
.zr-reserve-head{text-align:center;margin-bottom:2.4rem;}
.zr-reserve-head .zr-info-eyebrow{color:var(--sand-lt);}
.zr-reserve-head h2{font-family:'Cormorant Garamond',serif;font-size:clamp(1.9rem,3.6vw,2.8rem);font-weight:300;color:#fff;margin-bottom:.8rem;}
.zr-reserve-head h2 em{font-style:italic;color:var(--sand-lt);}
.zr-reserve-head p{font-size:.88rem;color:rgba(212,196,172,.8);line-height:1.75;font-weight:300;}
.zr-card{background:var(--white);border:1px solid var(--border);padding:2.4rem 2.2rem;}
.zr-venue-lock{display:flex;align-items:center;gap:.7rem;padding:.85rem 1rem;background:rgba(30,92,107,.06);border:1px solid rgba(30,92,107,.16);margin-bottom:1.6rem;font-size:.82rem;color:var(--teal);}
.zr-venue-lock i{font-size:.95rem;}
.zr-field{margin-bottom:1.5rem;}
.zr-field > label,.zr-lbl{display:block;font-size:.56rem;letter-spacing:.2em;text-transform:uppercase;color:var(--mid);margin-bottom:.5rem;font-weight:500;}
.zr-req{color:var(--teal);}
.zr-input,.zr-select,.zr-textarea{width:100%;font-family:'Jost',sans-serif;font-size:.9rem;color:var(--dark);background:var(--off);border:1px solid var(--border);padding:.75rem .9rem;outline:none;transition:border-color .2s,background .2s;appearance:none;-webkit-appearance:none;}
.zr-input:focus,.zr-select:focus,.zr-textarea:focus{border-color:rgba(30,92,107,.45);background:var(--white);}
.zr-select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236B6050' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .9rem center;padding-right:2.4rem;cursor:pointer;}
.zr-textarea{resize:vertical;min-height:90px;line-height:1.6;}
.zr-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.zr-field .dp-btn{width:100%;text-align:left;font-family:'Jost',sans-serif;font-size:.9rem;color:var(--light);background:var(--off);border:1px solid var(--border);padding:.75rem .9rem;cursor:pointer;transition:border-color .2s,background .2s;}
.zr-field .dp-btn.dp-btn--active{color:var(--dark);}
.zr-field .dp-btn:hover{border-color:rgba(30,92,107,.35);}
.zr-step{display:flex;align-items:center;gap:0;border:1px solid var(--border);background:var(--off);width:max-content;}
.zr-step button{width:44px;height:44px;border:none;background:transparent;font-size:1.2rem;color:var(--teal);cursor:pointer;line-height:1;}
.zr-step button:hover{background:rgba(30,92,107,.06);}
.zr-step span{min-width:52px;text-align:center;font-size:1rem;color:var(--dark);}
.zr-err{display:block;color:#9B3B2A;font-size:.72rem;margin-top:.4rem;}
.zr-field--bad .zr-input,.zr-field--bad .zr-select,.zr-field--bad .dp-btn{border-color:#c9603f;}
.zr-alert{background:rgba(155,59,42,.06);border:1px solid rgba(155,59,42,.25);border-left:3px solid #9B3B2A;padding:.9rem 1.1rem;font-size:.82rem;color:#7a2f22;line-height:1.6;margin-bottom:1.6rem;}
.zr-submit{width:100%;font-family:'Jost',sans-serif;font-size:.58rem;letter-spacing:.24em;text-transform:uppercase;font-weight:500;padding:1rem 2rem;background:var(--teal);color:#fff;border:none;cursor:pointer;transition:background .22s;margin-top:.5rem;}
.zr-submit:hover{background:#1a4a56;}
.zr-foot{margin-top:1rem;text-align:center;font-size:.7rem;color:var(--light);line-height:1.7;}
.zr-hp{display:none !important;}
.zr-fallback{background:var(--white);border:1px solid var(--border);padding:2rem;text-align:center;font-size:.9rem;color:var(--mid);line-height:1.8;}
.zr-fallback a{color:var(--teal);font-weight:500;}

@media(max-width:760px){
  .zr-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:560px){
  .zr-hero{min-height:62vh;padding:0 5vw 2.6rem;}
  .zr-card{padding:1.8rem 1.4rem;}
  .zr-row{grid-template-columns:1fr;}
}
</style>
<body class="ts-nav-transparent">

<?php include __DIR__ . '/includes/header.php'; ?>

<!-- ═══ HERO ═══ -->
<section class="zr-hero">
  <div class="zr-hero-bg"><img src="<?= e(page_image('zuri-restaurant','hero_image')) ?>" alt="Zuri beachfront restaurant on Garoda Beach, Watamu" width="1920" height="1080" loading="eager"></div>
  <div class="zr-hero-in">
    <div class="zr-badge"><?= page_text('zuri-restaurant','hero_badge') ?></div>
    <div class="zr-eyebrow"><?= page_text('zuri-restaurant','hero_eyebrow') ?></div>
    <h1><?= page_html('zuri-restaurant','hero_title') ?></h1>
    <p class="zr-hero-sub"><?= page_html('zuri-restaurant','hero_sub') ?></p>
    <div class="zr-cta">
      <a href="<?= e(site_url('menu.php?m=' . ZR_MENU)) ?>" class="zr-btn zr-btn--gold" target="_blank" rel="noopener"><i class="fa-solid fa-book-open" aria-hidden="true"></i> View Menu</a>
      <a href="#reserve" class="zr-btn zr-btn--ghost"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Book a Table</a>
    </div>
  </div>
</section>

<!-- ═══ INFO ═══ -->
<section class="zr-info">
  <div class="zr-info-eyebrow"><?= page_text('zuri-restaurant','info_eyebrow') ?></div>
  <h2><?= page_html('zuri-restaurant','info_title') ?></h2>
  <p><?= page_html('zuri-restaurant','info_body') ?></p>
  <div class="zr-facts">
    <span class="zr-fact">À La Carte</span>
    <span class="zr-fact">Beachfront Terrace</span>
    <span class="zr-fact">Fresh Seafood</span>
    <span class="zr-fact">Lunch &amp; Dinner</span>
    <span class="zr-fact">Reservation Only</span>
  </div>
</section>

<!-- ═══ GALLERY ═══ -->
<section class="zr-gallery">
  <div class="zr-gallery-head">
    <div class="zr-info-eyebrow"><?= page_text('zuri-restaurant','gal_eyebrow') ?></div>
    <h2><?= page_html('zuri-restaurant','gal_title') ?></h2>
  </div>
  <div class="zr-grid">
    <?php foreach ($gallery as [$slot, $alt]): $__u = page_image('zuri-restaurant', $slot); if ($__u === '') continue; ?>
    <a href="<?= e($__u) ?>" target="_blank" rel="noopener">
      <img src="<?= e($__u) ?>" alt="<?= e($alt) ?>" loading="lazy">
    </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- ═══ RESERVE ═══ -->
<section class="zr-reserve" id="reserve">
  <div class="zr-reserve-in">
    <div class="zr-reserve-head">
      <div class="zr-info-eyebrow"><?= page_text('zuri-restaurant','res_eyebrow') ?></div>
      <h2><?= page_html('zuri-restaurant','res_title') ?></h2>
      <p><?= page_text('zuri-restaurant','res_body') ?></p>
    </div>

    <?php if (!$venue): ?>
      <div class="zr-fallback">
        Online reservations aren’t available right now. Please call us on
        <a href="tel:+254115115247">+254 115 115 247</a> or
        <a href="https://wa.me/254115115247" target="_blank" rel="noopener">WhatsApp</a> to book your table at Zuri.
      </div>
    <?php else: ?>
    <div class="zr-card">
      <?php if (!empty($errors['general'])): ?>
        <div class="zr-alert"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <form method="POST" action="/api/submit-reservation.php" id="reserve-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">
        <input type="hidden" name="redirect" value="/zuri-restaurant.php">
        <input type="text" name="website" class="zr-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

        <div class="zr-venue-lock">
          <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
          <span>Reserving at <strong>Zuri</strong><?= $venue['location'] ? ' · ' . e($venue['location']) : ' · Watamu' ?></span>
        </div>

        <div class="zr-row">
          <div class="zr-field <?= isset($errors['reservation_date']) ? 'zr-field--bad' : '' ?>">
            <span class="zr-lbl">Date <span class="zr-req">*</span></span>
            <button type="button" class="dp-btn" data-dp-target="zrDate" data-dp-placeholder="Select date">Select date</button>
            <input type="hidden" id="zrDate" name="reservation_date" value="<?= $ov('reservation_date') ?>" required>
            <?php if (isset($errors['reservation_date'])): ?><span class="zr-err"><?= e($errors['reservation_date']) ?></span><?php endif; ?>
          </div>
          <div class="zr-field <?= isset($errors['reservation_time']) ? 'zr-field--bad' : '' ?>">
            <label for="zrTime">Time <span class="zr-req">*</span></label>
            <select class="zr-select" id="zrTime" name="reservation_time" data-nice required>
              <option value="">Select time…</option>
              <?php foreach ($slots as $val => $label): ?>
              <option value="<?= e($val) ?>" <?= ($old['reservation_time'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['reservation_time'])): ?><span class="zr-err"><?= e($errors['reservation_time']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="zr-field <?= isset($errors['party_size']) ? 'zr-field--bad' : '' ?>">
          <span class="zr-lbl">Party size <span class="zr-req">*</span></span>
          <div class="zr-step" role="group" aria-label="Party size">
            <button type="button" data-zr-step="-1" aria-label="Fewer guests">&minus;</button>
            <span data-zr-count>1</span>
            <button type="button" data-zr-step="1" aria-label="More guests">+</button>
          </div>
          <input type="hidden" name="party_size" id="zrParty" value="<?= (int)($old['party_size'] ?? 0) > 0 ? (int)$old['party_size'] : 1 ?>">
          <?php if (isset($errors['party_size'])): ?><span class="zr-err"><?= e($errors['party_size']) ?></span><?php endif; ?>
        </div>

        <div class="zr-row">
          <div class="zr-field <?= isset($errors['guest_name']) ? 'zr-field--bad' : '' ?>">
            <label for="zrName">Your name <span class="zr-req">*</span></label>
            <input type="text" class="zr-input" id="zrName" name="guest_name" value="<?= $ov('guest_name') ?>" placeholder="Full name" required autocomplete="name">
            <?php if (isset($errors['guest_name'])): ?><span class="zr-err"><?= e($errors['guest_name']) ?></span><?php endif; ?>
          </div>
          <div class="zr-field <?= isset($errors['guest_phone']) ? 'zr-field--bad' : '' ?>">
            <label for="zrPhone">Phone / WhatsApp <span class="zr-req">*</span></label>
            <input type="tel" class="zr-input" id="zrPhone" name="guest_phone" value="<?= $ov('guest_phone') ?>" placeholder="+254 …" required autocomplete="tel">
            <?php if (isset($errors['guest_phone'])): ?><span class="zr-err"><?= e($errors['guest_phone']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="zr-field <?= isset($errors['guest_email']) ? 'zr-field--bad' : '' ?>">
          <label for="zrEmail">Email <span style="text-transform:none;letter-spacing:0;color:var(--light)">(optional — for your confirmation)</span></label>
          <input type="email" class="zr-input" id="zrEmail" name="guest_email" value="<?= $ov('guest_email') ?>" placeholder="you@example.com" autocomplete="email">
          <?php if (isset($errors['guest_email'])): ?><span class="zr-err"><?= e($errors['guest_email']) ?></span><?php endif; ?>
        </div>

        <div class="zr-field">
          <label for="zrNotes">Anything we should know?</label>
          <textarea class="zr-textarea" id="zrNotes" name="notes" placeholder="Dietary requirements, a special occasion, high chair…"><?= $ov('notes') ?></textarea>
        </div>

        <?php if (captcha_site_key()): ?>
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin-bottom:1rem"></div>
        <?php endif; ?>

        <button type="submit" class="zr-submit">Request Table →</button>
        <p class="zr-foot">We confirm within 24 hours · No payment required · Call us on <a href="tel:+254115115247" style="color:var(--teal)">+254 115 115 247</a></p>
      </form>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
// Party-size stepper (1–30)
(function(){
  var count = document.querySelector('[data-zr-count]');
  var input = document.getElementById('zrParty');
  if (!count || !input) return;
  var n = Math.max(1, Math.min(30, parseInt(input.value, 10) || 1));
  var render = function(){ count.textContent = n; input.value = n; };
  render();
  document.querySelectorAll('[data-zr-step]').forEach(function(b){
    b.addEventListener('click', function(){
      n = Math.max(1, Math.min(30, n + parseInt(b.getAttribute('data-zr-step'), 10)));
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
      'Thank you — your request is <strong>pending confirmation</strong>. Our team will be in touch within 24 hours to confirm your table at Zuri.<?php if ($okRef !== '1'): ?><br><br>Your reference: <strong style="letter-spacing:1px"><?= e($okRef) ?></strong><?php endif; ?>',
      false
    );
  } else {
    alert('Table request received — pending confirmation. We\'ll be in touch within 24 hours.');
  }
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, document.title, '/zuri-restaurant.php');
  }
});
</script>
<?php endif; ?>

</body>
</html>
