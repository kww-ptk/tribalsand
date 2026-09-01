<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

/* ── Inputs ── */
$checkin  = trim($_GET['checkin']  ?? '');
$checkout = trim($_GET['checkout'] ?? '');
$adults   = max(1, (int)($_GET['adults']   ?? 2));
$children = max(0, (int)($_GET['children'] ?? 0));
$guests   = $adults + $children;

$today = date('Y-m-d');
$valid = ($checkin !== '' && $checkout !== ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout)
    && $checkin >= $today && $checkout > $checkin);

$results = [];
$nights  = 0;
$error   = '';
if ($valid) {
    $nights = (int)((strtotime($checkout) - strtotime($checkin)) / 86400);
    try {
        $results = ts_search_availability($checkin, $checkout, $guests);
    } catch (Throwable $e) {
        $error = 'We could not check live availability right now. Please try again, or contact us.';
    }
} elseif ($checkin !== '' || $checkout !== '') {
    $error = 'Please choose a check-in date and a later check-out date.';
}

$total_available = array_sum(array_map(fn($r) => $r['count'] > 0 ? 1 : 0, $results));
$fmt_date = fn($d) => $d ? date('D, j M Y', strtotime($d)) : '';
$money = fn($n, $cur) => (float)$n > 0 ? number_format((float)$n, 0) . ' ' . e($cur) : '';

/* No-JS currency links: keep the whole search query (dates, guests, filters) and
   only swap `cur`. The header's plain "?cur=CODE" would drop the search. */
$cur_url = function (string $code): string {
    $q = $_GET; $q['cur'] = $code;
    return '?' . http_build_query($q);
};

/* Property type per venue (mirrors the homepage "Our Properties" filters). */
$venue_type = [
    'maya-kobe' => 'hotel', 'zuri' => 'hotel', 'maya_ilai' => 'hotel',
    'my-amani'  => 'villa', 'enkare-bofa' => 'villa', 'sandbox' => 'villa',
];
/* Shown on each result card. Wording matches the filter chips above the list,
   so "Private Villas" as a filter and "Private Villa" on the card agree. */
$venue_type_label = ['hotel' => 'Boutique Hotel', 'villa' => 'Private Villa'];
$loc_slug = fn($loc) => strtolower(trim(preg_split('/[·,]/', (string)$loc)[0] ?? ''));

/* ── Page meta ── */
$page_title = 'Search Availability · Tribal Sand';
$page_desc  = 'Check live availability across Tribal Sand\'s beachfront hotels and villas on the Kenyan coast.';
$page_url   = site_url('search');
$page_rooms_rates = true; // loads booking widget + modal assets

