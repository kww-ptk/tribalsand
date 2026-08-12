# Wellness pricing & two-up mobile cards — design

**Date:** 2026-08-12
**Status:** approved, ready for planning
**Scope:** Group D of a four-group request — the last outstanding item, plus a mobile layout change

## Problem

### 1. The pricing machinery works but nothing is priced

Every piece already exists end to end: `service_options` with an owner editor (`admin/services.php`),
tour prices with an admin field (`admin/tour-edit.php:284`), per-person totals in the portal, and a
price snapshot written onto the bill at request time. The portal deliberately renders a price only
when it is greater than zero (`includes/app/_services.php:46`, `activities.php:44`).

The reason no price is ever visible is simply that none has been entered:

```
service_options — all 8 rows:  price_amount = 0.00
published tours — all 15 rows: price_amount = NULL
```

Nothing in the admin signals that. The Service pricing page shows a `0` in a number input, which
looks like a deliberate "free", and the Tours list has no price column at all — so 23 unpriced
items are invisible until a guest sees a bare label.

### 2. Sellable services have no priced catalog

`service_options.service` is CHECK-constrained to `laundry` and `transfer`. Services a property
actually sells — massage and similar treatments — have nowhere to live. The guest Requests grid
offers only free-text tiles for everything else, so a guest cannot see what is on offer or what it
costs, and staff get an unstructured message instead of a priced line.

### 3. Browse cards are one-per-row on a phone

`.pa-grid` (`css/portal-app.css:98`) is `grid-template-columns:1fr` until the 720px breakpoint. It
drives the Activities list and the What's on board — exactly the two browse-style screens where a
guest is scanning options. On a 375px phone that is a lot of scrolling past 120px-tall images.

## Decisions taken

| Question | Decision |
|---|---|
| Which services get a priced catalog | One new catalog for sellable services — massage and similar. Keyed `wellness`, shown as "Wellness & spa" |
| Empty prices | Flag unpriced items in admin. Do **not** hide them from guests |
| Two-up mobile | `.pa-grid` — Activities and What's on |

**On the naming.** The requester asked for "only the services, like massage etc", which matches
their earlier (then deferred) request for "wellness activities … with their price". This builds one
catalog rather than splitting across housekeeping/restaurant/maintenance/amenities — those stay
free-text, because a fault report or a towel request is not a purchase.

**On not hiding unpriced options.** A zero price legitimately means "free" for some items, so
hiding them would remove working options. The admin badge makes the gap visible instead.

## Architecture

### 1. The `wellness` catalog

Migration `db/migrations/add_wellness_services.sql`:

- Widen `service_options_service_check` to include `wellness`.
- Widen `booking_addons_kind_check` to include `wellness`.
- Seed a starter set at price 0 (`Massage`, `Couples massage`, `Facial`, `Manicure`, `Pedicure`,
  `Yoga session`), only when no wellness rows exist — mirroring how `add_service_options.sql`
  seeded laundry and transfer. Gives the owner rows to price rather than a blank page.

Both CHECK rewrites follow the existing `DROP CONSTRAINT IF EXISTS` / `ADD CONSTRAINT` pattern from
`add_events.sql`, so the migration is re-runnable.

**Code touchpoints, all mirroring laundry:**

| File | Change |
|---|---|
| `includes/services.php:7` | `fetch_service_options()` whitelist gains `wellness` |
| `admin/services.php:13` | `$SERVICES` gains `'wellness' => 'Wellness & spa'` — the whole editor (add / rename / price / activate / reorder) then works with no further change |
| `includes/app/_services.php` | A `wellness` tile with an icon and a priced `<select>`, identical in shape to laundry |
| `api/booking-addon.php:61` | `wellness` joins the `transfer`/`laundry` branch that validates the option id and snapshots its price |

**One thing in that branch does not scale and must change.** It currently resolves the posted
field name with a ternary:

```php
$optId = (int)($data[$kind === 'laundry' ? 'service' : 'transfer'] ?? 0);
```

Laundry posts `service`, transfer posts `transfer` — a two-way ternary cannot express a third.
It becomes an explicit map, which also documents the coupling between each tile's `<select name>`
and its kind:

```php
// Each priced tile posts its option id under its own field name.
$OPTION_FIELD = ['laundry' => 'service', 'transfer' => 'transfer', 'wellness' => 'wellness'];
```
| `includes/booking.php` `_itin_map_kind()` | `wellness` maps to `activity`, not the `note` default, so a booked treatment appears properly on the guest's day plan |

`default_assignee_for()` has no wellness job type, so these requests stay unassigned for a manager
to route — the same as `restaurant` today. No change needed.

### 2. Unpriced is visible in admin

**`admin/services.php`** — each row whose `price_amount` is not greater than zero gets a
`no price` badge next to its input, and each service card's header count becomes
`N active · M total · K unpriced`, with the unpriced part shown only when non-zero.

**`admin/tours.php`** — the list gains a **Price** column showing the formatted price, or the same
`no price` badge when `price_amount` is NULL or zero. This is the bigger gap of the two: the tours
list currently gives no indication of price at all.

Presentation only. No query, guest-facing or pricing-logic changes.

### 3. Two-up browse cards on mobile

`.pa-grid` becomes `repeat(2, minmax(0,1fr))` at all widths; the existing 720px rule already
widens it further on desktop and is unchanged.

At 375px each column is roughly 165px, so the card is tightened **only below 720px**:

- `.pa-media` height 120px → 92px
- `.pa-card__body` padding 14px 16px → 11px 12px
- `.pa-card__title` 15px → 14px, and `.pa-card__meta` 12px → 11.5px
- `.pa-btn` inside a grid card: padding 11px 14px → 10px 8px, font 14px → 13px

**The open request form is the real constraint.** An activity's form (guest count, date, notes,
total, submit) cannot work in a 165px column. When a card's form is open it spans the full grid
width — `grid-column: 1 / -1` — which is precisely the pattern `.cx-tile[aria-expanded=true]`
already uses for the Requests tiles in `_services.php:24`. The Activities toggle in
`activities.php:93-100` gains an `is-open` class on the card alongside the existing
`form.style.display` switch, and the class is what the CSS keys on.

The What's on board needs no equivalent: its only action is a single Join button, which fits.

## Testing

`tests/services_logic.php` requires only `db.php` and `services.php`, so the catalog assertions go
there:

- `fetch_service_options('wellness')` returns rows and is not rejected by the whitelist.
- `fetch_service_options('nonsense')` still returns `[]`.
- A wellness option round-trips through `fetch_service_option($id)` with its `service` intact.

`_itin_map_kind()` lives in `includes/booking.php`, which `services_logic.php` does **not** load —
so its assertion goes in `tests/portal_logic.php`, which already requires that file:

- `_itin_map_kind('wellness') === 'activity'` (and `'other'` still maps to `'note'`, to prove the
  default did not shift).

Layout is CSS and cannot be unit-tested; it is verified in the browser at 375px during the final
task, including a card with its request form open.

Every `tests/*_logic.php` must end `ALL PASS` except `team_logic.php`, whose two failures reproduce
on `master` and are tracked separately.

## Files touched

| File | Change |
|---|---|
| `db/migrations/add_wellness_services.sql` | New — two CHECK widenings plus a seed |
| `includes/services.php` | Whitelist |
| `includes/booking.php` | `_itin_map_kind()` |
| `admin/services.php` | `$SERVICES`; unpriced badge and count |
| `admin/tours.php` | Price column with unpriced badge |
| `includes/app/_services.php` | Wellness tile + priced select |
| `api/booking-addon.php` | Validate and snapshot a wellness option |
| `css/portal-app.css` | Two-up `.pa-grid` and the sub-720px card tightening |
| `includes/app/activities.php` | `is-open` class on the expanded card |
| `tests/services_logic.php`, `tests/portal_logic.php` | Assertions |

## Out of scope

- **Entering the actual prices.** This makes the gap visible and gives every service somewhere to
  store a price; filling in 23 values is the owner's job and cannot be done from code.
- Housekeeping, maintenance, restaurant and amenities stay free-text.
- The two pre-existing `team_logic.php` failures, including the Nairobi-vs-database timezone bug
  that also affects `admin/gate.php`'s visitor day filter in production.
- Reordering the portal home tabs — dropped by the requester earlier.
