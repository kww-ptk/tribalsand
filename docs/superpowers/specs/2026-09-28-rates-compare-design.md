# Rates comparison + Quote Builder — every property, KES | USD

**Date:** 2026-09-28 (revised 2026-09-29) · **Status:** approved by owner (brainstorm)
**Pages:** `admin/rates.php` (redesigned) · `admin/quote-builder.php` (new) · `admin/submission-view.php` (pop-up)

## Problem

1. `admin/rates.php` shows ONE property at a time, one 3-month calendar per room.
   Comparing Zuri against Maya Kobe means switching the property select and
   reading grids in your head. Rooms also price in different currencies (Maya
   Ilai USD, the rest KES), so two numbers on screen are not comparable.
2. The team has no way to build a guest quote across properties. The only quote
   tool is the Maya Ilai calculator (`admin/maya-ilai-rates.php`), which covers
   Maya Ilai only and uses its own standalone pricing.

## Goal

- **Rates page** — owner and reception review the **rate card** (every room's
  Standard / Mid / Peak price, all properties, season dates) and a **timeline**
  (rooms × days, coloured by season), with a **KES | USD** switch.
- **Quote Builder** — the Maya Ilai tool's look and flow, for **everything**:
  rooms from any property (mixed in one quote), activities (incl. massages /
  wellness, kite, excursions), transfers, and custom lines; optional discount;
  KES | USD; Copy / Print-PDF. Reachable from **Bookings → Quote builder** and
  as a pop-up on every **enquiry**, where it inserts the quote into the reply box
  (build several → Option 1, Option 2 …).

## Non-goals

- No editing of prices. Rates stay owner-only on `admin/venue-edit.php` /
  `admin/room-edit.php`; the activity and transfer catalogues are unchanged.
- **Quotes are not saved**, create **no hold**, send **no email**. The builder
  produces text / a printable page; staff send it through the existing reply flow.
- No migration, no new setting, no stored conversions, no per-page FX rate.
- No min-stay or extra-guest pricing (the `rates` table has no column for them).
- Maya Ilai's own tool is untouched. In the builder Maya Ilai is priced at its
  **live** nightly rates like every property; group / availability deals stay in
  the Maya Ilai tool (the builder links to it).

## Shared: the currency switch

Used by both pages.

- Every amount is sent in its OWN currency (`data-amt`, `data-cur`); a small
  script (`admin/assets/admin-money.js`) re-renders in the chosen currency —
  instant, no reload.
- Rate = the site's daily table `fx_rates()` (same as the guest switcher),
  injected as `{rates:{USD:1,KES:r,…}, fetched_at}`. Shown in small print:
  "1 USD = 129.0 KES · updated 29 Sep".
- Converted figures carry "≈"; figures in their own currency are exact. KES
  rounds to whole shillings, USD to whole dollars (display rounding only).
- Choice persists per device (`localStorage`, try/catch) and in the URL
  (`cur=`); default KES. The server renders `?cur=`; with none in the URL the
  script applies the stored choice on load.
- A missing rate leaves the figure in its own currency — never a broken or zero
  price (same rule as `convert_price()`).

## Part A — Rates page (`admin/rates.php`)

Access unchanged: `require_login()`, scoped by `admin_venue_ids()`, read-only.

- Three views, each a real URL (bookmarkable, works with JS off):
  `?view=card` (default), `?view=timeline`, `?view=calendar&venue=<id>` (today's
  per-property 3-month calendars, unchanged — `includes/rate-calendar.php` +
  `admin/rate-calendar-frag.php`; `$rc_base_url` gains `view=calendar`).
- Toolbar: view switch · property chips (all in-scope on by default;
  `?venues=1,4` narrows; ids validated against `admin_venue_ids()`, foreign ids
  dropped) · period (`?year=` card, `?month=` timeline) · KES | USD ·
  **"Build a quote →"** link to the Quote Builder.
- Any room name links to its property's Calendar view.

### Rate card (`?view=card`)
- Rows = rooms grouped by property (property then room `sort_order`).
- Columns = season labels in the data, ordered **Standard season, Mid season,
  Peak season**, then other labels alphabetically; plus **Base** = the room's
  `price_amount` (nights with no override). Unused season → "—".
