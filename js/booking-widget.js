// Tribal Sand booking widget — self-contained availability calendar + request-to-book.
// Exposes window.initBookingWidget() and window.tsLoadRoom(...) so the same calendar
// powers both the inline widget and the booking modal. Inert unless #availCalendar exists.
(function () {
  let slug = "", defPrice = 0, currency = "USD";
  let bound = false;

  window.initBookingWidget = function initBookingWidget() {
    const wrap = document.getElementById("availCalendar");
    if (!wrap) return;
    const form = document.getElementById("availForm");
    if (!form) return;

    // Elements
    const datesBtn   = document.getElementById("bkDatesBtn");
    const datesPop   = document.getElementById("bkDatesPop");
    const datesValue = document.getElementById("bkDatesValue");
    const datesHint  = document.getElementById("bkDatesHint");
    const datesDone  = document.getElementById("bkDatesDone");
    const guestsBtn  = document.getElementById("bkGuestsBtn");
    const guestsPop  = document.getElementById("bkGuestsPop");
    const guestsValue= document.getElementById("bkGuestsValue");
    const guestsDone = document.getElementById("bkGuestsDone");
    const calGrid    = document.getElementById("bkCalGrid");
    const monthLbl   = document.getElementById("bkMonthLabel");
    const prevBtn    = document.getElementById("bkPrevMonth");
    const nextBtn    = document.getElementById("bkNextMonth");
    const ciHidden   = document.getElementById("availCheckin");
    const coHidden   = document.getElementById("availCheckout");
    const adultsH    = document.getElementById("availAdults");
    const childrenH  = document.getElementById("availChildren");
    const totalCard  = document.getElementById("bkTotal");
    const totalLabel = document.getElementById("bkTotalLabel");
    const totalPrice = document.getElementById("bkTotalPrice");
    const feedback   = document.getElementById("availFeedback");
    const submitBtn  = form.querySelector(".bk-submit");
    const submitLbl  = submitBtn.querySelector(".bk-submit__label");

    // State
    let fullyBlocked = [];
    let viewYear, viewMonth;
    let selStart = null, selEnd = null;
    let availSeq = 0;            // monotonic to ignore stale responses
    let availOk = null;          // true / false / null

    const today = new Date(); today.setHours(0, 0, 0, 0);
    viewYear  = today.getFullYear();
    viewMonth = today.getMonth();

    function ymd(d) {
      return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
    }
    function parseYmd(s) { return new Date(s + "T00:00"); }
    function isBlocked(d) { return fullyBlocked.includes(ymd(d)); }
    function isPast(d) { return d < today; }

    // ── Calendar render ─────────────────────────────────────────
    function renderCal() {
      const months = ["January","February","March","April","May","June","July","August","September","October","November","December"];
      monthLbl.textContent = `${months[viewMonth]} ${viewYear}`;
      const firstDay = new Date(viewYear, viewMonth, 1);
      const lastDay  = new Date(viewYear, viewMonth + 1, 0);
      const leadingBlanks = (firstDay.getDay() + 6) % 7; // Mon-first

      let html = "";
      for (let i = 0; i < leadingBlanks; i++) html += `<div class="bk-cell bk-cell--blank"></div>`;

      for (let d = 1; d <= lastDay.getDate(); d++) {
        const date = new Date(viewYear, viewMonth, d);
        const key  = ymd(date);
        let cls = "bk-cell";

        if (isPast(date) || isBlocked(date)) {
          cls += " bk-cell--blocked";
        } else {
          if (selStart && key === ymd(selStart)) cls += " bk-cell--start";
          else if (selEnd && key === ymd(selEnd)) cls += " bk-cell--end";
          else if (selStart && selEnd && date > selStart && date < selEnd) cls += " bk-cell--in-range";
        }
        html += `<div class="${cls}" data-date="${key}">${d}</div>`;
      }
      calGrid.innerHTML = html;

      // Attach handlers
      calGrid.querySelectorAll(".bk-cell:not(.bk-cell--blocked):not(.bk-cell--blank)").forEach(cell => {
        cell.addEventListener("click", () => onDayClick(cell.dataset.date));
        cell.addEventListener("mouseenter", () => onCellHover(cell.dataset.date));
      });
    }

    function onCellHover(dateStr) {
      if (!selStart || selEnd) return;
      const start = ymd(selStart);
      calGrid.querySelectorAll(".bk-cell[data-date]").forEach(c => {
        const d = c.dataset.date;
        const lo = start <= dateStr ? start : dateStr;
        const hi = start <= dateStr ? dateStr : start;
        c.classList.toggle("bk-cell--hover-range", d > lo && d < hi);
      });
    }

    function clearHoverRange() {
      calGrid.querySelectorAll(".bk-cell--hover-range").forEach(c => c.classList.remove("bk-cell--hover-range"));
    }

    function onDayClick(dateStr) {
      const clicked = parseYmd(dateStr);

      if (!selStart || (selStart && selEnd)) {
        // Start fresh
        selStart = clicked; selEnd = null;
        setHint("Now select your check-out date", "neutral");
      } else if (clicked <= selStart) {
        // Clicked earlier than current start — move start
        selStart = clicked; selEnd = null;
        setHint("Now select your check-out date", "neutral");
      } else {
        // Valid check-out
        selEnd = clicked;
      }
      clearHoverRange();
      renderCal();
      updateDatesPill();
      updateTotal();

      // Both dates set → check live availability
      if (selStart && selEnd) checkAvailability();
    }

    function setHint(text, tone /* neutral | ok | bad | loading */) {
      datesHint.textContent = text;
      datesHint.dataset.tone = tone || "neutral";
    }

    async function checkAvailability() {
      if (!selStart || !selEnd) return;
      const ci = ymd(selStart), co = ymd(selEnd);
      setHint("Checking availability…", "loading");
      const mySeq = ++availSeq;
      try {
        const res = await fetch(`/api/check-availability.php?room=${encodeURIComponent(slug)}&check_in=${ci}&check_out=${co}`);
        const data = await res.json();
        if (mySeq !== availSeq) return; // a newer selection has fired
        availOk = !!data.available;
        if (data.available) {
          const nights = data.nights || Math.round((selEnd - selStart) / 86400000);
          const totalFmt = (data.total || 0).toLocaleString("en-US", { style: "currency", currency: data.currency || currency });
          setHint(`✓ Available — ${nights} night${nights > 1 ? "s" : ""} · ${totalFmt}`, "ok");
          // Refresh total card with real price (may include rate overrides)
          totalLabel.textContent = `${nights} night${nights > 1 ? "s" : ""}`;
          totalPrice.textContent = totalFmt;
          totalCard.hidden = false;
        } else {
          setHint("✗ Sorry — no availability for these dates. Try different ones.", "bad");
        }
      } catch {
        if (mySeq !== availSeq) return;
        availOk = null;
        setHint("Couldn't verify availability right now — try again.", "bad");
      }
    }

    function updateDatesPill() {
      if (selStart && selEnd) {
        const fmt = d => d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
        const nights = Math.round((selEnd - selStart) / 86400000);
        datesValue.textContent = `${fmt(selStart)} → ${fmt(selEnd)} · ${nights}n`;
        datesValue.classList.remove("is-empty");
        ciHidden.value = ymd(selStart);
        coHidden.value = ymd(selEnd);
      } else {
        datesValue.textContent = "Add dates";
        datesValue.classList.add("is-empty");
        ciHidden.value = "";
        coHidden.value = "";
      }
    }

    function updateTotal() {
      if (!selStart || !selEnd) { totalCard.hidden = true; return; }
      const nights = Math.round((selEnd - selStart) / 86400000);
      const total  = defPrice * nights;
      totalLabel.textContent = `${nights} night${nights > 1 ? "s" : ""} · ${defPrice ? defPrice.toLocaleString("en-US", { style: "currency", currency }) : "rate"} / night`;
      totalPrice.textContent = total > 0 ? total.toLocaleString("en-US", { style: "currency", currency }) : "—";
      totalCard.hidden = false;
    }

    // ── Popover open/close logic ────────────────────────────────
    function closeAllPops() {
      [datesPop, guestsPop].forEach(p => p.hidden = true);
      datesBtn.setAttribute("aria-expanded", "false");
      guestsBtn.setAttribute("aria-expanded", "false");
    }
    function togglePop(btn, pop) {
      const open = !pop.hidden;
      closeAllPops();
      if (!open) {
        pop.hidden = false;
        btn.setAttribute("aria-expanded", "true");
      }
    }

    // ── Guests stepper ───────────────────────────────────────────
    function updateGuestsPill() {
      const a = parseInt(adultsH.value, 10);
      const c = parseInt(childrenH.value, 10);
      let parts = [`${a} adult${a !== 1 ? "s" : ""}`];
      if (c > 0) parts.push(`${c} child${c !== 1 ? "ren" : ""}`);
      guestsValue.textContent = parts.join(", ");
    }

    // ── Submit ───────────────────────────────────────────────────
    function showError(msg) {
      feedback.hidden = false;
      feedback.textContent = msg;
      feedback.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
    function clearError() { feedback.hidden = true; feedback.textContent = ""; }

    if (!bound) {
      prevBtn.addEventListener("click", () => { viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } renderCal(); });
      nextBtn.addEventListener("click", () => { viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } renderCal(); });

      datesBtn.addEventListener("click", e => { e.stopPropagation(); togglePop(datesBtn, datesPop); });
      guestsBtn.addEventListener("click", e => { e.stopPropagation(); togglePop(guestsBtn, guestsPop); });
      datesDone.addEventListener("click", closeAllPops);
      guestsDone.addEventListener("click", closeAllPops);
      // Prevent clicks inside the popovers from reaching the document-level
      // outside-click handler. Needed because the calendar re-renders mid-click,
      // detaching e.target so wrap.contains() returns false and the pop closes.
      datesPop.addEventListener("click",  e => e.stopPropagation());
      guestsPop.addEventListener("click", e => e.stopPropagation());
      document.addEventListener("click", e => {
        if (!wrap.contains(e.target)) closeAllPops();
      });
      document.addEventListener("keydown", e => { if (e.key === "Escape") closeAllPops(); });

      guestsPop.querySelectorAll("[data-bk]").forEach(btn => {
        btn.addEventListener("click", () => {
          const key = btn.dataset.bk;
          const min = key === "adult" ? 1 : 0;
          const countEl  = guestsPop.querySelector(`[data-bk-count="${key}"]`);
          const hiddenEl = key === "adult" ? adultsH : childrenH;
          let val = parseInt(countEl.textContent, 10) + parseInt(btn.dataset.dir, 10);
          val = Math.max(min, Math.min(20, val));
          countEl.textContent = val;
          hiddenEl.value = val;
          updateGuestsPill();
        });
      });

      form.addEventListener("submit", async e => {
        e.preventDefault();
        clearError();

        if (!ciHidden.value || !coHidden.value) {
          showError("Please pick your check-in and check-out dates.");
          togglePop(datesBtn, datesPop);
          return;
        }
        if (availOk === false) {
          showError("Those dates aren't available. Please pick a different range.");
          togglePop(datesBtn, datesPop);
          return;
        }

        const originalLabel = submitLbl.textContent;
        submitBtn.disabled = true;
        submitLbl.textContent = "Checking availability…";

        const data = {
          room_slug:            slug,
          checkin:              ciHidden.value,
          checkout:             coHidden.value,
          name:                 form.querySelector("[name=name]").value.trim(),
          email:                form.querySelector("[name=email]").value.trim(),
          phone:                form.querySelector("[name=phone]")?.value.trim() || "",
          adults:               parseInt(adultsH.value, 10),
          children:             parseInt(childrenH.value, 10),
          message:              form.querySelector("[name=message]")?.value.trim() || "",
          "h-captcha-response": form.querySelector("[name='h-captcha-response']")?.value || "",
        };

        try {
          const res  = await fetch("/api/submit-enquiry.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data),
          });
          const json = await res.json();

          if (json.ok) {
            wrap.innerHTML = `
              <div class="bk-success">
                <svg class="bk-success__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h3 class="bk-success__title">Your dates are held</h3>
                <p class="bk-success__body">Good news — those dates are available. We've put a 24-hour hold on your booking and will confirm by email shortly.</p>
              </div>`;
            return;
          }
          if (json.errors) {
            showError(Object.values(json.errors).filter(Boolean).join(" "));
          } else if (res.status === 409) {
            showError(json.error || "Sorry — those dates are no longer available. Try different dates.");
          } else {
            showError(json.error || "Something went wrong. Please try again.");
          }
        } catch {
          showError("Network error. Check your connection and try again.");
        } finally {
          submitBtn.disabled = false;
          submitLbl.textContent = originalLabel;
        }
      });
      calGrid.addEventListener("mouseleave", clearHoverRange);
    } // end if (!bound)

    renderCal();
    updateDatesPill();
    updateGuestsPill();

    // Expose a loader so the widget can be (re)pointed at a room (modal reuse).
    window.tsLoadRoom = function (newSlug, newPrice, newCurrency, prefill) {
      slug     = newSlug || wrap.dataset.slug || "";
      defPrice = parseFloat(newPrice != null ? newPrice : wrap.dataset.price) || 0;
      currency = newCurrency || wrap.dataset.currency || "USD";
      wrap.dataset.slug = slug; wrap.dataset.price = defPrice; wrap.dataset.currency = currency;
      selStart = null; selEnd = null; availOk = null; fullyBlocked = [];
      clearError();
      updateDatesPill(); updateTotal(); renderCal();
      if (slug) {
        fetch(`/api/check-availability.php?room=${encodeURIComponent(slug)}`)
          .then(r => r.json()).then(d => { fullyBlocked = d.fully_blocked || []; renderCal(); })
          .catch(() => {});
      }
      if (prefill && prefill.checkin && prefill.checkout) {
        selStart = parseYmd(prefill.checkin); selEnd = parseYmd(prefill.checkout);
        updateDatesPill(); updateTotal(); renderCal(); checkAvailability();
      }
    };

    bound = true;
    if (wrap.dataset.slug) window.tsLoadRoom(wrap.dataset.slug, wrap.dataset.price, wrap.dataset.currency);
  };

  document.addEventListener("DOMContentLoaded", () => window.initBookingWidget());
})();
