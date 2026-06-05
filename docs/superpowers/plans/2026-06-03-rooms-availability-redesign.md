# Rooms & Availability Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the booking section on every property page into a search-first "Rooms & Availability" block — Zuri-style suite cards placed right after the gallery, with a single sticky search bar (dates + guests) that marks which rooms are available and opens the booking popup per available room.

**Architecture:** Restyle the data-driven `rooms-and-rates.php` to Zuri `.suite-card` markup (no per-card buttons); add a shared sticky `availability-bar.php` + `availability-search.js` that, on submit, checks each room card (multi-room) or a venue fallback room (whole-villa) via the existing `/api/check-availability.php` and reveals "Request these dates →" buttons that open the existing modal via a new `window.tsOpenBookingModal`. Add an admin-editable `rooms.tag_label` badge. No URL or backend-API changes.

**Tech Stack:** PHP 8 + PostgreSQL, vanilla JS, the existing `bk-*` modal + `tsLoadRoom` engine, `/api/check-availability.php` + `/api/submit-enquiry.php`.

Spec: `docs/superpowers/specs/2026-06-03-rooms-availability-redesign-design.md`.

**Conventions:** No test framework — verify with `php -l`, `node --check`, a throwaway `php -S` + `curl`, `psql`, and screenshots (controller). Postgres v18 client `/Applications/Postgres.app/Contents/Versions/18/bin/psql` (user/db `patrikgiuliana`/`tribalsand`). Branch `main`. End every commit with a trailing blank line + `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File structure

- **Create** `db/migrations/add_room_tag_label.sql` — `rooms.tag_label`.
- **Modify** `admin/room-edit.php` — tag_label field.
- **Modify** `includes/rooms-and-rates.php` — Zuri suite-card layout, "Rooms & Availability" heading, data-* on cards, per-card availability slot, NO per-card check button, drop toggle.
- **Create** `includes/availability-bar.php` — sticky search bar (dates + guests + check + result), computes a venue fallback room.
- **Modify** `js/booking-modal.js` — expose `window.tsOpenBookingModal(slug,name,price,currency,prefill)`.
- **Create** `js/availability-search.js` — the search → mark cards / fallback result → Request buttons.
- **Modify** `css/rooms-and-rates.css` — port `.suite-card*`, bar, available/unavailable states, result.
- **Modify** `includes/head.php` — add `availability-search.js` to the `$page_rooms_rates` bundle.
- **Modify** `my-amani.php`, `maya-kobe.php` — move section after gallery + add the bar.
- **Modify** `zuri.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`, `tribal-dunes.php` — swap bottom inline widget for the sticky bar (after gallery), set `$rr_venue_slug`.

---

## Task 1: `tag_label` badge field

**Files:** Create `db/migrations/add_room_tag_label.sql`; Modify `admin/room-edit.php`

- [ ] **Step 1: Migration**
```sql
-- Optional short badge shown on the room card (e.g. "Oceanfront", "Family Suite").
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS tag_label VARCHAR(100);
```
Run:
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php bin/migrate.php db/migrations/add_room_tag_label.sql
/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA -c "SELECT column_name FROM information_schema.columns WHERE table_name='rooms' AND column_name='tag_label';"
```
Expected: migration OK; prints `tag_label`.

- [ ] **Step 2: admin/room-edit.php — add tag_label to save + form.** In the `save_details` `$data` array add:
```php
                ':tag_label'     => trim($_POST['tag_label'] ?? ''),
```
Add `tag_label` to the INSERT column list + `:tag_label` to VALUES, and `tag_label=:tag_label,` to the UPDATE SET clause (mirror `venue_id`). Add a form field near the name:
```php
<label style="display:block;margin:10px 0">Badge label (optional)
  <input type="text" name="tag_label" value="<?= e($room['tag_label'] ?? '') ?>" placeholder="e.g. Oceanfront" style="width:100%;max-width:320px;padding:8px 10px">
</label>
```

