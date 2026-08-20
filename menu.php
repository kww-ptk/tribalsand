<?php
/**
 * Public restaurant menu — DB-driven, per property.
 *   /menu.php?m=<slug>   e.g. /menu.php?m=zuri  ·  /menu.php?m=maya-kobe-breakfast
 *
 * Standalone "digital menu" page (mobile-first, noindex) — reproduces the
 * original zuri-menu design from the `menus` / `menu_categories` / `menu_items`
 * tables. Managed in /admin/menus.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/menu.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['m'] ?? ''));
$menu = $slug !== '' ? fetch_menu_by_slug($slug) : null;

if (!$menu) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$cats     = fetch_menu_categories((int)$menu['id'], false);   // public: visible cats, available items
$curLabel = $menu['currency_label'] ?: 'Kes';

$food   = array_values(array_filter($cats, fn($c) => $c['section'] === 'food'));
$drinks = array_values(array_filter($cats, fn($c) => $c['section'] === 'drinks'));
$hasFood = (bool)$food; $hasDrinks = (bool)$drinks;
$showTabs = $hasFood && $hasDrinks;

/** Render one item's badge chips. */
function menu_item_badge_html(array $it): string {
    $out = '';
    foreach (menu_badge_defs() as $col => [$cls, $label]) {
        if (!empty($it[$col]) && $it[$col] !== 'f') $out .= '<span class="badge ' . $cls . '">' . $label . '</span>';
    }
    return $out !== '' ? '<div class="item-badges">' . $out . '</div>' : '';
}
/** A category renders as a compact "sides grid" when no item has a description or badge. */
function menu_cat_is_simple(array $cat): bool {
    foreach ($cat['items'] as $it) {
        if (trim((string)$it['description']) !== '') return false;
        foreach (menu_badge_defs() as $col => $_) if (!empty($it[$col]) && $it[$col] !== 'f') return false;
    }
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0"/>
<title><?= e($menu['title']) ?> · Menu<?= $menu['subtitle'] ? ' · ' . e(strip_tags($menu['subtitle'])) : '' ?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;
  --dark:#141412;--off:#FAF8F4;--cream:#F5EFE3;--white:#fff;
  --mid:#5a4a38;--light:#8C7A60;--border:rgba(184,150,90,.18);
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html{scroll-behavior:smooth;}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased;max-width:480px;margin:0 auto;}

.menu-header{background:var(--cream);text-align:center;padding:2rem 1.5rem 1.5rem;border-bottom:1px solid var(--border);}
.menu-logo{font-family:'Cormorant Garamond',serif;font-size:2.4rem;font-weight:300;color:var(--teal-d);line-height:1;margin-bottom:.2rem;}
.menu-sub{font-size:.68rem;letter-spacing:.35em;text-transform:uppercase;color:var(--sand);margin-bottom:.9rem;}
.menu-tagline{font-size:1.02rem;color:var(--light);line-height:1.7;max-width:320px;margin:0 auto 1.2rem;font-style:italic;font-family:'Cormorant Garamond',serif;}
.menu-location{font-size:.74rem;color:var(--light);letter-spacing:.12em;}

.tabs{display:flex;background:var(--white);border-bottom:2px solid var(--border);position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.06);}
.tab{flex:1;padding:.75rem .5rem;text-align:center;font-size:1.14rem;letter-spacing:.18em;text-transform:uppercase;color:var(--light);cursor:pointer;transition:all .2s;border-bottom:2px solid transparent;margin-bottom:-2px;font-weight:500;}
.tab.on{color:var(--teal);border-bottom-color:var(--teal);}

.menu-section{display:none;}
.menu-section.on{display:block;}

.cat-header{padding:1.6rem 1.4rem .6rem;display:flex;align-items:flex-end;justify-content:space-between;gap:.5rem;}
.cat-name{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:300;color:var(--teal-d);line-height:1.05;}
.cat-name em{font-style:italic;color:var(--sand);}
.cat-tag{font-size:.5rem;letter-spacing:.2em;text-transform:uppercase;color:var(--light);text-align:right;flex-shrink:0;}
.cat-rule{height:1px;background:var(--border);margin:0 1.4rem .2rem;}

