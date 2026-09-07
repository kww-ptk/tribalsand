/*
 * Cloudflare Turnstile — explicit-mode bootstrap.
 *
 * api.js is loaded with ?render=explicit rather than the implicit build, and
 * every .cf-turnstile container on the page is rendered from here instead of
 * by Cloudflare's auto-scan.
 *
 * Why: the GoHighLevel chat widget shares this page's single window.turnstile.
 * Its own loader bails out the moment it finds one --
 *
 *   loadCaptchaScript(){ if (this.getTurnstileInstance()) return; ... }
 *
 * -- and it is built against the explicit build (its internal script const is
 * api.js?render=explicit). While we loaded the implicit build it inherited an
 * instance it could not drive: its #lc-captcha container stayed empty, no token
 * was ever minted, and every chat submission failed with "Please try again".
 * Loading the explicit build hands GHL the instance it expects and keeps the
 * src attribute byte-identical to the one it looks for.
 *
 * The rendered widget still writes the hidden cf-turnstile-response input into
 * its container exactly as implicit mode did, so every form and every
 * verify_captcha() call downstream is unchanged.
 */
(function () {
  var SCAN_CLASS = '.cf-turnstile';

  function renderOne(el) {
    if (el.getAttribute('data-ts-rendered')) return;
    var key = el.getAttribute('data-sitekey');
    if (!key) return; // no site key configured (dev) — leave it alone
    el.setAttribute('data-ts-rendered', '1');
    try {
      window.turnstile.render(el, { sitekey: key });
    } catch (e) {
      // let a later scan retry rather than leaving the form permanently tokenless
      el.removeAttribute('data-ts-rendered');
    }
  }

  function renderAll() {
    var els = document.querySelectorAll(SCAN_CLASS);
    for (var i = 0; i < els.length; i++) renderOne(els[i]);
  }

  function watchForLateWidgets() {
    if (!window.MutationObserver || !document.body) return;
    var queued = false;
    new MutationObserver(function () {
      if (queued) return;            // coalesce bursts of DOM churn into one scan
      queued = true;
      requestAnimationFrame(function () { queued = false; renderAll(); });
    }).observe(document.body, { childList: true, subtree: true });
  }

  // api.js is async+defer, so it may land before or after this file. Poll for it
  // rather than calling turnstile.ready(), which throws outright on an
  // async/defer script tag ("Remove async/defer ... before using
  // turnstile.ready()"). A live turnstile.render is itself proof of readiness.
  function boot() {
    if (!window.turnstile || typeof window.turnstile.render !== 'function') {
      setTimeout(boot, 50);
      return;
    }
    renderAll();
    watchForLateWidgets();
  }

  boot();
})();