- [ ] **Step 3: Verify (lint + flag persists)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l admin/room-edit.php
php -S localhost:8068 router.php >/tmp/ra1.log 2>&1 & SRV=$!; sleep 1
BASE=http://localhost:8068; PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
JAR=$(mktemp); curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/admin/login.php" --data-urlencode "email=klickenya@gmail.com" --data-urlencode "password=TribalAdmin2026!" -o /dev/null
RID=$($PSQL -c "SELECT id FROM rooms WHERE slug='zuri'"); VEN=$($PSQL -c "SELECT venue_id FROM rooms WHERE id=$RID")
TOK=$(curl -s -b "$JAR" "$BASE/admin/room-edit.php?id=$RID" | grep -oE 'name="csrf_token" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')
curl -s -b "$JAR" -X POST "$BASE/admin/room-edit.php?id=$RID" --data-urlencode "csrf_token=$TOK" --data-urlencode "action=save_details" --data-urlencode "name=Zuri Whole Villa" --data-urlencode "price_amount=0" --data-urlencode "venue_id=$VEN" --data-urlencode "tag_label=Beachfront" -o /dev/null
echo "tag_label=$($PSQL -c "SELECT tag_label FROM rooms WHERE id=$RID")"
$PSQL -c "UPDATE rooms SET tag_label=NULL WHERE id=$RID" >/dev/null
kill $SRV
```
Expected: lint clean; `tag_label=Beachfront` (saved; reset after).

- [ ] **Step 4: Commit**
```bash
git add db/migrations/add_room_tag_label.sql admin/room-edit.php
git commit -m "feat: add rooms.tag_label badge (+ admin field)"
```

---

## Task 2: Restyle the Rooms & Availability section

**Files:** Modify `includes/rooms-and-rates.php`

- [ ] **Step 1: Replace the entire file** with the Zuri-suite-card, search-ready version (no per-card check button, no toggle):
```php
<?php
/**
 * Tribal Sand "Rooms & Availability" section for a property (venue).
 * Usage: $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php';
 * Renders nothing if the venue has no published rooms. Requires includes/availability-bar.php
 * and includes/booking-modal.php on the page; availability is driven by js/availability-search.js.
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
?>
<section class="rr" id="rooms">
  <div class="rr__inner">
    <div class="sec-label">Accommodations</div>
    <h2 class="sec-h">Rooms &amp; <em>Availability</em></h2>
    <div class="sec-rule"></div>
    <div class="suites-grid">
      <?php foreach ($__rooms as $r):
        $img = !empty($r['hero']) ? storage_url($r['hero']) : '/images/whitelogo11.png';
        $guests = (int)($r['capacity'] ?? 0);
        $beds   = (int)($r['bed_count'] ?? 0);
        $meta = trim(($beds ? $beds . ' bed' . ($beds > 1 ? 's' : '') : '') . ($beds && $guests ? ' · ' : '') . ($guests ? 'Up to ' . $guests . ' guests' : ''));
      ?>
      <article class="suite-card rr-card"
               data-room-slug="<?= e($r['slug']) ?>" data-room-name="<?= e($r['name']) ?>"
               data-price="<?= e((float)$r['price_amount']) ?>" data-currency="<?= e($r['price_currency']) ?>">
        <div class="suite-card-img"<?= empty($r['hero']) ? ' data-placeholder="1"' : '' ?>>
          <img src="<?= e($img) ?>" alt="<?= e($r['name']) ?>" loading="lazy">
          <?php if (!empty($r['tag_label'])): ?><span class="suite-card-tag"><?= e($r['tag_label']) ?></span><?php endif; ?>
        </div>
        <div class="suite-card-body">
          <div class="suite-card-name"><?= e($r['name']) ?></div>
          <?php if ($meta): ?><div class="suite-card-meta"><?= e($meta) ?></div><?php endif; ?>
          <?php if (!empty($r['short_desc'])): ?><p class="suite-card-desc"><?= e($r['short_desc']) ?></p><?php endif; ?>
          <div class="rr-card__avail" hidden></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
```
NOTE: this REMOVES the old `.js-check-availability` per-card buttons, the By room/Entire place toggle, the sticky `.rr-bar`, and the inline toggle `<script>` — those are superseded by `availability-bar.php` + `availability-search.js`.

- [ ] **Step 2: Lint + render check**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l includes/rooms-and-rates.php
php -r '$rr_venue_slug="my-amani"; ob_start(); include "includes/rooms-and-rates.php"; $h=ob_get_clean(); echo "cards=".substr_count($h,"suite-card rr-card")." heading=".substr_count($h,"Rooms &amp;")." perCardButtons=".substr_count($h,"js-check-availability")."\n";'
```
Expected: `cards=11 heading=1 perCardButtons=0`.

- [ ] **Step 3: Commit**
```bash
git add includes/rooms-and-rates.php
git commit -m "feat: restyle Rooms & Availability to Zuri suite-cards (no per-card buttons)"
```

---

## Task 3: Sticky availability search bar

**Files:** Create `includes/availability-bar.php`

- [ ] **Step 1: Write `includes/availability-bar.php`**
```php
<?php
/**
 * Sticky "search availability" bar for a property page.
 * Usage: $rr_venue_slug = 'zuri'; include __DIR__ . '/includes/availability-bar.php';
 * Driven by js/availability-search.js. For pages with .suite-card[data-room-slug] cards it marks
 * those; otherwise (whole-villa) it checks the venue's first published room (the fallback).
 */