include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/header.php';
?>
<style>
:root{--sand:#B8965A;--sand-lt:#D4B07A;--sand-faint:#FAF6EE;--teal:#1E5C6B;--teal-d:#102F3A;--dark:#141412;--off:#FAF8F4;--white:#fff;--mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.16);}
.srch{background:var(--off);min-height:70vh;}
.srch-hero{background:var(--teal-d);padding:96px 5vw 26px;}
.srch-hero__eyebrow{font-size:.62rem;letter-spacing:.24em;text-transform:uppercase;color:var(--sand-lt);margin-bottom:.5rem;}
.srch-hero__title{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(1.8rem,4vw,2.8rem);color:#fff;margin:0;}
/* search form */
.srch-form{max-width:1120px;margin:-22px auto 0;padding:0 5vw;}
.srch-form__card{background:#fff;border-radius:6px;box-shadow:0 16px 44px rgba(10,30,40,.14);display:flex;flex-wrap:wrap;align-items:flex-end;gap:0;padding:.5rem;}
.srch-field{flex:1;min-width:150px;display:flex;flex-direction:column;padding:.55rem 1rem;border-right:1px solid var(--border);}
.srch-field:last-of-type{border-right:none;}
.srch-field label{font-size:.58rem;letter-spacing:.16em;text-transform:uppercase;color:var(--sand);font-weight:600;margin-bottom:.3rem;}
.srch-field input,.srch-field select{border:none;background:none;font-family:'Jost',sans-serif;font-size:.95rem;color:var(--dark);padding:0;width:100%;cursor:pointer;}
.srch-field input:focus,.srch-field select:focus{outline:none;}
/* styled datepicker trigger — borderless to match the other fields */
.srch-field .dp-btn{border:none;background:none;padding:0;font-family:'Jost',sans-serif;font-size:.95rem;color:var(--dark);}
.srch-field .dp-btn:hover,.srch-field .dp-btn:focus{border:none;}
.srch-form__btn{flex-shrink:0;background:var(--teal-d);color:#fff;border:none;border-radius:4px;margin:.3rem;padding:0 1.7rem;height:52px;font-family:'Jost',sans-serif;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;}
.srch-form__btn:hover{background:var(--teal);}
/* results */
.srch-wrap{max-width:1120px;margin:0 auto;padding:2.4rem 5vw 5rem;}
.srch-summary{font-size:.95rem;color:var(--mid);margin-bottom:1.6rem;}
.srch-summary strong{color:var(--dark);font-weight:600;}
.srch-alert{background:var(--sand-faint);border:1px solid var(--border);border-radius:6px;padding:1rem 1.2rem;color:var(--mid);font-size:.92rem;margin-bottom:1.4rem;}
.vcard{display:grid;grid-template-columns:280px 1fr;gap:0;background:#fff;border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:1.2rem;transition:box-shadow .2s;}
.vcard:hover{box-shadow:0 10px 34px rgba(10,30,40,.08);}
.vcard.is-sold .vcard__body{opacity:.72;}
.vcard__img{position:relative;min-height:200px;background:linear-gradient(135deg,#2f7a6b,#0d2b33);}
.vcard__img img{width:100%;height:100%;object-fit:cover;display:block;position:absolute;inset:0;}
.vcard__body{padding:1.4rem 1.6rem;display:flex;flex-direction:column;}
.vcard__loc{font-size:.56rem;letter-spacing:.2em;text-transform:uppercase;color:var(--sand);margin-bottom:.3rem;}
.vcard__type{color:var(--teal);font-weight:600;}
.vcard__name{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.7rem;color:var(--dark);line-height:1.05;margin-bottom:.3rem;}
.vcard__name a{color:inherit;}
.vcard__meta{font-size:.86rem;color:var(--light);margin-bottom:.9rem;}
.vcard__status{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap;margin-top:auto;}
.vcard__avail{display:inline-flex;align-items:center;gap:.4rem;font-size:.74rem;font-weight:600;letter-spacing:.01em;padding:.32rem .72rem;border-radius:999px;}
.vcard__avail svg{flex-shrink:0;}
.vcard__avail.ok{color:#0F6E56;background:#E1F5EE;}
.vcard__avail.no{color:#7A2417;background:#F6D9CF;}
.vcard__from{margin-left:auto;text-align:right;}
.vcard__from small{display:block;font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.vcard__from b{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:1.35rem;color:var(--dark);}
.vcard__toggle{margin-top:1rem;align-self:flex-start;background:var(--sand);color:var(--teal-d);border:none;border-radius:4px;padding:.7rem 1.4rem;font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;}
.vcard__toggle:hover{background:var(--sand-lt);}
.vcard__view{margin-top:1rem;align-self:flex-start;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--teal);border-bottom:1px solid var(--border);padding-bottom:2px;}
/* room list — image-forward card grid */
.rlist{grid-column:1 / -1;border-top:1px solid var(--border);background:var(--sand-faint);display:none;}
.rlist.open{display:block;}
.rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1.1rem;padding:1.4rem 1.6rem;}
.rcard{display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;transition:box-shadow .2s,transform .2s;}
.rcard:hover{box-shadow:0 12px 30px rgba(10,30,40,.10);transform:translateY(-2px);}
.rcard__img{position:relative;aspect-ratio:16/10;background:linear-gradient(135deg,#2f7a6b,#0d2b33);}
.rcard__img img{width:100%;height:100%;object-fit:cover;display:block;position:absolute;inset:0;}
.rcard__tag{position:absolute;top:.6rem;left:.6rem;font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;color:#fff;background:rgba(16,47,58,.82);padding:.22rem .55rem;border-radius:999px;font-weight:600;}
.rcard__body{display:flex;flex-direction:column;flex:1;padding:.95rem 1.05rem 1.05rem;}
.rcard__name{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:1.28rem;line-height:1.1;color:var(--dark);}
.rcard__desc{font-size:.82rem;color:var(--light);line-height:1.5;margin-top:.4rem;}
.rcard__chips{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.55rem;}
.rchip{display:inline-flex;align-items:center;gap:.32rem;font-size:.68rem;letter-spacing:.02em;color:#5C5340;background:#F1E9DA;border-radius:999px;padding:.28rem .6rem;}
.rchip svg{color:var(--sand);flex-shrink:0;}
.rcard__foot{display:flex;align-items:flex-end;justify-content:space-between;gap:.6rem;margin-top:auto;padding-top:.95rem;}
.rcard__price b{display:block;font-family:'Cormorant Garamond',serif;font-weight:600;font-size:1.3rem;color:var(--dark);line-height:1;}
.rcard__price small{display:block;font-size:.64rem;letter-spacing:.02em;color:var(--light);margin-top:.25rem;}
.rcard__btn{flex-shrink:0;display:inline-flex;align-items:center;gap:.4rem;background:var(--teal-d);color:#fff;border:none;border-radius:6px;padding:.6rem 1.1rem;font-family:'Jost',sans-serif;font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;}
.rcard__btn svg{flex-shrink:0;}
.rcard__btn:hover{background:var(--teal);}
.srch-empty{text-align:center;padding:3rem 1rem;color:var(--mid);}
/* filter + sort toolbar */
.srch-tools{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem 1.4rem;margin-bottom:1.6rem;padding-bottom:1.2rem;border-bottom:1px solid var(--border);}
.srch-filters{display:flex;flex-wrap:wrap;gap:.5rem;}
.srch-chip{font-family:'Jost',sans-serif;font-size:.66rem;letter-spacing:.12em;text-transform:uppercase;font-weight:600;color:var(--mid);background:#fff;border:1px solid var(--border);border-radius:999px;padding:.5rem 1rem;cursor:pointer;transition:all .18s;}
.srch-chip:hover{border-color:var(--sand);color:var(--teal-d);}
.srch-chip.on{background:var(--teal-d);border-color:var(--teal-d);color:#fff;}
.srch-sort{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;}
.srch-sort__lbl{font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;color:var(--sand);font-weight:600;margin-right:.2rem;}
.srch-sortbtn{font-family:'Jost',sans-serif;font-size:.66rem;letter-spacing:.06em;color:var(--mid);background:none;border:1px solid transparent;border-radius:999px;padding:.42rem .8rem;cursor:pointer;transition:all .18s;}
.srch-sortbtn:hover{color:var(--teal-d);}
.srch-sortbtn.on{color:var(--teal-d);background:var(--sand-faint);border-color:var(--border);font-weight:600;}
.srch-tools__right{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1.2rem;margin-left:auto;}
.srch-cur{display:flex;align-items:center;gap:.4rem;}
/* Currency switcher, light-theme. Base .ts-cur* styles live in includes/header.php
   and are built for the dark nav; these re-skin the same markup for this page.
   Classes and data-attributes are unchanged so js/currency.js binds it as-is. */
.srch-cur .ts-cur-btn{color:var(--mid);background:#fff;border:1px solid var(--border);border-radius:999px;padding:.44rem .9rem;font-size:.64rem;}
.srch-cur .ts-cur-btn:hover{color:var(--teal-d);border-color:var(--sand);background:#fff;}
.srch-cur .ts-cur-chev{opacity:.7;}
.srch-cur .ts-cur-menu{background:#fff;backdrop-filter:none;-webkit-backdrop-filter:none;border:1px solid var(--border);border-top:2px solid var(--sand);border-radius:0 0 6px 6px;box-shadow:0 18px 40px rgba(20,20,18,.14);}
.srch-cur .ts-cur-head{color:var(--sand);}
.srch-cur .ts-cur-opt:hover{background:var(--sand-faint);}
.srch-cur .ts-cur-opt-code{color:var(--dark);}
.srch-cur .ts-cur-opt-name{color:var(--light);}
.srch-cur .ts-cur-opt:hover .ts-cur-opt-code{color:var(--teal-d);}
.srch-cur .ts-cur-opt.on{background:var(--sand-faint);}
.srch-cur .ts-cur-opt.on .ts-cur-opt-code{color:var(--teal-d);font-weight:600;}
@media(max-width:720px){.srch-tools{flex-direction:column;align-items:flex-start;}}
@media(max-width:720px){
  .vcard{grid-template-columns:1fr;}
  .vcard__img{min-height:180px;}
  .srch-field{flex:1 1 45%;border-right:none;}
  .srch-form__btn{flex:1 1 100%;}
  .rgrid{grid-template-columns:1fr 1fr;gap:.8rem;padding:1rem 1.1rem;}
}
@media(max-width:460px){
  .rgrid{grid-template-columns:1fr;}
}
</style>

<div class="srch">
  <div class="srch-hero">
    <div class="srch-hero__eyebrow">Tribal Sand · Kenya's North Coast</div>
    <h1 class="srch-hero__title">Find your stay</h1>
  </div>

  <!-- Search / edit form -->
  <div class="srch-form">
    <form class="srch-form__card" method="GET" action="/search">
      <div class="srch-field">
        <label>Check-in</label>
        <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="srch" data-dp-target="fCheckin" data-dp-placeholder="Add date">Add date</button>
        <input type="hidden" id="fCheckin" name="checkin" value="<?= e($checkin) ?>">
      </div>
      <div class="srch-field">
        <label>Check-out</label>
        <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="srch" data-dp-target="fCheckout" data-dp-placeholder="Add date">Add date</button>
        <input type="hidden" id="fCheckout" name="checkout" value="<?= e($checkout) ?>">
      </div>
      <div class="srch-field">
        <label for="fAdults">Adults</label>
        <select id="fAdults" name="adults"><?php for ($i=1;$i<=16;$i++): ?><option value="<?= $i ?>"<?= $i===$adults?' selected':'' ?>><?= $i ?></option><?php endfor; ?></select>
      </div>
      <div class="srch-field">
        <label for="fChildren">Children</label>
        <select id="fChildren" name="children"><?php for ($i=0;$i<=10;$i++): ?><option value="<?= $i ?>"<?= $i===$children?' selected':'' ?>><?= $i ?></option><?php endfor; ?></select>
      </div>
      <button type="submit" class="srch-form__btn">Search</button>
    </form>
  </div>

  <div class="srch-wrap">
    <?php if ($error): ?>
      <div class="srch-alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$valid): ?>
      <div class="srch-empty">
        <p style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--teal);margin-bottom:.4rem">Choose your dates to see live availability</p>
        <p style="color:var(--light)">Pick a check-in and check-out date above and we'll show what's open across all our properties.</p>
      </div>
    <?php else: ?>
      <div class="srch-summary">
        <strong id="srchCount"><?= $total_available ?></strong> <span id="srchNoun">propert<?= $total_available === 1 ? 'y' : 'ies' ?></span> available &nbsp;·&nbsp;
        <?= e($fmt_date($checkin)) ?> → <?= e($fmt_date($checkout)) ?> &nbsp;·&nbsp;
        <?= $nights ?> night<?= $nights !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
        <?= $adults ?> adult<?= $adults !== 1 ? 's' : '' ?><?= $children ? ' · ' . $children . ' child' . ($children !== 1 ? 'ren' : '') : '' ?>
      </div>

      <?php if ($results): $__multi = count($results) > 1; $__cur = current_currency(); ?>
      <div class="srch-tools">
        <?php if ($__multi): ?>
        <div class="srch-filters" role="group" aria-label="Filter properties">
          <button type="button" class="srch-chip on" data-filter="all">All Properties</button>
          <button type="button" class="srch-chip" data-filter="type:hotel">Boutique Hotels</button>
          <button type="button" class="srch-chip" data-filter="type:villa">Private Villas</button>
          <button type="button" class="srch-chip" data-filter="loc:watamu">Watamu</button>
          <button type="button" class="srch-chip" data-filter="loc:kilifi">Kilifi</button>
          <button type="button" class="srch-chip" data-filter="loc:vipingo">Vipingo</button>
        </div>
        <?php endif; ?>
        <div class="srch-tools__right">
          <?php if ($__multi): ?>
          <div class="srch-sort" role="group" aria-label="Sort results">
            <span class="srch-sort__lbl">Sort</span>
            <button type="button" class="srch-sortbtn on" data-sort="rec">Recommended</button>
            <button type="button" class="srch-sortbtn" data-sort="price-asc">Price: Low to High</button>
            <button type="button" class="srch-sortbtn" data-sort="price-desc">Price: High to Low</button>
          </div>
          <?php endif; ?>
          <!-- Currency switcher. Same markup contract as the header instance
               (.ts-cur-btn / [data-cur-active-*] / [data-cur-set]) so the shared
               js/currency.js drives it and reprices every .ts-price on the page.
               No id= here: the header already owns #tsCur / #tsCurBtn. -->
          <div class="srch-cur">
            <span class="srch-sort__lbl">Currency</span>
            <div class="ts-cur">
              <button type="button" class="ts-cur-btn" aria-haspopup="true" aria-expanded="false" aria-label="Change currency">
                <span data-cur-active-sym><?= e(currency_symbol_prefix($__cur)) ?></span>
                <span data-cur-active-code><?= e($__cur) ?></span>
                <svg class="ts-cur-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
              </button>
              <div class="ts-cur-menu" role="menu">
                <span class="ts-cur-head">Currency</span>
                <?php foreach (TS_CURRENCIES as $code => $meta): ?>
                <a href="<?= e($cur_url($code)) ?>" class="ts-cur-opt<?= $code === $__cur ? ' on' : '' ?>" data-cur-set="<?= e($code) ?>" role="menuitem">
                  <span class="ts-cur-opt-code"><?= e(currency_label($code)) ?></span>
                  <span class="ts-cur-opt-name"><?= e($meta['name']) ?></span>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="srch-results" id="srchResults">
      <?php foreach ($results as $__rank => $r):
      $v = $r['venue']; $sold = $r['count'] === 0;
      $room_count  = count(array_filter($r['rooms'], fn($x) => empty($x['entire'])));
      $has_entire  = (bool) array_filter($r['rooms'], fn($x) => !empty($x['entire']));
      $only_entire = !$sold && $room_count === 0 && $has_entire;
      if ($only_entire)      { $avail_txt = 'Entire villa available'; $cta_txt = 'Book the villa →'; }
      elseif ($room_count>0) { $avail_txt = $room_count . ' room' . ($room_count !== 1 ? 's' : '') . ' available' . ($has_entire ? ' · or the whole villa' : ''); $cta_txt = 'Select a room →'; }
      else                   { $avail_txt = 'Available'; $cta_txt = 'Select →'; }
      ?>
      <div class="vcard<?= $sold ? ' is-sold' : '' ?>"
           data-order="<?= (int)$__rank ?>"
           data-type="<?= e($venue_type[$v['slug']] ?? '') ?>"
           data-loc="<?= e($loc_slug($v['location'] ?? '')) ?>"
           data-avail="<?= $sold ? '0' : '1' ?>"
           data-from="<?= $sold ? '' : (float)($r['from'] ?? 0) ?>">
        <a class="vcard__img" href="/<?= e($v['slug']) ?>" aria-label="<?= e($v['name']) ?>">
          <?php if ($r['hero']): ?><img src="<?= e($r['hero']) ?>" alt="<?= e($v['name']) ?>" loading="lazy"><?php endif; ?>
        </a>
        <div class="vcard__body">
          <?php $__vtype = $venue_type_label[$venue_type[$v['slug']] ?? ''] ?? ''; ?>
          <div class="vcard__loc"><?= e($v['location'] ?? '') ?><?= $__vtype ? ' &middot; <span class="vcard__type">' . e($__vtype) . '</span>' : '' ?></div>
          <div class="vcard__name"><a href="/<?= e($v['slug']) ?>"><?= e($v['name']) ?></a></div>
          <div class="vcard__meta"><?= $r['count'] > 0 ? (int)$r['count'] . ' option' . ($r['count'] !== 1 ? 's' : '') . ' match your dates' : 'View property' ?></div>

          <div class="vcard__status">
            <?php if ($sold): ?>
              <span class="vcard__avail no">No availability for these dates</span>
            <?php else: ?>
              <span class="vcard__avail ok"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg><?= e($avail_txt) ?></span>
              <span class="vcard__from"><small>From (<?= $nights ?> night<?= $nights !== 1 ? 's' : '' ?>)</small><b><?= money_html((float)$r['from'], $r['currency']) ?></b></span>
            <?php endif; ?>
          </div>

          <?php if ($sold): ?>
            <a class="vcard__view" href="/<?= e($v['slug']) ?>">View property →</a>
          <?php else: ?>
            <button type="button" class="vcard__toggle" data-toggle="rl-<?= (int)$v['id'] ?>"><?= e($cta_txt) ?></button>
          <?php endif; ?>
        </div>

        <?php if (!$sold): ?>
        <div class="rlist" id="rl-<?= (int)$v['id'] ?>">
          <div class="rgrid">
          <?php foreach ($r['rooms'] as $room): ?>
          <div class="rcard">
            <div class="rcard__img">
              <?php if (!empty($room['hero'])): ?><img src="<?= e($room['hero']) ?>" alt="<?= e($room['name']) ?>" loading="lazy"><?php endif; ?>
              <?php if ($room['tag']): ?><span class="rcard__tag"><?= e($room['tag']) ?></span><?php endif; ?>
            </div>
            <div class="rcard__body">
              <div class="rcard__name"><?= e($room['name']) ?></div>
              <?php if ($room['capacity']): ?>
              <div class="rcard__chips">
                <span class="rchip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>Up to <?= (int)$room['capacity'] ?> guests</span>
              </div>
              <?php endif; ?>
              <?php if ($room['short_desc']): ?><div class="rcard__desc"><?= e($room['short_desc']) ?></div><?php endif; ?>
              <div class="rcard__foot">
                <div class="rcard__price">
                  <b><?= money_html((float)$room['total'], $room['currency']) ?></b>
                  <small><?= $room['nights'] ?> night<?= $room['nights'] !== 1 ? 's' : '' ?> · final price by email</small>
                </div>
                <button type="button" class="rcard__btn js-select-room"
                        data-slug="<?= e($room['slug']) ?>" data-name="<?= e($v['name'] . ' — ' . $room['name']) ?>"
                        data-price="<?= e($room['price']) ?>" data-currency="<?= e($room['currency']) ?>">Select <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div><!-- /.srch-results -->

      <div class="srch-empty srch-nomatch" id="srchNoMatch" hidden>
        <p style="font-family:'Cormorant Garamond',serif;font-size:1.4rem;color:var(--teal);margin-bottom:.3rem">No properties match this filter</p>
        <p style="color:var(--light)">Try a different property type or location.</p>
      </div>

      <?php if (!$results): ?>
        <div class="srch-empty"><p style="color:var(--light)">No properties are set up yet. Please check back soon.</p></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Booking popup (shared) -->
<?php include __DIR__ . '/includes/booking-modal.php'; ?>

<script>
(function(){
  // Expand / collapse a property's room list
  document.querySelectorAll('[data-toggle]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var el = document.getElementById(btn.dataset.toggle);
      if (!el) return;
      var open = el.classList.toggle('open');
      btn.textContent = open ? 'Hide rooms' : 'Select a room →';
      if (open) el.scrollIntoView({behavior:'smooth', block:'nearest'});
    });
  });

  // "Select" a room → open the booking popup prefilled with the search dates
  var SEARCH = { checkin: <?= json_encode($checkin) ?>, checkout: <?= json_encode($checkout) ?>, adults: <?= (int)$adults ?>, children: <?= (int)$children ?> };
  document.querySelectorAll('.js-select-room').forEach(function(btn){
    btn.addEventListener('click', function(){
      var d = btn.dataset;
      if (typeof window.tsOpenBookingModal === 'function') {
        window.tsOpenBookingModal(d.slug, d.name, d.price, d.currency, {
          checkin: SEARCH.checkin, checkout: SEARCH.checkout, adults: SEARCH.adults, children: SEARCH.children
        });
      } else {
        window.location.href = '/' + d.slug.replace(/-full-rental$|-main-house$/, '');
      }
    });
  });

  // Filter + sort the property results
  var wrap = document.getElementById('srchResults');
  if (wrap) {
    var cards = Array.prototype.slice.call(wrap.querySelectorAll('.vcard'));
    cards.forEach(function(c, i){ c.dataset.order = i; }); // remember curated order
    var noMatch = document.getElementById('srchNoMatch');
    var countEl = document.getElementById('srchCount');
    var nounEl  = document.getElementById('srchNoun');
    var filter = 'all', sort = 'rec';

    function apply(){
      var shown = 0, avail = 0;
      cards.forEach(function(c){
        var ok = true;
        if (filter !== 'all') {
          var p = filter.split(':');           // e.g. "type:villa" | "loc:watamu"
          ok = (c.dataset[p[0]] || '') === p[1];
        }
        c.style.display = ok ? '' : 'none';
        if (ok) { shown++; if (c.dataset.avail === '1') avail++; }
      });

      var vis = cards.filter(function(c){ return c.style.display !== 'none'; });
      vis.sort(function(a, b){
        var av = a.dataset.avail === '1', bv = b.dataset.avail === '1';
        if (av !== bv) return av ? -1 : 1;      // bookable properties always first
        if (sort === 'rec') return (+a.dataset.order) - (+b.dataset.order);
        var ap = parseFloat(a.dataset.from) || 0, bp = parseFloat(b.dataset.from) || 0;
        return sort === 'price-asc' ? ap - bp : bp - ap;
      });
      vis.forEach(function(c){ wrap.appendChild(c); });

      if (noMatch) noMatch.hidden = shown !== 0;
      if (countEl) countEl.textContent = avail;
      if (nounEl)  nounEl.textContent = avail === 1 ? 'property' : 'properties';
    }

    document.querySelectorAll('.srch-chip').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.querySelectorAll('.srch-chip').forEach(function(b){ b.classList.remove('on'); });
        btn.classList.add('on');
        filter = btn.dataset.filter;
        apply();
      });
    });
    document.querySelectorAll('.srch-sortbtn').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.querySelectorAll('.srch-sortbtn').forEach(function(b){ b.classList.remove('on'); });
        btn.classList.add('on');
        sort = btn.dataset.sort;
        apply();
      });
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
