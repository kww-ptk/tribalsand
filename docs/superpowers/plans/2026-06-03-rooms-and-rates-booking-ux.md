# Rooms & Rates Booking UX — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give multi-room property overview pages a klickenya-style "Rooms & Rates" card grid + "By room / Entire place" toggle + a sticky booking bar, where clicking "Check availability" opens a popup with that room's availability calendar — reusing the existing booking engine.

**Architecture:** Refactor the existing inline booking widget JS into a reusable controller (`initBookingWidget` + `loadRoom`) so one calendar implementation powers both the inline widget and a new modal. A data-driven `includes/rooms-and-rates.php` renders cards from the DB; a `includes/booking-modal.php` holds one reusable popup; `js/booking-modal.js` opens it for a chosen room. A new `rooms.is_entire_place` flag drives the toggle. No URL changes.

**Tech Stack:** PHP 8 + PostgreSQL (PDO), vanilla JS, the existing `bk-*` widget markup/CSS, `/api/check-availability.php` + `/api/submit-enquiry.php`.

Spec: `docs/superpowers/specs/2026-06-03-rooms-and-rates-booking-ux-design.md`.

**Conventions:** No test framework — verify with `php -l`, `node --check` (JS), `curl` against a throwaway server, `psql`, and (for the modal UX) a note to screenshot via the preview. Throwaway server: `php -S localhost:80NN router.php >/tmp/x.log 2>&1 & SRV=$!; sleep 1; … ; kill $SRV`. Postgres v18 client: `/Applications/Postgres.app/Contents/Versions/18/bin/psql` (user/db `patrikgiuliana`/`tribalsand`). Branch: `main`. End every commit with a trailing blank line then `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File structure

- **Create** `db/migrations/add_room_entire_place.sql` — `rooms.is_entire_place` flag.
- **Modify** `js/booking-widget.js` — extract `initBookingWidget(wrap)` + `loadRoom(...)`, expose globally; keep inline widget working.
- **Create** `includes/booking-modal.php` — one reusable popup containing the `bk-*` widget markup (room set at runtime).
- **Create** `js/booking-modal.js` — open/close the modal, load the clicked room, sticky-bar prefill.
- **Create** `includes/rooms-and-rates.php` — data-driven card grid + toggle + sticky bar (the `.js-check-availability` triggers).
- **Create** `css/rooms-and-rates.css` — card grid, toggle, sticky bar, modal styling (Tribal Sand palette).
- **Modify** `includes/head.php` — load the rooms-rates assets when `$page_rooms_rates` is set.
- **Modify** `my-amani.php`, `maya-kobe.php` — include the section + modal; set page vars.
- **Modify** `admin/room-edit.php` — "Entire place" checkbox bound to `is_entire_place`.

---

## Task 1: `is_entire_place` flag

**Files:** Create `db/migrations/add_room_entire_place.sql`

- [ ] **Step 1: Write the migration**
```sql
-- Marks a room as the whole-property ("Entire place") booking option,
-- so the front-end Rooms & Rates toggle can separate it from per-room bookings.
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS is_entire_place BOOLEAN NOT NULL DEFAULT FALSE;
```

- [ ] **Step 2: Run it + seed a demo flag**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php bin/migrate.php db/migrations/add_room_entire_place.sql
PSQL=/Applications/Postgres.app/Contents/Versions/18/bin/psql
"$PSQL" -h localhost -p 5432 -U patrikgiuliana -d tribalsand -c "UPDATE rooms SET is_entire_place = TRUE WHERE slug = 'my-amani-full-rental';"
"$PSQL" -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA -c "SELECT slug,is_entire_place FROM rooms WHERE venue_id=(SELECT id FROM venues WHERE slug='my-amani') ORDER BY is_entire_place DESC LIMIT 3;"
```
Expected: migration `OK`; the `my-amani-full-rental` row shows `t`.

- [ ] **Step 3: Commit**
```bash
git add db/migrations/add_room_entire_place.sql
git commit -m "feat: add rooms.is_entire_place flag for Rooms & Rates toggle"
```

---

## Task 2: Refactor the booking widget into a reusable controller

**Files:** Modify `js/booking-widget.js`

> Today the file is one IIFE bound to `#availCalendar`, reading `slug/price/currency` as `const` at the top and fetching blocked dates once. We make it: bind listeners ONCE, and expose a `loadRoom()` that (re)points the widget at a room (used by both the inline pages and the modal). Behavior for inline pages must be unchanged.

