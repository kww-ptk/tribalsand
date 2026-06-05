// Opens the booking popup (#bkModal) for a chosen room. Exposes window.tsOpenBookingModal so
// dynamically-created "Request these dates" buttons (availability-search.js) can open it too.
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("bkModal");
  if (!modal) return;
  const title = document.getElementById("bkModalTitle");

  window.tsOpenBookingModal = function (slug, name, price, currency, prefill) {
    if (typeof window.tsLoadRoom === "function") window.tsLoadRoom(slug, price, currency, prefill || null);
    if (title && name) title.textContent = name;
    if (prefill && prefill.adults) { const a = document.getElementById("availAdults"); if (a) a.value = prefill.adults; }
    modal.hidden = false;
    document.body.classList.add("bk-modal-lock");
  };
  function close() { modal.hidden = true; document.body.classList.remove("bk-modal-lock"); }

  document.querySelectorAll(".js-check-availability").forEach(btn => {
    btn.addEventListener("click", e => {
      e.preventDefault();
      const d = btn.dataset;
      window.tsOpenBookingModal(d.roomSlug, d.roomName, d.price, d.currency, null);
    });
  });

  modal.querySelectorAll("[data-bk-close]").forEach(el => el.addEventListener("click", close));
  document.addEventListener("keydown", e => { if (e.key === "Escape" && !modal.hidden) close(); });
});
