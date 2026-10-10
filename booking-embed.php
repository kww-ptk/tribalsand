<?php
declare(strict_types=1);
/**
 * The booking widget on its own, for a property's own website (iframe).
 * /booking-embed?venue=<slug>[&from=<site host>] — see includes/booking-embed.php.
 *
 * It renders the SAME partial the property page on tribalsand.com uses and posts
 * to the same endpoints, so availability, prices, holds and enquiries are one
 * system whichever site the guest books on. No site header, footer, cookie banner
 * or chat bubble — the property website has its own.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/booking-embed.php';

$__embSlug = preg_match('/^[a-z0-9_-]{1,80}$/', (string)($_GET['venue'] ?? '')) ? (string)$_GET['venue'] : '';
$__embVenue = false; $__embPlan = null;
try {
    if ($__embSlug !== '') {
        $__embVenue = db_query('SELECT id, name, slug, location FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => $__embSlug])->fetch();
        if ($__embVenue) $__embPlan = booking_embed_plan($__embVenue['slug'], booking_embed_rooms((int)$__embVenue['id']));
    }
} catch (Throwable $e) {
    $__embVenue = false;
}
$__embFrom = booking_embed_from($_GET['from'] ?? '', $_SERVER['HTTP_REFERER'] ?? '', (string)parse_url(site_url(), PHP_URL_HOST));

if (!$__embVenue || !$__embPlan) http_response_code(404);

$page_title       = ($__embVenue ? $__embVenue['name'] . ' · ' : '') . 'Book direct · Tribal Sand';
$page_url         = site_url('booking-embed' . ($__embSlug !== '' ? '?venue=' . $__embSlug : ''));
$noindex          = true;
$page_rooms_rates = true;   // datepicker, booking widget + modal, alternatives (includes/head.php)
include __DIR__ . '/includes/head.php';
?>
<base target="_blank">
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;--sand-faint:#FAF6EE;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.14);
  --ts-sand:#B8965A;--ts-sand-lt:#D4B07A;--ts-teal:#1E5C6B;--ts-teal-d:#102F3A;
}
html,body{margin:0;padding:0;background:transparent;overflow-x:hidden}
/* position:relative makes body the containing block of the widget's pop-overs,
   so body.scrollHeight includes them and the iframe grows to show them. */
body{position:relative;font-family:'Jost',sans-serif;color:var(--dark);-webkit-font-smoothing:antialiased;padding:clamp(12px,3vw,32px);box-sizing:border-box}
.ts-embed-standalone body{min-height:100svh;display:flex;flex-direction:column;justify-content:center}
.tse{max-width:440px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:14px;overflow:visible;box-shadow:0 1px 2px rgba(16,47,58,.04)}
.tse__head{background:var(--teal-d);color:#fff;padding:18px 20px;border-radius:14px 14px 0 0}
.tse__eyebrow{font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--sand-lt);margin-bottom:4px}
.tse__name{font-family:'Cormorant Garamond',serif;font-size:1.6rem;line-height:1.1}
.tse__loc{font-size:.76rem;color:rgba(255,255,255,.65);margin-top:4px}
.tse__body{padding:0}
/* The single-room widget has no inner padding of its own (on the property page its card
   gives it none either) — inset it here, and drop its room title, which repeats the header. */