- [ ] **Step 1: Change the top of the file** — replace lines 1–12 (the `DOMContentLoaded` + IIFE open + the three `const` reads):
```js
// Tribal Sand booking widget — self-contained availability calendar + request-to-book.
// Exposes window.initBookingWidget(wrap) and window.tsLoadRoom(...) so the same calendar
// powers both the inline widget and the booking modal. Inert unless #availCalendar exists.
(function () {
  let slug = "", defPrice = 0, currency = "USD";
  let bound = false;

  window.initBookingWidget = function initBookingWidget() {
    const wrap = document.getElementById("availCalendar");
    if (!wrap) return;
    const form = document.getElementById("availForm");
    if (!form) return;
```
(Everything from the element lookups onward stays, but see Steps 2–4 for the specific changes.)

- [ ] **Step 2: Remove the one-shot blocked-dates fetch.** Delete the existing block that fetches on load (around lines 38–42):
```js
    fetch(`/api/check-availability.php?room=${encodeURIComponent(slug)}`)
      .then(r => r.json())
      .then(data => { fullyBlocked = data.fully_blocked || []; renderCal(); })
      .catch(() => {});
```
(That fetch moves into `loadRoom`, below.)

- [ ] **Step 3: Guard listener-binding so re-init doesn't double-bind, and expose `loadRoom`.** Find the end of the function (just before the final `})();` at line ~325). The listener `.addEventListener` calls and the final `renderCal(); updateDatesPill();` currently run every call. Wrap the listener-binding in `if (!bound) { … bound = true; }`, and define `loadRoom`. Concretely, locate the comment `// ── Submit ───` block's `form.addEventListener("submit", …)` and the popover/guest listeners; ensure ALL `addEventListener` registrations are inside an `if (!bound) { … }` guard. Then, at the very end of `initBookingWidget` (before its closing `}`), add:
```js
    // Expose a loader so the widget can be (re)pointed at a room (modal reuse).
    window.tsLoadRoom = function (newSlug, newPrice, newCurrency, prefill) {
      slug     = newSlug || wrap.dataset.slug || "";
      defPrice = parseFloat(newPrice != null ? newPrice : wrap.dataset.price) || 0;
      currency = newCurrency || wrap.dataset.currency || "USD";
      wrap.dataset.slug = slug; wrap.dataset.price = defPrice; wrap.dataset.currency = currency;
      selStart = null; selEnd = null; availOk = null; fullyBlocked = [];
      clearError && clearError();
      updateDatesPill(); updateTotal(); renderCal();
      if (slug) {
        fetch(`/api/check-availability.php?room=${encodeURIComponent(slug)}`)
          .then(r => r.json()).then(d => { fullyBlocked = d.fully_blocked || []; renderCal(); })
          .catch(() => {});
      }
      // Optional date prefill from the sticky bar
      if (prefill && prefill.checkin && prefill.checkout) {
        selStart = parseYmd(prefill.checkin); selEnd = parseYmd(prefill.checkout);
        updateDatesPill(); updateTotal(); renderCal(); checkAvailability();
      }
    };

    bound = true;
    // Inline pages render the widget with a slug already in the markup → auto-load it.
    if (wrap.dataset.slug) window.tsLoadRoom(wrap.dataset.slug, wrap.dataset.price, wrap.dataset.currency);
  };

  document.addEventListener("DOMContentLoaded", () => window.initBookingWidget());
})();
```
NOTE: `selStart`, `selEnd`, `availOk`, `fullyBlocked`, `parseYmd`, `renderCal`, `updateDatesPill`, `updateTotal`, `checkAvailability`, `clearError` are existing names inside the function — keep them. The change is structural: make `slug/defPrice/currency` the outer `let`s (not inner `const`), move the fetch into `tsLoadRoom`, and guard the one-time listener binding with `bound`.

