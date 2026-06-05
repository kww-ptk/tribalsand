# Rooms & Availability Redesign — Design

> Date: 2026-06-03
> Status: Approved (design). Iterates on the Rooms & Rates booking UX.

## Goal

Refine the multi-room booking UX into a **search-first "Rooms & Availability"** experience that matches the look of the existing Zuri "Named Suites" cards: rich suite cards (image + badge + name + guests/beds + description), placed high on the page (right after the gallery), with a **single sticky search bar** (dates + guests) as the only booking control. Filling the bar shows **which rooms are available** for those dates; each available room then exposes a **"Request these dates →"** button that opens the booking popup. Applies to **all property pages**.

## Why (user feedback being addressed)

- "I like more the layout of Zuri" → adopt the `.suite-card` visual style.
- "I don't like to have it at the end of the page" → move the section up.
- "Before that section have the gallery" → section sits immediately after the gallery.
- "Instead of accommodations call the section Rooms & Availability" → rename heading.
- "The sidebar and the check availability are kind of duplicate, keep the sidebar only … two steps that opens the popup at the end" → remove per-card check buttons; one sticky bar (dates + guests) that, when filled, shows availability and opens the popup per available room.

## Decisions

| Decision | Choice |
|---|---|
| Card layout | Zuri **`.suite-card`** style: hero image + optional tag badge + name + meta (guests/beds) + description. |
| Section heading | Eyebrow "Accommodations" + **"Rooms & Availability"** (replaces "Rooms & Rates"). |
| Placement | Immediately **after the page's gallery** (top of content), not at the end. |
| Booking control | **One sticky search bar** (check-in · check-out · guests · "Check availability"). No per-card check buttons. |
| Bar fields | Dates + guests shown together in one compact bar. |
| After search | Show **which rooms are available**; each available room reveals **"Request these dates →"** → opens the popup for that room (dates pre-filled). |
| Scope | **All property pages** (multi-room overviews + whole-villa pages). |
| Whole-villa pages | Keep their existing hand-coded suite cards (display); replace the bottom inline widget with the sticky search bar (which books the single villa room). |

## Behavior — two modes (same bar + JS)

**Multi-room pages (My Amani, Maya Kobe):** the page renders DB-driven `.suite-card`s, each carrying `data-room-slug`. On "Check availability", JS loops the visible room cards, calls `/api/check-availability.php?room=<slug>&check_in&check_out` for each, and updates each card:
- available → adds an "available" state + **"Request these dates →"** button + the computed total;
- unavailable → greys the card + "Not available for these dates."

**Whole-villa pages (Zuri, Enkare Bofa, Sandbox, Maya Ilai, etc.):** the page keeps its hand-coded display suites (NOT individually bookable). The sticky bar carries the venue's single bookable room slug as a fallback. On "Check availability", JS finds no `data-room-slug` cards, so it checks that single room and shows the result **in the bar** with a "Request these dates →" button.

**Request → popup:** clicking "Request these dates →" opens the existing booking modal via `window.tsLoadRoom(slug, price, currency, {checkin, checkout})` — calendar pre-filled, guest completes name/email → `/api/submit-enquiry.php` → 24h hold. (Reuses the modal + shared widget engine already built.)

## Components

