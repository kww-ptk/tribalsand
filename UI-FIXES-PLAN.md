# Tribal Sand — Guest-Flow UI Fixes: Implementation Plan

> Self-contained spec. A fresh session can execute this without prior context.
> Approved 2026-08-19 after manual testing of prod (tribalsand.onrender.com).
> **Order: A → B → C. Scope: ALL property pages.**

---

## 0. Ground rules (READ FIRST)

- **Stack:** PHP 8.2, vanilla JS/CSS. **No framework, no build system, no npm, no React.**
- **"Lucide React" = inline Lucide SVG.** When the user says "use Lucide React icons," it means paste the Lucide icon as an inline `<svg>` (same pattern already used in hero steppers / booking widget). There is no JSX here.
- **No native UI rule (hard constraint):** never use native `<select>`, `<input type="date">`, `<input type="time">`, or unicode arrow/tick glyphs (`▾ ▸ ✓ ✔`) as UI. Always use the reusable styled components below + inline Lucide SVG icons.
- **Local dev server:** `D:\php84\php.exe -S localhost:8765 -t D:\TribalIsland D:\TribalIsland\router.php` (uses Neon cloud DB via `.env`). **Do NOT submit real enquiries/trip-builder forms during testing** — they write to the live DB and email staff/guest. Test rendering + client-side validation only.
- **Cache-busting:** CSS/JS `<link>`/`<script>` in `includes/head.php` already use `?v=<?= filemtime(...) ?>`. No manual version bumps needed.
- **Verify each change in the browser** (read_page / javascript_tool / console) before moving on. The Browser pane may not composite screenshots; use text-based inspection.

### Reusable components to reuse (do NOT reinvent)

| Need | Component | How |
|---|---|---|
| Date pick | `js/datepicker.js` + `css/booking.css` | Button `<button class="dp-btn" data-dp-role="ci|co" data-dp-pair="<uniquePair>" data-dp-target="<hiddenId>" data-dp-placeholder="Check-in date">Check-in date</button>` + `<input type="hidden" id="<hiddenId>" name="checkin">`. Roles `ci`/`co` sharing one `data-dp-pair` auto-link check-in→check-out. See `includes/form-enquiry.php` lines 12-21 for the canonical usage. |
| Small option set (radio/checkbox) | `.optchip` (`admin/assets/admin.css:927`) | `<label class="optchip"><input type="radio" name="x" value="y"> Label</label>`. Highlights via `:has(input:checked)`. **This CSS is admin-scoped** — for guest pages, port the same rule into the page/`css/main.css`. |
| Guest counters | stepper pattern | See `includes/enquiry-multistep.php:128-143` (`data-g`/`data-g-count`) or hero `data-bk`. |
| Chevron / check / dot icons | inline Lucide SVG | chevron-down: `<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>` · check: `<path d="M20 6 9 17l-5-5"/>` |

**No reusable time-picker exists.** For Group C time inputs, build a small styled component OR use a masked `HH:MM` styled text input. Confirm preference with user before building.

### Property pages in scope (all share the same template)
`my-amani.php`, `maya-kobe.php`, `zuri.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php` — plus `kenya-honeymoon.php` (FAQ only).

---

## GROUP A — Property-page polish (all pages above)

### A1. FAQ duplicate `+` (both `+` currently rotate to `×`)
**Root cause:** `css/main.css:885` already defines `.faq-q::after{content:'+'}` and `main.css:894` `.faq-item.open .faq-q::after{transform:rotate(45deg)}` — the canonical toggle. Each property page **redundantly** adds an inline `<span class="faq-ico">+</span>` inside every `.faq-q`, plus local CSS `.faq-ico` + `.faq-item.open .faq-ico{transform:rotate(45deg)}`. Result: two `+`.

**Fix (per page):**
1. Delete every `<span class="faq-ico" aria-hidden="true">+</span>` inside `.faq-q` (e.g. my-amani.php:546,551,556,561,566,571).
2. Delete the local CSS rules `.faq-ico{...}` and `.faq-item.open .faq-ico{...}` (my-amani.php:177-178). Leave the global `main.css` `::after` toggle untouched — it stays as the single, correct `+`→`×`.
3. Also delete/keep-consistent any local `.faq-q`/`.faq-item`/`.faq-a` overrides only if they conflict; the global rules already handle display + rotate.

