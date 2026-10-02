<?php
/**
 * The public restaurant menu page ("Cards" design) — rendered by menu.php, which
 * prepares: $menu, $food, $drinks, $curLabel, $reserveSlug.
 *
 * A dark teal header like the property pages, a sticky toolbar (Food | Drinks +
 * course chips that follow the reader), and each dish as a card on a responsive
 * grid (three across on a desktop, one on a phone) with its photo when it has
 * one (menu_items.image_key, includes/menu-images.php). Signature dishes get a
 * sand edge and a "Chef's signature" label; plain lists (sides, soft drinks) stay
 * a compact two-column list. With JS off Food and Drinks show one after the other.
 * Everything from the DB goes through e().
 */
declare(strict_types=1);
require_once __DIR__ . '/menu-images.php';

$sections = array_filter(['food' => $food, 'drinks' => $drinks]);

/** A boolean column as PDO returns it from Postgres (true / 't' / '1'). */
function menu_flag(array $it, string $col): bool {
    $v = $it[$col] ?? false;
    return $v === true || $v === 't' || $v === 1 || $v === '1';
}

/** The round dietary marks for a dish (V, VG, S, N, G, GF), each with its full name as the tooltip. */
function menu_marks_html(array $it): string {
    $h = '';
    foreach (['is_veg' => ['V', 'Vegetarian'], 'is_vegan' => ['VG', 'Vegan'], 'is_spicy' => ['S', 'Spicy'],
              'has_nuts' => ['N', 'Contains nuts'], 'has_gluten' => ['G', 'Contains gluten'], 'is_gf' => ['GF', 'Gluten-free']] as $col => [$code, $label]) {
        if (menu_flag($it, $col)) $h .= '<span class="mk" title="' . e($label) . '" aria-label="' . e($label) . '">' . e($code) . '</span>';
    }
    return $h;
}

$soldOut = fn(array $it): string => menu_item_sold_out($it) ? ' <span class="so">Sold out</span>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= e($menu['title']) ?> · Menu<?= $menu['subtitle'] ? ' · ' . e(strip_tags($menu['subtitle'])) : '' ?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--sand:#B8965A;--sand-lt:#D4B07A;--teal-d:#102F3A;--ink:#1d1b17;--off:#FAF8F4;--cream:#F5EFE3;--paper:#FFFDF9;
  --muted:#7c6d58;--line:rgba(184,150,90,.22);--line-soft:rgba(184,150,90,.12)}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--ink);-webkit-font-smoothing:antialiased}