1. **`includes/rooms-and-rates.php` (MODIFY)** — restyle to the Zuri `.suite-card` markup; heading "Rooms & Availability" with the `.sec-label`/`.sec-h` eyebrow style; **remove** the per-card `.js-check-availability` buttons; each card carries `data-room-slug`/`data-room-name`/`data-price`/`data-currency` (used by the search JS to mark availability + build the Request button) and renders name, meta (Sleeps {capacity}{, {bed_count} bed} ), `short_desc`, and an optional `tag_label` badge. Drop the By room/Entire place toggle (the search bar supersedes it).
2. **`includes/availability-bar.php` (NEW)** — the sticky search bar: `#rrBarCheckin`, `#rrBarCheckout`, a guests stepper, a "Check availability" button, and a result area (`#rrBarResult`) for whole-villa mode. Takes `$rr_fallback_slug` (+ name/price/currency) = the venue's single/primary bookable room, used when the page has no room cards.
3. **`js/availability-search.js` (NEW)** — wires the bar: on check, validate dates; if `.suite-card[data-room-slug]` cards exist → per-card check + mark + inject "Request these dates →"; else → check `$rr_fallback_slug` and render the result + Request button in `#rrBarResult`. Each Request button calls the modal opener with the dates. Reuses `/api/check-availability.php`.
4. **`includes/booking-modal.php` + `js/booking-modal.js` (REUSE)** — unchanged; the Request buttons are `.js-check-availability` with `data-from-bar`-style date data so the existing opener pre-fills dates.
5. **`css/rooms-and-rates.css` (MODIFY)** — add `.suite-card` look (port from Zuri's inline CSS into the shared sheet), the sticky bar, available/unavailable card states, and the in-bar result. Keep the modal styles.
6. **Data/admin:** add `rooms.tag_label VARCHAR(100)` (migration) + an admin field in `room-edit.php`. Badge renders only if set.
7. **Page wiring:** on each property page set `$page_rooms_rates = true`; multi-room overviews include `rooms-and-rates.php` (after the gallery) + `availability-bar.php` + `booking-modal.php`; whole-villa pages include `availability-bar.php` (with `$rr_fallback_slug`) + `booking-modal.php` after their gallery and **remove the previously-added bottom `#book` inline widget**.

## Data model

- New migration `db/migrations/add_room_tag_label.sql`: `ALTER TABLE rooms ADD COLUMN IF NOT EXISTS tag_label VARCHAR(100);`
- Reused: `name`, `capacity` (guests), `bed_count`, `short_desc`, `room_images` hero, `price_amount`/`price_currency`, `is_published`.

## Scope / pages

- **Multi-room overviews:** `my-amani.php`, `maya-kobe.php` — full section + bar.
- **Whole-villa:** `zuri.php`, `enkare-bofa.php`, `sandbox.php`, `maya_ilai.php`, `tribal-dunes.php` — keep their suites; swap bottom widget for the sticky bar.
- **Unchanged:** individual room pages (`my-amani-*.php`, `maya-kobe-main-house/cottages.php`) keep their inline widget; all URLs unchanged.

## Out of scope / preserved (YAGNI)

- No payments (still request-to-book). No URL changes. No rebuild of page content beyond the section restyle/relocation + bar swap.
- Real room data (names/prices/photos/badges) curated later in admin.
- A dedicated cross-room availability API endpoint (client-side per-card checks reuse the existing endpoint; acceptable for ≤ ~11 rooms; revisit only if it feels slow).

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Per-card availability loop = many requests | Only fires on explicit "Check availability"; ≤ ~11 rooms; each request is tiny. Log/limit if needed. |
| Whole-villa pages have hand-coded cards without slugs | Bar uses `$rr_fallback_slug` (the venue's single room) and renders the result in the bar, not on display cards. |
| Moving the section / removing bottom widget breaks a page | Per-page lint + curl render checks; only relocate/insert, no URL/content rewrites; keep galleries intact. |
| Suite-card CSS divergence from Zuri | Port Zuri's `.suite-card*` rules into the shared `css/rooms-and-rates.css` so the look is identical and reusable. |
| Regression to the booking engine | Reuse the existing modal + `tsLoadRoom` + `/api/*` untouched; verify a hold still creates. |

## Success criteria

1. On `my-amani.php`/`maya-kobe.php`, the "Rooms & Availability" section appears right after the gallery with Zuri-style suite cards and no per-card check buttons.
2. A single sticky bar (dates + guests) is present on every property page.
3. Filling the bar + "Check availability" marks each room card available/unavailable (multi-room) or shows the villa result (whole-villa); available rooms expose "Request these dates →".
4. "Request these dates →" opens the popup for that room with dates pre-filled; submitting creates a 24h hold (verified in admin).
5. Whole-villa pages no longer show the old bottom inline widget; their suite cards remain.
6. Admin can set a room's `tag_label` badge; it renders on the card.
7. No URL changes; individual room pages and the booking API unchanged.
