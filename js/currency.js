/* Tribal Sand — display-currency switcher (Stage 3).
 * Server renders every price already converted; this reformats them INSTANTLY on
 * switch (no reload) and persists the choice in a cookie. Mirrors the PHP helpers
 * in includes/db.php — keep the convert/round/format logic in lock-step.
 *
 * Globals injected by includes/head.php:
 *   window.TS_FX        = { base:"USD", rates:{USD:1, KES:129, ...} }
 *   window.TS_CUR       = "USD"                     (current currency)
 *   window.TS_CUR_META  = { USD:{symbol:"$",round:1}, KES:{symbol:"KES ",round:100}, ... }
 */
(function () {
  'use strict';
  if (!window.TS_FX || !window.TS_CUR_META) return; // nothing to do without rate data

  function convert(amount, from, to) {
    if (from === to) return amount;
    var R = window.TS_FX.rates || {};
    var rf = parseFloat(R[from]), rt = parseFloat(R[to]);
    if (!(rf > 0) || !(rt > 0)) return null;         // missing rate → signal fallback
    var out = (amount / rf) * rt;
    var round = (window.TS_CUR_META[to] && window.TS_CUR_META[to].round) || 1;
    return round > 1 ? Math.round(out / round) * round : Math.round(out);
  }

  function format(amount, cur) {
    var meta = window.TS_CUR_META[cur];
    var sym = (meta && meta.symbol) || (cur + ' ');
    return sym + Math.round(amount).toLocaleString('en-US');
  }

  // Public: format a base amount in the visitor's current currency (falls back to
  // the original currency if the rate is missing). Prefixes "≈" when an actual
  // conversion happened. For JS-computed prices.
  window.tsMoney = function (amount, from) {
    from = (from || '').toUpperCase();
    var to = window.TS_CUR;
    var out = convert(amount, from, to);
    if (out === null) return format(amount, from);
    return (from !== to ? '≈ ' : '') + format(out, to);
  };

  // Public: an HTML <span class="ts-price"> for a base amount — embed inside larger
  // strings (e.g. "2 nights · <span>…</span>"). renderAll() reformats it on switch.
  window.tsPriceSpan = function (amount, from) {
    from = (from || '').toUpperCase();
    return '<span class="ts-price" data-base-amount="' + amount + '" data-base-cur="' + from + '">'
         + window.tsMoney(amount, from) + '</span>';
  };

  // Re-render one <span class="ts-price" data-base-amount data-base-cur>
  function render(el, to) {
    var base = parseFloat(el.getAttribute('data-base-amount'));
    var from = (el.getAttribute('data-base-cur') || '').toUpperCase();
    if (isNaN(base) || !from) return;
    var out = convert(base, from, to);
    el.textContent = (out === null) ? format(base, from)
                                    : (from !== to ? '≈ ' : '') + format(out, to);
  }

  function renderAll(to) {
    var nodes = document.querySelectorAll('.ts-price[data-base-amount]');
    for (var i = 0; i < nodes.length; i++) render(nodes[i], to);
  }

  function setCookie(code) {
    document.cookie = 'ts_currency=' + code + ';path=/;max-age=31536000;samesite=lax';
  }

  // Reflect the active currency in the switcher chrome (button label + option state)
  function reflect(code) {
    var meta = window.TS_CUR_META[code] || {};
    document.querySelectorAll('[data-cur-active-sym]').forEach(function (n) {
      // '' when the symbol IS the code (KES), else the button reads "KES KES"
      var sym = (meta.symbol || '').trim();
      n.textContent = sym.toUpperCase() === code.toUpperCase() ? '' : sym;
    });
    document.querySelectorAll('[data-cur-active-code]').forEach(function (n) {
      n.textContent = code;
    });
    document.querySelectorAll('[data-cur-set]').forEach(function (n) {
      n.classList.toggle('on', n.getAttribute('data-cur-set') === code);
    });
  }

  function setCurrency(code) {
    code = (code || '').toUpperCase();
    if (!window.TS_CUR_META[code]) return;
    window.TS_CUR = code;
    setCookie(code);
    reflect(code);
    renderAll(code);
    closeMenus();
  }
  window.tsSetCurrency = setCurrency; // exposed for any other UI that wants to switch

  // ── Switcher dropdown wiring (click-toggle; works on mobile + in the drawer) ──
  function closeMenus() {
    document.querySelectorAll('.ts-cur.open').forEach(function (w) {
      w.classList.remove('open');
      var b = w.querySelector('[aria-expanded]');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Toggle a switcher open/closed
    document.querySelectorAll('.ts-cur-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var wrap = btn.closest('.ts-cur');
        if (!wrap) return;
        var willOpen = !wrap.classList.contains('open');
        closeMenus();
        if (willOpen) { wrap.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); }
      });
    });

    // Pick a currency (progressive enhancement over the ?cur= links)
    document.querySelectorAll('[data-cur-set]').forEach(function (opt) {
      opt.addEventListener('click', function (e) {
        e.preventDefault();
        setCurrency(opt.getAttribute('data-cur-set'));
      });
    });

    // Close on outside click / Escape
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.ts-cur')) closeMenus();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenus();
    });

    // Server already rendered the correct currency; ensure the switcher chrome matches.
    reflect(window.TS_CUR);
  });
})();
