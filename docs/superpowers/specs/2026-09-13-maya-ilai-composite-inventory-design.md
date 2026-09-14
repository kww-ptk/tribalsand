# Maya Ilai — composite inventory design

**Date:** 2026-09-13
**Status:** approved, awaiting implementation plan
**Scope:** the availability engine only. Seasonal rates are a separate follow-on project (see "Follow-on work").

## Problem

Maya Ilai sells eight accommodation products, but they are not eight independent
room types. They are eight different ways of slicing the same eight villas.

Physical inventory is **8 villas × (2 Double + 1 Bunk + 1 Living/Kitchen) + 8 Studios**
— forty resources, not eight room types. Every product except the Studio consumes a
subset of the components of **one** villa.

Two properties of the current engine make this inexpressible:

- **`holds.unit_id` is singular.** One booking binds exactly one unit. A
  Three-Bedroom Villa booking consumes four components.
- **`room_conflict_unit_ids()` has exactly one rule** — the venue-wide
  `is_entire_place` buyout. It cannot say "Villa 3's Double blocks Villa 3's
  Two-Bedroom Suite but not Villa 5's."

Creating the eight products as ordinary rooms today would sell the same bed twice.

## The product catalogue

`double` means "either free double in that villa" — it resolves to `double_a` or
`double_b` at allocation time.

| Product | Components | Incl. | Max | High | Standard |
|---|---|---|---|---|---|
| Private Bunk Room | `bunk` | 3 | 6 | $150 | $120 |
| Double Room | `double` ×1 | 2 | 2 | $350 | $280 |
| Studio | *(own unit)* | 2 | 2 | $390 | $312 |
| Two-Bed Family Room | `double` + `bunk` | 5 | 8 | $500 | $400 |
| One-Bedroom Suite | `double` + `living` | 2 | 2 | $750 | $600 |
| Two-Bed Family Suite | `double` + `bunk` + `living` | 5 | 8 | $900 | $720 |
| Two-Bedroom Suite | `double` ×2 + `living` | 4 | 4 | $1,100 | $880 |
| Three-Bedroom Villa | all four | 7 | 10 | $1,170 | $936 |

Source of truth: the internal rate tool (`DEFAULTS` in the Maya Ilai Rate & Quote
Tool HTML). Its five primitives — `double 350`, `bunk 150`, `studio 390`,
`living 400`, `villa 1170` — compose into all eight products above. Standard season
is High × 0.8 (`standardReduction: 20`).

`rooms.capacity` takes the **Max** column, because that is what the guest-count
filter in `ts_search_availability()` compares against. The Incl./Max distinction
exists only to drive the $45 extra-guest supplement, which is out of scope (below).

The prices above are the products' **`rooms.price_amount`** defaults — the figure
shown when no override applies. Populating the `rates` table with dated seasonal
ranges is the follow-on project, not this one.

## Approach

Three approaches were considered.

**A. Full resource model** — a `resources` table (40 rows), a `room_resources`
bundle map, and a `hold_resources` join for multi-resource holds, with
`availability_blocks` keyed on `resource_id`. Clean and fully general, but it
breaks `holds.unit_id`, which is read by the Gantt, the iCal importer, the finance
ledger (`bookings.block_id`), check-in and the guest portal.

**B. Villa-as-unit, components on the block** — **chosen**. Units stay physical:
8 villas + 8 studios. A hold still binds exactly one unit, so `holds.unit_id` never
changes. The block records which components of that villa it consumes.

**C. Villa-state model** — a villa is free / shared / exclusive. Rejected: it
cannot express "a One-Bedroom Suite is sold in Villa 3 and Double B is still
sellable", which is the required behaviour.

B was chosen because it buys correct behaviour for a fraction of A's blast radius,
and because the same-villa constraint falls out for free — everything is scoped to
one unit.

## Data model

```sql
ALTER TABLE availability_blocks ADD COLUMN components TEXT[] NULL;
```

`NULL` means "the whole unit". Every existing block at every property keeps its
current meaning, so this is a no-op for Zuri, Maya Kobe and the rest. Only Maya Ilai
villa blocks populate it, with a subset of `{double_a, double_b, bunk, living}`.
Studio blocks stay `NULL`.

The product→component map lives in **`includes/maya-ilai-inventory.php`**, not the
database. It is a fixed physical fact about the building, and keeping it in code
makes it unit-testable without a DB connection.

**Pre-migration safety is enforced by ordering, not by a runtime guard.** The new
code path fires only for rooms whose slug is in `mi_product_map()`, and those rooms
exist only after the catalogue migration — so on a database without the column,
nothing reaches it. To make that ordering impossible to get wrong, the catalogue
migration **aborts if `availability_blocks.components` is missing**. This is
deliberately different from the `*_supported()` pattern used elsewhere: those guard
features that should degrade gracefully, whereas a Maya Ilai catalogue without the
column would silently oversell, which must fail loudly instead.

## Availability algorithm

For product P over dates D:

1. If P is the Studio, use the existing `find_available_unit()` path unchanged.
2. Otherwise order the 8 villas **most-occupied-first** (pack tight), so whole
   villas stay intact for as long as possible. Occupancy is counted as the number of
   components already blocked over D. **Ties break by villa number ascending**, so
   allocation is deterministic and reproducible when staff are debugging.
3. Skip **ring-fenced** villas unless P is the Three-Bedroom Villa. When P *is* the
   Three-Bedroom Villa, ring-fenced villas are tried **first** — consuming a
   reserved villa leaves the open villas available for component sales.
