# Listing page: show other properties when this one is full

**Date:** 2026-09-30 · **Status:** approved in chat, ready to plan

## Problem

On a property page, when the guest's dates and party don't fit, the sidebar only
says "Sorry — no space" (Maya Kobe / Zuri) or "no availability" (whole-property
pages). The guest has to go to `/search` themselves to find a Tribal Sand
property that *is* free. We lose them there.

## What we build

When the property has no option for the searched dates + party, the sidebar
lists the **other published properties that do**, the same way `/search` would,
directly under the existing "sorry" line.

```
Other places with space for 15–18 Oct · 2 guests
[img] Zuri            Watamu · Boutique Hotel     from KES 124,050 · 3 nights   See rooms →
[img] My Amani        Kilifi · Private Villa      from …                         See rooms →
See all on the search page →
```

- **Order:** same town as the current property first (`venues.location`, first
  segment before `·`/`,` — the same `$loc_slug` rule `search.php` uses), then the
  cheapest "from" price. **Max 3**, then "See all on the search page →"
  (`/search?checkin&checkout&adults&children`).
- **Card:** hero image, name, location · type ("Boutique Hotel" / "Private Villa",
  the `search.php` labels), "from" price for the stay + nights, "See rooms →".
- **Link:** `/<venue slug>?checkin=&checkout=&adults=&children=`. The multi-room
  widget already prefills + auto-runs from those params. The whole-property
  widget prefills from `sessionStorage.ts_search`, so the click handler also
  writes `{checkin, checkout, adults, children}` there before navigating.
- **Price:** rendered with `window.tsPriceSpan()` (the site's currency switch);
  the amount stays in the property's own currency underneath.
- **Nothing free anywhere:** no block — the existing sorry text stands alone.

## Where

| Page | Widget | Trigger |
|---|---|---|
| Maya Kobe, Zuri | `includes/property-availability-widget.php` | the existing "nothing fits" branch (`!(singles‖combos‖entire)`) |
| My Amani, Enkare Bofa, Sandbox | `includes/booking-widget.php` + `js/booking-widget.js` | `checkAvailability()` answer `available: false` (rare — booked nights are already greyed) |

**Out of scope:** Maya Ilai (own configurator), alternative dates at the same
property, anything in the admin.

## How

1. **`api/alternative-properties.php`** — public GET, JSON.
   `?venue=<current slug>&check_in&check_out&adults&children`.
   Guards follow `api/property-availability.php` (dates via `rates_window_ymd()`,
   422 on bad input or check-out ≤ check-in, party clamped the same way), plus two
   limits of its own because it checks *every* property: check-in not before
   today (Nairobi) and at most **60 nights** — both 422. Unknown `venue` is fine
   (just nothing to exclude/rank against).
   Calls **`ts_search_availability()`** — the `/search` function, so the list can
   never disagree with `/search` and there is no second availability or pricing
   path. Returns
   `{ok, check_in, check_out, nights, guests, adults, children, options:[{slug,name,location,type,hero,from,currency,nights,url}], more:int, search_url}`.
2. **Pure ranking helper** `ts_alternative_properties(array $results, string $excludeSlug, ?string $homeLoc, int $limit = 3): array`
   in `includes/db.php` next to `ts_search_availability()`: drops the current
   venue and every venue with `count === 0`, sorts same-town first then by `from`,
   returns `['options' => top N, 'more' => remaining count]`. Pure, so it is
   unit-tested without a DB.
3. **`js/alternatives.js`** — one shared renderer,
   `window.tsShowAlternatives(container, {venue, checkin, checkout, adults, children})`:
   fetches the endpoint, renders the block (or nothing), wires the
   sessionStorage write on click. Escapes every string. Loaded by both widgets,
   defined once (`window.tsShowAlternatives` guard). Styles live with it (a
   `<style>` injected once), scoped under `.ts-alt`.
4. **Hooks:** the two widgets call it from their "not available" branches into a
   container under the sorry text; a new check clears the container first.
5. The type label map (`hotel`/`villa`) moves out of `search.php` into a small
   `ts_venue_type_label($slug)` in `includes/db.php` so the endpoint and
   `search.php` share it.

**Depends on** the search fix in commit `7ce68c7` (one booked room no longer hides
a property on `/search`) — both ship together.

## Cost

The endpoint runs only when the current property is full — a normal availability
check costs nothing extra. It does what one `/search` page load does.

## Tests

- `tests/listing_alternatives_logic.php`: pure ranking (excludes current venue,
  excludes full venues, same town first, then cheapest, limit + `more`), type
  label; endpoint input guards (bad dates → 422); a DB round-trip in a
  rolled-back transaction when a DB is reachable.
- Browser: locally fill a property for a window, open its page with those dates,
  see the list, follow a link and confirm the dates/guests arrive prefilled; check
  375 px width for sideways scroll.