require_once __DIR__ . '/db.php';

$rr_venue_slug = $rr_venue_slug ?? '';
$__bv = $rr_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $rr_venue_slug])->fetch() : false;
$__fb = $__bv ? db_query(
    'SELECT slug, name, price_amount, price_currency FROM rooms WHERE venue_id = :vid AND is_published = TRUE ORDER BY is_entire_place DESC, sort_order ASC LIMIT 1',
    [':vid' => $__bv['id']]
)->fetch() : false;
?>
<div class="rr-bar" id="rrBar"
     data-fallback-slug="<?= e($__fb['slug'] ?? '') ?>"
     data-fallback-name="<?= e($__fb['name'] ?? ($__bv['name'] ?? '')) ?>"
     data-fallback-price="<?= e($__fb ? (float)$__fb['price_amount'] : 0) ?>"
     data-fallback-currency="<?= e($__fb['price_currency'] ?? 'USD') ?>">
  <div class="rr-bar__inner">
    <label class="rr-bar__field"><span>Check-in</span><input type="date" id="rrBarCheckin"></label>
    <label class="rr-bar__field"><span>Check-out</span><input type="date" id="rrBarCheckout"></label>
    <label class="rr-bar__field"><span>Guests</span>
      <select id="rrBarGuests">
        <?php for ($g = 1; $g <= 16; $g++): ?><option value="<?= $g ?>"<?= $g === 2 ? ' selected' : '' ?>><?= $g ?></option><?php endfor; ?>
      </select>
    </label>
    <button type="button" class="rr-bar__cta" id="rrBarCheck">Check availability</button>
  </div>
  <div class="rr-bar__result" id="rrBarResult" hidden></div>
