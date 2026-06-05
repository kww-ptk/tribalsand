# Rooms & Rates Booking UX — Design

> Date: 2026-06-03
> Status: Approved (design). Builds on the booking engine + property admin.

## Goal

Replace the single inline booking widget on **multi-room property pages** with a **klickenya-style "Rooms & Rates" experience**: a grid of **room cards** (photo, name, "Sleeps N", price/night, "Check availability"), a **"How would you like to stay? By room / Entire place"** toggle, and a **sticky booking bar** — where clicking "Check availability" (on a card or the bar) opens a **popup/modal** with that room's availability calendar + booking request. Modeled on klickenya's Maya Kobe page.

## Reference

klickenya's property pages (e.g. `https://klickenya.com/stays/kilifi/maya-kobe`): a `How would you like to stay? 🛏 By room / 🏠 Entire place` toggle, a `Rooms & Rates` heading, room cards (image, name, `Sleeps N`, `KSh X / night`, `Check availability`), and a sticky booking bar (check-in/out, guests, `Check availability`). Clicking `Check availability` opens a per-room popup.

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Data source | **Build UI now, real data later.** Use whatever rooms exist in the DB (currently placeholders); the cards reflect the DB and the owner corrects names/prices/photos/flags in admin afterward. |
| Scope (which properties) | **Data-driven, not hardcoded.** A property shows the card grid when it has 2+ published rooms; shows the toggle when it has both regular and "entire place" rooms; a single-room property shows just one booking option. |
| Sticky bar behavior | Clicking "Check availability" opens the popup for the property's **Entire place** room (or, if none flagged, the first room), with the chosen dates pre-filled and checked. |
| Booking model | Unchanged — still **request-to-book** (live availability → 24h hold → admin confirm). No payments. |
| URLs | Unchanged. Individual room pages stay as-is. Only the overview pages gain the new section. |

## Constraints

- No public URL changes; individual room pages (`my-amani-*.php`, `maya-kobe-main-house.php`, etc.) remain valid and unchanged.
- Reuse the existing availability API (`/api/check-availability.php`, `/api/submit-enquiry.php`) and the existing `bk-*` calendar/styling — do NOT duplicate the calendar logic.

## Data model

- **New migration** `db/migrations/add_room_entire_place.sql`:
  `ALTER TABLE rooms ADD COLUMN IF NOT EXISTS is_entire_place BOOLEAN NOT NULL DEFAULT FALSE;`
- **Reused existing columns:** `rooms.capacity` → "Sleeps N"; `rooms.price_amount` / `price_currency` → "X / night"; `room_images` (is_hero) → card photo (placeholder if none).
- Seed: mark the `my-amani-full-rental` room `is_entire_place = TRUE` as a sensible default so the toggle demonstrates immediately (owner adjusts later).

## Components

### 1. `includes/rooms-and-rates.php` (NEW) — the section partial
Input: `$rr_venue_slug` (string). Loads the venue and its published rooms (ordered by `sort_order`), split into **by-room** (`is_entire_place = FALSE`) and **entire-place** (`is_entire_place = TRUE`). Renders:
- A `Rooms & Rates` heading.
- The `How would you like to stay?` **toggle** with `By room` / `Entire place` buttons — rendered ONLY when both groups are non-empty. (Toggle switches which card group is shown.)
- A responsive **card grid**; each card: hero photo (via `storage_url` of the room's hero `room_images`, else a branded placeholder), room **name**, **"Sleeps {capacity}"**, **"{price_currency} {price} / night"**, and a **"Check availability"** button carrying `data-room-slug`, `data-room-name`, `data-price`, `data-currency`.
- Returns nothing (renders nothing) if the venue has no published rooms.
Styling: new `.rr-*` classes in a small CSS file, using the Tribal Sand palette (Cormorant Garamond headings, Jost body, teal accents) to match the site.

### 2. `includes/booking-modal.php` (NEW) — the popup
A single hidden modal included once per page. Contains the existing `bk-*` availability-widget markup (dates pill + calendar, guests stepper, totals, name/email/phone/message, hCaptcha when configured, submit) wrapped in a dialog with a backdrop + close button + a title showing the selected room name. Opened by JS; not tied to a specific room at render time.

### 3. `js/booking-modal.js` (NEW) — opener + reuse of the calendar engine
- Refactor `js/booking-widget.js`'s `initAvailForm` into a reusable `initBookingWidget(rootEl)` that reads `data-slug`/`data-price`/`data-currency` from its root and wires the calendar + availability + submit (so both the inline widget and the modal share ONE implementation — DRY).
- `booking-modal.js`: on click of any `.js-check-availability[data-room-slug]`, set the modal's `#availCalendar` root `data-slug`/`data-price`/`data-currency` + title to the clicked room, (re)run `initBookingWidget` for that room (fetching its blocked dates), optionally pre-fill dates passed from the sticky bar, then open the modal. Close on backdrop click / Esc / close button.
- Sticky bar: a `.js-check-availability` trigger carrying the **entire-place room's** slug (or the first room's), plus the bar's chosen check-in/out/guests; clicking opens the modal pre-filled with those dates and runs the check.

