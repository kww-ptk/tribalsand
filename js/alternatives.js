/* Listing page: "Other places with space" — shown when this property has
 * nothing for the guest's dates + party. One renderer for both sidebars
 * (property-availability-widget + booking-widget). Data comes from
 * /api/alternative-properties (the /search availability function).
 *   tsShowAlternatives(box, {venue, checkin, checkout, adults, children})
 *   tsClearAlternatives(box)
 */
(function () {
  if (window.tsShowAlternatives) return;

  var CSS =
    '.ts-alt{margin-top:14px;display:flex;flex-direction:column;gap:10px}' +
    '.ts-alt[hidden]{display:none}' +
    '.ts-alt__h{font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:#b8965a;font-weight:700}' +
    '.ts-alt__card{display:flex;gap:12px;align-items:center;border:1px solid #e7ded7;border-radius:10px;padding:10px;background:#fff;text-decoration:none;color:inherit;transition:border-color .15s}' +
    '.ts-alt__card:hover,.ts-alt__card:focus-visible{border-color:#1E5C6B}' +
    '.ts-alt__img{flex:0 0 76px;width:76px;height:64px;border-radius:8px;object-fit:cover;background:#f4efe9}' +
    '.ts-alt__body{flex:1 1 auto;min-width:0}' +
    '.ts-alt__name{font-weight:600;color:#102F3A;font-size:.95rem}' +
    '.ts-alt__meta{font-size:.74rem;color:#8a8173;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.ts-alt__price{font-size:.8rem;color:#102F3A;margin-top:2px}' +
    '.ts-alt__price small{color:#8a8173}' +
    '.ts-alt__go{font-size:.78rem;font-weight:600;color:#1E5C6B;white-space:nowrap}' +
    '.ts-alt__all{font-size:.8rem;color:#1E5C6B;font-weight:600;text-decoration:none}';

  function css() {
    if (document.getElementById('tsAltCss')) return;
    var s = document.createElement('style'); s.id = 'tsAltCss'; s.textContent = CSS;
    document.head.appendChild(s);
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function price(a, cur) {
    if (typeof window.tsPriceSpan === 'function') return window.tsPriceSpan(a, cur);
    return esc((cur || '') + ' ' + Math.round(Number(a) || 0).toLocaleString('en-US'));
  }
  function range(ci, co) {
    var o = { day: 'numeric', month: 'short' };
    var a = new Date(ci + 'T12:00:00'), b = new Date(co + 'T12:00:00');
    return a.toLocaleDateString('en-GB', o) + ' – ' + b.toLocaleDateString('en-GB', o);
  }

  window.tsClearAlternatives = function (box) {
    if (!box) return;
    box.__tsAltSeq = (box.__tsAltSeq || 0) + 1;   // drop any answer still in flight
    box.innerHTML = ''; box.hidden = true;
  };

  window.tsShowAlternatives = function (box, q) {
    window.tsClearAlternatives(box);
    if (!box || !q || !q.checkin || !q.checkout) return;
    var my = box.__tsAltSeq;
    var url = '/api/alternative-properties?venue=' + encodeURIComponent(q.venue || '') +
      '&check_in=' + encodeURIComponent(q.checkin) + '&check_out=' + encodeURIComponent(q.checkout) +
      '&adults=' + (parseInt(q.adults, 10) || 0) + '&children=' + (parseInt(q.children, 10) || 0);
    fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (box.__tsAltSeq !== my || !d || d.ok !== true || !d.options || !d.options.length) return;
        css();
        var stay = JSON.stringify({ checkin: d.check_in, checkout: d.check_out, adults: Math.max(1, d.adults), children: d.children });
        var nights = d.nights + ' night' + (d.nights === 1 ? '' : 's');
        var html = '<div class="ts-alt__h">Other places with space for ' + esc(range(d.check_in, d.check_out)) +
          ' · ' + d.guests + ' guest' + (d.guests === 1 ? '' : 's') + '</div>';
        d.options.forEach(function (o) {
          html += '<a class="ts-alt__card" href="' + esc(o.url) + '" data-ts-alt="' + esc(stay) + '">' +
            (o.hero ? '<img class="ts-alt__img" src="' + esc(o.hero) + '" alt="" loading="lazy">' : '<span class="ts-alt__img"></span>') +
            '<span class="ts-alt__body"><span class="ts-alt__name">' + esc(o.name) + '</span>' +
            '<span class="ts-alt__meta" style="display:block">' + esc([o.location, o.type].filter(Boolean).join(' · ')) + '</span>' +
            (o.from ? '<span class="ts-alt__price" style="display:block"><small>from</small> ' + price(o.from, o.currency) + ' <small>· ' + nights + '</small></span>' : '') +
            '</span><span class="ts-alt__go">See rooms →</span></a>';
        });
        html += '<a class="ts-alt__all" href="' + esc(d.search_url) + '">' +
          (d.more > 0 ? 'See ' + d.more + ' more on the search page →' : 'See all on the search page →') + '</a>';
        box.innerHTML = html;
        box.hidden = false;
      })
      .catch(function () { /* the sorry message already stands on its own */ });
  };

  // Calls made before this deferred file loaded (an inline widget that checked
  // during page load) waited in window.tsAltQueue — run them now.
  var queued = window.tsAltQueue || [];
  window.tsAltQueue = [];
  queued.forEach(function (c) { window.tsShowAlternatives(c[0], c[1]); });

  // Whole-property pages prefill from sessionStorage, not the URL — carry the stay.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-ts-alt]') : null;
    if (!a) return;
    try { sessionStorage.setItem('ts_search', a.getAttribute('data-ts-alt')); } catch (err) {}
  });
})();