**Verify:** open `/my-amani.php`, each FAQ row shows exactly ONE `+`; clicking rotates it to `×` and reveals the answer.

### A2. Property Policies — native `▾` chevron + tight left padding
**Where:** sidebar accordion. Button: `<button class="book-pol-btn" id="polBtn">Property Policies <span class="pol-chevron">▾</span></button>` (my-amani.php:638). Toggle JS ~my-amani.php:836 toggles `.book-pol-body.open` and rotates `.pol-chevron`. CSS: `.pol-chevron` my-amani.php:233; `.book-features`/`.book-feat`/`.book-feat-ico` my-amani.php:256-258; bullet rows my-amani.php:649-655 use `·` in `.book-feat-ico`.

**Fix (per page):**
1. Replace unicode `▾` inside `.pol-chevron` with inline Lucide **chevron-down** SVG (14–16px, `stroke="currentColor"`). Keep the `.pol-chevron{transition:transform .22s}` rotate-on-open (rotate 180deg on open). JS already toggles a class — confirm it rotates the SVG.
2. Add left breathing room to the policy/feature list. `.book-features` is `padding:.9rem 1.4rem` — increase left padding (e.g. `1.2rem`→`1.5rem`) and/or the `.book-feat` text indent so text isn't hard against the panel edge. **Verify visually** which element the user meant (the always-visible `.book-features` bullets vs. the expanded `.book-pol-body` key/value rows) and pad both if needed.
3. Optional polish: swap the `·` in `.book-feat-ico` for a small Lucide check/dot SVG for consistency.

**Verify:** chevron is a clean SVG, rotates on toggle; bullet text has comfortable left inset.

### A3. Native `<select>` in the booking sidebar
**Where:** 2 `select.book-inp` per page (CSS `select.book-inp` bg-image arrow at my-amani.php:227). Find the two `<select>` elements in each property page's sidebar.

**Fix (per page):** replace each native `<select>` with the `.optchip` pattern (if a small fixed option set) or a styled listbox in the booking-widget visual language. Port `.optchip` CSS into the guest scope. Preserve the underlying form field name/value so submission is unchanged.

**Verify:** no native dropdown remains; selection updates the hidden/real field; submit payload unchanged.

---

## GROUP B — Activities / tours enquiry form

### B1. Native date inputs → reusable datepicker
**Where:** `includes/enquiry-multistep.php` step 1 uses `<input type="date" ... name="checkin">` (line 118) and `name="checkout"` (line 122). Used on `enquire.php?tour=<slug>` and `?villa=<slug>`.

**Fix:**
1. Replace both native date inputs with the `.dp-btn` + hidden-input pattern (see reusable table above). Use a unique `data-dp-pair` (e.g. the existing `$enq_uid`). Keep `name="checkin"`/`name="checkout"` on the hidden inputs — the wizard JS reads `form.querySelector('[name=checkin]').value` (enquiry-multistep.php:243-245), so it works unchanged.
2. **Load assets on `enquire.php`:** `datepicker.js` + `booking.css` are only loaded under `$page_booking`/`$page_rooms_rates` (head.php:94,101). `enquire.php` sets neither. Fix: in `enquire.php` before `include head.php`, set `$page_booking = true;` **OR** add the two tags directly (as index.php hero does):
   `<link rel="stylesheet" href="css/booking.css?v=<?= filemtime(__DIR__.'/css/booking.css') ?>">`
   `<script src="js/datepicker.js?v=<?= filemtime(__DIR__.'/js/datepicker.js') ?>" defer></script>`
   Prefer the direct tags to avoid pulling booking-widget.js unnecessarily.

**Verify:** open `/enquire.php?tour=watamu-marine-snorkelling-dolphin`; date fields open the styled calendar (no native dd/mm/yyyy); Continue validates checkout > checkin.

### B2. Tour enquiry acknowledgement email polish
**Where:** `api/submit-enquiry.php` (enquiry branch) calls `send_guest_acknowledgement(['kind'=>'enquiry','tour_name'=>...])` → `includes/mail.php:117`. Design system already there (teal `#102F3A`, sand `#B8965A`, `#f0f4f5` bg, 600px card, detail tables — mail.php:198-250).

