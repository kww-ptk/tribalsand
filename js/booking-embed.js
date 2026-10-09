/*
 * Tribal Sand booking widget — loader for the properties' own websites.
 *
 *   <div data-tribalsand-booking="maya-kobe"></div>
 *   <script src="https://tribalsand.com/js/booking-embed.js" async></script>
 *
 * Turns every [data-tribalsand-booking] element into an iframe of
 * /booking-embed?venue=<slug> on the site this script was loaded from, so the
 * guest uses the same widget, availability and booking flow as tribalsand.com.
 * The iframe follows the widget's height, and covers the whole window while a
 * pop-up (booking form, confirmation) is open, then shrinks back.
 * Options on the element: data-max-width="440px" (default 440px).
 * Admin → Website → Properties & rooms → Booking widgets gives the code.
 */
(function () {
  "use strict";
  if (window.__tsBookingEmbed) { window.__tsBookingEmbed.scan(); return; }

  var script = document.currentScript || (function () {
    var s = document.querySelectorAll('script[src*="booking-embed.js"]');
    return s[s.length - 1];
  })();
  var origin = script ? new URL(script.src, location.href).origin : "https://tribalsand.com";
  var frames = {};
  var seq = 0;

  function make(el) {
    if (el.getAttribute("data-ts-ready")) return;
    var slug = (el.getAttribute("data-tribalsand-booking") || "").trim();
    if (!/^[a-z0-9_-]{1,80}$/.test(slug)) return;
    el.setAttribute("data-ts-ready", "1");
    var id = "tse" + (++seq);
    var f = document.createElement("iframe");
    f.src = origin + "/booking-embed?venue=" + encodeURIComponent(slug) +
            "&from=" + encodeURIComponent(location.hostname) + "#tsid=" + id;
    f.title = "Book your stay";
    f.setAttribute("loading", "lazy");
    f.setAttribute("allowtransparency", "true");
    f.style.cssText = "display:block;width:100%;max-width:" + (el.getAttribute("data-max-width") || "440px") +
                      ";height:620px;border:0;margin:0 auto;background:transparent;color-scheme:normal";
    el.appendChild(f);
    frames[id] = { frame: f, height: 620, overlay: false, inline: f.style.cssText };
  }

  function apply(rec) {
    var s = rec.frame.style;
    if (rec.overlay) {
      s.position = "fixed"; s.top = "0"; s.left = "0"; s.right = "0"; s.bottom = "0";
      s.width = "100%"; s.maxWidth = "none"; s.height = "100%"; s.zIndex = "2147483000";
      document.documentElement.style.overflow = "hidden";
    } else {
      rec.frame.style.cssText = rec.inline;
      s.height = rec.height + "px";
      var anyOpen = Object.keys(frames).some(function (k) { return frames[k].overlay; });
      if (!anyOpen) document.documentElement.style.overflow = "";
    }
  }

  window.addEventListener("message", function (e) {
    if (e.origin !== origin || !e.data || e.data.tsEmbed !== 1) return;
    var rec = frames[e.data.id];
    if (!rec || rec.frame.contentWindow !== e.source) return;
    var h = Math.max(200, Math.min(4000, parseInt(e.data.height, 10) || rec.height));
    var changed = h !== rec.height || !!e.data.overlay !== rec.overlay;
    rec.height = h; rec.overlay = !!e.data.overlay;
    if (changed) apply(rec);
  });

  function scan() { document.querySelectorAll("[data-tribalsand-booking]").forEach(make); }
  window.__tsBookingEmbed = { scan: scan };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", scan); else scan();
})();
