# Rates page — compare every property, KES | USD

**Date:** 2026-09-28 · **Status:** approved by owner (brainstorm) · **Page:** `admin/rates.php`

## Problem

`admin/rates.php` shows ONE property at a time, one 3-month calendar per room.
Comparing Zuri against Maya Kobe (or a suite against a villa) means switching
the property select and reading grids side by side in your head. Rooms also
price in different currencies (Maya Ilai USD, the rest KES), so even two
numbers on screen are not comparable.

## Goal

One read-only page where owner and reception can:

1. **Review the rate card** — every room's Standard / Mid / Peak price, all
   properties on one screen, with the season dates.
2. **Scan a timeline** — rooms × days for a month, coloured by season.
3. **Quote a stay** — pick dates, see every room's total side by side, and
   whether it is free.

…and switch every figure between **KES and USD** instantly.

## Non-goals

- No editing. Pricing stays owner-only on `admin/venue-edit.php` /
  `admin/room-edit.php` (Rates tabs). This page stays `require_login()` +
  `admin_venue_ids()`-scoped, exactly as today.
- No stored conversions, no per-page FX rate, no new setting, **no migration**.
- No booking/hold from this page (reception uses the existing flows).
- No min-stay or extra-guest pricing (the `rates` table has no column for them).

## Design

### Page, views, toolbar

- `admin/rates.php`, four views, each a real URL (works with JS off, bookmarkable):
  - `?view=card` (default) — Rate card
  - `?view=timeline` — Timeline
  - `?view=quote` — Quote
  - `?view=calendar&venue=<id>` — today's per-property 3-month calendars,
    unchanged (the `includes/rate-calendar.php` partial + `admin/rate-calendar-frag.php`
    Prev/Next keep working; `$rc_base_url` gains `view=calendar`).
- Any room name in card / timeline / quote links to its property's Calendar view.
- Shared toolbar (house design system, no native chrome):
  - view switch (segmented links),
  - property chips — every in-scope property on by default; `?venues=1,4,7`
    narrows it; ids are validated against `admin_venue_ids()` (an id outside the
    account's list is dropped, never honoured),
  - period — `?year=YYYY` (card, default current Nairobi year), `?month=YYYY-MM`
    (timeline, default current month, Prev/Next), `?check_in` / `?check_out`
    (quote, shared `.dp-btn` range datepicker),
  - **KES | USD** switch.

### Currency switch

- Every amount is rendered in the room's OWN currency with
  `data-amt="<number>" data-cur="<KES|USD>"` (+ `data-fmt="short"` for timeline
  cells). A small inline script re-renders all of them in the chosen currency —
  instant, no reload.
- Rate = the site's daily table, `fx_rates()` (same as the guest switcher),
  injected as `{KES: r, USD: 1, fetched_at}`. The toolbar shows it:
  "1 USD = 129.0 KES · updated 28 Sep".
- Converted figures carry "≈"; figures shown in their own currency are exact.
  KES rounds to whole shillings, USD to whole dollars.
- Choice persists per device (`localStorage`, try/catch) and in the URL (`cur=`);
  default KES. The server renders `?cur=` (default KES) so a shared link opens
  in its currency; with no `cur=` in the URL the script applies the stored
  choice on load. Toolbar links carry the current `cur=` so switching view keeps
  the currency.
- If a needed rate is missing, the figure stays in its own currency (never a
  broken or zero price) — same rule as `convert_price()`.

### Rate card (`?view=card`)

- Rows = rooms, grouped by property (property `sort_order`, room `sort_order`).
- Columns = the season labels present in the data, ordered **Standard season,
  Mid season, Peak season**, then any other labels alphabetically; plus a
  **Base** column = the room's own `price_amount` (the price on nights with no
  override). A season a room never uses shows "—".
- A label with more than one price for a room shows the range
  ("119,500 – 143,400") — e.g. Enkare's December peak.
- Under each property: season dates, merged into runs, e.g.
  "Peak: 26 Mar – 4 Apr · 20 – 31 Dec" (last-night dates, not checkout).
  Dates come from the property's rooms; when rooms disagree the line lists the
  union and the room rows stay the truth.
- Built from the resolved nightly map for the whole year (`rates_nightly_maps()`,
  ONE query for all rooms) — never from raw `rates` rows, so it cannot disagree
  with what a guest is charged.

### Timeline (`?view=timeline`)

- One month; rows = rooms grouped by property; columns = days.
- Cell = short price (`48.4k`, `$375`) coloured by season: Standard green, Mid
  amber, Peak red, any other label grey-blue, base-price nights uncoloured.
  Weekend columns lightly shaded. Tooltip (`title`) = full price + season + date.
- Room column sticky; the table scrolls horizontally inside its own wrapper (the
  page never scrolls sideways — `minmax(0,1fr)` rule).
- One `rates_nightly_maps()` call for the month.

### Quote (`?view=quote`)

- Check-in / check-out; validated with `rates_window_ymd()`; invalid or reversed
  → inline message, no table (never a 0 price).
- One row per room: property, room, **Free / Booked**, nights, season mix
  ("2 Mid + 2 Peak"; base nights count as "Base"), average per night, total.
- Sortable by total in the chosen currency (client-side; default order =
  property then room).
- Totals from **`room_stay_quotes()`** (new, batch) — `room_stay_quote()`
  becomes a one-room call into it, so there is still exactly ONE summation of
  the nightly map. One query for all rooms.
- Free / Booked from `find_available_unit()` per room — the booking engine's own
  check (Maya Ilai composite products, whole-villa ↔ by-room exclusion, a stay
  needs ONE unit free for the whole span).

## Components

| Unit | Responsibility |
|---|---|
| `includes/rates-compare.php` (new, pure — no I/O) | `rc_season_rank()` (order), `rc_season_class()` (colour key), `rc_rate_card_row()` (nightly map → per-season min/max + base), `rc_season_runs()` (nightly maps → per-label date runs, last-night dates), `rc_season_mix()` (nightly map → "2 Mid + 2 Peak"), `rc_short_amount()` (48.4k) |
| `includes/db.php` | `room_stay_quotes(array $defaults, $ci, $co)` batch; `room_stay_quote()` delegates to it |
| `admin/rates.php` | scope + params, toolbar, the four views, the currency/sort script |

## Error handling

- No properties in scope → existing "No properties are assigned" alert.
- A property with no rooms → skipped in card/timeline/quote (calendar view keeps
  its "no rooms yet" alert).
- Bad `year` / `month` / dates → fall back to defaults (card/timeline) or an
  inline message (quote). All date strings go through `rates_window_ymd()`.
- FX table unreadable → `fx_rates()` already falls back to its seed; the note
  shows "rate not synced".

## Testing

- `tests/rates_compare_logic.php` (pure always; DB block in a rolled-back
  transaction, SKIP with no DB):
  - season ordering + unknown labels + base;
  - rate-card row with two prices under one label → range;
  - season runs merge contiguous nights and report last nights;
  - season mix counts;
  - short amounts (48,360 → 48.4k; 1,250,000 → 1.25m; USD);
  - `room_stay_quotes()` total == `room_stay_quote()` for the same rooms/dates,
    and `nights = 0` for a bad window.
- `php tests/rates_logic.php` still passes.
- Browser: card / timeline / quote at 1440 and 375 wide, no horizontal page
  scroll, KES↔USD toggles every figure, screenshot as proof.

## Rollout

Branch `feat/rates-compare` from master. No migration — live on push. The Help
guide article for Rates lives on the unmerged `feat/team-help-guide` branch and
is updated when that branch lands.