- [ ] **Step 4: Verify the inline widget still works (no regression).**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
node --check js/booking-widget.js && echo "JS OK"
php -S localhost:8074 router.php >/tmp/rr2.log 2>&1 & SRV=$!; sleep 1
# /zuri inline widget must still render + the API still serves blocked dates
curl -s "http://localhost:8074/zuri" | grep -c 'id="availCalendar"'
curl -s "http://localhost:8074/api/check-availability.php?room=zuri" | grep -c "fully_blocked"
kill $SRV
```
Expected: `JS OK`; `1`; `1`. (Then I'll screenshot /zuri after to confirm the calendar opens — note for controller.)

- [ ] **Step 5: Commit**
```bash
git add js/booking-widget.js
git commit -m "refactor: make booking widget reusable (initBookingWidget + tsLoadRoom) for modal reuse"
```

---

## Task 3: The booking modal partial

**Files:** Create `includes/booking-modal.php`

> One hidden popup per page, holding the SAME `bk-*` form markup as `includes/booking-widget.php` but with empty `data-*` (the room is set by JS on open). Include it once on pages that use the cards.

- [ ] **Step 1: Write `includes/booking-modal.php`**
```php
<?php
/** Tribal Sand booking popup — one per page. The room is set at runtime by js/booking-modal.js. */
require_once __DIR__ . '/turnstile.php';
?>
<div class="bk-modal" id="bkModal" hidden>
  <div class="bk-modal__backdrop" data-bk-close></div>
  <div class="bk-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bkModalTitle">
    <button type="button" class="bk-modal__close" data-bk-close aria-label="Close">&times;</button>
    <h3 class="bk-modal__title" id="bkModalTitle">Check availability</h3>

    <div class="bk-avail" id="availCalendar" data-slug="" data-price="0" data-currency="USD">
      <form id="availForm" class="bk-form" novalidate>
        <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
        <input type="hidden" name="checkin"  id="availCheckin">
        <input type="hidden" name="checkout" id="availCheckout">
        <input type="hidden" name="adults"   id="availAdults"   value="2">
        <input type="hidden" name="children" id="availChildren" value="0">

        <button type="button" class="bk-pill" id="bkDatesBtn" aria-haspopup="dialog" aria-expanded="false">
          <span class="bk-pill__label">Dates</span>
          <span class="bk-pill__value" id="bkDatesValue">Add dates</span>
          <svg class="bk-pill__chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </button>
        <div class="bk-pop" id="bkDatesPop" role="dialog" aria-label="Select dates" hidden>
          <div class="bk-cal">
            <div class="bk-cal__head">
              <button type="button" class="bk-cal__nav" id="bkPrevMonth" aria-label="Previous month">&#8249;</button>
              <span class="bk-cal__title" id="bkMonthLabel"></span>
              <button type="button" class="bk-cal__nav" id="bkNextMonth" aria-label="Next month">&#8250;</button>
            </div>
            <div class="bk-cal__dow"><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span></div>
            <div class="bk-cal__grid" id="bkCalGrid"></div>
          </div>
          <div class="bk-pop__footer">
            <span class="bk-pop__hint" id="bkDatesHint">Select your check-in date</span>
            <button type="button" class="bk-pop__cta" id="bkDatesDone">Done</button>
          </div>
        </div>

        <button type="button" class="bk-pill" id="bkGuestsBtn" aria-haspopup="dialog" aria-expanded="false">
          <span class="bk-pill__label">Guests</span>
          <span class="bk-pill__value" id="bkGuestsValue">2 adults</span>
          <svg class="bk-pill__chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </button>
        <div class="bk-pop bk-pop--narrow" id="bkGuestsPop" role="dialog" aria-label="Select guests" hidden>
          <div class="bk-stepper-row">
            <div class="bk-stepper-row__label"><strong>Adults</strong><small>Age 18+</small></div>
            <div class="bk-stepper">
              <button type="button" data-bk="adult" data-dir="-1" aria-label="Decrease adults">&minus;</button>
              <span data-bk-count="adult">2</span>
              <button type="button" data-bk="adult" data-dir="1" aria-label="Increase adults">+</button>
            </div>
          </div>
          <div class="bk-stepper-row">
            <div class="bk-stepper-row__label"><strong>Children</strong><small>Age 0–17</small></div>
            <div class="bk-stepper">
              <button type="button" data-bk="child" data-dir="-1" aria-label="Decrease children">&minus;</button>
              <span data-bk-count="child">0</span>
              <button type="button" data-bk="child" data-dir="1" aria-label="Increase children">+</button>
            </div>
          </div>
          <div class="bk-pop__footer bk-pop__footer--end"><button type="button" class="bk-pop__cta" id="bkGuestsDone">Done</button></div>
        </div>

        <div class="bk-total" id="bkTotal" hidden>
          <div class="bk-total__row"><span class="bk-total__label" id="bkTotalLabel">— nights</span><span class="bk-total__price" id="bkTotalPrice">—</span></div>
          <div class="bk-total__hint">Final price confirmed by email</div>
        </div>

        <div class="bk-fields">
          <label class="bk-field"><span>Your name</span><input type="text" name="name" placeholder="Full name" required></label>
          <label class="bk-field"><span>Email</span><input type="email" name="email" placeholder="you@example.com" required></label>
          <label class="bk-field"><span>Phone <small>(optional)</small></span><input type="tel" name="phone" placeholder="+254 …"></label>
          <label class="bk-field"><span>Message <small>(optional)</small></span><textarea name="message" rows="2" placeholder="Special requests, arrival time, etc."></textarea></label>
        </div>

        <div class="bk-feedback" id="availFeedback" hidden></div>
        <?php if (captcha_site_key()): ?><div class="h-captcha" data-sitekey="<?= e(captcha_site_key()) ?>"></div><?php endif; ?>
        <button type="submit" class="bk-submit"><span class="bk-submit__label">Check availability</span></button>
        <p class="bk-hold-note">Dates are held for 24 hours pending confirmation</p>
      </form>
    </div>
  </div>
