# Rate overrides — property/room editors, multi-range entry, rates calendar

**Date:** 2026-09-04
**Status:** approved

## Problem

Nightly rate overrides live in one place: a "Price Overrides" form bolted to the bottom
of `admin/gantt.php`. It takes one room, one date range, one price, one label. Setting a
season across five rooms is five trips through the form, and pricing a property means
leaving the property page entirely to go to the site-wide calendar.

There is also no way to *see* rates. The Gantt tints a day yellow when any room has an
override, but never shows a price. The only place a figure appears is the flat override
table under the form, as raw `date_from`/`date_to` rows.

Two smaller problems fall out of the current model:

- **Overlapping rows resolve invisibly.** `room_stay_quote()` walks overlapping rows
  `created_at DESC` and lets the first one claim each night. Nothing prevents overlap, so
  a night's price can come from a row you cannot identify by looking, and deleting a rate
  can uncover an older one underneath.
- **Two loops price the same night.** `room_stay_quote()` resolves nightly prices for
  quoting. Any calendar view would need the same logic again, and the two could drift.

## Goals

1. Rate editing lives on the property and the room, not on the site-wide calendar.
2. One submission can carry several date ranges sharing a price and a label.
3. A visual month calendar shows what each night actually costs.
4. The calendar and the guest quote are guaranteed to agree.
5. Every night is owned by exactly one rate row.

## Non-goals

- No drag-to-select on the calendar. The calendar is read-only in this iteration;
  ranges are entered in the form. Drag-select is a possible follow-up.
- No per-unit rates. Rates stay per **room**, as they are today.
- No currency work. A rate inherits its room's `price_currency`.
- No change to how guests are quoted. Same resolution rule, same numbers.

## Data model

**No migration.** `rates` already has the right shape:

```
rates
  id            SERIAL PK
  room_id       INT NOT NULL
  date_from     DATE NOT NULL     first night
  date_to       DATE NOT NULL     EXCLUSIVE — checkout morning
  price_amount  NUMERIC NOT NULL
  label         VARCHAR NULL
  created_at    TIMESTAMP NOT NULL
```

### `date_to` is exclusive

`room_stay_quote()` loops `while ($d < $end)`, so `date_to` is the morning after the last
priced night. The current form hides this: it is labelled "To (last night)" and the
handler adds a day before insert. **Both new editors keep that exact wording and that
exact conversion.** Reinterpreting the column as inclusive would silently reprice every
override already stored in production.

### Resolution

A night's price is the first overlapping row ordered by `created_at DESC`, falling back to
the room's own `rooms.price_amount`. This is unchanged — production may hold legacy
overlapping rows written by the old form, and they must keep behaving as they do today.

Going forward, writes leave no overlaps (see below), so the rule stops mattering for new
data while remaining correct for old data.

## `includes/rates.php`

New file, the single home for rate logic. No `rates_supported()` guard: the table predates
this work and is already read unguarded by `includes/db.php`.

### `rates_nightly_map(int $roomId, float $default, string $fromYmd, string $toExclYmd): array`

Returns `ymd => ['price' => float, 'label' => ?string, 'rate_id' => ?int, 'is_override' => bool]`
for every night in the window. One query, resolution rule applied once.

This is the **single source of truth for what a night costs.** `room_stay_quote()` in
`includes/db.php` is refactored to call it and sum the result, so the rates calendar and
the guest quote cannot disagree. Its signature, return shape (`['nights','total']`) and
rounding are unchanged — it is used by the public availability API and the `/search`
results page.

### `rates_apply_ranges(int $roomId, array $ranges, float $price, ?string $label): int`

One transaction. Steps:

1. **Normalise the submission.** Drop invalid ranges (`from >= toExcl`), sort by start,
   and merge ranges that overlap or abut each other. They share a price and label, so
   merging is lossless — and it stops two ranges in the same submission from trimming
   each other.
2. **For each merged range, clear the nights it claims.** For every existing row `E` of
   this room overlapping new range `N`:

   | Case | Action |
   |---|---|
   | `E` inside `N` (`E.from >= N.from && E.to <= N.to`) | delete `E` |
   | `E` spans `N` (`E.from < N.from && E.to > N.to`) | split: `E.to = N.from`, insert `[N.to, E.to_original)` |
   | `E` overlaps `N`'s start (`E.from < N.from`) | `E.to = N.from` |
   | `E` overlaps `N`'s end (`E.to > N.to`) | `E.from = N.to` |

   The split case must be handled before the two one-sided cases, since a spanning row
   satisfies both of their tests.
3. **Insert the new row.**

Returns the number of rows inserted (one per merged range).

### Listing and deletion

- `rates_for_room(int $roomId, ?string $fromYmd = null, ?string $toExclYmd = null): array`
- `rates_for_venue(int $venueId, ?string $fromYmd = null, ?string $toExclYmd = null): array` —
  joined to `rooms` for the room name, ordered by room then `date_from`.
