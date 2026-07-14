<?php
/**
 * Static sticky booking bar for property listing pages.
 * Does NOT touch the database — always renders, so it shows on Render
 * even when the DB-driven gallery/booking widget are empty.
 *
 * Usage (before including):
 *   $sbb_name  = 'My Amani';
 *   $sbb_loc   = 'Vipingo · Kilifi County';
 *   $sbb_meta  = '5 Bedrooms · Up to 10 guests';   // optional
 *   $sbb_price = 'From $—/night';                    // optional
 *   $sbb_cta   = 'Check Availability';               // optional
 *   include __DIR__ . '/includes/sticky-book-bar.php';
 */
$sbb_name  = $sbb_name  ?? '';
$sbb_loc   = $sbb_loc   ?? '';
$sbb_meta  = $sbb_meta  ?? '';
$sbb_price = $sbb_price ?? '';
$sbb_cta   = $sbb_cta   ?? 'Check Availability';
if ($sbb_name === '') { return; }
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div class="sbb" id="stickyBookBar" aria-hidden="true">
  <div class="sbb__inner">
    <div class="sbb__id">
      <span class="sbb__name"><?= $e($sbb_name) ?></span>
      <?php if ($sbb_loc !== ''): ?><span class="sbb__loc"><?= $e($sbb_loc) ?></span><?php endif; ?>
    </div>
    <div class="sbb__meta">
      <?php if ($sbb_meta !== ''): ?><span class="sbb__facts"><?= $e($sbb_meta) ?></span><?php endif; ?>
      <span class="sbb__trust"><span class="sbb__stars">★★★★★</span> Free 24-hour hold</span>
      <?php if ($sbb_price !== ''): ?><span class="sbb__price"><?= $e($sbb_price) ?></span><?php endif; ?>
    </div>
    <button type="button" class="sbb__btn" id="stickyBookBtn"><?= $e($sbb_cta) ?></button>
  </div>
</div>
<style>
.sbb{
  position:fixed;bottom:0;left:0;right:0;z-index:350;
  background:rgba(255,255,255,.98);backdrop-filter:blur(10px);
  border-top:1px solid var(--border,rgba(184,150,90,.14));
  box-shadow:0 -6px 26px rgba(10,30,40,.09);
  transform:translateY(110%);transition:transform .38s cubic-bezier(.22,1,.36,1);
}
.sbb.show{transform:translateY(0);}
.sbb__inner{max-width:1280px;margin:0 auto;padding:.72rem 52px;display:flex;align-items:center;gap:1.6rem;}
.sbb__id{display:flex;flex-direction:column;line-height:1.2;min-width:0;}
.sbb__name{font-family:'Cormorant Garamond',serif;font-size:1.35rem;font-weight:400;color:var(--dark,#141412);white-space:nowrap;}
.sbb__loc{font-size:.55rem;letter-spacing:.16em;text-transform:uppercase;color:var(--light,#A89880);white-space:nowrap;}
.sbb__meta{display:flex;align-items:center;gap:1.5rem;margin-left:auto;}
.sbb__facts{font-size:.82rem;color:var(--mid,#6B6050);white-space:nowrap;}
.sbb__trust{font-size:.72rem;color:var(--mid,#6B6050);white-space:nowrap;}
.sbb__stars{color:#F4B942;letter-spacing:.05em;}
.sbb__price{font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:var(--dark,#141412);white-space:nowrap;}
.sbb__btn{
  flex-shrink:0;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);border:none;
  padding:.8rem 1.8rem;font-family:'Jost',sans-serif;font-size:.6rem;letter-spacing:.2em;
  text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;
}
.sbb__btn:hover{background:var(--sand-lt,#D4B07A);}
@media(max-width:1100px){ .sbb{display:none;} }  /* mobile keeps the page's own .sticky-cta */
</style>
<script>
(function(){
  var bar = document.getElementById('stickyBookBar');
  var btn = document.getElementById('stickyBookBtn');
  if (!bar) return;
  var hero = document.querySelector('.gallery') || document.querySelector('.breadcrumb');
  var widget = document.querySelector('#availCalendar') || document.querySelector('.book-card') || document.querySelector('.page-side');

  function inView(el){ if(!el) return false; var r = el.getBoundingClientRect(); return r.top < window.innerHeight && r.bottom > 0; }

  function update(){
    // Show once the hero has scrolled off, but hide while the booking widget itself is on screen.
    var pastHero = hero ? (hero.getBoundingClientRect().bottom < 40) : (window.scrollY > 560);
    var show = pastHero && !inView(widget);
    bar.classList.toggle('show', show);
    bar.setAttribute('aria-hidden', show ? 'false' : 'true');
  }
  window.addEventListener('scroll', update, {passive:true});
  window.addEventListener('resize', update, {passive:true});
  update();

  if (btn) btn.addEventListener('click', function(){
    var target = document.querySelector('#availCalendar') || document.querySelector('.book-card')
              || document.querySelector('.page-side') || document.getElementById('book');
    if (target){
      var y = target.getBoundingClientRect().top + window.scrollY - 84;
      window.scrollTo({top:Math.max(0,y), behavior:'smooth'});
      var focusEl = document.getElementById('bkCiBtn') || target.querySelector('input,button,select,a');
      if (focusEl) setTimeout(function(){ try{ focusEl.focus(); }catch(e){} }, 520);
    } else {
      window.location.href = 'contact.php';
    }
  });
})();
</script>
