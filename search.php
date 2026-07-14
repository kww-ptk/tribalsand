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
.vcard__name{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.7rem;color:var(--dark);line-height:1.05;margin-bottom:.3rem;}
.vcard__name a{color:inherit;}
.vcard__meta{font-size:.86rem;color:var(--light);margin-bottom:.9rem;}
.vcard__status{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap;margin-top:auto;}
.vcard__avail{font-size:.82rem;font-weight:600;letter-spacing:.02em;}
.vcard__avail.ok{color:#2D7A5F;}
.vcard__avail.no{color:#9B3B2A;}
.vcard__from{margin-left:auto;text-align:right;}
.vcard__from small{display:block;font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;color:var(--light);}
.vcard__from b{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:1.35rem;color:var(--dark);}
.vcard__toggle{margin-top:1rem;align-self:flex-start;background:var(--sand);color:var(--teal-d);border:none;border-radius:4px;padding:.7rem 1.4rem;font-family:'Jost',sans-serif;font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;}
.vcard__toggle:hover{background:var(--sand-lt);}
.vcard__view{margin-top:1rem;align-self:flex-start;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--teal);border-bottom:1px solid var(--border);padding-bottom:2px;}
/* room list */
.rlist{grid-column:1 / -1;border-top:1px solid var(--border);background:var(--sand-faint);display:none;}
.rlist.open{display:block;}
.rrow{display:flex;align-items:center;gap:1rem;padding:1rem 1.6rem;border-bottom:1px solid var(--border);}
.rrow:last-child{border-bottom:none;}
.rrow__info{flex:1;min-width:0;}
.rrow__name{font-size:.98rem;font-weight:600;color:var(--dark);}
.rrow__desc{font-size:.8rem;color:var(--light);margin-top:.15rem;}
.rrow__tag{display:inline-block;font-size:.54rem;letter-spacing:.14em;text-transform:uppercase;color:var(--sand);border:1px solid var(--border);padding:.12rem .5rem;margin-left:.4rem;vertical-align:middle;}
.rrow__price{text-align:right;white-space:nowrap;}
.rrow__price b{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:1.15rem;color:var(--dark);}
.rrow__price small{display:block;font-size:.68rem;color:var(--light);}
.rrow__btn{flex-shrink:0;background:var(--teal-d);color:#fff;border:none;border-radius:4px;padding:.7rem 1.3rem;font-family:'Jost',sans-serif;font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s;}
.rrow__btn:hover{background:var(--teal);}
.srch-empty{text-align:center;padding:3rem 1rem;color:var(--mid);}
@media(max-width:720px){
  .vcard{grid-template-columns:1fr;}
  .vcard__img{min-height:180px;}
  .srch-field{flex:1 1 45%;border-right:none;}
  .srch-form__btn{flex:1 1 100%;}
  .rrow{flex-wrap:wrap;}
  .rrow__price{text-align:left;}
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
        <strong><?= $total_available ?></strong> propert<?= $total_available === 1 ? 'y' : 'ies' ?> available &nbsp;·&nbsp;
        <?= e($fmt_date($checkin)) ?> → <?= e($fmt_date($checkout)) ?> &nbsp;·&nbsp;
        <?= $nights ?> night<?= $nights !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
        <?= $adults ?> adult<?= $adults !== 1 ? 's' : '' ?><?= $children ? ' · ' . $children . ' child' . ($children !== 1 ? 'ren' : '') : '' ?>
      </div>

      <?php foreach ($results as $r):
      $v = $r['venue']; $sold = $r['count'] === 0;
      $room_count  = count(array_filter($r['rooms'], fn($x) => empty($x['entire'])));
      $has_entire  = (bool) array_filter($r['rooms'], fn($x) => !empty($x['entire']));
      $only_entire = !$sold && $room_count === 0 && $has_entire;
      if ($only_entire)      { $avail_txt = 'Entire villa available'; $cta_txt = 'Book the villa →'; }
      elseif ($room_count>0) { $avail_txt = $room_count . ' room' . ($room_count !== 1 ? 's' : '') . ' available' . ($has_entire ? ' · or the whole villa' : ''); $cta_txt = 'Select a room →'; }
      else                   { $avail_txt = 'Available'; $cta_txt = 'Select →'; }
      ?>
      <div class="vcard<?= $sold ? ' is-sold' : '' ?>">
        <a class="vcard__img" href="/<?= e($v['slug']) ?>" aria-label="<?= e($v['name']) ?>">
          <?php if ($r['hero']): ?><img src="<?= e($r['hero']) ?>" alt="<?= e($v['name']) ?>" loading="lazy"><?php endif; ?>
        </a>
        <div class="vcard__body">
          <div class="vcard__loc"><?= e($v['location'] ?? '') ?></div>
          <div class="vcard__name"><a href="/<?= e($v['slug']) ?>"><?= e($v['name']) ?></a></div>
          <div class="vcard__meta"><?= $r['count'] > 0 ? (int)$r['count'] . ' option' . ($r['count'] !== 1 ? 's' : '') . ' match your dates' : 'View property' ?></div>

          <div class="vcard__status">
            <?php if ($sold): ?>
              <span class="vcard__avail no">No availability for these dates</span>
            <?php else: ?>
              <span class="vcard__avail ok"><?= e($avail_txt) ?></span>
              <span class="vcard__from"><small>From (<?= $nights ?> night<?= $nights !== 1 ? 's' : '' ?>)</small><b><?= e($money($r['from'], $r['currency'])) ?></b></span>
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
          <?php foreach ($r['rooms'] as $room): ?>
          <div class="rrow">
            <div class="rrow__info">
              <span class="rrow__name"><?= e($room['name']) ?><?php if ($room['tag']): ?><span class="rrow__tag"><?= e($room['tag']) ?></span><?php endif; ?></span>
              <div class="rrow__desc">
                <?php if ($room['capacity']): ?>Up to <?= (int)$room['capacity'] ?> guests<?php endif; ?>
                <?php if ($room['short_desc']): ?><?= $room['capacity'] ? ' · ' : '' ?><?= e($room['short_desc']) ?><?php endif; ?>
              </div>
            </div>
            <div class="rrow__price">
              <b><?= e($money($room['total'], $room['currency'])) ?></b>
              <small><?= $room['nights'] ?> night<?= $room['nights'] !== 1 ? 's' : '' ?> · final price by email</small>
            </div>
            <button type="button" class="rrow__btn js-select-room"
                    data-slug="<?= e($room['slug']) ?>" data-name="<?= e($v['name'] . ' — ' . $room['name']) ?>"
                    data-price="<?= e($room['price']) ?>" data-currency="<?= e($room['currency']) ?>">Select</button>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

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
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
