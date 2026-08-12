# Activity pricing & two-up mobile cards — design

**Date:** 2026-08-12
**Status:** approved, ready for planning
**Scope:** Group D — the last outstanding item from a four-group request, plus a mobile layout change

**Supersedes an earlier draft of this file** that proposed a new `wellness` catalog in
`service_options`. That was wrong: wellness activities already live in the `tours` catalog, which
already supports pricing, per-person totals, venue scoping and guest booking. Nothing new needs
building — an existing field is swallowing the prices. The catalog design is dropped entirely.

## Problem

### 1. The tour editor has two Price fields and the obvious one is dead

`admin/tour-edit.php` renders these one after the other:

```php
// line 277 — a text field
<label>Price <span class="text-muted">(activities; shown on card)</span></label>
<input type="text" name="price" placeholder="e.g. From $60 / person">

// line 283 — the numeric one
<label>Booking price (numeric — used for guest requests & the bill)</label>
<input type="number" name="price_amount" ...>
```

`tours.price` (varchar) is written at `admin/tour-edit.php:45` and **read by nothing**. Not
`fetch_portal_activities()`, which selects `price_amount` and `price_per_person`; not the portal
card at `includes/app/activities.php:44`; not `activity_price_total()`; not the booking API. A
repo-wide grep finds no reader outside its own admin input.

Its label nevertheless claims *"shown on card"*.

So an owner pricing an activity types into the field labelled **Price**, saves successfully, and
the guest app shows nothing. This is the same defect class as the guest board category bug fixed
in Group C: a control that looks like the right one, silently ignored.

### 2. Nothing is priced, and the admin does not say so

Every published tour has `price_amount = NULL`, and all 8 `service_options` rows are `0.00`. The
portal deliberately renders a price only when it is greater than zero
(`includes/app/activities.php:44`, `_services.php:46`), so a guest sees a bare label and no total.

The Tours list (`admin/tours.php`) shows Name, Category, Duration and Published — **no price at
all**. The Service pricing page shows `0` in a number input, which reads as a deliberate "free".
23 unpriced items are invisible until a guest hits one.

### 3. It is unclear why an activity is or is not bookable

A tour appears — and is therefore bookable — only when `is_published = TRUE` **and** it either has
no `tour_venues` rows (offered everywhere) or has one matching the guest's venue
(`includes/booking.php:528`, `:550`). There is no separate "bookable" flag: if it shows, the
Request button shows with it.

The Tours list surfaces `Published` but not venue scoping, so an activity restricted to the wrong
property looks fine in admin and is invisible in the app, with nothing to explain the difference.

### 4. Browse cards are one-per-row on a phone

`.pa-grid` (`css/portal-app.css:98`) is `grid-template-columns:1fr` until 720px. It drives
Activities and the What's on board — the two screens where a guest is scanning options — so a
375px phone scrolls past one 120px-tall image at a time.

## Decisions taken

| Question | Decision |
|---|---|
| The dead `price` text field | Remove the input. Surface any stored legacy value read-only as a hint so it can be retyped. **Never auto-convert it** |
| Diagnosing bookability | Extend the Tours list so Price, Published and where-it's-offered are all visible per row |
| Unpriced options | Flag in admin; do **not** hide from guests |
| Two-up mobile | `.pa-grid` — Activities and What's on |

**Why no auto-conversion.** `tours.price` holds free text like `"From $60 / person"`. Parsing that
into `price_amount` would guess a currency, guess whether it is per-person, and silently set a
number a guest can be charged. A wrong price on a bill is worse than a missing one, so the legacy
value is shown for a human to act on and never interpreted.

**Why unpriced stays visible to guests.** Zero legitimately means free for some items. Hiding
unpriced options would remove working ones; the admin badge makes the gap visible instead.

## Architecture

### 1. One Price field on the tour editor

Remove the `name="price"` input. The numeric field becomes the only one, relabelled plainly:

> **Price** *(per person unless you untick — used on the guest card, requests and the bill)*

`admin/tour-edit.php:45` stops writing `':price'`. The column is **not dropped**: it still holds
data on production, and this design's whole point is not to destroy it.

When a tour has a non-empty `tours.price` and `price_amount IS NULL`, the editor renders a hint
under the numeric field:

> Previously entered as text: **From $60 / person** — retype it above as a number to use it.

The hint disappears once `price_amount` is set, so it doubles as a per-tour migration checklist.
No migration, no data change.

### 2. The Tours list answers "why isn't this bookable?"

