# Quote builder: suggest options from dates + party

**Date:** 2026-09-30 · **Status:** approved in chat

## Problem

Reception builds a quote by picking rooms by hand from a table of every room.
For a party of 6 on given dates they have to work out themselves which rooms are
free, what fits, and which combination is cheapest — the thing `/search` and the
property pages already do for guests.

## What we build

A **"Suggest options"** button in the builder's Rooms card (Quote builder page
and the enquiry pop-up). It uses the dates + adults + children already in Stay
details and opens a panel above the rooms table, grouped by property:

```
Maya Kobe  (requested)                                     
  Haze Suite · sleeps 2                    KES 120,900   [Use]
  Glow Suite · sleeps 2                    KES 124,050   [Use]
  Whole property · sleeps 14               KES 900,000   [Use]
Zuri
  Maji Suite · sleeps 2                    KES 124,050   [Use]
Enkare Bofa — no availability for these dates
```

- **Per property, at most 3 options**, in this order: the 2 cheapest single
  rooms that fit the whole party, the best room combination (when no single
  fits, or a multi-room combo as a cheaper alternative), the whole property.
- **Use** loads that option into the rooms table (every other room back to 0;
  a combination's guests split across its rooms by what each sleeps), makes
  sure that property's rows are visible, and re-prices through the normal path.
  From there it is today's process: edit rooms / extras / discount → Save /
  Insert / Print as Option N. Another **Use** starts the next option.
- **Order:** the enquiry's requested property first (from the enquiry's room),
  then the same town, then the cheapest (compared in one currency); properties
  with nothing free last, shown as "no availability for these dates".
- **Scope:** only properties in the account's catalogue (`admin_venue_ids()`).
- A room with no price for the dates shows "no price set" (never 0).

## How

- **`api/quote-builder.php` action `suggest`** — same guards as `price` (session,
  owner/reception audience, CSRF in the JSON body). Input `sel.check_in`,
  `sel.check_out`, `sel.adults`, `sel.children`, `prefer_venue`. Refuses (422)
  unreadable dates, check-out ≤ check-in, more than 60 nights, or no guests.
- **`includes/quote-suggest.php`** (new):
  - pure `qb_suggest_split_guests()`, `qb_suggest_venue_options()`,
    `qb_suggest_order()` — the logic, unit-tested;
  - `qb_suggestions($sel, $scope, $preferVenueId)` — I/O: runs
    **`ts_property_configurations()`** (the `/search` + property-page function)
    for every property in the catalogue and maps room slugs to the catalogue's
    room ids. No new availability or pricing code.
- **`admin/assets/admin-quote-builder.js`** — button, panel, Use.
  **`includes/quote-builder-view.php`** — button + panel markup + styles, and
  `data-prefer-venue` (the prefilled room's property).

## Not included

Saving several options in one go, alternative dates, any change to saving /
printing / inserting.

## Tests

`tests/quote_suggest_logic.php`: guest split, option selection + limit +
unpriced handling, slug → id mapping (unknown slugs dropped), ordering
(requested, same town, cheapest in one currency, empty last); a DB round-trip
(every suggested room id is in the catalogue, and a single's total equals the
builder's own price for that room) when a DB is reachable. Browser: suggest →
Use → the rooms table and total match; save as an option still works.
