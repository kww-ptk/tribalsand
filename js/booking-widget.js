// Tribal Sand booking widget — luxury two-month calendar + request-to-book.
// Exposes window.initBookingWidget() and window.tsLoadRoom().
(function () {
  let slug = "", defPrice = 0, currency = "USD";
  let bound = false;

  window.initBookingWidget = function initBookingWidget() {
    const wrap = document.getElementById("availCalendar");
    if (!wrap) return;
    const form = document.getElementById("availForm");
    if (!form) return;

    // Elements
    const ciBtn      = document.getElementById("bkCiBtn");
    const coBtn      = document.getElementById("bkCoBtn");
    const ciValue    = document.getElementById("bkCiValue");
    const coValue    = document.getElementById("bkCoValue");
    const datesPop   = document.getElementById("bkDatesPop");
    const datesHint  = document.getElementById("bkDatesHint");
    const datesDone  = document.getElementById("bkDatesDone");
    const guestsBtn  = document.getElementById("bkGuestsBtn");
    const guestsPop  = document.getElementById("bkGuestsPop");
    const guestsValue= document.getElementById("bkGuestsValue");
    const guestsDone = document.getElementById("bkGuestsDone");
    const calGrid    = document.getElementById("bkCalGrid");
    const calGrid2   = document.getElementById("bkCalGrid2");
    const monthLbl   = document.getElementById("bkMonthLabel");
    const monthLbl2  = document.getElementById("bkMonthLabel2");
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
    let availSeq = 0;
    let availOk  = null;

    const today = new Date(); today.setHours(0, 0, 0, 0);
    viewYear  = today.getFullYear();
    viewMonth = today.getMonth();

    const MONTHS = ["January","February","March","April","May","June","July","August","September","October","November","December"];

    function ymd(d) {
      return d.getFullYear() + "-" + String(d.getMonth()+1).padStart(2,"0") + "-" + String(d.getDate()).padStart(2,"0");
    }
    function parseYmd(s) { return new Date(s + "T00:00"); }
    function isBlocked(d) { return fullyBlocked.includes(ymd(d)); }
    function isPast(d)    { return d < today; }

    // Right-month year/month
    function rightMonth() {
      return viewMonth === 11
        ? { y: viewYear + 1, m: 0 }
        : { y: viewYear,     m: viewMonth + 1 };
    }

    // ── Render a single month into a grid ───────────────────────
    function renderMonth(grid, label, year, month) {
      label.textContent = `${MONTHS[month]} ${year}`;
      const firstDay = new Date(year, month, 1);
      const lastDay  = new Date(year, month + 1, 0);
      const leading  = (firstDay.getDay() + 6) % 7; // Mon-first

      let html = "";
      for (let i = 0; i < leading; i++) html += `<div class="bk-cell bk-cell--blank"></div>`;

      for (let d = 1; d <= lastDay.getDate(); d++) {
        const date = new Date(year, month, d);
        const key  = ymd(date);
        let cls = "bk-cell";

        if (isPast(date) || isBlocked(date)) {
          cls += " bk-cell--blocked";
        } else {
          if (selStart && key === ymd(selStart)) cls += " bk-cell--start";
          if (selEnd   && key === ymd(selEnd))   cls += " bk-cell--end";
          if (selStart && selEnd && date > selStart && date < selEnd) cls += " bk-cell--in-range";
        }
        html += `<div class="${cls}" data-date="${key}">${d}</div>`;
      }
      grid.innerHTML = html;

      grid.querySelectorAll(".bk-cell:not(.bk-cell--blocked):not(.bk-cell--blank)").forEach(cell => {
        cell.addEventListener("click",      () => onDayClick(cell.dataset.date));
        cell.addEventListener("mouseenter", () => onCellHover(cell.dataset.date));
      });
    }

    function renderCal() {
      renderMonth(calGrid,  monthLbl,  viewYear, viewMonth);
      const r = rightMonth();
      if (calGrid2 && monthLbl2) renderMonth(calGrid2, monthLbl2, r.y, r.m);
    }

    // ── Hover range across both grids ───────────────────────────
    function allCells() {
      const cells = [...calGrid.querySelectorAll(".bk-cell[data-date]")];
      if (calGrid2) cells.push(...calGrid2.querySelectorAll(".bk-cell[data-date]"));
      return cells;
    }

    function onCellHover(dateStr) {
      if (!selStart || selEnd) return;
      const start = ymd(selStart);
      allCells().forEach(c => {
        const d  = c.dataset.date;
        const lo = start <= dateStr ? start : dateStr;
        const hi = start <= dateStr ? dateStr : start;
        c.classList.toggle("bk-cell--hover-range", d > lo && d < hi);
      });
    }

    function clearHoverRange() {
      allCells().forEach(c => c.classList.remove("bk-cell--hover-range"));
    }

    // ── Day click ───────────────────────────────────────────────
    function onDayClick(dateStr) {
      const clicked = parseYmd(dateStr);

      if (!selStart || (selStart && selEnd)) {
        selStart = clicked; selEnd = null;
        setHint("Now select your check-out date", "neutral");
        // visually indicate check-out trigger is next
        ciBtn.setAttribute("aria-expanded", "false");
        coBtn.setAttribute("aria-expanded", "true");
      } else if (clicked <= selStart) {
        selStart = clicked; selEnd = null;
        setHint("Now select your check-out date", "neutral");
        ciBtn.setAttribute("aria-expanded", "false");
        coBtn.setAttribute("aria-expanded", "true");
      } else {
        selEnd = clicked;
        ciBtn.setAttribute("aria-expanded", "false");
        coBtn.setAttribute("aria-expanded", "false");
      }
      clearHoverRange();
      renderCal();
      updateDateFields();
      updateTotal();

      if (selStart && selEnd) checkAvailability();
    }

    function setHint(text, tone) {
      datesHint.textContent  = text;
      datesHint.dataset.tone = tone || "neutral";
    }

    // ── Live availability check ──────────────────────────────────
    async function checkAvailability() {
      if (!selStart || !selEnd) return;
      const ci = ymd(selStart), co = ymd(selEnd);
      setHint("Checking availability…", "loading");
      const mySeq = ++availSeq;
      try {
        const res  = await fetch(`/api/check-availability.php?room=${encodeURIComponent(slug)}&check_in=${ci}&check_out=${co}`);
        const data = await res.json();
        if (mySeq !== availSeq) return;
        availOk = !!data.available;
        if (data.available) {
          const nights   = data.nights || Math.round((selEnd - selStart) / 86400000);
          const totalFmt = (data.total || 0).toLocaleString("en-US", { style: "currency", currency: data.currency || currency });
          setHint(`✓ Available — ${nights} night${nights > 1 ? "s" : ""} · ${totalFmt}`, "ok");
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

    // ── Update check-in / check-out display fields ───────────────
    function updateDateFields() {
      const fmt = d => d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });

      if (selStart) {
        ciValue.textContent = fmt(selStart);
        ciValue.classList.remove("is-empty");
        ciHidden.value = ymd(selStart);
      } else {
        ciValue.textContent = "Add date";
        ciValue.classList.add("is-empty");
        ciHidden.value = "";
      }

      if (selEnd) {
        coValue.textContent = fmt(selEnd);
        coValue.classList.remove("is-empty");
        coHidden.value = ymd(selEnd);
      } else {
        coValue.textContent = "Add date";
        coValue.classList.add("is-empty");
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

    // ── Popover open/close ──────────────────────────────────────
    function closeAllPops() {
      [datesPop, guestsPop].forEach(p => p.hidden = true);
      [ciBtn, coBtn, guestsBtn].forEach(b => b.setAttribute("aria-expanded", "false"));
    }

    function openDatePop(trigger) {
      guestsPop.hidden = true;
      guestsBtn.setAttribute("aria-expanded", "false");
      const isOpen = !datesPop.hidden;
      if (isOpen && trigger.getAttribute("aria-expanded") === "true") {
        // clicking the already-active trigger closes
        closeAllPops();
        return;
      }
      [ciBtn, coBtn].forEach(b => b.setAttribute("aria-expanded", "false"));
      datesPop.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
    }

    // ── Guests stepper ──────────────────────────────────────────
    function updateGuestsPill() {
      const a = parseInt(adultsH.value, 10);
      const c = parseInt(childrenH.value, 10);
      let parts = [`${a} adult${a !== 1 ? "s" : ""}`];
      if (c > 0) parts.push(`${c} child${c !== 1 ? "ren" : ""}`);
      guestsValue.textContent = parts.join(", ");
    }

    // ── Form error feedback ─────────────────────────────────────
    function showError(msg) {
      feedback.hidden = false;
      feedback.textContent = msg;
      feedback.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
    function clearError() { feedback.hidden = true; feedback.textContent = ""; }

    // ── Bind events (once only) ──────────────────────────────────
    if (!bound) {
      prevBtn.addEventListener("click", () => {
        viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } renderCal();
      });
      nextBtn.addEventListener("click", () => {
        viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } renderCal();
      });

      ciBtn.addEventListener("click", e => { e.stopPropagation(); openDatePop(ciBtn); });
      coBtn.addEventListener("click", e => { e.stopPropagation(); openDatePop(coBtn); });
      guestsBtn.addEventListener("click", e => {
        e.stopPropagation();
        const open = !guestsPop.hidden;
        closeAllPops();
        if (!open) { guestsPop.hidden = false; guestsBtn.setAttribute("aria-expanded", "true"); }
      });

      datesDone.addEventListener("click", closeAllPops);
      guestsDone.addEventListener("click", closeAllPops);

      // Stop popover clicks reaching document handler
      datesPop.addEventListener("click",  e => e.stopPropagation());
      guestsPop.addEventListener("click", e => e.stopPropagation());

      document.addEventListener("click",   e => { if (!wrap.contains(e.target)) closeAllPops(); });
      document.addEventListener("keydown", e => { if (e.key === "Escape") closeAllPops(); });

      calGrid.addEventListener("mouseleave", clearHoverRange);
      if (calGrid2) calGrid2.addEventListener("mouseleave", clearHoverRange);

      guestsPop.querySelectorAll("[data-bk]").forEach(btn => {
        btn.addEventListener("click", () => {
          const key     = btn.dataset.bk;
          const min     = key === "adult" ? 1 : 0;
          const countEl = guestsPop.querySelector(`[data-bk-count="${key}"]`);
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
          openDatePop(ciHidden.value ? coBtn : ciBtn);
          return;
        }
        if (availOk === false) {
          showError("Those dates aren't available. Please pick a different range.");
          openDatePop(ciBtn);
          return;
        }

        const originalLabel = submitLbl.textContent;
        submitBtn.disabled  = true;
        submitLbl.textContent = "Checking availability…";

        const data = {
          room_slug:             slug,
          checkin:               ciHidden.value,
          checkout:              coHidden.value,
          name:                  form.querySelector("[name=name]").value.trim(),
          email:                 form.querySelector("[name=email]").value.trim(),
          phone:                 form.querySelector("[name=phone]")?.value.trim() || "",
          adults:                parseInt(adultsH.value, 10),
          children:              parseInt(childrenH.value, 10),
          message:               form.querySelector("[name=message]")?.value.trim() || "",
          "h-captcha-response":  form.querySelector("[name='h-captcha-response']")?.value || "",
        };

        try {
          const res  = await fetch("/api/submit-enquiry.php", {
            method:  "POST",
            headers: { "Content-Type": "application/json" },
            body:    JSON.stringify(data),
          });
          const json = await res.json();

          if (json.ok) {
            // Scroll the widget into view so guest sees the confirmation area
            wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Show animated success modal with 24h countdown
            if (typeof window.showSuccessModal === 'function') {
              window.showSuccessModal(
                'Your dates are held!',
                'Good news — those dates are available. We\'ve put a 24-hour hold on your booking and will confirm by email shortly.',
                true // show countdown
              );
            }
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
    } // end if (!bound)

    renderCal();
    updateDateFields();
    updateGuestsPill();

    // Re-point the widget at a new room (modal reuse)
    window.tsLoadRoom = function (newSlug, newPrice, newCurrency, prefill) {
      slug     = newSlug || wrap.dataset.slug || "";
      defPrice = parseFloat(newPrice != null ? newPrice : wrap.dataset.price) || 0;
      currency = newCurrency || wrap.dataset.currency || "USD";
      wrap.dataset.slug = slug; wrap.dataset.price = defPrice; wrap.dataset.currency = currency;
      selStart = null; selEnd = null; availOk = null; fullyBlocked = [];
      clearError();
      updateDateFields(); updateTotal(); renderCal();
      if (slug) {
        fetch(`/api/check-availability.php?room=${encodeURIComponent(slug)}`)
          .then(r => r.json())
          .then(d => { fullyBlocked = d.fully_blocked || []; renderCal(); })
          .catch(() => {});
      }
      if (prefill && prefill.checkin && prefill.checkout) {
        selStart = parseYmd(prefill.checkin); selEnd = parseYmd(prefill.checkout);
        updateDateFields(); updateTotal(); renderCal(); checkAvailability();
      }
    };

    bound = true;
    if (wrap.dataset.slug) window.tsLoadRoom(wrap.dataset.slug, wrap.dataset.price, wrap.dataset.currency);
  };

  document.addEventListener("DOMContentLoaded", () => window.initBookingWidget());
})();