</div>
```

- [ ] **Step 2: Lint**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l includes/booking-modal.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**
```bash
git add includes/booking-modal.php
git commit -m "feat: add reusable booking popup partial (room set at runtime)"
```

---

## Task 4: Modal opener JS

**Files:** Create `js/booking-modal.js`

- [ ] **Step 1: Write `js/booking-modal.js`**
```js
// Opens the booking popup (#bkModal) for a chosen room when any .js-check-availability is clicked.
// Reuses the shared calendar via window.tsLoadRoom (from booking-widget.js).
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("bkModal");
  if (!modal) return;
  const title = document.getElementById("bkModalTitle");

  function openFor(slug, name, price, currency, prefill) {
    if (typeof window.tsLoadRoom === "function") {
      window.tsLoadRoom(slug, price, currency, prefill || null);
    }
    if (title && name) title.textContent = name;
    modal.hidden = false;
    document.body.classList.add("bk-modal-lock");
  }
  function close() {
    modal.hidden = true;
    document.body.classList.remove("bk-modal-lock");
  }

  // Room cards + any trigger
  document.querySelectorAll(".js-check-availability").forEach(btn => {
    btn.addEventListener("click", e => {
      e.preventDefault();
      const d = btn.dataset;
      let prefill = null;
      // Sticky bar passes dates via #rrBar inputs when present
      if (btn.dataset.fromBar) {
        const ci = document.getElementById("rrBarCheckin");
        const co = document.getElementById("rrBarCheckout");
        if (ci && co && ci.value && co.value) prefill = { checkin: ci.value, checkout: co.value };
      }
      openFor(d.roomSlug, d.roomName, d.price, d.currency, prefill);
    });
  });

  modal.querySelectorAll("[data-bk-close]").forEach(el => el.addEventListener("click", close));
  document.addEventListener("keydown", e => { if (e.key === "Escape" && !modal.hidden) close(); });
});
```

- [ ] **Step 2: Verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
node --check js/booking-modal.js && echo "JS OK"
```
Expected: `JS OK`.

- [ ] **Step 3: Commit**
```bash
git add js/booking-modal.js
git commit -m "feat: booking modal opener (loads chosen room, sticky-bar date prefill)"
```

---

## Task 5: Rooms & Rates section partial

**Files:** Create `includes/rooms-and-rates.php`