- A label with several prices for a room shows the range ("119,500 – 143,400").
- Under each property: merged season date runs, last-night dates
  ("Peak: 26 Mar – 4 Apr · 20 – 31 Dec").
- Source = the resolved nightly map for the whole year, `rates_nightly_maps()`
  — ONE query for all rooms; never raw `rates` rows.

### Timeline (`?view=timeline`)
- One month, Prev/Next; rows = rooms grouped by property; columns = days.
- Cell = short price (`48.4k`, `$375`), coloured by season: Standard green, Mid
  amber, Peak red, other labels grey-blue, base uncoloured; weekends shaded;
  `title` = full price + season + date.
- Room column sticky; table scrolls inside its wrapper (page never scrolls
  sideways). One `rates_nightly_maps()` call for the month.

## Part B — Quote Builder

### Where
- **Page:** `admin/quote-builder.php`, sidebar **Bookings → Quote builder**
  (same audience as the Bookings group). `require_login()`, rooms scoped by
  `admin_venue_ids()`.
- **Enquiry pop-up:** `admin/submission-view.php` gets a **Build quote** button
  beside "Draft options with AI". It opens the same builder in a modal (not an
  iframe — the same partial + script), pre-filled from the enquiry: guest name,
  check-in / check-out, adults / children, and the enquiry's room ticked (qty 1)
  when it has one.
- **One component:** `includes/quote-builder-view.php` (markup) +
  `admin/assets/admin-quote-builder.js` (behaviour), pricing via
  `api/quote-builder.php`. Page and pop-up render the same thing.

### Layout (mirrors `admin/maya-ilai-rates.php` → Quote Builder tab)
- **Stay details** card: guest or group name · check-in / check-out (shared
  `.dp-btn` range datepicker) · adults · children · discount % (optional) +
  discount note.
- **Rooms** card: every in-scope room, grouped by property, with property chips
  to narrow the list. Row: room · **quantity** (0 … number of active units;
  whole-property rooms 0/1) · **guests allocated** · capacity (qty × room
  capacity) · **free** (units free for the dates) · nightly average · line total.
- **Extras** card: "Add extra" picker with three groups —
  - **Activities** — published `tours` (includes wellness / massages, kite,
    excursions). Price = `price_amount` (USD); `price_per_person` → quantity
    means people, else trips. Unpriced activities ("On request") need a typed
    price before they count.
  - **Transfers** — active `service_options` where `service='transfer'`, priced
    in `site_currency`.
  - **Custom line** — description + amount + KES/USD + basis
    (per stay / per night / per person).
  Each extra row: description · quantity · unit price (pre-filled, editable for
  this quote only; an edited price shows "edited") · line total · remove.
- **Summary** panel (sticky, like the Maya Ilai tool): big total in the chosen
  currency · per-guest · metrics (nights, guests, capacity, accommodation /
  night) · breakdown (each room line with its season mix "2 Mid + 2 Peak", each
  extra, discount line, total) · notice · **Copy quote** · **Print / PDF** ·
  **Insert into reply** (pop-up only).
- KES | USD switch in the builder header.

### Pricing rules
- **Rooms** — `room_stay_quotes()` (new batch; `room_stay_quote()` becomes a
  one-room call into it) → ONE summation of the nightly map, ONE query for all
  rooms. Line = per-unit stay total × quantity. `nights = 0` (bad dates) →
  no room lines, notice "Choose valid dates".
- **Free** — per ticked room, from the booking engine: `count_available_units()`
  for ordinary rooms, `find_available_unit()` (free = 1 / 0) for Maya Ilai
  composite products. A quantity above what is free → warning notice, line kept.
- **Capacity** — guests allocated > capacity, or the party (adults + children)
  > total capacity → warning notice (as in the Maya Ilai tool).
- **Discount %** — 0–100, applies to **accommodation only** (extras are never
  discounted), shown as its own line with the note.
- **Extras** — per stay: amount × qty; per night: amount × nights × qty; per
  person: amount × qty (qty defaults to the party size).