4. Return the first villa where every component P requires has no overlapping block.
   `double` resolves to whichever of `double_a`/`double_b` is free, lower letter
   first.

A booking writes one `availability_blocks` row against that villa's unit, carrying
its component set.

### Ring-fencing

Two of the eight villas never accept component bookings — they are held for
whole-villa sales. Stored as a `maya_ilai_reserved_villas` setting (default `2`),
editable in Admin → Properties, so it can be tuned seasonally without a deploy.

The reserved villas are the **last N by sort order** (with N=2: Villa 7 and Villa 8).
Taking them from the end means raising or lowering N leaves the identity of the
already-reserved villas unchanged, so staff keep a stable mental model.

A ring-fenced villa accepts **only** the Three-Bedroom Villa product — not the
suites. This is a deliberate simplification: admitting suites would idle the
remaining bedrooms in a villa that exists specifically to serve groups.

## Code changes

- `includes/maya-ilai-inventory.php` — new. The component map, the villa ordering,
  the ring-fence rule, and component-intersection helpers. Pure functions, no I/O.
  Two rules in here are load-bearing and fail **closed** by design, both after
  review found them failing open:
  - `mi_resolve()` returns `null` for an empty pattern and for any component
    outside the closed `double`/`bunk`/`living` vocabulary. An unknown pattern must
    never resolve to "fits, consumes nothing".
  - `mi_block_taken_components()` is the **only** place that reads a stored block's
    component set, because it owns the NULL-means-whole-unit rule.
    `mi_pg_array_decode()` returns `[]` for NULL, which reads as "nothing is taken"
    and would oversell the villa.

  `mi_order_villas()` derives the villa total from the list it is passed, so callers
  must hand it the complete villa set; and it ranks villas by position after sorting
  on `sort_order`, never on the raw column, which is `NOT NULL DEFAULT 0` and so
  cannot be assumed dense or 1-based.
- `find_available_unit()` (`includes/db.php`) — a Maya Ilai branch; all other
  venues route through the existing query untouched.
- `room_conflict_unit_ids()` — must not apply the venue-wide `is_entire_place`
  rule to Maya Ilai, whose exclusion is per-villa.
- Admin Gantt — one row per villa, with the component detail shown inside the bar.
- Admin → Properties — the `maya_ilai_reserved_villas` field.

`holds`, the iCal importer, `bookings.block_id`, check-in and the guest portal need
no changes, because a booking is still exactly one unit.

## Migration and rollout

1. Add the `components` column (no-op for existing data).
2. **Check production for live holds on the three current Maya Ilai rooms**
   (`maya_ilai`, `maya-ilai-studio`, `maya-ilai-garden-room`) before retiring them.
   The local database is a separate Postgres and is not evidence about production.
3. Rebuild the Maya Ilai room catalogue to the eight products and 16 units.
4. Apply rates (follow-on project).

The migration must be reversible up to step 3, and must not run destructively while
live holds exist on the retired rooms.

## Out of scope

`rates` holds one flat nightly price per room per date range. The following exist in
the rate tool and have **no home in the schema**. They stay in the offline quote
tool, and are not part of this project:

- $45 extra-guest supplement (bunk and villa, beyond the included count)
- $20 per-person, per-stay Eco-Resort Fee
- −15% single-occupancy discount (Double Room only)
- Availability bands (−15% / −5% / 0 / +15% by units remaining)
- Group tiers (5% / 7.5% / 10% at 10 / 20 / 30 guests, min 3 nights)

The availability bands are worth noting twice: they price by *remaining inventory*,
which this engine will now know accurately for the first time. That makes them a
plausible next project rather than a permanent exclusion.

## Follow-on work

**Seasonal rates.** The eight products priced across a season calendar. The rate
tool carries only a High/Standard switch and no dates; the agreed source for the
calendar is Maya Kobe's 2026 windows (`db/migrations/rates_maya_kobe_2026.sql`).

Maya Kobe has three seasons and Maya Ilai has two, so the agreed mapping is:

| Maya Ilai | Maya Kobe windows | Nights |
|---|---|---|
| **High** | Peak only — 1–10 Jan, 26 Mar–4 Apr, 20–31 Dec | 32 |
| **Standard** | Mid **and** Standard — everything else | 333 |

This is deliberate and was chosen with the consequence stated: 333 nights a year,
including January–March and August, sell at the −20% Standard rate, so the
Three-Bedroom Villa is $936 for all but 32 nights. Revisit if the intent was for
the Mid windows to hold the full rate.

**Migration path to A.** If another property later needs true general resources,
B's component sets migrate into A cleanly: each distinct `(unit_id, component)` pair
becomes a `resources` row, each block's component set becomes `hold_resources` rows,
and the PHP component map becomes `room_resources`. No data is lost in the
translation, which is why B is safe to build first.

## Tests

`tests/maya_ilai_inventory.php`, following the existing pure-logic-plus-rolled-back-
transaction pattern:

- Component intersection for all eight products.
- The same-villa constraint — components never satisfied across two villas.
- **The One-Bedroom Suite case:** a `{double_a, living}` block leaves Double Room,
  Private Bunk Room and Two-Bed Family Room sellable in that villa, and blocks a
  second One-Bedroom Suite, the suites and the full villa.
- Ring-fence: component products skip reserved villas; the full villa does not.
- Pack-tight ordering prefers the most-occupied villa that fits.
- **No regression:** `components IS NULL` behaves exactly as today, asserted against
  a Zuri or Maya Kobe booking.