- [ ] **Step 1: Write `includes/rooms-and-rates.php`**
```php
<?php
/**
 * Tribal Sand "Rooms & Rates" section for a property (venue).
 * Usage (before including): $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php';
 * Renders nothing if the venue has no published rooms. Requires includes/booking-modal.php on the page too.
 */
require_once __DIR__ . '/db.php';

$rr_venue_slug = $rr_venue_slug ?? '';
$__v = $rr_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $rr_venue_slug])->fetch() : false;
if (!$__v) { return; }

$__rooms = db_query(
    "SELECT r.*, (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero
     FROM rooms r WHERE r.venue_id = :vid AND r.is_published = TRUE ORDER BY r.is_entire_place ASC, r.sort_order ASC",
    [':vid' => $__v['id']]
)->fetchAll();
if (!$__rooms) { return; }

$by_room = array_values(array_filter($__rooms, fn($r) => !$r['is_entire_place']));
$entire  = array_values(array_filter($__rooms, fn($r) => $r['is_entire_place']));
$has_both = $by_room && $entire;

// price label helper
$rr_price = function(array $r): string {
    $p = (float)$r['price_amount'];
    return $p > 0
        ? e($r['price_currency']) . ' ' . e(number_format($p, 0)) . ' <span class="rr-card__per">/ night</span>'
        : '<span class="rr-card__req">Price on request</span>';
};
// one card
$rr_card = function(array $r) use ($rr_price) {
    $img = !empty($r['hero']) ? storage_url($r['hero']) : '/images/whitelogo11.png';
    $sleeps = (int)($r['capacity'] ?? 0);
    ?>
    <article class="rr-card">
      <div class="rr-card__media"<?= empty($r['hero']) ? ' data-placeholder="1"' : '' ?>>
        <img src="<?= e($img) ?>" alt="<?= e($r['name']) ?>" loading="lazy">
      </div>
      <div class="rr-card__body">
        <h3 class="rr-card__name"><?= e($r['name']) ?></h3>
        <?php if ($sleeps): ?><p class="rr-card__meta">Sleeps <?= $sleeps ?></p><?php endif; ?>
        <div class="rr-card__foot">
          <div class="rr-card__price"><?= $rr_price($r) ?></div>
          <button type="button" class="rr-card__cta js-check-availability"
                  data-room-slug="<?= e($r['slug']) ?>" data-room-name="<?= e($r['name']) ?>"
                  data-price="<?= e((float)$r['price_amount']) ?>" data-currency="<?= e($r['price_currency']) ?>">
            Check availability
          </button>
        </div>
      </div>
    </article>
    <?php
};
$rr_entire_slug = $entire ? $entire[0]['slug'] : ($by_room ? $by_room[0]['slug'] : '');
$rr_entire_name = $entire ? $entire[0]['name'] : ($by_room ? $by_room[0]['name'] : '');
$rr_entire_price = $entire ? (float)$entire[0]['price_amount'] : ($by_room ? (float)$by_room[0]['price_amount'] : 0);
$rr_entire_curr = $entire ? $entire[0]['price_currency'] : ($by_room ? $by_room[0]['price_currency'] : 'USD');
?>
<section class="rr" id="book">
  <div class="rr__inner">
    <?php if ($has_both): ?>
    <div class="rr-toggle" role="tablist" aria-label="How would you like to stay?">
      <span class="rr-toggle__label">How would you like to stay?</span>
      <button type="button" class="rr-toggle__btn is-active" data-rr-tab="byroom" role="tab" aria-selected="true">By room</button>
      <button type="button" class="rr-toggle__btn" data-rr-tab="entire" role="tab" aria-selected="false">Entire place</button>
    </div>
    <?php endif; ?>

    <h2 class="rr__heading">Rooms &amp; Rates</h2>

    <?php if ($by_room): ?>
    <div class="rr-grid" data-rr-group="byroom"><?php foreach ($by_room as $r) $rr_card($r); ?></div>
    <?php endif; ?>
    <?php if ($entire): ?>
    <div class="rr-grid" data-rr-group="entire"<?= $has_both ? ' hidden' : '' ?>><?php foreach ($entire as $r) $rr_card($r); ?></div>
    <?php endif; ?>
  </div>

  <!-- Sticky booking bar -->
  <div class="rr-bar" id="rrBar">
    <div class="rr-bar__dates">
      <input type="date" id="rrBarCheckin" aria-label="Check-in">
      <input type="date" id="rrBarCheckout" aria-label="Check-out">
    </div>
    <button type="button" class="rr-bar__cta js-check-availability" data-from-bar="1"
            data-room-slug="<?= e($rr_entire_slug) ?>" data-room-name="<?= e($rr_entire_name) ?>"
            data-price="<?= e($rr_entire_price) ?>" data-currency="<?= e($rr_entire_curr) ?>">
      Check availability
    </button>
  </div>
</section>

<script>
(function () {
  var toggle = document.querySelector('.rr-toggle');
  if (!toggle) return;
  toggle.querySelectorAll('[data-rr-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      toggle.querySelectorAll('[data-rr-tab]').forEach(function (x) { x.classList.toggle('is-active', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      document.querySelectorAll('[data-rr-group]').forEach(function (g) { g.hidden = (g.getAttribute('data-rr-group') !== b.getAttribute('data-rr-tab')); });
    });
  });
})();
</script>
```

- [ ] **Step 2: Lint**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l includes/rooms-and-rates.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**
```bash
git add includes/rooms-and-rates.php
git commit -m "feat: data-driven Rooms & Rates section (cards + toggle + sticky bar)"
```

---

## Task 6: Rooms & Rates + modal CSS

**Files:** Create `css/rooms-and-rates.css`