.cd-hero{background:var(--teal-d);color:#fff;text-align:center;padding:3.6rem 1.5rem 3rem;position:relative;overflow:hidden}
.cd-hero:after{content:'';position:absolute;inset:auto -10% -60% -10%;height:120%;background:radial-gradient(closest-side,rgba(184,150,90,.18),transparent);pointer-events:none}
.cd-sub{font-size:.66rem;letter-spacing:.4em;text-transform:uppercase;color:var(--sand-lt)}
.cd-title{font:300 clamp(2.8rem,7vw,4.4rem)/1 'Cormorant Garamond',serif;margin:.7rem 0 .9rem}
.cd-tagline{font:italic 400 1.15rem/1.65 'Cormorant Garamond',serif;color:rgba(255,255,255,.72);max-width:580px;margin:0 auto}
.cd-loc{font-size:.72rem;letter-spacing:.16em;color:rgba(212,176,122,.8);margin-top:1rem}
.cd-bar{position:sticky;top:0;z-index:30;background:rgba(250,248,244,.96);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.cd-bar__in{max-width:1240px;margin:0 auto;display:flex;align-items:center;gap:1rem;padding:.75rem clamp(14px,3vw,40px)}
.cd-seg{display:inline-flex;flex:none;background:var(--cream);border-radius:99px;padding:4px}
.cd-seg button{border:0;background:none;font:500 .68rem 'Jost',sans-serif;letter-spacing:.24em;text-transform:uppercase;color:var(--teal-d);padding:.65rem 1.4rem;border-radius:99px;cursor:pointer}
.cd-seg button.on{background:var(--teal-d);color:#fff}
.cd-chips{display:flex;gap:.4rem;overflow-x:auto;scrollbar-width:none;min-width:0}
.cd-chips::-webkit-scrollbar{display:none}
.cd-chips a{flex:none;font-size:.78rem;color:var(--muted);text-decoration:none;padding:.5rem .95rem;border:1px solid var(--line);border-radius:99px;white-space:nowrap;background:var(--paper)}
.cd-chips a.on{color:var(--teal-d);border-color:var(--teal-d);font-weight:500}
.cd-chips[hidden],.cd-sec[hidden]{display:none}
.cd-main{max-width:1240px;margin:0 auto;padding:2.2rem clamp(14px,3vw,40px) 2.6rem}
.cd-cat{margin-bottom:2.8rem;scroll-margin-top:84px}
.cd-cat__head{display:flex;align-items:baseline;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--line);padding-bottom:.7rem;margin-bottom:1.2rem}
.cd-cat__name{font:400 2.1rem/1.1 'Cormorant Garamond',serif;color:var(--teal-d)}
.cd-cat__tag{font-size:.62rem;letter-spacing:.3em;text-transform:uppercase;color:var(--muted);text-align:right}
.cd-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.cd-card{position:relative;background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:1.1rem 1.15rem;display:flex;gap:1rem;transition:box-shadow .2s,transform .2s}
.cd-card:hover{box-shadow:0 10px 26px rgba(16,47,58,.08);transform:translateY(-1px)}
.cd-card.sig{border-color:var(--sand);background:linear-gradient(180deg,#fffaf0,var(--paper) 60%)}
.cd-card__img{flex:none;width:104px;height:104px;border-radius:10px;object-fit:cover;background:var(--cream)}
.cd-card__body{min-width:0;flex:1;display:flex;flex-direction:column;gap:.4rem}
.cd-card__sig{font-size:.58rem;letter-spacing:.26em;text-transform:uppercase;color:var(--sand);font-weight:500}
.cd-card__top{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem}
.cd-card .nm{font:500 1.3rem/1.2 'Cormorant Garamond',serif;color:var(--ink)}
.cd-card .pr{flex:none;font:500 .8rem 'Jost',sans-serif;color:var(--teal-d);background:var(--cream);border-radius:99px;padding:.3rem .68rem;white-space:nowrap}
.cd-card .ds{font-size:.88rem;line-height:1.6;color:var(--muted)}
.cd-marks{display:flex;gap:.3rem;margin-top:auto;padding-top:.2rem}
.mk{display:inline-grid;place-items:center;min-width:24px;height:24px;padding:0 4px;border-radius:99px;background:var(--cream);font:500 .56rem 'Jost',sans-serif;color:var(--muted)}
.cd-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 2.4rem;background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:.4rem 1.4rem}
.cd-row{display:flex;justify-content:space-between;gap:1rem;padding:.8rem 0;border-bottom:1px solid var(--line-soft)}
.cd-row .nm{font:500 1.1rem 'Cormorant Garamond',serif}
.cd-row .pr{font:500 .85rem 'Jost',sans-serif;color:var(--teal-d);white-space:nowrap}
.so{display:inline-block;vertical-align:middle;margin-left:.45rem;font:500 .6rem/1 'Jost',sans-serif;letter-spacing:.1em;text-transform:uppercase;padding:.28rem .5rem;border-radius:99px;background:rgba(140,122,96,.14);color:#6d5c2a}
.is-out .nm,.is-out .pr,.is-out .ds,.is-out .cd-card__img{opacity:.45}
.cd-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:.6rem 1.3rem;font-size:.8rem;color:var(--muted);padding:1.4rem 0 0;border-top:1px solid var(--line)}
.lg-i{display:inline-flex;align-items:center;gap:.4rem}.cd-legend .star{color:var(--sand)}
.cd-legend p{flex-basis:100%;text-align:center;font:italic 400 1rem 'Cormorant Garamond',serif}
.ml-reserve{background:var(--cream);text-align:center;padding:2.4rem 1.5rem;border-top:1px solid var(--line)}
.ml-reserve p{font:italic 400 1.15rem 'Cormorant Garamond',serif;color:var(--muted);margin-bottom:.9rem}
.ml-reserve a{display:inline-block;background:var(--teal-d);color:#fff;text-decoration:none;font-size:.68rem;letter-spacing:.24em;text-transform:uppercase;font-weight:500;padding:.95rem 2.2rem;border-radius:99px}
.ml-foot{background:var(--teal-d);text-align:center;padding:2.6rem 1.5rem}
.ml-foot__note{font:italic 400 1.1rem/1.7 'Cormorant Garamond',serif;color:rgba(212,196,172,.7);max-width:560px;margin:0 auto .6rem}
.ml-foot__thanks{font:300 1.9rem 'Cormorant Garamond',serif;color:rgba(255,255,255,.6);margin:.6rem 0 .3rem}
.ml-foot__web{font-size:.72rem;letter-spacing:.14em;color:rgba(184,150,90,.55)}
@media (max-width:1100px){.cd-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){
  .cd-grid,.cd-list{grid-template-columns:1fr}
  .cd-card__img{width:88px;height:88px}
  .cd-bar__in{flex-wrap:wrap;gap:.6rem}
  .cd-seg{width:100%;justify-content:center}.cd-seg button{flex:1}
  .cd-chips{width:100%}
  .cd-cat__head{flex-direction:column;align-items:flex-start;gap:.2rem}.cd-cat__tag{text-align:left}
}
</style>
</head>
<body>
<header class="cd-hero">
  <?php if ($menu['subtitle']): ?><div class="cd-sub"><?= e($menu['subtitle']) ?></div><?php endif; ?>
  <h1 class="cd-title"><?= e($menu['title']) ?></h1>
  <?php if ($menu['tagline']): ?><p class="cd-tagline"><?= e($menu['tagline']) ?></p><?php endif; ?>
  <?php if ($menu['location_label']): ?><p class="cd-loc"><?= e($menu['location_label']) ?></p><?php endif; ?>
</header>

<div class="cd-bar"><div class="cd-bar__in">
  <?php if (count($sections) > 1): ?>
  <div class="cd-seg" role="tablist">
    <?php $i = 0; foreach ($sections as $key => $_): ?>
    <button type="button" class="<?= $i++ === 0 ? 'on' : '' ?>" data-cd-sec="<?= $key ?>"><?= $key === 'food' ? 'Food' : 'Drinks' ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php $i = 0; foreach ($sections as $key => $cats): ?>
  <nav class="cd-chips" data-cd-chips="<?= $key ?>"<?= $i++ > 0 ? ' data-cd-later' : '' ?> aria-label="<?= $key === 'food' ? 'Courses' : 'Drinks' ?>">
    <?php foreach ($cats as $c): if (!$c['items']) continue; ?><a href="#cd-c<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
  </nav>
  <?php endforeach; ?>
</div></div>

<main class="cd-main">
  <?php $i = 0; foreach ($sections as $key => $cats): ?>
  <section class="cd-sec" id="cd-<?= $key ?>"<?= $i++ > 0 ? ' data-cd-later' : '' ?>>
    <?php foreach ($cats as $c): if (!$c['items']) continue; ?>
    <div class="cd-cat" id="cd-c<?= (int)$c['id'] ?>">
      <div class="cd-cat__head"><h2 class="cd-cat__name"><?= e($c['name']) ?></h2><?php if ($c['tag']): ?><span class="cd-cat__tag"><?= e($c['tag']) ?></span><?php endif; ?></div>
      <?php if (menu_cat_is_simple($c)): ?>
      <div class="cd-list">
        <?php foreach ($c['items'] as $it): $price = menu_price_label($it['price'], $curLabel); ?>
        <div class="cd-row<?= menu_item_sold_out($it) ? ' is-out' : '' ?>"><span class="nm"><?= e($it['name']) ?><?= $soldOut($it) ?></span><?php if ($price !== ''): ?><span class="pr"><?= e($price) ?></span><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="cd-grid">
        <?php foreach ($c['items'] as $it): $price = menu_price_label($it['price'], $curLabel); $sig = menu_flag($it, 'is_signature'); $img = menu_item_image_url($it); ?>
        <div class="cd-card<?= $sig ? ' sig' : '' ?><?= menu_item_sold_out($it) ? ' is-out' : '' ?>">
          <?php if ($img !== ''): ?><img class="cd-card__img" src="<?= e($img) ?>" alt="<?= e($it['name']) ?>" loading="lazy" decoding="async" width="104" height="104" onerror="this.remove()"><?php endif; ?>
          <div class="cd-card__body">
            <?php if ($sig): ?><span class="cd-card__sig">★ Chef’s signature</span><?php endif; ?>
            <div class="cd-card__top"><span class="nm"><?= e($it['name']) ?><?= $soldOut($it) ?></span><?php if ($price !== ''): ?><span class="pr"><?= e($price) ?></span><?php endif; ?></div>
            <?php if (trim((string)$it['description']) !== ''): ?><p class="ds"><?= e($it['description']) ?></p><?php endif; ?>
            <?php if (($mk = menu_marks_html($it)) !== ''): ?><div class="cd-marks"><?= $mk ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
  <div class="cd-legend">
    <?php foreach ([['V', 'Vegetarian'], ['VG', 'Vegan'], ['S', 'Spicy'], ['N', 'Contains nuts'], ['G', 'Contains gluten'], ['GF', 'Gluten-free']] as [$code, $label]): ?>
    <span class="lg-i"><span class="mk"><?= $code ?></span><?= $label ?></span>
    <?php endforeach; ?>
    <span class="lg-i"><span class="star">★</span>Chef’s signature</span>
    <p>Please tell us about any allergies or dietary requirements — we will gladly prepare an alternative.</p>
  </div>
</main>

<?php if ($reserveSlug !== ''): ?>
<section class="ml-reserve">
  <p>Join us for a meal</p>
  <a href="/reserve.php?venue=<?= e($reserveSlug) ?>">Reserve a table</a>
</section>
<?php endif; ?>
<footer class="ml-foot">
  <?php if ($menu['footer_note']): ?><p class="ml-foot__note"><?= e($menu['footer_note']) ?></p><?php endif; ?>
  <p class="ml-foot__thanks">Truly, thank you.</p>
  <p class="ml-foot__web">tribalsand.com · reservations@tribalsand.com</p>
</footer>

<script>
(function () {
  // Food | Drinks switch in place (with JS off both halves show, one after the other).
  document.querySelectorAll('[data-cd-later]').forEach(function (el) { el.hidden = true; });
  document.querySelectorAll('[data-cd-sec]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.dataset.cdSec;
      document.querySelectorAll('[data-cd-sec]').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('.cd-sec').forEach(function (s) { s.hidden = s.id !== 'cd-' + k; });
      document.querySelectorAll('[data-cd-chips]').forEach(function (n) { n.hidden = n.dataset.cdChips !== k; });
      window.scrollTo({ top: document.querySelector('.cd-bar').offsetTop, behavior: 'smooth' });
    });
  });
  // Light the chip of the course in view.
  function spy() {
    var nav = document.querySelector('[data-cd-chips]:not([hidden])'); if (!nav) return;
    var links = nav.querySelectorAll('a'), cur = 0;
    links.forEach(function (a, i) { var c = document.querySelector(a.getAttribute('href')); if (c && c.getBoundingClientRect().top < 120) cur = i; });
    links.forEach(function (a, i) { a.classList.toggle('on', i === cur); });
    var on = links[cur]; if (on && nav.scrollWidth > nav.clientWidth) nav.scrollTo({ left: on.offsetLeft - 20, behavior: 'smooth' });
  }
  window.addEventListener('scroll', spy, { passive: true }); spy();
})();
</script>
</body>
</html>
