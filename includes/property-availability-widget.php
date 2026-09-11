<?php
/**
 * Availability-first property sidebar (Phase 3).
 *
 * Usage on a MULTI-ROOM property page (before including this file):
 *   $pa_venue_slug = 'maya-kobe';
 *   include __DIR__ . '/includes/property-availability-widget.php';
 *
 * No room is preselected. The guest picks dates + guests; on "Check
 * availability" we call /api/property-availability.php (which returns
 * ts_property_configurations() for this one venue — the ONE pricing path) and
 * render the fitting options INLINE:
 *   · single rooms / the whole property → "Select" opens the existing booking
 *     modal (tsOpenBookingModal) with dates + guests prefilled = the normal
 *     24h-hold / enquiry flow, unchanged.
 *   · a multi-room COMBINATION (v1) → "Request these rooms" opens a prefilled
 *     enquiry (posted to /api/submit-contact.php; Turnstile fail-closed + IP
 *     rate-limit on the endpoint). A true atomic multi-room hold is a v2
 *     follow-up — see the plan §6.4.
 *
 * Requires the styled datepicker (js/datepicker.js) and the booking modal
 * scripts (js/booking-modal.js + js/booking-widget.js) — all loaded by
 * $page_rooms_rates in includes/head.php, which every multi-room page sets.
 * This partial emits the booking modal + combo-enquiry modal itself (once).
 * No-JS: a link to /search with the chosen venue/dates prefilled.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/turnstile.php';

$pa_venue_slug = $pa_venue_slug ?? '';
try {
    $__pav = $pa_venue_slug
        ? db_query('SELECT id, name, slug FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => $pa_venue_slug])->fetch()
        : false;
} catch (Throwable $e) {
    $__pav = false;
}
if (!$__pav) { return; }   // unknown/unpublished venue → render nothing (page keeps its other content)

// Prefill from query params (arriving from search's "Request these rooms", or a
// shared link). Validated loosely here; the endpoint re-validates strictly.
$__pa_ci  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['checkin']  ?? '')) ? $_GET['checkin']  : '';
$__pa_co  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['checkout'] ?? '')) ? $_GET['checkout'] : '';
$__pa_ad  = max(1, min(30, (int)($_GET['adults']   ?? 2)));
$__pa_ch  = max(0, min(20, (int)($_GET['children'] ?? 0)));
$__pa_uid = 'pa' . substr(md5($__pav['slug']), 0, 6);   // unique id base if two instances ever coexist
?>

<?php if (empty($GLOBALS['__pa_css_done'])): $GLOBALS['__pa_css_done'] = true; ?>
<style>
.pa-wrap{padding:20px}
.pa-head{font-family:'Cormorant Garamond',serif;font-size:1.35rem;color:#102F3A;margin:0 0 4px}
.pa-sub{font-size:.82rem;color:#6b6257;margin:0 0 16px}
.pa-dates{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
.pa-field label{display:block;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:#8a8173;margin-bottom:4px}
.pa-guests{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}
.pa-grow{display:flex;align-items:center;justify-content:space-between;gap:12px}
.pa-grow__lbl strong{display:block;font-size:.92rem;color:#102F3A}
.pa-grow__lbl small{font-size:.72rem;color:#8a8173}
.pa-step{display:inline-flex;align-items:center;gap:12px}
.pa-step button{width:32px;height:32px;border-radius:50%;border:1px solid #d8cec3;background:#fff;color:#1E5C6B;font-size:18px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
.pa-step button:disabled{opacity:.35;cursor:not-allowed}
.pa-step span{min-width:20px;text-align:center;font-variant-numeric:tabular-nums;font-weight:600}
.pa-go{width:100%;padding:12px 16px;border:none;border-radius:10px;background:#1E5C6B;color:#fff;font-size:.95rem;font-weight:600;cursor:pointer}
.pa-go:disabled{opacity:.6;cursor:wait}
.pa-msg{font-size:.82rem;margin:12px 0 0;color:#6b6257}
.pa-msg--err{color:#b91c1c}
.pa-results{margin-top:16px;display:flex;flex-direction:column;gap:12px}
.pa-sec-h{font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:#b8965a;font-weight:700;margin:4px 0 0}
.pa-opt{border:1px solid #e7ded7;border-radius:10px;padding:12px 14px;background:#fff}
.pa-opt.is-entire{border-color:#b8965a;background:#fcf9f3}
.pa-opt__main{display:flex;gap:12px;align-items:center;justify-content:space-between}
.pa-opt__thumb{flex:1 1 0;min-width:120px;height:92px;border-radius:8px;object-fit:cover;background:#f4efe9;cursor:pointer;display:block}
.pa-opt__thumb:focus-visible{outline:2px solid #1E5C6B;outline-offset:2px}
.pa-opt__head{margin-bottom:8px}
.pa-opt__right{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-end;gap:6px;text-align:right}
.pa-opt__top{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
.pa-opt__name{font-weight:600;color:#102F3A}
.pa-opt__cap{font-size:.74rem;color:#8a8173}
.pa-opt__price b{font-family:'Cormorant Garamond',serif;font-size:1.3rem;color:#102F3A;line-height:1}
.pa-opt__price small{display:block;font-size:.68rem;color:#8a8173;text-align:right}
.pa-opt__btn{margin-top:10px;width:100%;padding:9px 12px;border:none;border-radius:8px;background:#1E5C6B;color:#fff;font-weight:600;font-size:.86rem;cursor:pointer}
.pa-opt__btn--inline{margin-top:0;width:auto;white-space:nowrap;padding:8px 12px;font-size:.8rem}
.pa-opt__btn--ghost{background:#fff;color:#1E5C6B;border:1px solid #1E5C6B}
.pa-combo__rooms{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
.pa-chip{display:inline-flex;gap:6px;align-items:center;background:#f4efe9;border:1px solid #e7ded7;border-radius:999px;padding:4px 10px;font-size:.78rem;color:#102F3A}
.pa-chip small{color:#8a8173}
.pa-none{font-size:.88rem;color:#6b6257;line-height:1.5}
/* combo-enquiry modal */
.pae-back{position:fixed;inset:0;background:rgba(16,47,58,.55);display:flex;align-items:center;justify-content:center;padding:18px;z-index:3000}
.pae-back[hidden]{display:none}
.pae-dlg{background:#fff;border-radius:14px;max-width:440px;width:100%;max-height:92vh;overflow:auto;padding:22px}
.pae-dlg h3{font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:#102F3A;margin:0 0 6px}
.pae-dlg p{font-size:.85rem;color:#6b6257;margin:0 0 14px}
.pae-f{margin-bottom:10px}
.pae-f label{display:block;font-size:.72rem;color:#8a8173;margin-bottom:3px}
.pae-f input,.pae-f textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d8cec3;border-radius:8px;font-size:.9rem}
.pae-actions{display:flex;gap:10px;margin-top:6px}
.pae-actions button{flex:1;padding:11px;border-radius:8px;border:none;font-weight:600;cursor:pointer}
.pae-cancel{background:#f1ece5;color:#6b6257}
.pae-send{background:#1E5C6B;color:#fff}
.pae-msg{font-size:.82rem;margin-top:8px}
</style>
<?php endif; ?>

<div class="pa-wrap" id="<?= e($__pa_uid) ?>" data-venue="<?= e($__pav['slug']) ?>" data-venue-name="<?= e($__pav['name']) ?>"
     data-endpoint="/api/property-availability.php">
  <div class="pa-head">Check availability</div>
  <p class="pa-sub">Choose your dates and party size to see the rooms and combinations that fit.</p>

  <div class="pa-dates">
    <div class="pa-field">
      <label>Check-in</label>
      <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="<?= e($__pa_uid) ?>" data-dp-target="<?= e($__pa_uid) ?>Ci" data-dp-placeholder="Add date">Add date</button>
      <input type="hidden" id="<?= e($__pa_uid) ?>Ci" value="<?= e($__pa_ci) ?>">
    </div>
    <div class="pa-field">
      <label>Check-out</label>
      <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="<?= e($__pa_uid) ?>" data-dp-target="<?= e($__pa_uid) ?>Co" data-dp-placeholder="Add date">Add date</button>
      <input type="hidden" id="<?= e($__pa_uid) ?>Co" value="<?= e($__pa_co) ?>">
    </div>
  </div>

  <div class="pa-guests">
    <div class="pa-grow">
      <div class="pa-grow__lbl"><strong>Adults</strong><small>Age 18+</small></div>
      <div class="pa-step" data-pa-step="adults" data-min="1" data-max="30">
        <button type="button" data-dir="-1" aria-label="Fewer adults">&minus;</button>
        <span data-pa-count><?= (int)$__pa_ad ?></span>
        <button type="button" data-dir="1" aria-label="More adults">+</button>
      </div>
    </div>
    <div class="pa-grow">
      <div class="pa-grow__lbl"><strong>Children</strong><small>Age 0–17</small></div>
      <div class="pa-step" data-pa-step="children" data-min="0" data-max="20">
        <button type="button" data-dir="-1" aria-label="Fewer children">&minus;</button>
        <span data-pa-count><?= (int)$__pa_ch ?></span>
        <button type="button" data-dir="1" aria-label="More children">+</button>
      </div>
    </div>
  </div>

  <button type="button" class="pa-go" data-pa-go>Check availability</button>
  <div class="pa-msg" data-pa-msg hidden></div>
  <div class="pa-results" data-pa-results></div>

  <noscript>
    <p class="pa-sub" style="margin-top:14px">
      <a href="/search?checkin=<?= e($__pa_ci) ?>&amp;checkout=<?= e($__pa_co) ?>&amp;adults=<?= (int)$__pa_ad ?>&amp;children=<?= (int)$__pa_ch ?>">
        See live availability across all properties →
      </a>
    </p>
  </noscript>
</div>

<?php
// The booking modal powers "Select" for a single room / whole property. Emit it
// once per page (a page could, in theory, include this widget twice).
if (empty($GLOBALS['__pa_modal_done'])) {
    $GLOBALS['__pa_modal_done'] = true;
    include __DIR__ . '/booking-modal.php';
}
?>

<?php if (empty($GLOBALS['__pa_enq_done'])): $GLOBALS['__pa_enq_done'] = true; ?>
<!-- Combo → prefilled enquiry (v1). Posts to /api/submit-contact.php. -->
<div class="pae-back" id="paEnq" hidden>
  <div class="pae-dlg" role="dialog" aria-modal="true" aria-labelledby="paEnqTitle">
    <h3 id="paEnqTitle">Request these rooms</h3>
    <p id="paEnqIntro">We’ll check the combination and confirm by email — nothing is charged now.</p>
    <form id="paEnqForm" autocomplete="on">
      <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
      <div class="pae-f"><label>Your name</label><input type="text" name="name" required placeholder="Full name"></div>
      <div class="pae-f"><label>Email</label><input type="email" name="email" required placeholder="you@example.com"></div>
      <div class="pae-f"><label>Phone <small>(optional)</small></label><input type="tel" name="phone" placeholder="+254 …"></div>
      <div class="pae-f"><label>Message</label><textarea name="note" rows="3" placeholder="Anything else we should know?"></textarea></div>
      <?php if (captcha_site_key()): ?>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
      <?php endif; ?>
      <div class="pae-msg" data-pae-msg hidden></div>
      <div class="pae-actions">
        <button type="button" class="pae-cancel" data-pae-close>Cancel</button>
        <button type="submit" class="pae-send">Send request</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (empty($GLOBALS['__pa_js_done'])): $GLOBALS['__pa_js_done'] = true; ?>
<script>
(function () {
  // Plain-text price in the visitor's CURRENT currency (for enquiry-email text).
  function money(amount, cur) {
    if (typeof window.tsMoney === 'function') return window.tsMoney(amount, cur);
    var n = Math.round(Number(amount) || 0).toLocaleString('en-US');
    return (cur === 'USD' || !cur) ? '$' + n : cur + ' ' + n;
  }
  // Same figure as an HTML <span class="ts-price"> that js/currency.js re-renders
  // instantly when the visitor switches currency — used for on-screen prices.
  // Each room converts from ITS OWN base currency; the booking is still charged
  // in that base currency (o.currency is passed to the booking modal unchanged).
  function moneyHtml(amount, cur) {
    if (typeof window.tsPriceSpan === 'function') return window.tsPriceSpan(amount, cur);
    return money(amount, cur);
  }
  function el(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; }

  // ── Combo enquiry modal (shared) ──
  var enq = document.getElementById('paEnq');
  var enqForm = document.getElementById('paEnqForm');
  var enqIntro = document.getElementById('paEnqIntro');
  var enqMsg = enqForm ? enqForm.querySelector('[data-pae-msg]') : null;
  var enqCtx = null;
  function openEnq(ctx) {
    enqCtx = ctx;
    if (enqIntro) enqIntro.textContent = 'For ' + ctx.guests + ' guest' + (ctx.guests === 1 ? '' : 's') + ', ' + ctx.dates + '. We’ll confirm this combination by email — nothing is charged now.';
    if (enqMsg) { enqMsg.hidden = true; enqMsg.textContent = ''; }
    if (enq) { enq.hidden = false; document.body.style.overflow = 'hidden'; }
  }
  function closeEnq() { if (enq) { enq.hidden = true; document.body.style.overflow = ''; } }
  if (enq) {
    enq.addEventListener('click', function (e) { if (e.target === enq) closeEnq(); });
    enq.querySelectorAll('[data-pae-close]').forEach(function (b) { b.addEventListener('click', closeEnq); });
  }
  if (enqForm) {
    enqForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!enqCtx) return;
      var fd = new FormData(enqForm);
      if ((fd.get('website') || '').trim() !== '') { closeEnq(); return; } // honeypot
      var note = (fd.get('note') || '').trim();
      var message = 'Room-combination request for ' + enqCtx.venueName + ' (' + enqCtx.dates + ', ' + enqCtx.guests +
                    ' guest' + (enqCtx.guests === 1 ? '' : 's') + '):\n' + enqCtx.roomsText +
                    '\nEstimated total: ' + enqCtx.totalText + (note ? ('\n\nGuest note: ' + note) : '');
      var btn = enqForm.querySelector('.pae-send');
      btn.disabled = true; btn.textContent = 'Sending…';
      fetch('/api/submit-contact.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: fd.get('name'), email: fd.get('email'), phone: fd.get('phone'),
          subject: 'Room combination — ' + enqCtx.venueName, message: message,
          'cf-turnstile-response': (enqForm.querySelector('[name="cf-turnstile-response"]') || {}).value || ''
        })
      })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j || res.j.ok !== true) {
          var err = (res.j && (res.j.error || (res.j.errors && Object.values(res.j.errors)[0]))) || 'Could not send your request.';
          throw new Error(err);
        }
        if (typeof window.showSuccessModal === 'function') {
          closeEnq();
          window.showSuccessModal('Request received', 'Thanks! We’ll confirm this room combination and pricing by email shortly.');
        } else if (enqMsg) {
          enqMsg.hidden = false; enqMsg.style.color = '#15803d';
          enqMsg.textContent = 'Request sent — we’ll confirm by email shortly.';
        }
        enqForm.reset();
      })
      .catch(function (err) {
        if (enqMsg) { enqMsg.hidden = false; enqMsg.style.color = '#b91c1c'; enqMsg.textContent = err.message; }
      })
      .finally(function () { btn.disabled = false; btn.textContent = 'Send request'; });
    });
  }

  // ── Each availability widget on the page ──
  document.querySelectorAll('.pa-wrap').forEach(function (wrap) {
    var uid = wrap.id;
    var ciIn = document.getElementById(uid + 'Ci');
    var coIn = document.getElementById(uid + 'Co');
    var results = wrap.querySelector('[data-pa-results]');
    var msg = wrap.querySelector('[data-pa-msg]');
    var go = wrap.querySelector('[data-pa-go]');
    var venue = wrap.dataset.venue;
    var venueName = wrap.dataset.venueName;

    // Guest steppers
    var counts = { adults: 2, children: 0 };
    wrap.querySelectorAll('[data-pa-step]').forEach(function (st) {
      var key = st.dataset.paStep, span = st.querySelector('[data-pa-count]');
      var min = +st.dataset.min, max = +st.dataset.max;
      counts[key] = parseInt(span.textContent, 10) || min;
      st.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
          var v = counts[key] + (+b.dataset.dir);
          if (v < min || v > max) return;
          counts[key] = v; span.textContent = v;
        });
      });
    });

    function setMsg(text, isErr) {
      if (!msg) return;
      if (!text) { msg.hidden = true; msg.textContent = ''; return; }
      msg.hidden = false; msg.textContent = text;
      msg.classList.toggle('pa-msg--err', !!isErr);
    }
    function fmtRange(ci, co) {
      try {
        var o = { day: 'numeric', month: 'short' };
        return new Date(ci + 'T00:00').toLocaleDateString('en-GB', o) + ' → ' +
               new Date(co + 'T00:00').toLocaleDateString('en-GB', o);
      } catch (e) { return ci + ' → ' + co; }
    }

    function render(data) {
      results.innerHTML = '';
      var ci = data.check_in, co = data.check_out, guests = data.guests;
      var nightsTxt = data.nights + ' night' + (data.nights === 1 ? '' : 's');
      var prefill = { checkin: ci, checkout: co, adults: data.adults, children: data.children };

      function optCard(o, isEntire) {
        var card = el('<div class="pa-opt' + (isEntire ? ' is-entire' : '') + '"></div>');
        card.appendChild(el('<div class="pa-opt__head"><div class="pa-opt__name">' + esc(o.name) + '</div>' +
          (o.capacity ? '<div class="pa-opt__cap">Sleeps up to ' + o.capacity + '</div>' : '') + '</div>'));
        var thumb = o.hero ? '<img class="pa-opt__thumb" src="' + esc(o.hero) + '" alt="' + esc(o.name) + '" loading="lazy">' : '';
        var main = el('<div class="pa-opt__main">' + thumb +
          '<div class="pa-opt__right">' +
          '<div class="pa-opt__price"><b>' + moneyHtml(o.total, o.currency) + '</b><small>' + nightsTxt + '</small></div>' +
          '</div>' +
          '</div>');
        // Clicking the photo opens the SAME per-room lightbox the room cards use
        // (rrOpenLb, defined by includes/rooms-and-rates.php on these pages). No-ops
        // gracefully if that gallery isn't present or the room has no photos.
        var img = main.querySelector('.pa-opt__thumb');
        if (img) {
          img.setAttribute('role', 'button');
          img.setAttribute('tabindex', '0');
          img.setAttribute('aria-label', 'View photos of ' + o.name);
          var openGallery = function () { if (typeof window.rrOpenLb === 'function') window.rrOpenLb(o.slug, 0); };
          img.addEventListener('click', openGallery);
          img.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openGallery(); } });
        }
        var btn = el('<button type="button" class="pa-opt__btn pa-opt__btn--inline">Select ' + (isEntire ? 'property' : 'this room') + '</button>');
        btn.addEventListener('click', function () {
          if (typeof window.tsOpenBookingModal === 'function') {
            window.tsOpenBookingModal(o.slug, venueName + ' — ' + o.name, o.price, o.currency, prefill);
          } else {
            window.location.href = '/search?checkin=' + ci + '&checkout=' + co + '&adults=' + data.adults + '&children=' + data.children;
          }
        });
        main.querySelector('.pa-opt__right').appendChild(btn);
        card.appendChild(main);
        return card;
      }

      if (data.singles && data.singles.length) {
        results.appendChild(el('<div class="pa-sec-h">Available rooms</div>'));
        data.singles.forEach(function (o) { results.appendChild(optCard(o, false)); });
      }

      if (data.combos && data.combos.length) {
        results.appendChild(el('<div class="pa-sec-h">For ' + guests + ' guests we suggest' + (data.combos.length > 1 ? ' — best fit first' : '') + '</div>'));
        data.combos.forEach(function (combo) {
          var card = el('<div class="pa-opt"></div>');
          var roomsWrap = el('<div class="pa-combo__rooms"></div>');
          var roomsText = [];
          combo.rooms.forEach(function (cr) {
            var label = cr.name + (cr.units_used > 1 ? ' ×' + cr.units_used : '');
            roomsWrap.appendChild(el('<span class="pa-chip">' + esc(label) + ' <small>' + moneyHtml(cr.total, cr.currency) + '</small></span>'));
            roomsText.push('• ' + label + ' — ' + money(cr.total, cr.currency));
          });
          card.appendChild(el('<div class="pa-opt__top"><div class="pa-opt__name">Combination · sleeps ' + combo.capacity + '</div>' +
            '<div class="pa-opt__price"><b>' + moneyHtml(combo.total, combo.currency) + '</b><small>' + nightsTxt + ' total</small></div></div>'));
          card.appendChild(roomsWrap);
          var btn = el('<button type="button" class="pa-opt__btn pa-opt__btn--ghost">Request these rooms</button>');
          btn.addEventListener('click', function () {
            openEnq({
              venueName: venueName, guests: guests, dates: fmtRange(ci, co),
              roomsText: roomsText.join('\n'), totalText: money(combo.total, combo.currency)
            });
          });
          card.appendChild(btn);
          results.appendChild(card);
        });
      }

      if (data.entire && data.entire.length) {
        results.appendChild(el('<div class="pa-sec-h">' + ((data.singles.length || data.combos.length) ? 'Or book the whole property' : 'The whole property') + '</div>'));
        data.entire.forEach(function (o) { results.appendChild(optCard(o, true)); });
      }

      if (!(data.singles.length || data.combos.length || data.entire.length)) {
        var cap = data.max_capacity ? (' It sleeps up to ' + data.max_capacity + ' for these dates.') : '';
        results.appendChild(el('<p class="pa-none">Sorry — we don’t have space for ' + guests + ' guest' + (guests === 1 ? '' : 's') +
          ' at ' + esc(venueName) + ' for those dates.' + cap +
          ' Try different dates, or <a href="/search?checkin=' + ci + '&checkout=' + co + '&adults=' + data.adults + '&children=' + data.children + '">search all properties →</a></p>'));
      }
    }

    function check() {
      var ci = (ciIn.value || '').trim(), co = (coIn.value || '').trim();
      if (!ci || !co) { setMsg('Please choose check-in and check-out dates.', true); return; }
      if (ci >= co)   { setMsg('Check-out must be after check-in.', true); return; }
      setMsg('Checking live availability…', false);
      go.disabled = true;
      var url = wrap.dataset.endpoint + '?venue=' + encodeURIComponent(venue) +
                '&check_in=' + ci + '&check_out=' + co +
                '&adults=' + counts.adults + '&children=' + counts.children;
      fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || data.ok !== true) throw new Error((data && data.error) || 'Could not load availability.');
          setMsg('', false);
          render(data);
        })
        .catch(function (err) { setMsg(err.message || 'Could not load availability.', true); })
        .finally(function () { go.disabled = false; });
    }

    go.addEventListener('click', check);
    // Auto-run when arriving with dates prefilled (e.g. from search).
    if ((ciIn.value || '').trim() && (coIn.value || '').trim()) check();
  });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
})();
</script>
<?php endif; ?>