.tse__body--room{padding:18px 20px 20px}
.tse__body--room .bk-room-label{display:none}
.tse__mi{padding:20px}
.tse__mi p{font-size:.86rem;color:var(--mid);line-height:1.6;margin:0 0 14px}
.tse__btn{display:block;width:100%;padding:14px 16px;border:0;border-radius:8px;background:var(--sand);color:#fff;font:500 .78rem 'Jost',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}
.tse__btn:hover{background:var(--teal-d)}
.tse__foot{padding:10px 20px 14px;border-top:1px solid var(--border);font-size:.7rem;color:var(--light);text-align:center}
.tse__foot a{color:var(--mid)}
.tse__none{padding:28px 20px;text-align:center;font-size:.9rem;color:var(--mid)}
.tse{width:100%;max-width:440px;box-sizing:border-box;background:var(--embed-background,#fff);border-radius:var(--embed-radius,14px);box-shadow:0 8px 32px rgba(16,47,58,.08)}
.ts-embed .pa-wrap,.tse__head,.tse__body--room,.tse__mi{padding:clamp(14px,4vw,24px)}
.ts-embed .pa-dates{grid-template-columns:repeat(2,minmax(0,1fr))}
.ts-embed .pa-field{min-width:0}
.ts-embed .dp-btn{min-height:44px;padding:10px;font-size:14px}
.ts-embed .pa-step button,.ts-embed .bk-stepper button{width:40px;height:40px}
.ts-embed .bk-modal__dialog{max-height:calc(100dvh - 24px);overscroll-behavior:contain;padding:clamp(14px,4vw,24px)}
.ts-embed .pae-dlg{max-height:calc(100dvh - 36px);overscroll-behavior:contain}
@media(max-width:360px){.ts-embed .pa-dates{grid-template-columns:minmax(0,1fr)}.ts-embed .pa-opt__main{flex-direction:column;align-items:stretch}.ts-embed .pa-opt__thumb{flex:auto;width:100%}.ts-embed .pa-opt__right{align-items:flex-start;text-align:left}}
.tse__head{border-radius:var(--embed-radius,14px) var(--embed-radius,14px) 0 0}
.tse__name{font-family:var(--embed-heading-font,'Cormorant Garamond',serif)}
.ts-embed .bk-cal__title,.ts-embed .bk-date-trigger__value:not(.is-empty),.ts-embed .bk-total__price{font-family:var(--embed-heading-font,'Cormorant Garamond',serif)}
@media(max-width:360px){.tse__head,.tse__body--room{padding:14px 12px}}
:root{<?= booking_embed_theme($_GET) ?>}
</style>
</head>
<body class="ts-embed">
<div class="tse" id="tsEmbed">
<?php if (!$__embVenue || !$__embPlan): ?>
  <div class="tse__none">This booking form isn’t available. Please book on <a href="<?= e(site_url()) ?>">tribalsand.com</a>.</div>
<?php else: ?>
  <div class="tse__head">
    <div class="tse__eyebrow">Book direct</div>
    <div class="tse__name"><?= e($__embVenue['name']) ?></div>
    <?php if (trim((string)($__embVenue['location'] ?? '')) !== ''): ?><div class="tse__loc"><?= e($__embVenue['location']) ?></div><?php endif; ?>
  </div>
  <div class="tse__body<?= $__embPlan['kind'] === 'room' ? ' tse__body--room' : '' ?>">
  <?php if ($__embPlan['kind'] === 'property'): ?>
    <?php $pa_venue_slug = $__embVenue['slug']; include __DIR__ . '/includes/property-availability-widget.php'; ?>
  <?php elseif ($__embPlan['kind'] === 'room'): ?>
    <?php $bk_alternatives = true; $booking_slug = $__embPlan['room_slug']; include __DIR__ . '/includes/booking-widget.php'; ?>
  <?php else: ?>
    <div class="tse__mi">
      <p>Tell us your party and your dates, and we’ll show you the stays that fit — with the price.</p>
      <button type="button" class="tse__btn" data-mib-open>Build your stay &amp; get a price</button>
    </div>
    <?php include __DIR__ . '/includes/maya-ilai-booking.php'; ?>
  <?php endif; ?>
  </div>
  <div class="tse__foot">Secure booking by <a href="<?= e(site_url()) ?>">Tribal Sand</a></div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/success-modal.php'; ?>
<script>
/* Talks to js/booking-embed.js on the property website: reports the height the
   widget needs, and asks for the whole window while a pop-up (booking modal,
   confirmation, configurator) is open — inside a short iframe it would be cut off. */
(function () {
  document.documentElement.classList.toggle('ts-embed-standalone', window.parent === window);
  window.TS_EMBED_FROM = <?= json_encode($__embFrom) ?> || (function () {
    try { return document.referrer ? new URL(document.referrer).hostname : ''; } catch (e) { return ''; }
  })();
  if (window.parent === window) return;   // opened directly, not framed
  var id = (location.hash.match(/tsid=([\w-]+)/) || [])[1] || '';
  var last = '', lastH = 0;
  function overlayOpen() {
    var b = document.body, h = document.documentElement;
    return b.classList.contains('bk-modal-lock') || b.classList.contains('mib-locked') || h.classList.contains('mib-locked')
      || b.style.overflow === 'hidden' || !!document.querySelector('.ts-modal-backdrop');
  }
  function needed() {
    // Measure content, not body.scrollHeight (which can retain the iframe's
    // old viewport height and prevent it shrinking after a panel closes).
    var h = document.getElementById('tsEmbed').getBoundingClientRect().bottom + window.scrollY;
    var pop = document.querySelector('.dp-pop:not([hidden])');
    var a = document.activeElement;
    if (pop) {   // the date picker is fixed-position: leave room for it under its button
      var below = (a && a.classList && a.classList.contains('dp-btn')) ? a.getBoundingClientRect().bottom + window.scrollY : 0;
      h = Math.max(h, below + pop.offsetHeight + 24, lastH);   // never shrink under an open picker (it would jump)
    }
    lastH = Math.ceil(h);
    return lastH + parseFloat(getComputedStyle(document.body).paddingBottom) + 2;
  }
  function report() {
    var msg = { tsEmbed: 1, id: id, height: needed(), overlay: overlayOpen() };
    var key = msg.height + '|' + msg.overlay;
    if (key === last) return;
    last = key;
    window.parent.postMessage(msg, '*');
  }
  if (window.ResizeObserver) new ResizeObserver(report).observe(document.body);
  new MutationObserver(report).observe(document.documentElement, { attributes: true, childList: true, subtree: true });
  document.addEventListener('click', function () { setTimeout(report, 30); }, true);
  window.addEventListener('load', report);
  setInterval(report, 700);
  report();
})();
</script>
</body>
</html>