- **Total** — every line converted to the chosen currency with the site rate;
  the quote text states it when any conversion happened ("Converted at
  1 USD = 129 KES on 29 Sep 2026"). All lines already in that currency → exact.
- Server is the authority: the script posts the selection
  (dates, room ids + qty + guests, extras, discount, currency) to
  `api/quote-builder.php`, which re-prices everything (room prices and catalogue
  prices are looked up server-side by id — a client price is used only for
  custom lines and explicitly edited catalogue prices, which are staff input) and
  returns lines + totals. Debounced like the Maya Ilai tool.

### Output
- **Copy quote** — plain text: header (guest name, dates, nights, party), each
  room line (property · room × qty · season mix · total), extras, discount,
  total, conversion note, "Prices valid on <date>; subject to availability until
  booked."
- **Print / PDF** — a print stylesheet: Tribal Sand logo, same content as a
  clean one-page quote (browser print → Save as PDF).
- **Insert into reply** — appends the text to `#replyBody` (prefixed
  "Option N" when the box already holds a quote) and closes the pop-up; staff
  edit and send with the existing note/reply flow.

### Endpoint `api/quote-builder.php`
- POST JSON; session-authed (`require_login()`, JSON 401 when signed out);
  CSRF token in the body (non-empty session token required — `verify_csrf()`
  reads `$_POST`); room ids filtered through `admin_venue_ids()` (a foreign id
  is dropped, never priced); tour / transfer ids must be published / active.
- Read-only: no writes of any kind.

## Components

| Unit | Responsibility |
|---|---|
| `includes/rates-compare.php` (new, pure) | season order + colour key, rate-card row (min/max per label + base), season date runs, season mix, short amounts |
| `includes/quote-builder.php` (new) | pure: line maths (rooms × qty, extras by basis, discount on accommodation, currency conversion, totals), quote text; I/O: catalogue loaders (rooms in scope, tours, transfers), `qb_price_selection()` |
| `includes/db.php` | `room_stay_quotes()` batch; `room_stay_quote()` delegates |
| `admin/assets/admin-money.js` (new) | the KES/USD switch (shared) |
| `admin/rates.php` | toolbar, card / timeline / calendar views |
| `includes/quote-builder-view.php` + `admin/assets/admin-quote-builder.js` (new) | builder markup + behaviour (page and pop-up) |
| `admin/quote-builder.php` (new) | page wrapper, nav entry |
| `api/quote-builder.php` (new) | pricing endpoint |
| `admin/submission-view.php` | Build quote button + modal + insert into reply |
| `admin/_layout.php` | Bookings → Quote builder link |

## Error handling
- No properties in scope → existing "No properties are assigned" alert.
- Bad `year` / `month` / dates → defaults (rates page) or an inline notice
  (builder). Every date string goes through `rates_window_ymd()`.
- FX table unreadable → `fx_rates()` falls back to its seed; note shows
  "rate not synced".
- Endpoint errors → JSON `{ok:false,error}`; the builder keeps the last good
  quote and shows the error in the notice.
- Pre-migration-safe reads: tours / `service_options` missing → that extras
  group is simply empty.

## Testing
- `tests/rates_compare_logic.php` — season order + unknown labels + base; two
  prices under one label → range; season runs (merge, last nights); season mix;
  short amounts.
- `tests/quote_builder_logic.php` — line maths per basis; discount on
  accommodation only; mixed-currency total + conversion note; exact when single
  currency; quote text; DB block (rolled back): `room_stay_quotes()` ==
  `room_stay_quote()` per room, `nights = 0` on a bad window, foreign room id
  dropped by scope, builder room line == website quote for the same dates.
- `php tests/rates_logic.php` still passes.
- Browser: rates card + timeline, builder page, enquiry pop-up + insert, at
  1440 and 375 wide, no horizontal page scroll, KES↔USD flips every figure,
  print preview; screenshots as proof.

## Rollout
Branch `feat/rates-compare` from master. No migration — live on push. The Help
guide lives on the unmerged `feat/team-help-guide` branch; its Rates / Quote
builder articles are written when that branch lands.
