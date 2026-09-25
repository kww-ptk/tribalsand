<?php
/**
 * Tribal Gym — coming-soon page for the gym at Tribal Dunes, Bofa Beach.
 *
 * Copy and photos are editable in Admin → Content → Pages → Tribal Gym
 * (page_content_registry()['tribal-gym']). A membership price slot left empty
 * hides its price line, so the page reads right before prices are announced.
 *
 * Clearing a slot in admin brings back its default, so optional parts (card
 * photos, the note, the closing band) are hidden with a single "-" instead —
 * see tg_off().
 *
 * The waitlist posts to api/submit-waitlist.php (list 'tribal-gym'): Turnstile +
 * rate limit + admin inbox, forwarded to GHL server-side — never from the browser.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/page-content.php';

$P = 'tribal-gym';

/** True when the owner switched an optional slot off with a single "-". */
function tg_off(string $slot): bool {
    $v = trim(page_value('tribal-gym', $slot));
    return $v === '' || $v === '-';
}

$heroImg = page_image($P, 'hero_image');
$facts   = array_values(array_filter(array_map('trim', explode('|', page_value($P, 'hero_facts')))));

$plans = [];
foreach ([1, 2, 3] as $n) {
    $name = trim(page_value($P, "plan{$n}_name"));
    if ($name === '') continue;
    $plans[] = [
        'name'  => page_text($P, "plan{$n}_name"),
        'desc'  => page_text($P, "plan{$n}_desc"),
        'price' => page_text($P, "plan{$n}_price"),
        'image' => tg_off("plan{$n}_image") ? '' : page_image($P, "plan{$n}_image"),
    ];
}

/* ═══ SEO ═══ */
$page_title = 'Tribal Gym · Coming Soon · Tribal Dunes, Kilifi · Tribal Sand';
$page_desc  = 'Tribal GYM is coming to Tribal Dunes, Kilifi — brand-new equipment, air conditioning, personal training and showers, with the pool, chill-out area and Somewhere Café next door. Join the waitlist.';
$page_url   = 'https://tribalsand.com/tribal-gym.php';
$page_image = page_image($P, 'og_image');
$bandImg    = tg_off('band_image') ? '' : page_image($P, 'band_image');
$page_preload = $heroImg;
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',         'url' => 'https://tribalsand.com/'],
    ['name' => 'Tribal Dunes', 'url' => 'https://tribalsand.com/tribal-dunes.php'],
    ['name' => 'Tribal Gym',   'url' => 'https://tribalsand.com/tribal-gym.php'],
]);
require_once 'includes/head.php';
?>
<body class="ts-nav-transparent">
<?php include 'includes/header.php'; ?>

