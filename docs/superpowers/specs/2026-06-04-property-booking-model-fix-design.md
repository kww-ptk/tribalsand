# Per-Property Booking Model Fix — Design

> Date: 2026-06-04
> Status: Approved (design). Corrects the room data + page modes set during the booking-UX work.

## Goal

Make each property's booking model match reality: some are booked as a **whole villa** (one unit), some **by individual room**, and one is **not bookable**. Re-seed the database accordingly and switch each page to the correct mode (villa vs by-room vs none), reusing the existing Rooms & Availability + search-bar machinery. No URL changes.

## The model (authoritative)

| Property | Slug | Mode |
|---|---|---|
| My Amani | `my-amani` | **Entire villa** |
| Enkare Bofa | `enkare-bofa` | **Entire villa** |
| Sandbox | `sandbox` | **Entire villa** |
| Zuri | `zuri` | **By-room** |
| Maya Kobe | `maya-kobe` | **By-room** |
| Maya Ilai | `maya_ilai` | **By-room** |
| Tribal Dunes | `tribal-dunes` | **Not bookable** |

## Two page modes (already supported)

- **Villa mode:** the page includes `availability-bar.php` (+ `booking-modal.php`) only. The bar books the venue's single `is_entire_place` room (its `data-fallback-slug`). No `rooms-and-rates.php` card grid. (Enkare Bofa & Sandbox already work this way.)
- **By-room mode:** the page includes `rooms-and-rates.php` (DB card grid) + `availability-bar.php` + `booking-modal.php`. Each room is individually bookable.
- **Not bookable:** no bar, no modal, no `$page_rooms_rates`.

## Data changes (re-seed)

A new idempotent seed `db/seed_rooms_model.sql` (run after the existing seed) brings the DB in line:

- **My Amani — entire villa:** delete its 11 placeholder room types; insert one room `My Amani — Whole Villa` (slug `my-amani`, `is_entire_place = TRUE`, `capacity = 10`). One unit.
- **Enkare Bofa, Sandbox:** keep their single room; set `is_entire_place = TRUE`. (Already villa mode.)
- **Zuri — by-room:** delete the single whole-villa room; insert the **6 named suites** from the current Zuri page, each with `capacity` + a hero photo (stored as a full `https://tribalsand.com/images/zuri/…` URL so `storage_url()` passes it through and it renders in dev via the image proxy and in prod):
  - Maji Suite — 2 guests; Mwezi Suite — 4 guests; Ua Suite — 2; Anga Suite — 2; Jua Suite — 2; Bahari Suite — 2. One unit each.
- **Maya Kobe — by-room:** delete the 2 placeholders; insert the **5 suites** with klickenya prices in **KES**: Prestige Suite 2 (72,800; sleeps 4), Haze Suite (30,000; 2), Glow Suite (31,009; 2), Tide Suite (33,800; 2), Drift Suite (33,800; 2). One unit each. (Other rooms keep USD; per-room currency is supported.)
- **Maya Ilai — by-room (placeholder):** delete the single placeholder; insert 2 placeholder room types to refine in admin: `Studio` (capacity 2) and `Garden Room` (capacity 2), price 0 (on request). One unit each. (The page describes "16 units" of studios/rooms — the owner curates the real breakdown in admin.)
- **Tribal Dunes:** leave its room row (harmless) — it just won't be rendered (page becomes non-bookable).

Every property keeps **≥1 unit per room** (insert units for any new room) so availability/holds work.

## Page-mode changes

- **`my-amani.php`** → villa mode: remove the `rooms-and-rates.php` include (added earlier); keep `availability-bar.php` + `booking-modal.php` (already after the gallery). `$rr_venue_slug='my-amani'`. The bar's fallback resolves to the new whole-villa room.
- **`zuri.php`** → by-room mode: it's currently villa mode with a hand-coded "Six Named Suites" section. Replace that hand-coded `<section>` (the `.sec-label`Accommodations + `Six Named Suites` + `.suites-grid` block) with `<?php $rr_venue_slug='zuri'; include includes/rooms-and-rates.php; ?>` so the suites come from the DB (with their seeded photos). Keep the already-present `availability-bar.php` + `booking-modal.php`.
- **`maya_ilai.php`** → by-room mode: add the `rooms-and-rates.php` include (after the gallery, before the bar). Currently villa mode (bar only).
- **`maya-kobe.php`** → already by-room; no page change (data re-seed only).
- **`enkare-bofa.php`, `sandbox.php`** → already villa mode; no page change (just the `is_entire_place` flag in the seed).
- **`tribal-dunes.php`** → not bookable: remove `$page_rooms_rates`, the `availability-bar.php` and `booking-modal.php` includes; leave the rest of the page as plain content.

## Out of scope (YAGNI / data later)

- Real photos for Maya Kobe + Maya Ilai (placeholder logo image until set in admin). Zuri uses its existing photos.
- The precise Maya Ilai room breakdown (2 placeholders now; owner curates the real 16-unit structure in admin).
- Prices for the villa properties + Zuri + Maya Ilai (on request until set). Only Maya Kobe gets seeded KES prices.
- No URL changes; individual room pages untouched; booking API/engine untouched.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Deleting My Amani's 11 rooms orphans holds | No real holds exist (only test data); `availability_blocks`/`holds` FK is `ON DELETE …`; safe in this pre-launch DB. The re-seed runs locally only. |
| Zuri replacing the hand-coded suites loses its photos | Seed the 6 Zuri rooms WITH their existing image URLs so the DB cards show the same photos. |
| My Amani villa mode: bar fallback must resolve | The bar computes its fallback as the venue's `is_entire_place` room first; the new whole-villa room satisfies it. |
| Currency mismatch (KES vs USD) | Per-room `price_currency`; Maya Kobe = KES, others unchanged. Card/popup already render per-room currency. |
| Re-seed not idempotent → duplicates | Use slug-based delete+insert (delete the venue's rooms, then insert the correct set) wrapped so re-running is safe. |

## Success criteria

1. My Amani, Enkare Bofa, Sandbox show **no room-card grid** — just the search bar, which books the whole villa (one `is_entire_place` room each).
2. Zuri shows a **Rooms & Availability card grid of its 6 named suites** (with photos) sourced from the DB; the hand-coded suites block is gone.
3. Maya Kobe shows its **5 suites** with KES prices; Maya Ilai shows its **placeholder by-room cards**.
4. Tribal Dunes has **no booking bar/modal**.
5. Filling the bar still marks availability + opens the popup per the existing flow; a 24h hold still creates.
6. No URL changes; individual room pages and the booking API unchanged.
