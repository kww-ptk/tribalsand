// Search-first availability: on "Check availability" the bar checks each room card
// (multi-room) or the venue fallback room (whole-villa) and reveals "Request these dates" buttons.
document.addEventListener("DOMContentLoaded", () => {
  const bar = document.getElementById("rrBar");
  if (!bar) return;
  const ci = document.getElementById("rrBarCheckin");
  const co = document.getElementById("rrBarCheckout");
  const guests = document.getElementById("rrBarGuests");
  const btn = document.getElementById("rrBarCheck");
  const result = document.getElementById("rrBarResult");
  if (!btn) return;

  const fmtMoney = (n, cur) => (n || 0).toLocaleString("en-US", { style: "currency", currency: cur || "USD" });

  function reqButton(slug, name, price, currency) {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "rr-req-btn";
    b.textContent = "Request these dates →";
    b.addEventListener("click", () => {
      const adults = guests ? parseInt(guests.value, 10) : 2;
      window.tsOpenBookingModal(slug, name, price, currency, { checkin: ci.value, checkout: co.value, adults });
    });
    return b;
  }

  async function check(slug) {
    const url = `/api/check-availability.php?room=${encodeURIComponent(slug)}&check_in=${ci.value}&check_out=${co.value}`;
    try { return await (await fetch(url)).json(); } catch { return { available: false, error: 1 }; }
  }

  btn.addEventListener("click", async () => {
    if (!ci.value || !co.value || ci.value >= co.value) {
      if (result) { result.hidden = false; result.textContent = "Please choose a check-in and a later check-out date."; }
      return;
    }
    btn.disabled = true; const lbl = btn.textContent; btn.textContent = "Checking…";

    const cards = Array.from(document.querySelectorAll(".suite-card.rr-card[data-room-slug]"));
    if (cards.length) {
      await Promise.all(cards.map(async card => {
        const d = card.dataset;
        const slot = card.querySelector(".rr-card__avail");
        const data = await check(d.roomSlug);
        card.classList.toggle("is-unavailable", !data.available);
        if (slot) {
          slot.hidden = false;
          slot.innerHTML = "";
          if (data.available) {
            const p = document.createElement("div");
            p.className = "rr-card__avail-ok";
            p.textContent = "Available" + (data.total ? " · " + fmtMoney(data.total, data.currency) : "");
            slot.appendChild(p);
            slot.appendChild(reqButton(d.roomSlug, d.roomName, d.price, d.currency));
          } else {
            const p = document.createElement("div");
            p.className = "rr-card__avail-no";
            p.textContent = "Not available for these dates";
            slot.appendChild(p);
          }
        }
      }));
      const firstOk = document.querySelector(".suite-card.rr-card:not(.is-unavailable) .rr-card__avail");
      if (firstOk) firstOk.scrollIntoView({ behavior: "smooth", block: "center" });
    } else {
      const slug = bar.dataset.fallbackSlug;
      if (result) {
        result.hidden = false; result.innerHTML = "";
        if (!slug) { result.textContent = "Please contact us to check availability."; }
        else {
          const data = await check(slug);
          if (data.available) {
            const p = document.createElement("span");
            p.className = "rr-bar__ok";
            p.textContent = "✓ Available" + (data.total ? " · " + fmtMoney(data.total, data.currency) : "");
            result.appendChild(p);
            result.appendChild(reqButton(slug, bar.dataset.fallbackName, bar.dataset.fallbackPrice, bar.dataset.fallbackCurrency));
          } else {
            result.textContent = "✗ Not available for these dates — try different dates or contact us.";
          }
        }
      }
    }
    btn.disabled = false; btn.textContent = lbl;
  });
});