<style>
.tg-hero{min-height:100vh;background:var(--teal-d,#102F3A);display:flex;align-items:center;justify-content:center;padding:7rem var(--px,5vw) 4rem;position:relative;overflow:hidden;text-align:center}
.tg-hero-bg{position:absolute;inset:0}
.tg-hero-bg img{width:100%;height:100%;object-fit:cover;opacity:.7}
.tg-hero-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,rgba(16,47,58,.82) 0%,rgba(16,47,58,.66) 50%,rgba(16,47,58,.9) 100%)}
.tg-hero-in{position:relative;z-index:1;max-width:720px;width:100%}
.tg-badge{display:inline-flex;align-items:center;gap:.5rem;font-size:.5rem;letter-spacing:.38em;text-transform:uppercase;color:var(--sand,#B8965A);border:1px solid rgba(184,150,90,.5);padding:.45rem 1.1rem;margin-bottom:2rem}
.tg-badge::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--sand,#B8965A);animation:tgPulse 2s ease-in-out infinite}
@keyframes tgPulse{0%,100%{opacity:1}50%{opacity:.3}}
@media(prefers-reduced-motion:reduce){.tg-badge::before{animation:none}}
.tg-eyebrow{font-size:.55rem;letter-spacing:.38em;text-transform:uppercase;color:rgba(184,150,90,.9);margin-bottom:.9rem;display:flex;align-items:center;justify-content:center;gap:.65rem}
.tg-eyebrow::before,.tg-eyebrow::after{content:'';width:20px;height:1px;background:rgba(184,150,90,.6)}
.tg-hero h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.6rem,6.5vw,4.6rem);font-weight:300;color:#fff;line-height:1;margin:0 0 1.4rem}
.tg-hero h1 em{font-style:italic;color:var(--sand-lt,#D4B07A)}
.tg-sub{font-size:1.02rem;color:rgba(212,196,172,.92);line-height:1.8;margin:0 auto 2.2rem;max-width:600px}
.tg-pills{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin-bottom:2.4rem}
.tg-pill{font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;padding:.45rem 1rem;border:1px solid rgba(184,150,90,.45);color:rgba(212,196,172,.88)}
.tg-btn{display:inline-block;padding:.95rem 1.8rem;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);border:none;font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;cursor:pointer;font-family:'Jost',sans-serif;font-weight:500;text-decoration:none;transition:background .2s}
.tg-btn:hover{background:var(--sand-lt,#D4B07A)}

.tg-sec{padding:5.5rem var(--px,5vw);background:#fff}
.tg-sec--sand{background:var(--cream,#F7F3EC)}
.tg-in{max-width:1080px;margin:0 auto}
.tg-center{text-align:center}
.tg-after{font-family:'Cormorant Garamond',serif;font-size:clamp(1.35rem,2.6vw,1.8rem);font-weight:300;line-height:1.55;color:var(--teal-d,#102F3A);max-width:760px;margin:0 auto}
.tg-after a{color:var(--sand,#B8965A);text-decoration:underline;text-underline-offset:3px}
.tg-h2{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,4vw,3rem);font-weight:300;color:var(--teal-d,#102F3A);line-height:1.1;margin:0 0 2.6rem}
.tg-h2 em{font-style:italic;color:var(--sand,#B8965A)}
.tg-plans{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;text-align:left}
.tg-plan{background:#fff;border:1px solid rgba(184,150,90,.3);display:flex;flex-direction:column;overflow:hidden}
.tg-plan-img{aspect-ratio:4/3;overflow:hidden;background:var(--teal-d,#102F3A)}
.tg-plan-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s ease}
.tg-plan:hover .tg-plan-img img{transform:scale(1.04)}
@media(prefers-reduced-motion:reduce){.tg-plan-img img{transition:none}.tg-plan:hover .tg-plan-img img{transform:none}}
.tg-plan-body{padding:1.75rem 1.75rem 2rem;display:flex;flex-direction:column;gap:.7rem;flex:1}
.tg-plan-n{font-size:.55rem;letter-spacing:.3em;text-transform:uppercase;color:var(--sand,#B8965A)}
.tg-plan h3{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;color:var(--teal-d,#102F3A);margin:0;line-height:1.15}
.tg-plan p{font-size:.92rem;line-height:1.7;color:#4a5a60;margin:0}
.tg-plan-price{margin-top:auto;padding-top:.8rem;border-top:1px solid rgba(184,150,90,.25);font-size:.95rem;color:var(--teal-d,#102F3A);font-weight:500}
.tg-note{margin:2rem 0 0;font-size:.78rem;letter-spacing:.06em;color:#6d7b80;font-style:italic}

.tg-wl{background:var(--teal-d,#102F3A);padding:5.5rem var(--px,5vw);text-align:center}
.tg-wl .tg-h2{color:#fff;margin-bottom:1rem}
.tg-wl .tg-h2 em{color:var(--sand-lt,#D4B07A)}
.tg-wl-p{color:rgba(212,196,172,.9);font-size:1rem;line-height:1.8;max-width:520px;margin:0 auto 2rem}
.tg-form{display:flex;gap:.5rem;max-width:460px;margin:0 auto}
.tg-inp{flex:1;min-width:0;padding:.9rem 1.1rem;border:1px solid rgba(184,150,90,.4);background:rgba(255,255,255,.08);color:#fff;font-size:.9rem;font-family:'Jost',sans-serif;outline:none;transition:border-color .2s}
.tg-inp::placeholder{color:rgba(255,255,255,.4)}
.tg-inp:focus{border-color:rgba(184,150,90,.8)}
.tg-form .tg-btn{flex-shrink:0;white-space:nowrap}
.tg-captcha{display:flex;justify-content:center;margin:.9rem 0 0;min-height:65px}
.tg-err{color:#f3b8a8;font-size:.85rem;min-height:1.2em;margin:.5rem 0 0}
.tg-success{display:none;font-size:.85rem;color:rgba(212,196,172,.95);border:1px solid rgba(184,150,90,.35);padding:1rem 1.5rem;max-width:460px;margin:0 auto}
.tg-small{font-size:.6rem;color:rgba(184,150,90,.6);margin:1.2rem 0 2rem}
.tg-back{font-size:.58rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(184,150,90,.65);text-decoration:none;transition:color .2s}
.tg-back:hover{color:rgba(184,150,90,1)}


.tg-band{position:relative;height:clamp(320px,62vh,640px);overflow:hidden;background:var(--teal-d,#102F3A)}
.tg-band img{width:100%;height:100%;object-fit:cover;display:block}
.tg-band::after{content:'';position:absolute;inset:0;background:linear-gradient(to top,rgba(16,47,58,.6) 0%,rgba(16,47,58,0) 45%)}
.tg-band-cap{position:absolute;left:0;right:0;bottom:2.4rem;z-index:1;text-align:center;padding:0 var(--px,5vw);font-family:'Cormorant Garamond',serif;font-style:italic;font-weight:300;font-size:clamp(1.3rem,2.6vw,1.9rem);color:#fff;margin:0}

@media(max-width:860px){.tg-plans{grid-template-columns:1fr}}
@media(max-width:480px){.tg-form{flex-direction:column}.tg-form .tg-btn{width:100%}}
</style>

<!-- ── HERO ── -->
<section class="tg-hero">
  <?php if ($heroImg !== ''): ?>
  <div class="tg-hero-bg"><img src="<?= e($heroImg) ?>" alt="Tribal Dunes, Bofa Beach, Kilifi — future home of Tribal Gym" width="1920" height="1080" loading="eager"></div>
  <?php endif; ?>
  <div class="tg-hero-in">
    <div class="tg-badge"><?= page_text($P, 'hero_badge') ?></div>
    <p class="tg-eyebrow"><?= page_text($P, 'hero_eyebrow') ?></p>
    <h1><?= page_html($P, 'hero_title') ?></h1>
    <p class="tg-sub"><?= page_text($P, 'hero_sub') ?></p>
    <?php if ($facts): ?>
    <div class="tg-pills">
      <?php foreach ($facts as $f): ?><span class="tg-pill"><?= e($f) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <a class="tg-btn" href="#waitlist"><?= page_text($P, 'wl_button') ?></a>
  </div>
</section>

<!-- ── AFTER YOUR WORKOUT ── -->
<?php if (!tg_off('after_body')): ?>
<section class="tg-sec">
  <div class="tg-in tg-center">
    <p class="tg-after"><?= page_html($P, 'after_body') ?></p>
  </div>
</section>
<?php endif; ?>

<!-- ── MEMBERSHIPS ── -->
<?php if ($plans): ?>
<section class="tg-sec tg-sec--sand">
  <div class="tg-in tg-center">
    <h2 class="tg-h2"><?= page_html($P, 'plans_title') ?></h2>
    <div class="tg-plans">
      <?php foreach ($plans as $i => $pl): ?>
      <div class="tg-plan">
        <?php if ($pl['image'] !== ''): ?>
        <div class="tg-plan-img"><img src="<?= e($pl['image']) ?>" alt="<?= $pl['name'] ?> at Tribal Gym, Tribal Dunes" width="800" height="600" loading="lazy"></div>
        <?php endif; ?>
        <div class="tg-plan-body">
          <span class="tg-plan-n"><?= sprintf('%02d', $i + 1) ?></span>
          <h3><?= $pl['name'] ?></h3>
          <p><?= $pl['desc'] ?></p>
          <?php if ($pl['price'] !== ''): ?><div class="tg-plan-price"><?= $pl['price'] ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (!tg_off('plans_note')): ?>
    <p class="tg-note"><?= page_text($P, 'plans_note') ?></p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- ── WAITLIST ── -->
<section class="tg-wl" id="waitlist">
  <h2 class="tg-h2"><?= page_html($P, 'wl_title') ?></h2>
  <p class="tg-wl-p"><?= page_text($P, 'wl_body') ?></p>

  <div class="tg-success" id="tgSuccess" role="status">✦ &nbsp; <?= page_text($P, 'wl_success') ?></div>

  <form class="tg-form" id="tgForm" novalidate>
    <div style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
    <label for="tgEmail" class="visually-hidden" style="position:absolute;left:-9999px">Email address</label>
    <input type="email" class="tg-inp" id="tgEmail" name="email" placeholder="your@email.com" required>
    <button type="submit" class="tg-btn" id="tgBtn"><?= page_text($P, 'wl_button') ?></button>
  </form>
  <?php if (captcha_site_key()): ?><div class="tg-captcha"><div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" data-theme="dark"></div></div><?php endif; ?>
  <p class="tg-err" id="tgErr" role="alert" aria-live="polite"></p>
  <p class="tg-small">No spam. Just launch pricing and membership details.</p>

  <a href="tribal-dunes.php" class="tg-back">← Back to Tribal Dunes</a>
</section>

<!-- ── CLOSING PHOTO ── -->
<?php if ($bandImg !== ''): ?>
<section class="tg-band">
  <img src="<?= e($bandImg) ?>" alt="Tribal Dunes, Bofa Beach, Kilifi" width="1920" height="1080" loading="lazy">
  <?php if (!tg_off('band_caption')): ?><p class="tg-band-cap"><?= page_text($P, 'band_caption') ?></p><?php endif; ?>
</section>
<?php endif; ?>

<script>
/* Waitlist sign-up → our backend (Turnstile + rate limit + admin inbox), which
   forwards it to GoHighLevel server-side. Never straight to GHL from the browser. */
(function () {
  var form = document.getElementById('tgForm');
  var btn  = document.getElementById('tgBtn');
  var label = btn.textContent;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var email = document.getElementById('tgEmail').value.trim();
    var err   = document.getElementById('tgErr');
    err.textContent = '';
    if (!email || email.indexOf('@') < 1) { err.textContent = 'Please enter a valid email address.'; return; }
    var tokEl = document.querySelector('.tg-captcha [name="cf-turnstile-response"]');
    if (document.querySelector('.tg-captcha .cf-turnstile') && !(tokEl && tokEl.value)) {
      err.textContent = 'Please complete the security check below.'; return;
    }
    btn.textContent = '…';
    btn.disabled = true;
    fetch('/api/submit-waitlist.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        list: 'tribal-gym',
        email: email,
        website: (form.querySelector('[name="website"]') || {}).value || '',
        'cf-turnstile-response': tokEl ? tokEl.value : ''
      })
    })
    .then(function (r) { return r.json().catch(function () { return {ok: false}; }); })
    .then(function (r) {
      if (!r.ok) throw new Error((r.errors && r.errors.email) || r.error || 'Something went wrong — please try again.');
      form.style.display = 'none';
      var cap = document.querySelector('.tg-captcha'); if (cap) cap.style.display = 'none';
      document.getElementById('tgSuccess').style.display = 'block';
    })
    .catch(function (ex) {
      err.textContent = ex.message || 'Something went wrong — please try again.';
      btn.textContent = label;
      btn.disabled = false;
      if (window.turnstile) { try { window.turnstile.reset(); } catch (x) {} }
    });
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
</body>
