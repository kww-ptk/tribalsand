/* KES | USD switch for admin pages (Rates, Quote builder).
 *
 * Every amount on the page is a <span class="mny" data-amt data-cur [data-fmt=short]>
 * holding its ORIGINAL amount + currency (rc_money_html() in PHP). This script
 * re-renders them all in the chosen currency using window.TS_FX (the site's
 * fx_rates(), USD-based) — instant, no reload. Text must stay identical to
 * rc_money_text() in includes/rates-compare.php.
 *
 * Emitted INLINE by includes/money-switch.php (admin shell navigation re-runs
 * inline scripts only), so it guards against running twice.
 */
(function () {
  'use strict';
  if (window.tsMoney) { window.tsMoney.apply(document); return; }
  var KEY = 'ts_admin_cur', ALLOWED = ['KES', 'USD'];

  function cfg() { return window.TS_FX || { rates: { USD: 1 }, symbols: { USD: '$' } }; }
  function sym(c) { var s = cfg().symbols || {}; return s[c] || (c + ' '); }
  function trimz(s) { return s.indexOf('.') >= 0 ? s.replace(/0+$/, '').replace(/\.$/, '') : s; }
  // Rounding: scale, round half UP with Math.floor(x + 0.5), then place the decimal.
  // Must stay identical to rc_money_text() (PHP floor($x + 0.5)) — toFixed() rounds
  // the binary value and would disagree on half-way amounts.
  function r(x) { return Math.floor(x + 0.5); }
  function group(v) { return r(v).toLocaleString('en-US'); }
  function text(v, c, short) {
    if (short) {
      var a = Math.abs(v);
      if (c === 'KES') {
        if (a >= 1000000) return trimz((r(v / 10000) / 100).toFixed(2)) + 'm';
        if (a >= 1000) return trimz((r(v / 100) / 10).toFixed(1)) + 'k';
        return String(r(v));
      }
      if (a >= 100000) return sym(c) + trimz((r(v / 100) / 10).toFixed(1)) + 'k';
    }
    return sym(c) + group(v);
  }
  function convert(a, from, to) {
    if (from === to) return a;
    var r = cfg().rates || {};
    if (!(r[from] > 0) || !(r[to] > 0)) return null;
    return a / r[from] * r[to];
  }
  function initial() {
    var p = null;
    try { p = new URLSearchParams(location.search).get('cur'); } catch (e) {}
    if (ALLOWED.indexOf(p) >= 0) return p;
    try { var s = localStorage.getItem(KEY); if (ALLOWED.indexOf(s) >= 0) return s; } catch (e) {}
    return 'KES';
  }
  var cur = initial();

  function paint(el) {
    var a = parseFloat(el.getAttribute('data-amt')), from = el.getAttribute('data-cur');
    if (isNaN(a) || !from) return;
    var short = el.getAttribute('data-fmt') === 'short';
    var v = convert(a, from, cur), shown = v === null ? from : cur;
    if (v === null) v = a;
    var approx = shown !== from;
    el.textContent = (approx && !short ? '≈ ' : '') + text(v, shown, short);
    el.classList.toggle('is-approx', approx);
  }
  function apply(root) {
    root = root || document;
    Array.prototype.forEach.call(root.querySelectorAll('.mny[data-amt]'), paint);
    Array.prototype.forEach.call(document.querySelectorAll('[data-money-cur]'), function (b) {
      var on = b.getAttribute('data-money-cur') === cur;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    Array.prototype.forEach.call(document.querySelectorAll('a[data-keep-cur]'), function (a) {
      try {
        var u = new URL(a.getAttribute('href'), location.href);
        u.searchParams.set('cur', cur);
        a.setAttribute('href', u.pathname + u.search + u.hash);
      } catch (e) {}
    });
  }
  function set(c) {
    if (ALLOWED.indexOf(c) < 0 || c === cur) return;
    cur = c;
    try { localStorage.setItem(KEY, c); } catch (e) {}
    try {
      var u = new URL(location.href);
      u.searchParams.set('cur', c);
      history.replaceState(history.state, '', u.pathname + u.search + u.hash);
    } catch (e) {}
    apply(document);
    document.dispatchEvent(new CustomEvent('ts:currency', { detail: { cur: c } }));
  }

  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-money-cur]') : null;
    if (!b) return;
    e.preventDefault();
    set(b.getAttribute('data-money-cur'));
  });

  window.tsMoney = { apply: apply, set: set, current: function () { return cur; }, text: text, convert: convert };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { apply(document); });
  else apply(document);
})();