- `rates_delete(int $rateId, ?array $venueScope): bool` — `$venueScope` of `null` means
  owner/unscoped; otherwise the row's room must belong to one of those venues.

## UI partials

### `includes/rate-form.php`

Repeatable date-range rows on the **shared** `js/datepicker.js` already loaded by
`admin/_layout.php`. Each row is a `ci`/`co` pair sharing a unique `data-dp-pair`, so the
range picker's existing two-click behaviour works per row with no new picker code.
"Add another date range" clones the row, rewrites its `data-dp-pair` to a fresh id, and
calls `window.initDatepickers()`. A row can be removed; the last remaining row cannot.

One price and one label apply to every range in the submission.

Config before include:
- `$rf_room_id` — fixed room (room tab), or `null` to render a room `<select>` (property tab)
- `$rf_rooms` — rooms to offer in the selector
- `$rf_action` — the posting page

House components only, per the codebase convention: `.eselect` selects, `.inp` fields,
`.dp-btn` pickers, `.btn-icon` actions. No native date inputs, no native selects.

Posts PRG to the host page with `csrf_token`, `room_id`, `price`, `label`, and parallel
`range_from[]` / `range_to[]` arrays.

### `includes/rate-calendar.php`

Read-only month grid for one room. Each cell shows the night's price from
`rates_nightly_map()`; override nights carry the yellow `is-rate` treatment the Gantt
already defines, with the label in the cell title. Prev/next month navigation via a query
param so it works without JavaScript.

Config: `$rc_room_id`, `$rc_default_price`, `$rc_month` (`Y-m`), `$rc_currency`.

Month navigation and room selection are carried in explicitly named query params —
`rate_month` and `rate_room` — so they cannot collide with the params the host pages
already use (`venue-edit.php` and `room-edit.php` both carry an `id`, and `gantt.php`
uses `room`). On the property tab the calendar renders `rate_room` when present and the
property's first room otherwise; `venue-edit.php` restores the active tab from
`sessionStorage`, so a month step reloads the page and lands back on Rates.

## Pages

### 1. `admin/venue-edit.php` — new **Rates** tab

Between Rooms and Gallery. Room selector, the form, the calendar for the selected room,
and a table of that property's existing overrides with delete. Follows the tab pattern
already in the file (`data-tab` buttons, `sessionStorage` remembers the active tab across
saves).

### 2. `admin/room-edit.php` — new **Rates** tab

Same two partials, room fixed, plus that room's override table.

### 3. `admin/rates.php` — new page, sidebar **Bookings** group

Property filter, month navigation, one calendar per room, read-only. Each room heading
links to its room-edit Rates tab. Scoped by `admin_venue_ids()`.

### 4. `admin/gantt.php` — remove the Price Overrides form and table

Replaced by a line pointing at the new Rates page. The `$rate_dates` / `$rate_dates_any`
day-tinting stays — it reads the same table and is genuinely useful on the availability
grid. The `rate_add` / `rate_delete` POST handlers are removed with the form.

## Permissions

`venue-edit.php` and `room-edit.php` are already `require_owner()`, so both new tabs are
owner-only. `admin/rates.php` is `require_login()` + `admin_venue_ids()` scoping,
read-only, so reception can see rates for their own properties when quoting.

**This tightens an existing permission.** `admin/gantt.php` is `require_login()` only, so
any reception account can create and delete rate overrides there today. Removing that form
moves rate writing behind `require_owner()`. That matches the documented rule in
CLAUDE.md — `require_owner()` covers pricing — but it is a live behaviour change for
reception accounts, not just a move.

## Tests

`tests/rates_logic.php`, following the `*_logic.php` convention and running inside a
rolled-back transaction like `tests/checkin_consent.php`:

- All four trim/split cases produce the expected surviving rows.
- Split runs before the one-sided cases (a spanning row becomes two rows, not one).
- Overlapping and abutting ranges in one submission merge into a single row.
- Invalid ranges (`from >= toExcl`) are dropped, not inserted.
- After any `rates_apply_ranges()` call, no two rows for a room overlap.
- `rates_nightly_map()` summed equals `room_stay_quote()['total']` for the same window,
  including a window with no overrides at all (pure default price).
- `rates_delete()` refuses a row outside a non-null venue scope.

## Risks

- **`room_stay_quote()` is on the public path.** It prices the availability API and
  `/search`. The refactor keeps its signature and rounding, and the map-vs-quote test pins
  the behaviour, but this is the one change that can affect guest-facing numbers.
- **Legacy overlapping rows.** Trim/split only cleans rows a save touches. Production rows
  written by the old form may still overlap; they resolve exactly as they do today. No
  backfill is attempted.