**Fix:** ensure the acknowledgement clearly shows the **tour/experience name + requested dates + guest count** in the styled detail table (not a bare generic "thank you"). Extend the acknowledgement builder if the tour path currently omits these.

**Verify:** trace the code path (do not send live mail). Confirm `tour_name`, dates, guests flow into the styled table.

---

## GROUP C — Trip Builder full redesign (`trip-builder.php`, 1199 lines, 7 steps)

Screenshots showed: small fonts/icons, native dropdowns, native time inputs, radio-dot property cards with no images, unicode `✓` in step progress.

### C1. Typography & controls scale-up
Increase base font sizes, control sizes, and spacing across all 7 steps so it reads as a premium flow (match the density of `enquiry-multistep.php`). Audit the `trip-builder.php` `<style>` block.

### C2. Property cards (step 2, lines 268-273)
Current: `<div class="prop-card"><div class="prop-radio"></div><div class="prop-ico"></div>...`. `.prop-radio` (lines 80-82) is a custom radio dot; `.prop-ico` is empty.
**Fix:**
- Remove `.prop-radio` dot entirely.
- Show the **whole selected card highlighted** (`.prop-card.on` — border/background/shadow, plus a small Lucide check badge in the corner).
- Put each property's **hero image** into the card (`.prop-ico` → image). Hero image resolution: reuse `venue_hero_url('<slug>', '<fallback>')` (see index.php:502) or the venue's `room_images`/`venue` hero. Map each `data-v` (zuri, mayakobe, amani, enkare, sandbox) to its hero.

### C3. Native `<select>` airports (lines 351, 382)
Replace both `<select class="sel">` (arrival airport, departure airport — 4 options each: Malindi MYD, Mombasa MBA, Nairobi NBO, Wilson WIL) with `.optchip` chips or a styled listbox. Preserve selected value for submission.

### C4. Native `<input type="time">` ×3 (lines 322 activity, 355 arrival, 386 departure)
**No reusable time component exists.** Options (confirm with user):
- (a) Build a small styled time component (hour/minute steppers or masked input) in the same visual language.
- (b) Styled text input with masked `HH:MM` + validation.
Whichever — no native `<input type="time">`.

### C5. Step progress `✓` → Lucide (line 576)
`if(num)num.innerHTML=g<n?'✓':String(g);` — replace the unicode `✓` for completed steps with an inline Lucide **check** SVG. Keep the number for the current/future steps.

### C6. General
Sweep all 7 steps for any other native controls/glyphs. Everything styled + Lucide.

### C7. Trip-builder itinerary email (`api/trip-builder.php`, 95 lines)
Currently posts to `/api/trip-builder.php` which calls the **generic** `send_notification` + `send_guest_acknowledgement` (mail.php) — the rich trip data is crammed into one `message` field, so the email looks generic.
**Fix:** build a **dedicated itinerary email** (guest acknowledgement + staff notification) that lays out: chosen property, dates/nights, per-day activities, arrival/departure travel details, extras, guest details — using the existing `includes/mail.php` design system (teal header `#102F3A`, sand accent `#B8965A`, `#f0f4f5` bg, 600px card, detail tables). Add a new builder function in `mail.php` (e.g. `_trip_builder_html()`) and call it from `api/trip-builder.php`.

**Verify:** trace code path only (no live send). Confirm all captured fields render in the styled layout.

---

## Note-only (NOT code)
- **Activities page images:** `activities.php:111,116` already renders `tour_images` hero when present, else a category-icon+gradient placeholder. Cards are blank because **tour hero images aren't seeded/uploaded**. Action = upload/seed hero images per tour. Ties to image storage: uploads need R2 (`R2_*` env) or they save local-only & 404 on prod (see `memory/image-storage-r2.md`).

## Suggested commit slicing
1. `fix(property): single FAQ toggle + Lucide policies chevron + padding + styled selects` (Group A, all pages)
2. `feat(enquire): styled datepicker on tours/villa enquiry + email polish` (Group B)
3. `feat(trip-builder): full UI redesign — images, styled selects/time, Lucide icons` (Group C1–C6)
4. `feat(trip-builder): dedicated itinerary email template` (Group C7)