</div>
```

- [ ] **Step 2: Lint**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l includes/availability-bar.php
php -r '$rr_venue_slug="zuri"; ob_start(); include "includes/availability-bar.php"; $h=ob_get_clean(); echo (strpos($h,"data-fallback-slug=\"zuri\"")!==false?"fallback OK\n":"fallback MISSING\n");'
```
Expected: lint clean; `fallback OK` (Zuri's single room slug is `zuri`).

- [ ] **Step 3: Commit**
```bash
git add includes/availability-bar.php
git commit -m "feat: sticky availability search bar (with venue fallback room)"
```

---

## Task 4: Expose a modal opener

**Files:** Modify `js/booking-modal.js`

- [ ] **Step 1: Refactor to expose `window.tsOpenBookingModal`.** Replace the file's body so the open logic is a global function (keep the close handlers + the existing static `.js-check-availability` binding for backward-compat). New content:
```js
// Opens the booking popup (#bkModal) for a chosen room. Exposes window.tsOpenBookingModal so
// dynamically-created "Request these dates" buttons (availability-search.js) can open it too.
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("bkModal");
  if (!modal) return;
  const title = document.getElementById("bkModalTitle");

  window.tsOpenBookingModal = function (slug, name, price, currency, prefill) {
    if (typeof window.tsLoadRoom === "function") window.tsLoadRoom(slug, price, currency, prefill || null);
    if (title && name) title.textContent = name;
    // optional guests prefill
    if (prefill && prefill.adults) { const a = document.getElementById("availAdults"); if (a) a.value = prefill.adults; }
    modal.hidden = false;
    document.body.classList.add("bk-modal-lock");
  };
  function close() { modal.hidden = true; document.body.classList.remove("bk-modal-lock"); }

  // Backward-compat: any static .js-check-availability trigger still works.
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
```

- [ ] **Step 2: Verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; node --check js/booking-modal.js && echo "JS OK"
grep -c "window.tsOpenBookingModal" js/booking-modal.js
```
Expected: `JS OK`; `>=1`.

- [ ] **Step 3: Commit**
```bash
git add js/booking-modal.js
git commit -m "feat: expose window.tsOpenBookingModal for dynamic Request buttons"
```

---

## Task 5: Availability search JS

**Files:** Create `js/availability-search.js`

- [ ] **Step 1: Write `js/availability-search.js`**
```js
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
      // Multi-room: mark each card.
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
      // Whole-villa: check the fallback room and render the result in the bar.
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
```

- [ ] **Step 2: Verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; node --check js/availability-search.js && echo "JS OK"
```
Expected: `JS OK`.

- [ ] **Step 3: Commit**
```bash
git add js/availability-search.js
git commit -m "feat: availability search (mark cards / fallback result + Request buttons)"
```

---

## Task 6: Styles — suite cards, bar, states

**Files:** Modify `css/rooms-and-rates.css`

- [ ] **Step 1: Replace the `.rr*` card/grid/bar rules** in `css/rooms-and-rates.css` (keep the `.bk-modal*` rules at the bottom intact) with the suite-card port + search styling. Replace everything from the top of the file DOWN TO (but not including) the first `.bk-modal-lock{` line with:
```css
/* Tribal Sand — Rooms & Availability (Zuri suite-card look) + search bar */
.rr{padding:3.5rem 1.25rem 1rem;max-width:1200px;margin:0 auto;font-family:'Jost',sans-serif}
.rr .sec-label{font-size:.75rem;letter-spacing:.28em;text-transform:uppercase;color:#B8965A;margin-bottom:.55rem;display:flex;align-items:center;gap:.5rem}
.rr .sec-label::before{content:'';width:14px;height:1px;background:#B8965A}
.rr .sec-h{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:2.1rem;color:#141412;margin:0 0 .3rem}
.rr .sec-h em{font-style:italic;color:#1E5C6B}
.rr .sec-rule{width:54px;height:1px;background:#d9cfc2;margin:.9rem 0 1.8rem}
.suites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.6rem}
.suite-card{border:1px solid #ece4d8;background:#fff;overflow:hidden;transition:box-shadow .3s,opacity .3s;display:flex;flex-direction:column}
.suite-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.09)}
.suite-card-img{height:220px;overflow:hidden;position:relative;background:#1E5C6B}
.suite-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .5s ease}
.suite-card-img[data-placeholder] img{object-fit:contain;padding:2.4rem;filter:brightness(0) invert(1);opacity:.5}
.suite-card:hover .suite-card-img img{transform:scale(1.05)}
.suite-card-tag{position:absolute;bottom:.75rem;left:.75rem;font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;background:rgba(16,47,58,.82);color:rgba(184,150,90,.95);padding:.28rem .75rem;backdrop-filter:blur(6px)}
.suite-card-body{padding:1.1rem 1.2rem 1.25rem;display:flex;flex-direction:column;flex:1}
.suite-card-name{font-family:'Cormorant Garamond',serif;font-size:1.35rem;font-weight:400;color:#141412;margin-bottom:.18rem}
.suite-card-meta{font-size:.78rem;letter-spacing:.08em;color:#A89880;margin-bottom:.6rem}
.suite-card-desc{font-size:.95rem;color:#6B6050;line-height:1.7;margin:0}
.suite-card.is-unavailable{opacity:.5}
.rr-card__avail{margin-top:.9rem;padding-top:.8rem;border-top:1px solid #f0e9df}
.rr-card__avail-ok{color:#1E5C6B;font-weight:600;margin-bottom:.5rem}
.rr-card__avail-no{color:#A89880;font-style:italic}
.rr-req-btn{background:#1E5C6B;color:#fff;border:none;border-radius:999px;padding:.55rem 1.1rem;font-family:'Jost',sans-serif;font-size:.8rem;cursor:pointer}
.rr-req-btn:hover{background:#16424d}
/* sticky search bar */
.rr-bar{position:sticky;bottom:0;z-index:50;background:#fff;border-top:1px solid #ece4d8;box-shadow:0 -2px 12px rgba(0,0,0,.07);font-family:'Jost',sans-serif}
.rr-bar__inner{max-width:1100px;margin:0 auto;display:flex;gap:1rem;align-items:flex-end;justify-content:center;flex-wrap:wrap;padding:.85rem 1rem}
.rr-bar__field{display:flex;flex-direction:column;gap:.25rem;font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:#6B6050}
.rr-bar__field input,.rr-bar__field select{border:1px solid #d9cfc2;border-radius:8px;padding:.55rem .7rem;font-family:'Jost',sans-serif;font-size:.95rem;color:#141412}
.rr-bar__cta{background:#B8965A;color:#141412;border:none;border-radius:999px;padding:.7rem 1.8rem;font-family:'Jost',sans-serif;font-weight:600;font-size:.9rem;cursor:pointer}
.rr-bar__cta:disabled{opacity:.6;cursor:default}
.rr-bar__result{max-width:1100px;margin:0 auto;padding:0 1rem .85rem;display:flex;gap:1rem;align-items:center;justify-content:center;flex-wrap:wrap;color:#6B6050}
.rr-bar__ok{color:#1E5C6B;font-weight:600}
@media(max-width:600px){.rr-bar__inner{gap:.6rem}.rr-bar__field{font-size:.6rem}}
```
(The `.bk-modal*` rules from `.bk-modal-lock{` to the end of the file stay unchanged.)

- [ ] **Step 2: Verify braces balanced**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -r '$c=file_get_contents("css/rooms-and-rates.css");echo substr_count($c,"{")===substr_count($c,"}")?"BRACES OK\n":"MISMATCH\n";'
grep -c "bk-modal__dialog" css/rooms-and-rates.css
```
Expected: `BRACES OK`; `1` (modal styles preserved).

- [ ] **Step 3: Commit**
```bash
git add css/rooms-and-rates.css
git commit -m "feat: suite-card + search bar styling (port Zuri look)"
```

---

## Task 7: Load the search JS

**Files:** Modify `includes/head.php`

- [ ] **Step 1: Add `availability-search.js` to the `$page_rooms_rates` block.** In the `<?php if (!empty($page_rooms_rates)): ?>` block, after the `<script src="js/booking-modal.js" defer></script>` line, add:
```php
<script src="js/availability-search.js" defer></script>
```

- [ ] **Step 2: Verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l includes/head.php
grep -c "availability-search.js" includes/head.php
```
Expected: lint clean; `1`.

- [ ] **Step 3: Commit**
```bash
git add includes/head.php
git commit -m "feat: load availability-search.js with the Rooms & Availability bundle"
```

---

## Task 8: Wire the multi-room overview pages

**Files:** Modify `my-amani.php`, `maya-kobe.php`

> Both currently include `rooms-and-rates.php` + `booking-modal.php` at the BOTTOM (≈ line 571 in my-amani, 772 in maya-kobe), have a `.gallery` near the top (line 312 / 326), and already set `$page_rooms_rates = true; $rr_venue_slug = '<slug>';`.

- [ ] **Step 1 (my-amani.php): remove the bottom include block.** Delete the two lines:
```php
<?php $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```

- [ ] **Step 2 (my-amani.php): insert the section + bar right AFTER the gallery.** Find the `</div>` that closes `<div class="gallery" ...>` (the gallery block starting ~line 312) and immediately after it insert:
```php
<?php $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
<?php include __DIR__ . '/includes/availability-bar.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```
(Read the gallery block first to place this after its closing tag, before the next section.)

- [ ] **Step 3 (maya-kobe.php): same two edits** — remove the bottom `rooms-and-rates.php` + `booking-modal.php` includes (≈ line 772), and insert the same 3-line block after the `.gallery` close (≈ line 326), with `$rr_venue_slug = 'maya-kobe'`.

- [ ] **Step 4: Verify both render the section high + bar + modal**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -l my-amani.php && php -l maya-kobe.php
php -S localhost:8067 router.php >/tmp/ra8.log 2>&1 & SRV=$!; sleep 1
for p in my-amani maya-kobe; do
  out=$(curl -s "http://localhost:8067/$p")
  echo "$p: section=$(echo "$out" | grep -c 'class="rr"') bar=$(echo "$out" | grep -c 'id="rrBar"') modal=$(echo "$out" | grep -c 'id="bkModal"') heading=$(echo "$out" | grep -c 'Rooms &amp;')"
  # section must appear BEFORE the footer / near the gallery (sanity: rr index < footer index)
  python3 - "$out" <<'PY'
import sys
h=sys.argv[1]; 
import re
a=h.find('id="rooms"'); g=h.find('class="gallery"'); f=h.find('Other')
print("  order: gallery@%d rooms@%d (rooms after gallery=%s)"%(g,a,a>g))
PY
done
kill $SRV
```
Expected: each shows `section=1 bar=1 modal=1 heading=1`, and `rooms after gallery=True`.

- [ ] **Step 5: Commit**
```bash
git add my-amani.php maya-kobe.php
git commit -m "feat: move Rooms & Availability after the gallery + add search bar (My Amani, Maya Kobe)"
```

---

## Task 9: Wire the whole-villa pages

**Files:** Modify `zuri.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`, `tribal-dunes.php`

> Each currently sets `$page_booking = true; $booking_slug = '<slug>';` and has a bottom `<section id="book" class="ts-booking-section" …>` that includes `includes/booking-widget.php`. They keep their hand-coded suite content; we swap the bottom widget for the sticky bar.

Apply this recipe to EACH of the five files (slug per file: zuri, enkare-bofa, sandbox, maya_ilai, tribal-dunes):

- [ ] **Step 1: Switch the page flags.** Replace:
```php
$page_booking = true;
$booking_slug = '<slug>';
```
with:
```php
$page_rooms_rates = true;
$rr_venue_slug = '<slug>';
```

- [ ] **Step 2: Remove the bottom inline widget section.** Delete the whole block:
```php
<section id="book" class="ts-booking-section" style="max-width:760px;margin:4rem auto;padding:0 1.5rem;">
  <h2 ...>Check availability &amp; request your dates</h2>
  <?php include __DIR__ . '/includes/booking-widget.php'; ?>
</section>
```
(Match each file's exact block — the heading line may vary; remove from `<section id="book"` through its closing `</section>`.)

- [ ] **Step 3: Insert the bar + modal after the gallery.** For pages WITH `<div class="gallery" ...>` (zuri, enkare-bofa, sandbox, maya_ilai): immediately after the gallery block's closing `</div>`, insert:
```php
<?php $rr_venue_slug = '<slug>'; include __DIR__ . '/includes/availability-bar.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```
For `tribal-dunes.php` (NO gallery): insert the same two lines immediately after `<?php include 'includes/header.php'; ?>` (right below the header) instead.

- [ ] **Step 4: Keep the sticky-cta link** pointing to `#rooms` if the page has a `#rooms` section, else to the bar — actually the bar is sticky (always visible), so repoint any leftover `href="#book"` sticky-cta to `#rrBar` (or leave `#book` → it no longer exists; change to a no-op that focuses the bar). Simplest: change `href="#book"` to `href="#rrBar"` on each page's sticky-cta if present.

- [ ] **Step 5: Verify each page (lint + bar present + old widget gone + modal present)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -S localhost:8066 router.php >/tmp/ra9.log 2>&1 & SRV=$!; sleep 1
for pair in "zuri:zuri" "enkare-bofa:enkare-bofa" "sandbox:sandbox" "maya_ilai:maya_ilai" "tribal-dunes:tribal-dunes"; do
  page="${pair%%:*}"; php -l "$page.php" >/dev/null || echo "LINT FAIL $page"
  out=$(curl -s "http://localhost:8066/$page")
  echo "$page: bar=$(echo "$out" | grep -c 'id=\"rrBar\"') modal=$(echo "$out" | grep -c 'id=\"bkModal\"') oldwidget=$(echo "$out" | grep -c 'ts-booking-section') fallback=$(echo "$out" | grep -oE 'data-fallback-slug=\"[^\"]+\"' | head -1)"
done
kill $SRV
```
Expected per page: `bar=1 modal=1 oldwidget=0` and a non-empty `data-fallback-slug` matching the page slug.

- [ ] **Step 6: Commit**
```bash
git add zuri.php enkare-bofa.php sandbox.php maya_ilai.php tribal-dunes.php
git commit -m "feat: swap bottom booking widget for sticky availability bar on whole-villa pages"
```

---

## Self-review

**Spec coverage:**
- Zuri suite-card layout → Task 2 + Task 6 ✓
- Heading "Rooms & Availability", eyebrow "Accommodations" → Task 2 ✓
- Section moved after the gallery → Task 8 (multi-room) + Task 9 (whole-villa place bar after gallery) ✓
- Single sticky search bar (dates + guests), per-card check buttons removed → Tasks 2 (removed), 3, 5 ✓
- Search marks available rooms; "Request these dates →" opens popup pre-filled → Task 5 (+ Task 4 opener) ✓
- Whole-villa fallback (single villa room, result in bar) → Tasks 3, 5, 9 ✓
- All property pages → Tasks 8 + 9 ✓
- tag_label badge + admin → Task 1 (+ rendered in Task 2) ✓
- Reuse modal/engine/API, no URL changes → Tasks 4/5 reuse `/api/check-availability.php`, `tsLoadRoom`, the modal; no page renames ✓

**Placeholder scan:** none — full code for new files + the restyled partial + CSS; per-page edits give exact anchors (gallery close, `#book` section, page flags) and a repeatable recipe for the five whole-villa pages.

**Type/name consistency:** `#rrBar` + `#rrBarCheckin/#rrBarCheckout/#rrBarGuests/#rrBarCheck/#rrBarResult` + `data-fallback-*` defined in Task 3, consumed in Task 5; `.suite-card.rr-card[data-room-slug]` + `.rr-card__avail` emitted in Task 2, read in Task 5; `window.tsOpenBookingModal(slug,name,price,currency,prefill)` defined in Task 4, called in Task 5; `window.tsLoadRoom` (from the earlier refactor) used by Task 4; `$page_rooms_rates` loads booking-widget.js + booking-modal.js + availability-search.js (Task 7); `tag_label` migrated/saved in Task 1, rendered in Task 2.

**Known follow-up (out of scope):** real room data (names/prices/photos/badges/capacity) curated in admin; whole-villa hand-coded suite cards remain display-only (the bar books the villa); individual room pages keep their inline widget.