.item{padding:.95rem 1.4rem;border-bottom:1px solid rgba(184,150,90,.08);background:var(--white);margin-bottom:1px;}
.item:last-child{border-bottom:none;}
.item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.28rem;}
.item-name{font-size:1.02rem;font-weight:500;color:var(--dark);line-height:1.25;flex:1;}
.item-price{font-family:'Cormorant Garamond',serif;font-size:1.14rem;font-weight:400;color:var(--teal);white-space:nowrap;flex-shrink:0;}
.item-desc{font-size:1.14rem;color:var(--light);line-height:1.72;}
.item-badges{display:flex;gap:.3rem;margin-top:.35rem;flex-wrap:wrap;}
.badge{font-size:.66rem;padding:.15rem .45rem;border-radius:2px;letter-spacing:.06em;}
.badge.veg{background:rgba(76,150,80,.1);color:#3a7a3d;}
.badge.vegan{background:rgba(46,125,50,.1);color:#2e7d32;}
.badge.spicy{background:rgba(211,47,47,.08);color:#c62828;}
.badge.nuts{background:rgba(184,150,90,.12);color:#8C7A60;}
.badge.gluten{background:rgba(120,100,60,.1);color:#6d5c2a;}
.badge.sig{background:rgba(30,92,107,.1);color:var(--teal);}
.badge.gf{background:rgba(76,150,80,.08);color:#3a7a3d;}
.item.sig-item{border-left:2px solid var(--sand);}

.sides-grid{padding:.5rem 1.4rem;display:flex;flex-direction:column;gap:0;background:var(--white);}
.side-row{display:flex;justify-content:space-between;align-items:center;padding:.65rem 0;border-bottom:1px solid rgba(184,150,90,.08);}
.side-row:last-child{border-bottom:none;}
.side-name{font-size:.96rem;color:var(--dark);}
.side-price{font-family:'Cormorant Garamond',serif;font-size:1.08rem;color:var(--teal);}

.legend{background:var(--cream);padding:1.2rem 1.4rem;margin:.5rem 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
.legend-title{font-size:.66rem;letter-spacing:.24em;text-transform:uppercase;color:var(--sand);margin-bottom:.65rem;}
.legend-items{display:flex;flex-wrap:wrap;gap:.4rem;}
.legend-note{font-size:.78rem;color:var(--light);line-height:1.7;margin-top:.75rem;font-style:italic;}

.cat-gap{height:.75rem;background:var(--off);}

.drink-item{padding:.85rem 1.4rem;border-bottom:1px solid rgba(184,150,90,.08);background:var(--white);}
.drink-name{font-size:1.14rem;font-weight:500;color:var(--dark);margin-bottom:.2rem;}
.drink-desc{font-size:.84rem;color:var(--light);line-height:1.65;margin-bottom:.2rem;}
.drink-price{font-family:'Cormorant Garamond',serif;font-size:1.08rem;color:var(--teal);}

.menu-footer{background:var(--teal-d);padding:2rem 1.5rem;text-align:center;margin-top:.5rem;}
.footer-text{font-family:'Cormorant Garamond',serif;font-size:1.14rem;font-style:italic;color:rgba(212,196,172,.65);line-height:1.7;margin-bottom:.65rem;}
.footer-thank{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;color:rgba(255,255,255,.55);margin:.75rem 0 .25rem;}
.footer-web{font-size:.74rem;color:rgba(184,150,90,.4);letter-spacing:.12em;}

.cat-slider-wrap{position:sticky;top:<?= $showTabs ? '46px' : '0' ?>;z-index:90;background:var(--cream);border-bottom:1px solid var(--border);overflow:hidden;}
.cat-slider{display:flex;gap:0;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:0;}
.cat-slider::-webkit-scrollbar{display:none;}
.cat-pill{flex-shrink:0;scroll-snap-align:start;padding:.65rem 1.1rem;font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--light);cursor:pointer;white-space:nowrap;border-bottom:2px solid transparent;transition:all .2s;font-family:'Jost',sans-serif;font-weight:500;}
.cat-pill.on{color:var(--teal);border-bottom-color:var(--teal);background:rgba(30,92,107,.04);}
.cat-pill:active{background:rgba(30,92,107,.06);}
</style>
</head>
<body>

<div class="menu-header">
  <div class="menu-logo"><?= e($menu['title']) ?></div>
  <?php if ($menu['subtitle']): ?><div class="menu-sub"><?= e($menu['subtitle']) ?></div><?php endif; ?>
  <?php if ($menu['tagline']): ?><div class="menu-tagline"><?= e($menu['tagline']) ?></div><?php endif; ?>
  <?php if ($menu['location_label']): ?><div class="menu-location"><?= e($menu['location_label']) ?></div><?php endif; ?>
</div>

<?php if ($showTabs): ?>
<div class="tabs">
  <div class="tab on" onclick="switchTab('food',this)">🍽 Food</div>
  <div class="tab" onclick="switchTab('drinks',this)">🍹 Drinks</div>
</div>
<?php endif; ?>

<?php
// ── Render one section (food/drinks) ──────────────────────────────
$renderSection = function(string $key, array $sectionCats, bool $on, string $curLabel) {
    $isDrinks = $key === 'drinks';
    $prefix   = $isDrinks ? 'dcat' : 'cat';
    ?>
    <div class="menu-section<?= $on ? ' on' : '' ?>" id="<?= $key ?>">
      <?php if (!$isDrinks): ?>
      <div class="legend">
        <div class="legend-title">Key</div>
        <div class="legend-items">
          <span class="badge veg">🌿 Vegetarian</span>
          <span class="badge vegan">🌱 Vegan</span>
          <span class="badge spicy">🌶 Spicy</span>
          <span class="badge nuts">🥜 Contains Nuts</span>
          <span class="badge gluten">🌾 Contains Gluten</span>
          <span class="badge sig">★ Signature</span>
        </div>
        <div class="legend-note">Some dishes may contain nuts, gluten, shellfish or spices. Please inform our team of any allergies or dietary requirements — we will be delighted to prepare an alternative.</div>
      </div>
      <?php endif; ?>

      <div class="cat-slider-wrap">
        <div class="cat-slider" id="<?= $prefix ?>Slider">
          <?php foreach ($sectionCats as $i => $c): ?>
          <div class="cat-pill<?= $i === 0 ? ' on' : '' ?>" onclick="scrollToCat('<?= $prefix ?>-<?= (int)$c['id'] ?>',this,'<?= $prefix ?>Slider')"><?= e(trim(($c['icon'] ? $c['icon'] . ' ' : '') . $c['name'])) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php foreach ($sectionCats as $c): if (!$c['items']) continue; ?>
      <div id="<?= $prefix ?>-<?= (int)$c['id'] ?>"></div>
      <div class="cat-header">
        <div class="cat-name"><?= e($c['name']) ?></div>
        <?php if ($c['tag']): ?><div class="cat-tag"><?= e($c['tag']) ?></div><?php endif; ?>
      </div>
      <div class="cat-rule"></div>

      <?php if ($isDrinks): ?>
        <?php foreach ($c['items'] as $it): $price = menu_price_label($it['price'], $curLabel); ?>
        <div class="drink-item">
          <div class="drink-name"><?= e($it['name']) ?></div>
          <?php if (trim((string)$it['description']) !== ''): ?><div class="drink-desc"><?= e($it['description']) ?></div><?php endif; ?>
          <?php if ($price !== ''): ?><div class="drink-price"><?= e($price) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php elseif (menu_cat_is_simple($c)): ?>
        <div class="sides-grid">
          <?php foreach ($c['items'] as $it): $price = menu_price_label($it['price'], $curLabel); ?>
          <div class="side-row"><span class="side-name"><?= e($it['name']) ?></span><?php if ($price !== ''): ?><span class="side-price"><?= e($price) ?></span><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?php foreach ($c['items'] as $it): $price = menu_price_label($it['price'], $curLabel); $sig = (!empty($it['is_signature']) && $it['is_signature'] !== 'f'); ?>
        <div class="item<?= $sig ? ' sig-item' : '' ?>">
          <div class="item-top">
            <div class="item-name"><?= e($it['name']) ?></div>
            <?php if ($price !== ''): ?><div class="item-price"><?= e($price) ?></div><?php endif; ?>
          </div>
          <?php if (trim((string)$it['description']) !== ''): ?><div class="item-desc"><?= e($it['description']) ?></div><?php endif; ?>
          <?= menu_item_badge_html($it) ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="cat-gap"></div>
      <?php endforeach; ?>
    </div>
    <?php
};

$renderSection('food',   $food,   $hasFood, $curLabel);
$renderSection('drinks', $drinks, $hasDrinks && !$hasFood, $curLabel);
?>

<div class="menu-footer">
  <?php if ($menu['footer_note']): ?><div class="footer-text"><?= e($menu['footer_note']) ?></div><?php endif; ?>
  <div class="footer-thank">Truly, Thank You.</div>
  <div class="footer-web">tribalsand.com · reservations@tribalsand.com</div>
</div>

<script>
function scrollToCat(id, el, sliderId){
  document.querySelectorAll('#'+sliderId+' .cat-pill').forEach(p=>p.classList.remove('on'));
  el.classList.add('on');
  el.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
  var target = document.getElementById(id);
  if(target){
    var offset = target.getBoundingClientRect().top + window.scrollY - 100;
    window.scrollTo({top:offset, behavior:'smooth'});
  }
}
function switchTab(id, el){
  document.querySelectorAll('.menu-section').forEach(s=>s.classList.remove('on'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));
  document.getElementById(id).classList.add('on');
  el.classList.add('on');
  window.scrollTo({top:0,behavior:'smooth'});
}
// Highlight the active pill as you scroll each section.
window.addEventListener('scroll', function(){
  var section = document.querySelector('.menu-section.on');
  if(!section) return;
  var slider = section.querySelector('.cat-slider');
  if(!slider) return;
  var pills = slider.querySelectorAll('.cat-pill');
  var anchors = section.querySelectorAll('[id^="cat-"],[id^="dcat-"]');
  // Only the empty anchor divs (not the pills) — filter to those preceding a cat-header
  var current = 0, idx = 0;
  section.querySelectorAll('div[id]').forEach(function(el){
    if(/^d?cat-\d+$/.test(el.id)){
      if(el.getBoundingClientRect().top < 120) current = idx;
      idx++;
    }
  });
  pills.forEach(function(p,i){ p.classList.toggle('on', i===current); });
}, {passive:true});
</script>
</body>
</html>
