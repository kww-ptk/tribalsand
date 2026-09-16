/**
 * timepicker.js — a small in-house time picker in the house style, built the same
 * way as datepicker.js (a shared popup singleton, a hidden real input it writes,
 * and a `change` it dispatches). No native <input type="time"> (against the
 * "no native inputs" rule) and no dependencies.
 *
 * USAGE — enhancement of a real text input so it still works with JS off:
 *   <input type="text" class="tp-input inp inp--sm" name="in1[42]" value="08:00">
 * On init each `.tp-input` is hidden and a styled `.tp-btn` is inserted before it.
 * Picking a time writes "HH:MM" (or "HH:MM+1" when it crosses midnight) into the
 * input and dispatches `input` + `change`, so anything listening (the attendance
 * live totals) reacts exactly as it did to typing. The raw input keeps the value
 * and submits normally.
 *
 * Programmatic updates: set input.value and dispatch `change` (or call the exposed
 * input._tpSync()) and the button relabels itself — this is how the quick-fill
 * shift buttons drive it.
 */
(function () {
  "use strict";

  var pop, tpTitle, tpHours, tpMins, tpNext, tpDone, tpClear;
  var activeInput = null, activeBtn = null;
  var state = { h: null, m: null, plus: false };

  function pad(n) { return String(n).padStart(2, "0"); }

  function parseVal(v) {
    v = String(v || "").trim();
    var plus = false;
    if (v.slice(-2) === "+1") { plus = true; v = v.slice(0, -2).trim(); }
    var m = /^(\d{1,2}):(\d{2})$/.exec(v);
    if (!m) return { h: null, m: null, plus: plus };
    return { h: Math.min(23, +m[1]), m: Math.min(59, +m[2]), plus: plus };
  }
  function fmtState(s) { return s.h == null ? "" : pad(s.h) + ":" + pad(s.m == null ? 0 : s.m) + (s.plus ? "+1" : ""); }
  function labelFor(v) { return v && v.trim() ? v.trim() : "—"; }

  // ── Build the shared popup once ─────────────────────────────────────────────
  function buildPopup() {
    pop = document.createElement("div");
    pop.className = "tp-pop";
    pop.hidden = true;
    var h = '';
    h += '<div class="tp-pop__title" id="_tpTitle">—</div>';
    h += '<div class="tp-pop__lbl">Hour</div><div class="tp-grid tp-grid--h" id="_tpHours"></div>';
    h += '<div class="tp-pop__lbl">Minute</div><div class="tp-grid tp-grid--m" id="_tpMins"></div>';
    h += '<label class="tp-next"><input type="checkbox" id="_tpNext"> Ends next day (+1)</label>';
    h += '<div class="tp-pop__foot"><button type="button" class="tp-x" id="_tpClear">Clear</button>'
       + '<button type="button" class="tp-ok" id="_tpDone">Done</button></div>';
    pop.innerHTML = h;
    document.body.appendChild(pop);

    tpTitle = pop.querySelector("#_tpTitle");
    tpHours = pop.querySelector("#_tpHours");
    tpMins  = pop.querySelector("#_tpMins");
    tpNext  = pop.querySelector("#_tpNext");
    tpDone  = pop.querySelector("#_tpDone");
    tpClear = pop.querySelector("#_tpClear");

    var i, b;
    for (i = 0; i < 24; i++) {
      b = document.createElement("button");
      b.type = "button"; b.className = "tp-cell"; b.dataset.h = i; b.textContent = pad(i);
      tpHours.appendChild(b);
    }
    [0, 15, 30, 45].forEach(function (mm) {
      b = document.createElement("button");
      b.type = "button"; b.className = "tp-cell"; b.dataset.m = mm; b.textContent = pad(mm);
      tpMins.appendChild(b);
    });

    tpHours.addEventListener("click", function (e) {
      var c = e.target.closest(".tp-cell"); if (!c) return;
      state.h = +c.dataset.h; if (state.m == null) state.m = 0; commit();
    });
    tpMins.addEventListener("click", function (e) {
      var c = e.target.closest(".tp-cell"); if (!c) return;
      state.m = +c.dataset.m; if (state.h == null) state.h = 8; commit();
    });
    tpNext.addEventListener("change", function () { state.plus = tpNext.checked; commit(); });
    tpClear.addEventListener("click", function () { state = { h: null, m: null, plus: false }; commit(); });
    tpDone.addEventListener("click", closePop);

    pop.addEventListener("click", function (e) { e.stopPropagation(); });
    document.addEventListener("click", function (e) { if (!pop.hidden && !pop.contains(e.target) && e.target !== activeBtn) closePop(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closePop(); });
    window.addEventListener("scroll", function () { if (!pop.hidden && activeBtn) positionPop(activeBtn); }, { passive: true });
    window.addEventListener("resize", function () { if (!pop.hidden && activeBtn) positionPop(activeBtn); });
  }

  // Write the current state to the active input (+ dispatch) and refresh the UI.
  function commit() {
    var v = fmtState(state);
    if (activeInput) {
      activeInput.value = v;
      activeInput.dispatchEvent(new Event("input", { bubbles: true }));
      activeInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (activeBtn) activeBtn.textContent = labelFor(v);
    render();
  }

  function render() {
    if (tpTitle) tpTitle.textContent = state.h == null ? "—" : fmtState(state);
    if (tpNext) tpNext.checked = !!state.plus;
    tpHours.querySelectorAll(".tp-cell").forEach(function (c) { c.classList.toggle("is-on", +c.dataset.h === state.h); });
    tpMins.querySelectorAll(".tp-cell").forEach(function (c) { c.classList.toggle("is-on", +c.dataset.m === state.m); });
  }

  function positionPop(btn) {
    var r = btn.getBoundingClientRect();
    var popW = Math.min(240, window.innerWidth - 24);
    pop.style.width = popW + "px";
    var left = r.left;
    if (left + popW > window.innerWidth - 12) left = window.innerWidth - popW - 12;
    if (left < 12) left = 12;
    var popH = pop.offsetHeight;
    var below = window.innerHeight - r.bottom;
    var top = (below >= popH + 8 || below >= r.top) ? r.bottom + 6 : r.top - popH - 6;
    top = Math.max(8, Math.min(top, window.innerHeight - popH - 8));
    pop.style.left = left + "px";
    pop.style.top = top + "px";
  }

  function openPop(btn, input) {
    if (input.disabled) return;
    activeInput = input; activeBtn = btn;
    state = parseVal(input.value);
    pop.hidden = false;
    render();
    positionPop(btn);
  }
  function closePop() { pop.hidden = true; activeInput = null; activeBtn = null; }

  // ── Enhance every .tp-input ─────────────────────────────────────────────────
  function enhance(input) {
    if (input.dataset.tpBound) return;
    input.dataset.tpBound = "1";
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "tp-btn";
    // Match the sizing hook the raw input used so rows stay aligned.
    if (input.classList.contains("inp--sm")) btn.classList.add("tp-btn--sm");
    btn.textContent = labelFor(input.value);
    btn.disabled = input.disabled;
    input.parentNode.insertBefore(btn, input);
    input.hidden = true;
    input._tpBtn = btn;
    // Keep the button in sync when the value/disabled state changes elsewhere
    // (the quick-fill shift buttons drive the input, not the picker).
    input._tpSync = function () {
      btn.textContent = labelFor(input.value);
      btn.disabled = input.disabled;
    };
    input.addEventListener("change", input._tpSync);
    input.addEventListener("input", input._tpSync);
    btn.addEventListener("click", function (e) { e.stopPropagation(); openPop(btn, input); });
  }

  function init() {
    var inputs = document.querySelectorAll(".tp-input");
    if (!inputs.length) return;
    if (!pop) buildPopup();
    inputs.forEach(enhance);
  }

  window.initTimepickers = init;
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