### 4. `css/rooms-and-rates.css` (NEW) — card grid + modal styling
`.rr-grid`, `.rr-card`, `.rr-toggle`, sticky-bar `.rr-bar`, and `.bk-modal`/backdrop styles, on the Tribal Sand palette. Loaded (with `booking-modal.js`) when `$page_rooms_rates` is set, via `includes/head.php` (mirrors the existing `$page_booking` mechanism).

### 5. Page wiring — `my-amani.php`, `maya-kobe.php` (MODIFY)
On each multi-room overview page: set `$page_rooms_rates = true` (loads the CSS/JS) and `$rr_venue_slug = '<venue-slug>'` before `head.php`; replace the page's primary "BOOK NOW"/rooms area with `include includes/rooms-and-rates.php` + `include includes/booking-modal.php` + the sticky bar. Keep all other page content/URLs intact.

### 6. Admin — `admin/room-edit.php` (MODIFY)
Add an **"Entire place (whole-property option)"** checkbox bound to `is_entire_place` in the room save (INSERT + UPDATE). Confirm **capacity ("Sleeps")** and **price** are editable (capacity already is). This lets the owner curate the cards/toggle once real data is entered.

## Whole-villa & single-room pages

Whole-villa property pages (Zuri, Enkare Bofa, Sandbox, Maya Ilai, Tribal Dunes) and individual room pages keep their **current inline widget** unchanged — they already represent a single "entire place" booking. (The shared `initBookingWidget` refactor keeps them working.) They are out of scope for the card grid unless/until they gain multiple rooms in the DB, at which point they could opt in by adding the include.

## Data flow

Card "Check availability" → `booking-modal.js` opens modal for that room slug → `initBookingWidget` fetches `/api/check-availability.php?room=<slug>` (blocked dates) → guest picks dates (live check + price) → submits → `/api/submit-enquiry.php` creates the 24h hold (+ GHL mirror) → success state in the modal. Identical backend to today.

## Out of scope (YAGNI)

- Correcting real room data (names/prices/photos) — done later in admin.
- Online payments (still request-to-book).
- A cross-room availability search on the sticky bar (the bar opens the entire-place popup, per decision).
- Rebuilding individual room pages or changing any URL.

## Files

**Create:** `includes/rooms-and-rates.php`, `includes/booking-modal.php`, `js/booking-modal.js`, `css/rooms-and-rates.css`, `db/migrations/add_room_entire_place.sql`
**Modify:** `js/booking-widget.js` (extract `initBookingWidget`), `includes/head.php` (load rooms-rates assets on `$page_rooms_rates`), `my-amani.php`, `maya-kobe.php`, `admin/room-edit.php` (is_entire_place checkbox)
**DB:** add `rooms.is_entire_place`; seed flag on `my-amani-full-rental`.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Duplicating the calendar logic (drift between inline + modal) | Extract ONE `initBookingWidget(rootEl)`; both inline widget and modal call it. |
| Placeholder rooms make ugly cards (price 0, no photo) | Cards show "Price on request" when price is 0 and a branded placeholder when no photo; owner fixes data in admin. |
| Breaking the existing inline widget during the JS refactor | Keep `#availCalendar` + `bk-*` contract identical; verify the whole-villa pages (e.g. /zuri) still create holds after the refactor. |
| My Amani currently has 11 placeholder rooms but reads as a "full retreat" | Data-driven UI shows whatever's there now; owner later removes the fake rooms / flags entire-place. UI adapts with no code change. |
| URL/SEO regression | Overview pages only gain a section; no slugs/pages renamed; individual room pages untouched. |

## Success criteria

1. `my-amani.php` and `maya-kobe.php` show a "Rooms & Rates" card grid (one card per published room) with Sleeps/price/photo and a "Check availability" button.
2. The "By room / Entire place" toggle appears when the property has both kinds of room and switches the visible cards.
3. Clicking a card's "Check availability" opens a popup with that room's live availability calendar; picking dates + submitting creates a 24h hold (verified in admin).
4. The sticky bar opens the entire-place popup with its dates pre-filled and checked.
5. The existing inline widget on whole-villa/individual room pages still works (shared `initBookingWidget`).
6. Admin can flag a room as "Entire place" and set its capacity/price; the front end reflects it.
7. No public URL changes; no payment step introduced.
