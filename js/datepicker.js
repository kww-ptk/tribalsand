/**
 * datepicker.js — Reusable bk-cal date picker. No dependencies, no flatpickr.
 *
 * SINGLE DATE:
 *   <button type="button" class="dp-btn" data-dp-target="myInputId">Select date</button>
 *   <input type="hidden" id="myInputId" name="field_name">
 *
 * DATE RANGE (ci + co share the same data-dp-pair value):
 *   <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="g1" data-dp-target="ciId">Select date</button>
 *   <input type="hidden" id="ciId" name="check_in">
 *   <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="g1" data-dp-target="coId">Select date</button>
 *   <input type="hidden" id="coId" name="check_out">
 *
 * Prefill: just set a YYYY-MM-DD value on the hidden input — datepicker.js reads it on open.
 */
(function () {
  "use strict";

  const MONTHS = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  const today  = new Date(); today.setHours(0, 0, 0, 0);

  // ── Popup singleton ──────────────────────────────────────────────────────────
  let pop, dpGrid, dpMonthLbl, dpPrev, dpNext, dpHint, dpDone;

  // ── State ─────────────────────────────────────────────────────────────────────
  let vy = today.getFullYear(), vm = today.getMonth();
  let selStart = null, selEnd = null;
  let mode = null;           // "single" | "ci" | "co"
  let activeTrigger = null;
  // Range context
  let rCiBtn = null, rCoBtn = null, rCiIn = null, rCoIn = null;
  // Single context
  let sSingleIn = null;

  // ── Helpers ───────────────────────────────────────────────────────────────────
  function ymd(d) {
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }
  function parseYmd(s) { return new Date(s + "T00:00"); }
  function fmtDisp(d)  { return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }); }

  // ── Build shared popup (appended to <body> once) ──────────────────────────────
  function buildPopup() {
    pop = document.createElement("div");
    pop.className = "dp-pop";
    pop.hidden = true;
    pop.innerHTML =
      '<div class="bk-cal">' +
        '<div class="bk-cal__head">' +
          '<button type="button" class="bk-cal__nav" id="_dpPrev">&#8249;</button>' +
          '<span class="bk-cal__title" id="_dpMonth"></span>' +
          '<button type="button" class="bk-cal__nav" id="_dpNext">&#8250;</button>' +
        '</div>' +
        '<div class="bk-cal__dow"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>' +
        '<div class="bk-cal__grid" id="_dpGrid"></div>' +
      '</div>' +
      '<div class="bk-pop__footer">' +
        '<span class="bk-pop__hint" id="_dpHint"></span>' +
        '<button type="button" class="bk-pop__cta" id="_dpDone">Done</button>' +
      '</div>';
    document.body.appendChild(pop);

    dpGrid     = document.getElementById("_dpGrid");
    dpMonthLbl = document.getElementById("_dpMonth");
    dpPrev     = document.getElementById("_dpPrev");
    dpNext     = document.getElementById("_dpNext");
    dpHint     = document.getElementById("_dpHint");
    dpDone     = document.getElementById("_dpDone");

    dpPrev.addEventListener("click", () => { vm--; if (vm < 0)  { vm = 11; vy--; } renderCal(); });
    dpNext.addEventListener("click", () => { vm++; if (vm > 11) { vm = 0;  vy++; } renderCal(); });
    dpDone.addEventListener("click", closePopup);
    pop.addEventListener("click",    e => e.stopPropagation());
    dpGrid.addEventListener("mouseleave", clearHover);

    document.addEventListener("click",   e => { if (!pop.hidden && !pop.contains(e.target)) closePopup(); });
    document.addEventListener("keydown", e => { if (e.key === "Escape") closePopup(); });
    window.addEventListener("scroll",  () => { if (!pop.hidden && activeTrigger) positionPop(activeTrigger); }, { passive: true });
    window.addEventListener("resize",  () => { if (!pop.hidden && activeTrigger) positionPop(activeTrigger); });
  }

  // ── Calendar render ───────────────────────────────────────────────────────────
  function renderCal() {
    dpMonthLbl.textContent = `${MONTHS[vm]} ${vy}`;
    const first   = new Date(vy, vm, 1);
    const last    = new Date(vy, vm + 1, 0);
    const leading = (first.getDay() + 6) % 7; // Mon-first grid

    let html = "";
    for (let i = 0; i < leading; i++) html += `<div class="bk-cell bk-cell--blank"></div>`;
    for (let d = 1; d <= last.getDate(); d++) {
      const date = new Date(vy, vm, d);
      const key  = ymd(date);
      let cls = "bk-cell";
      if (date < today || (mode === "co" && selStart && date <= selStart)) {
        cls += " bk-cell--blocked";
      } else if (selStart && key === ymd(selStart)) {
        cls += " bk-cell--start";
      } else if (selEnd && mode !== "single" && key === ymd(selEnd)) {
        cls += " bk-cell--end";
      } else if (selStart && selEnd && mode !== "single" && date > selStart && date < selEnd) {
        cls += " bk-cell--in-range";
      }
      html += `<div class="${cls}" data-date="${key}">${d}</div>`;
    }
    dpGrid.innerHTML = html;
    dpGrid.querySelectorAll(".bk-cell:not(.bk-cell--blocked):not(.bk-cell--blank)").forEach(cell => {
      cell.addEventListener("click",      () => onDayClick(cell.dataset.date));
      cell.addEventListener("mouseenter", () => onCellHover(cell.dataset.date));
    });
  }

  function onCellHover(dateStr) {
    if (mode !== "co" || !selStart || selEnd) return;
    const start = ymd(selStart);
    dpGrid.querySelectorAll(".bk-cell[data-date]").forEach(c => {
      c.classList.toggle("bk-cell--hover-range", c.dataset.date > start && c.dataset.date < dateStr);
    });
  }
  function clearHover() {
    dpGrid.querySelectorAll(".bk-cell--hover-range").forEach(c => c.classList.remove("bk-cell--hover-range"));
  }

  // ── Day click logic ───────────────────────────────────────────────────────────
  function onDayClick(dateStr) {
    const clicked = parseYmd(dateStr);

    if (mode === "single") {
      activeTrigger.textContent = fmtDisp(clicked);
      activeTrigger.classList.add("dp-btn--active");
      if (sSingleIn) {
        sSingleIn.value = dateStr;
        sSingleIn.dispatchEvent(new Event("change", { bubbles: true }));
      }
      closePopup();
      return;
    }

    if (mode === "ci") {
      selStart = clicked;
      // Clear checkout if it's on or before the new checkin
      if (selEnd && selEnd <= selStart) {
        selEnd = null;
        if (rCoIn)  rCoIn.value = "";
        if (rCoBtn) { rCoBtn.textContent = rCoBtn.dataset.dpPlaceholder || "Select date"; rCoBtn.classList.remove("dp-btn--active"); }
      }
      mode = "co";
      dpHint.textContent = "Now select your check-out date";
      if (rCiBtn) { rCiBtn.textContent = fmtDisp(selStart); rCiBtn.classList.add("dp-btn--active"); }
      if (rCiIn)  rCiIn.value = ymd(selStart);
    } else {
      selEnd = clicked;
      dpHint.textContent = "Click Done to confirm";
      if (rCoBtn) { rCoBtn.textContent = fmtDisp(selEnd); rCoBtn.classList.add("dp-btn--active"); }
      if (rCoIn)  rCoIn.value = ymd(selEnd);
    }
    clearHover();
    renderCal();
  }

  // ── Popup position (fixed, follows trigger) ───────────────────────────────────
  function positionPop(trigger) {
    const r    = trigger.getBoundingClientRect();
    const popW = Math.min(310, window.innerWidth - 32);
    let left   = r.left;
    if (left + popW > window.innerWidth - 16) left = window.innerWidth - popW - 16;
    if (left < 16) left = 16;
    pop.style.left = `${left}px`;
    pop.style.top  = `${r.bottom + 8}px`;
  }

  // ── Open single-date picker ───────────────────────────────────────────────────
  function openSingle(btn) {
    sSingleIn     = document.getElementById(btn.dataset.dpTarget);
    mode          = "single";
    activeTrigger = btn;
    selStart      = sSingleIn?.value ? parseYmd(sSingleIn.value) : null;
    selEnd        = null;
    vy = selStart ? selStart.getFullYear() : today.getFullYear();
    vm = selStart ? selStart.getMonth()    : today.getMonth();
    dpHint.textContent = "Select a date";
    pop.hidden = false;
    positionPop(btn);
    renderCal();
  }

  // ── Open range picker (ci or co) ──────────────────────────────────────────────
  function openRange(btn, role) {
    const pairId  = btn.dataset.dpPair;
    const allPair = pairId ? [...document.querySelectorAll(`[data-dp-pair="${pairId}"]`)] : [];
    rCiBtn = allPair.find(b => b.dataset.dpRole === "ci") || (role === "ci" ? btn : null);
    rCoBtn = allPair.find(b => b.dataset.dpRole === "co") || (role === "co" ? btn : null);
    rCiIn  = rCiBtn ? document.getElementById(rCiBtn.dataset.dpTarget) : null;
    rCoIn  = rCoBtn ? document.getElementById(rCoBtn.dataset.dpTarget) : null;
    selStart      = rCiIn?.value  ? parseYmd(rCiIn.value)  : null;
    selEnd        = rCoIn?.value  ? parseYmd(rCoIn.value)  : null;
    if (role === "co" && !selStart) role = "ci"; // can't pick checkout first
    mode          = role;
    activeTrigger = btn;
    vy = selStart ? selStart.getFullYear() : today.getFullYear();
    vm = selStart ? selStart.getMonth()    : today.getMonth();
    dpHint.textContent = mode === "ci"
      ? (selStart ? "Change your check-in date" : "Select your check-in date")
      : "Select your check-out date";
    pop.hidden = false;
    positionPop(btn);
    renderCal();
  }

  function closePopup() {
    pop.hidden    = true;
    mode          = null;
    activeTrigger = null;
    rCiBtn = rCoBtn = rCiIn = rCoIn = sSingleIn = null;
  }

  // ── Init ──────────────────────────────────────────────────────────────────────
  function init() {
    const btns = document.querySelectorAll(".dp-btn");
    if (!btns.length) return;
    buildPopup();
    btns.forEach(btn => {
      // Pre-fill button label if hidden input already has a value (e.g. from URL params)
      const inp = btn.dataset.dpTarget ? document.getElementById(btn.dataset.dpTarget) : null;
      if (inp?.value) { btn.textContent = fmtDisp(parseYmd(inp.value)); btn.classList.add("dp-btn--active"); }

      btn.addEventListener("click", e => {
        e.stopPropagation();
        const role = btn.dataset.dpRole;
        if (role === "ci" || role === "co") openRange(btn, role);
        else openSingle(btn);
      });
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