- [ ] **Step 1: Write `css/rooms-and-rates.css`**
```css
/* Tribal Sand — Rooms & Rates cards, toggle, sticky bar, booking modal */
.rr{--rr-teal:#1E5C6B;--rr-sand:#B8965A;--rr-dark:#141412;padding:3.5rem 1.25rem 6rem;max-width:1200px;margin:0 auto;font-family:'Jost',sans-serif}
.rr__heading{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:2rem;color:var(--rr-dark);margin:0 0 1.5rem}
.rr-toggle{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.rr-toggle__label{font-size:.95rem;color:#6B6050;margin-right:.5rem}
.rr-toggle__btn{border:1px solid #d9cfc2;background:#fff;color:var(--rr-dark);padding:.55rem 1.1rem;border-radius:999px;cursor:pointer;font:inherit;font-size:.85rem}
.rr-toggle__btn.is-active{background:var(--rr-sand);border-color:var(--rr-sand);color:#fff;font-weight:600}
.rr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.5rem}
.rr-card{border:1px solid #ece4d8;border-radius:14px;overflow:hidden;background:#fff;display:flex;flex-direction:column;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.rr-card__media{aspect-ratio:4/3;background:var(--rr-teal);overflow:hidden}
.rr-card__media img{width:100%;height:100%;object-fit:cover;display:block}
.rr-card__media[data-placeholder] img{object-fit:contain;padding:2.2rem;filter:brightness(0) invert(1);opacity:.5}
.rr-card__body{padding:1.1rem 1.2rem 1.3rem;display:flex;flex-direction:column;gap:.4rem;flex:1}
.rr-card__name{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.4rem;margin:0;color:var(--rr-dark)}
.rr-card__meta{color:#8a7c80;font-size:.85rem;margin:0}
.rr-card__foot{margin-top:auto;display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding-top:.8rem;border-top:1px solid #f0e9df}
.rr-card__price{color:var(--rr-teal);font-weight:700;font-size:1.05rem}
.rr-card__per{color:#8a7c80;font-weight:400;font-size:.8rem}
.rr-card__req{color:#8a7c80;font-weight:400;font-size:.9rem;font-style:italic}
.rr-card__cta{background:var(--rr-teal);color:#fff;border:none;border-radius:999px;padding:.6rem 1.1rem;font:inherit;font-size:.8rem;cursor:pointer;white-space:nowrap}
.rr-card__cta:hover{background:#16424d}
/* sticky bar */
.rr-bar{position:sticky;bottom:0;display:flex;gap:.75rem;align-items:center;justify-content:center;flex-wrap:wrap;background:#fff;border-top:1px solid #ece4d8;padding:.85rem 1rem;margin-top:2.5rem;box-shadow:0 -2px 10px rgba(0,0,0,.06)}
.rr-bar__dates{display:flex;gap:.5rem}
.rr-bar__dates input{border:1px solid #d9cfc2;border-radius:8px;padding:.55rem .7rem;font:inherit}
.rr-bar__cta{background:var(--rr-sand);color:var(--rr-dark);border:none;border-radius:999px;padding:.65rem 1.6rem;font:inherit;font-weight:600;cursor:pointer}
/* modal */
.bk-modal-lock{overflow:hidden}
.bk-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center}
.bk-modal[hidden]{display:none}
.bk-modal__backdrop{position:absolute;inset:0;background:rgba(20,20,18,.55)}
.bk-modal__dialog{position:relative;background:#fff;border-radius:16px;max-width:460px;width:calc(100% - 2rem);max-height:90vh;overflow:auto;padding:1.6rem 1.4rem;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.bk-modal__title{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:1.6rem;margin:0 2rem 1rem 0;color:var(--rr-dark,#141412)}
.bk-modal__close{position:absolute;top:.8rem;right:1rem;background:none;border:none;font-size:1.8rem;line-height:1;color:#8a7c80;cursor:pointer}
@media(max-width:600px){.rr{padding-top:2.5rem}.rr-bar{flex-direction:column;align-items:stretch}}
```

- [ ] **Step 2: Verify braces balanced**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -r '$c=file_get_contents("css/rooms-and-rates.css");echo substr_count($c,"{")===substr_count($c,"}")?"BRACES OK\n":"MISMATCH\n";'
```
Expected: `BRACES OK`.

- [ ] **Step 3: Commit**
```bash
git add css/rooms-and-rates.css
git commit -m "feat: Rooms & Rates + booking modal styling (Tribal Sand palette)"
```

---

## Task 7: Load the assets via head.php

**Files:** Modify `includes/head.php`

> The modal reuses `css/booking.css` + the refactored `js/booking-widget.js`, plus the new `css/rooms-and-rates.css` + `js/booking-modal.js`. Load all of them when `$page_rooms_rates` is set.

- [ ] **Step 1: Add the block** right after the existing `<?php if (!empty($page_booking)): ?>…<?php endif; ?>` booking block (added previously). Insert:
```php
<?php if (!empty($page_rooms_rates)): ?>
<!-- ── ROOMS & RATES + BOOKING MODAL ── -->
<link rel="stylesheet" href="css/booking.css">
<link rel="stylesheet" href="css/rooms-and-rates.css">
<script src="js/booking-widget.js" defer></script>
<script src="js/booking-modal.js" defer></script>
<?php if (!empty(getenv('HCAPTCHA_SITE_KEY')) || !empty($_ENV['HCAPTCHA_SITE_KEY'])): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 2: Document the variable** — add to the head.php docblock (after the `$page_booking` line):
```php
 *   $page_rooms_rates — set truthy to load the Rooms & Rates cards + booking modal assets, optional
```