`admin/tours.php` gains two columns:

- **Price** — the formatted `price_amount` with a `/pp` suffix when per-person, or a `no price`
  badge when NULL or zero.
- **Offered at** — "All properties" when the tour has no `tour_venues` rows, else the venue names.
  This is the piece that currently has no representation anywhere in the list, and the likely
  reason an activity is missing from a given guest's app.

With the existing Published column, one row now shows every reason a tour would or would not
appear for a guest.

`admin/tours.php:47` already selects `t.*` plus a correlated subquery for the hero image, so the
venue names follow the same shape rather than a join and `GROUP BY` (which would need every
`t.*` column in the grouping) or an N+1 loop over `activity_venue_ids()`:

```sql
(SELECT string_agg(v.name, ', ' ORDER BY v.name)
   FROM tour_venues tv JOIN venues v ON v.id = tv.venue_id
  WHERE tv.tour_id = t.id) AS venue_names
```

`NULL` from that subquery means no `tour_venues` rows, which is precisely "offered everywhere" —
so the display rule is a null check, matching the query semantics in `fetch_portal_activities()`.

`admin/services.php` gains the same `no price` badge per row, and its per-service header count
becomes `N active · M total · K unpriced`, the last part shown only when non-zero.

Presentation only. No guest-facing or pricing-logic change.

### 3. Two-up browse cards on mobile

`.pa-grid` becomes `repeat(2, minmax(0,1fr))` at all widths; the existing 720px rule already
widens it further and is unchanged.

At 375px each column is roughly 165px, so the card is tightened **only below 720px**:

- `.pa-media` height 120px → 92px
- `.pa-card__body` padding 14px 16px → 11px 12px
- `.pa-card__title` 15px → 14px, `.pa-card__meta` 12px → 11.5px
- `.pa-btn` inside a grid card: padding 11px 14px → 10px 8px, font 14px → 13px

**The open request form is the real constraint.** An activity's form (guest count, date, notes,
total, submit) cannot work in a 165px column. When a card's form is open the card spans the full
grid width — `grid-column: 1 / -1` — exactly the pattern `.cx-tile[aria-expanded=true]` already
uses for the Requests tiles (`_services.php:24`). The toggle at `activities.php:93-100` adds an
`is-open` class to the card alongside the existing `form.style.display` switch, and the CSS keys
on that class.

The What's on board needs no equivalent: its only action is a single Join button, which fits.

## Testing

Most of this is presentation, which the plain-script harnesses cannot assert. What is testable:

`tests/activities_logic.php:47-53` **already** asserts `activity_price_total()` for the
per-person, flat and unpriced cases. Those are exactly the regression guard this group needs — the
whole finding is that the pricing logic was always correct and only the input was broken — so no
new assertions are required there. They must keep passing untouched.

A new pure helper backs the admin badge so the "is this priced?" rule has one definition:

```php
/** True when an amount is a usable price (set and greater than zero). Pure. */
function is_priced($amount): bool
```

asserted against `null`, `''`, `0`, `'0.00'`, `-5` and `12.5`.

The layout and the two admin lists are verified in the browser at 375px and desktop during the
final task, including an activity card with its request form open.

Every `tests/*_logic.php` must end `ALL PASS` except `team_logic.php`, whose two failures reproduce
on `master` and are tracked separately.

## Files touched

| File | Change |
|---|---|
| `admin/tour-edit.php` | Remove the dead `price` input and its write; relabel the numeric field; legacy-value hint |
| `admin/tours.php` | Price and "Offered at" columns; `no price` badge |
| `admin/services.php` | `no price` badge and per-service unpriced count |
| `includes/services.php` | `is_priced()` |
| `css/portal-app.css` | Two-up `.pa-grid`; sub-720px card tightening; `is-open` full-width rule |
| `includes/app/activities.php` | `is-open` class on the expanded card |
| `tests/services_logic.php` | `is_priced()` assertions |

**No migration. No schema change.** `tours.price` is deliberately left in place with its data.

## Out of scope

- **Entering the prices.** This removes the trap that swallowed them and makes every gap visible,
  but filling in the values is the owner's job.
- Dropping the `tours.price` column. Once every tour is priced numerically it becomes dead weight
  and can go, but not in the same change that stops writing to it.
- Housekeeping, maintenance, restaurant and amenities stay free-text — a fault report or a towel
  request is not a purchase.
- The two pre-existing `team_logic.php` failures, including the Nairobi-vs-database timezone bug
  that also affects `admin/gate.php` in production.