- [ ] **Step 3: Lint**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand" && php -l includes/head.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**
```bash
git add includes/head.php
git commit -m "feat: load Rooms & Rates + modal assets when \$page_rooms_rates is set"
```

---

## Task 8: Wire My Amani overview page

**Files:** Modify `my-amani.php`

- [ ] **Step 1: Set page vars** — immediately before `include 'includes/head.php';` (line ~39) add:
```php
$page_rooms_rates = true;
$rr_venue_slug = 'my-amani';
```

- [ ] **Step 2: Insert the section + modal.** Choose a spot inside `<body>` after the main overview content and before the "Other Properties" section (the `<h2 class="sec-h">Other <em>Properties</em></h2>` block, ~line 572). Read around that line, then insert just before that section's opening container:
```php
<?php $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```

- [ ] **Step 3: Repoint the sticky-cta button to open the modal.** The existing sticky CTA (~line 735) is `<a href="/my-amani-premium-sea-view-twin" class="sticky-cta-btn">Request →</a>`. Change it to scroll to the new section:
```php
<a href="#book" class="sticky-cta-btn">Request →</a>
```

- [ ] **Step 4: Lint + verify the section renders with cards + the modal markup**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l my-amani.php
php -S localhost:8073 router.php >/tmp/rr8.log 2>&1 & SRV=$!; sleep 1
out=$(curl -s "http://localhost:8073/my-amani")
echo "Rooms&Rates heading: $(echo "$out" | grep -c 'Rooms &amp; Rates')"
echo "room cards: $(echo "$out" | grep -c 'js-check-availability')"
echo "toggle present: $(echo "$out" | grep -c 'data-rr-tab=\"entire\"')"
echo "modal present: $(echo "$out" | grep -c 'id=\"bkModal\"')"
echo "assets loaded: $(echo "$out" | grep -oE 'rooms-and-rates.css|booking-modal.js' | sort -u | tr '\n' ' ')"
kill $SRV
```
Expected: heading `1`; cards `>= 11` (10 room cards + 1 entire + the sticky bar trigger); toggle `1` (My Amani has both by-room + the entire-place full-rental); modal `1`; assets show both files.

- [ ] **Step 5: Commit**
```bash
git add my-amani.php
git commit -m "feat: Rooms & Rates booking section on My Amani overview"
```

---

## Task 9: Wire Maya Kobe overview page

**Files:** Modify `maya-kobe.php`

- [ ] **Step 1: Set page vars** — before `include 'includes/head.php';` (~line 46) add:
```php
$page_rooms_rates = true;
$rr_venue_slug = 'maya-kobe';
```

- [ ] **Step 2: Insert the section + modal** — before the page's "Other Properties"/closing content (read the page to find a sensible spot inside `<body>` after the main content), insert:
```php
<?php $rr_venue_slug = 'maya-kobe'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```

- [ ] **Step 3: Repoint the sticky-cta button** (~line 778) from `<a href="/maya-kobe-main-house" class="sticky-cta-btn">Book →</a>` to:
```php
<a href="#book" class="sticky-cta-btn">Book →</a>
```

- [ ] **Step 4: Lint + verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l maya-kobe.php
php -S localhost:8073 router.php >/tmp/rr9.log 2>&1 & SRV=$!; sleep 1
out=$(curl -s "http://localhost:8073/maya-kobe")
echo "cards: $(echo "$out" | grep -c 'js-check-availability')  modal: $(echo "$out" | grep -c 'id=\"bkModal\"')  heading: $(echo "$out" | grep -c 'Rooms &amp; Rates')"
kill $SRV
```
Expected: Maya Kobe shows its 2 room cards + the sticky-bar trigger (cards `>= 3`), modal `1`, heading `1`. (No toggle, since neither Maya Kobe room is flagged entire-place — that's correct/data-driven.)

- [ ] **Step 5: Commit**
```bash
git add maya-kobe.php
git commit -m "feat: Rooms & Rates booking section on Maya Kobe overview"
```

---

## Task 10: Admin — "Entire place" checkbox

**Files:** Modify `admin/room-edit.php`

- [ ] **Step 1: Read the save handler + form** to find the `save_details` `$data` array and the form fields (the venue dropdown added earlier is a good anchor):
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
grep -n "venue_id\|is_published\|form_mode\|save_details\|INSERT INTO rooms\|UPDATE rooms" admin/room-edit.php
```

- [ ] **Step 2: Add `is_entire_place` to the save.** In the `save_details` `$data` array add a binding:
```php
                ':is_entire_place' => isset($_POST['is_entire_place']) ? 'TRUE' : 'FALSE',
```
Add `is_entire_place` to the INSERT column list + `:is_entire_place` to its VALUES, and `is_entire_place=:is_entire_place` to the UPDATE SET clause (mirroring how `venue_id` was added). Keep column/placeholder/param counts balanced.

- [ ] **Step 3: Add the form checkbox.** Near the venue dropdown / form_mode controls, add:
```php
<label style="display:flex;align-items:center;gap:8px;margin:10px 0">
  <input type="checkbox" name="is_entire_place" value="1" <?= !empty($room['is_entire_place']) ? 'checked' : '' ?>>
  Entire place (whole-property booking option — shown under the "Entire place" tab)
</label>
```

- [ ] **Step 4: Lint + functional check (no SQL regression, flag persists)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l admin/room-edit.php
php -S localhost:8073 router.php >/tmp/rr10.log 2>&1 & SRV=$!; sleep 1
BASE=http://localhost:8073; PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
RID=$($PSQL -c "SELECT id FROM rooms WHERE slug='maya-kobe-main-house'")
TOK=$(curl -s -b "$JAR" "$BASE/admin/room-edit.php?id=$RID" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
VEN=$($PSQL -c "SELECT venue_id FROM rooms WHERE id=$RID")
curl -s -b "$JAR" -X POST "$BASE/admin/room-edit.php?id=$RID" --data-urlencode "csrf_token=$TOK" --data-urlencode "action=save_details" --data-urlencode "name=Maya Kobe — Main House" --data-urlencode "price_amount=0" --data-urlencode "venue_id=$VEN" --data-urlencode "is_entire_place=1" -o /dev/null
echo "is_entire_place now: $($PSQL -c "SELECT is_entire_place FROM rooms WHERE id=$RID")  (expect t)"
$PSQL -c "UPDATE rooms SET is_entire_place=FALSE WHERE id=$RID" >/dev/null  # reset
kill $SRV
```
Expected: lint clean; `is_entire_place now: t` (proves the new column saves and other fields didn't break).

- [ ] **Step 5: Commit**
```bash
git add admin/room-edit.php
git commit -m "feat(admin): add Entire place flag to room editor"
```

---

## Self-review

**Spec coverage:**
- Data-driven cards + toggle → Task 5 (`rooms-and-rates.php`) ✓
- `is_entire_place` flag → Task 1 (migration) + Task 10 (admin) ✓
- Booking popup reusing the calendar → Tasks 2 (refactor), 3 (modal markup), 4 (opener) ✓
- Sticky bar opens entire-place popup with prefilled dates → Task 4 (`fromBar` prefill) + Task 5 (`rrBar` + entire-place data on the bar CTA) ✓
- Load assets on `$page_rooms_rates` → Task 7 ✓
- Multi-room overview pages wired; whole-villa/individual pages untouched → Tasks 8, 9 ✓
- "Sleeps"=capacity, price label, "Price on request"/placeholder image → Task 5 ✓
- No URL changes; inline widget still works → Task 2 Step 4 regression check; Tasks 8/9 only add a section ✓

**Placeholder scan:** none — full code for new files; the two refactor tasks (2, 10) give exact old→new snippets with named anchors (existing function/var names preserved).

**Type/name consistency:** `window.initBookingWidget` + `window.tsLoadRoom` defined in Task 2, consumed in Task 4; `.js-check-availability` with `data-room-slug/data-room-name/data-price/data-currency` emitted in Task 5, read in Task 4; `#bkModal`/`#bkModalTitle`/`[data-bk-close]` defined in Task 3, used in Task 4 + styled in Task 6; `#rrBarCheckin`/`#rrBarCheckout` + `data-from-bar` emitted in Task 5, read in Task 4; `$page_rooms_rates` set in Tasks 8/9, consumed in Task 7; `is_entire_place` migrated in Task 1, written in Task 10, read in Task 5.

**Known follow-up (out of scope, per spec):** real room data (names/prices/photos) is curated later in admin; until then My Amani shows its placeholder room types and Maya Kobe its two placeholder rooms, with "Price on request" + logo placeholder images.
