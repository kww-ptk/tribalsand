# Inventory & Assets — one inventory database under POS, properties, staff and central stock

**Date:** 2026-09-27 · **Status:** design approved, awaiting spec review
**Depends on:** POS (`add_pos.sql` → `add_pos_job_types.sql` → `add_pos_v2.sql`)

## 1. Goal

One inventory database that every interface reads and writes — POS, asset
management, property inventory, employee assets and central stock. No interface
keeps its own quantity. Today the POS keeps `pos_items.stock_qty` for ONE outlet;
that becomes a view onto the shared inventory.

Owner decisions (brainstorm, 2026-09-27):

| Question | Decision |
|---|---|
| Tracking | **Both** — counted items (plates) and optional per-unit serial tracking (laptops, phones) |
| Location depth | **Property + optional sub-areas** ("Villa 3", "Kitchen") |
| POS data | **Not live yet** — POS stock can be restructured; seeded items are carried over |
| Count mismatch | **Flag for a manager** — counting never changes stock; a resolution does |
| "Expected" | **System qty** (live ledger balance) **+ par level** (restock target) |
| Count schedule | **Manual, per location** — manager/admin sets how often each check is due |
| Architecture | **Approach A** — new `inv_*` core; `pos_items` becomes a sellable listing on top |
| Accounting | **Separate project, later** — this design only keeps the seam (§8) |

## 2. Data model — migration `add_inventory.sql` (after `add_pos_v2.sql`, idempotent)

### `inv_items` — the one catalogue
`id, name, item_type, category, sku, image_key, icon, tracking, unit_label,
replacement_value NUMERIC(12,2), currency CHAR(3) DEFAULT 'KES', consignor_id → pos_consignors,
low_stock_at INT, is_active, created_at, updated_at`

- `item_type` ∈ `sellable | operational | employee | consignment | spare` — a
  **classification** for filters and defaults, not a constraint on where it may sit.
- `tracking` ∈ `qty | serial`.
- `category` is free text with suggestions (Kitchen, Linen, Electronics, Furniture, Retail…).
- `icon` is a key from the existing admin icon set; `image_key` uses the existing
  storage upload path (same as POS item photos).

### `inv_locations` — a tree
`id, parent_id → inv_locations, kind, name, venue_id → venues, pos_outlet_id → pos_outlets,
hr_staff_id → hr_staff, owner_venue_id → venues, count_every_days INT NULL,
count_assignee_id → admin_users, last_counted_at, is_active, sort_order`

- `kind` ∈ `store | property | area | outlet | person`.
- `property` has `venue_id`; `area` has a `parent_id` (a property); `outlet` has
  `pos_outlet_id` (UNIQUE); `person` has `hr_staff_id` (UNIQUE), created on first assignment.
- `owner_venue_id` is the accounting seam (§8): every location must resolve to an
  owning property — a property/area via its venue, an outlet via its outlet's venue,
  a person via their `hr_staff.venue_id`, a store via `owner_venue_id` (optional in v1).
- `count_every_days` NULL = manual only; 1 = daily, 7 = weekly, any N. Due date =
  `last_counted_at + N days` (Nairobi-local).

### `inv_balances` — quantity per item per location (cached)
`item_id, location_id, qty INT, par_qty INT NULL, PRIMARY KEY (item_id, location_id)`
Written **only** by `inv_move()` in the same transaction as the move. `par_qty` is
edited directly (it is a target, not a quantity).

### `inv_moves` — the only way a quantity changes (append-only)
`id, item_id, qty INT CHECK (qty > 0), from_location_id NULL, to_location_id NULL,
reason, unit_value, value, currency, asset_id → inv_assets, pos_sale_id → pos_sales,
count_line_id → inv_count_lines, consignor_id, consign_pct, consignor_cost,
note, admin_user_id, created_at`

- `CHECK (from_location_id IS NOT NULL OR to_location_id IS NOT NULL)`.
- `from = NULL` → stock **enters** (receive, found). `to = NULL` → stock **leaves**
  (sale, broken, missing, stolen, written_off).
- `reason` ∈ `receive | transfer | assign | return | sale | void | found | broken |
  missing | stolen | written_off | replaced | opening`.
- `unit_value`/`value`/`currency` are **snapshots** at move time — later price edits
  never rewrite a loss or a report.
- Consignment terms per delivery (POS v2) live here on the `receive` move.
- Rows are never updated or deleted; corrections are new moves.

### `inv_assets` — serial-tracked units
`id, item_id, serial, tag, condition, status, location_id, purchase_date,
purchase_value, notes, created_at` — `status` ∈ `active | written_off | sold`.
A serial unit also counts in `inv_balances` (qty 1 per unit); its moves carry `asset_id`.

### `inv_counts` / `inv_count_lines` — the check
- `inv_counts`: `id, location_id, counted_by → admin_users, started_at, submitted_at,
  status` (`open | submitted | resolved`).
- `inv_count_lines`: `id, count_id, item_id, expected INT` (snapshot at count start),
  `counted INT, resolution NULL, resolved_by, resolved_at, balance_at_resolve`.
  `resolution` ∈ `missing | broken | stolen | found | recount | accepted`.

### POS links
- `pos_items.inv_item_id → inv_items` (NULL for services/tours = untracked).
- `pos_outlets.inv_location_id → inv_locations`.
- `pos_items.stock_qty` and `pos_stock_moves` are **no longer written**; kept one
  release for rollback, then dropped in a follow-up migration.

### Seeding in the migration
Main stock (`store`); one `property` location per published venue; one `outlet`
location per POS outlet. Every stock-tracked `pos_items` row → an `inv_item`
(`sellable`, or `consignment` when it has a consignor), linked, with an `opening`
move for its `stock_qty` into its outlet's location.

## 3. Integrity rules

1. **ONE write path — `inv_move()`** in `includes/inventory.php`. Every receive,
   transfer, assignment, sale, void, loss and count resolution goes through it,
   including the POS. Nothing else writes `inv_balances`.
2. **Locking:** `inv_move()` locks the `(item, from)` and `(item, to)` balance rows
   `FOR UPDATE` in ascending `location_id` order (no deadlocks), checks, writes the
   move, updates both balances. It opens a transaction only when none is open
   (`db()->inTransaction()`, the `rates_apply_ranges()` pattern) so callers and tests
   can wrap it.
3. **Compound actions are one transaction:** Replace (= loss move + transfer from
   Main stock), Restock-to-par (N transfers), count resolution batches.
4. **No negative stock**, except a POS sale of a `pos_items` row with
   `allow_negative` (till keeps selling when a delivery wasn't entered). A negative
   outlet balance renders red.
5. **POS sale** = a `sale` move from the **owning** outlet's location (cross-sold
   lines deduct where the item lives), `to = NULL`, `pos_sale_id` set, inside
   `pos_complete_sale_tx()`'s transaction. **Void** = matching `void` moves back,
   derived from the sale's own `sale` moves. Sale and void remain the only POS
   stock paths.
6. **Serial units** move qty 1 with `asset_id`; the unit must be at `from`. The
   unit's `location_id`/`status`, the balance and the move are written together.
   Invariant (tested): for a serial item, balance at L = count of active units at L.
7. **Counts never move stock.** `expected` is snapshotted at count start. Resolving
   a line writes the move for `expected − counted` (loss) or `counted − expected`
   (found) — **refused, with "recount", if the live balance changed since the count
   started**. A line resolves once; `recount` closes it with no move; `accepted`
   closes a zero-difference line.
8. **Scoping:** every action re-checks the location's owning venue against
   `admin_venue_ids()` server-side; client-posted location/item ids are never trusted.
9. **Money is never summed across currencies** — totals are keyed by currency (the
   reports pattern). POS revenue stays in `pos_sales` and never mixes with asset value.
10. **Pre-migration safe:** every surface checks `inv_supported()`
    (`information_schema` probe, not a failing SELECT); without it the POS keeps its
    v1/v2 `stock_qty` behaviour.

## 4. Pages

Admin sidebar group **Inventory** (owner + manager).

| Page | Purpose |
|---|---|
| `admin/inventory.php` | **Central list.** Row: icon/photo, name, type, total qty, location breakdown (`Main 30 · My Amani 20 · …`), unit + total value. Filters: property, location, team member, type, status (**in stock** = store/property/area/outlet; **assigned** = person; **sold / written off** = moves in a date range, qty + value). `dt_*` toolkit. |
| `admin/inventory-item.php` | Edit item + photo; "where is it" table summing to the total; serial units; move history. Actions: **Receive, Transfer, Assign, Return, Report loss** (broken/missing/stolen/written off), **Replace**. Each shows the value before confirming. |
| `admin/inventory-locations.php` | Manage the tree (properties → areas, Main stock); per location: count schedule (`count_every_days`) + responsible person. |
| `admin/inventory-location.php` | **Property inventory.** Per item: system qty, par, shortfall ("needs 3"), value; last/next count; **Restock to par** from Main stock. |
| `admin/inventory-count.php` | **The check — phone/tablet first.** One card per item: photo, name, Expected, big ± numeric field; card turns red on mismatch. Submit saves; stock unchanged. |
| `admin/inventory-counts.php` | **Discrepancy queue** for managers: per gap choose missing/broken/stolen/found/recount/accepted; loss value shown; resolving writes the move. |
| `admin/employee.php` | New **Assigned assets** card (beside Documents): what the person holds, serial, value, since when; Assign / Return. |

Due/overdue counts surface on **My Work** (staff with that location in scope or
assigned) and on the manager's Front Desk.

**POS changes:** the till UI is unchanged. `admin/pos-items.php` links a sellable
product to an `inv_item` or creates one inline. `admin/pos-stock.php` becomes the
outlet's inventory view: receive a delivery (with consignment terms) and transfer in
from Main stock. `pos_low_stock()` reads the outlet's balance vs `inv_items.low_stock_at`.

**Access:** owner = all. Manager = own properties (`require_manager()` + scope
re-check per action). Ops/villa staff = the count screen only, for locations in
their venue or assigned to them. All UI uses the house design system (no native
chrome: `.eselect`, `.inp`, `.dp-btn`, `.btn-icon`).

## 5. POS switch-over

Rewire to `inv_move()`: `pos_stock_move`, `pos_stock_receive`, `pos_stock_adjust`,
`pos_stock_restore`, the stock step in `pos_complete_sale_tx`, `pos_low_stock`,
and the catalogue payload's `stock` field (reads the outlet balance). The till's
request/response contract does not change.

## 6. Testing

- **`tests/inventory_logic.php`** — pure: move validation (from/to/reason rules),
  count difference → proposed move, due-date math (Nairobi), per-currency totals,
  Replace/Restock composition. DB block (one rolled-back transaction): balances ==
  Σ moves; transfer can't go negative; serial invariant; count resolves once and
  refuses on a changed balance; scoped manager can't act on another property.
- **`tests/pos_logic.php`** extended: a sale deducts the outlet's inventory
  balance, a void restores it, `stock_qty` is not consulted. Existing 196 checks pass.

## 7. Rollout

Apply `add_inventory.sql` locally and to production via `/admin/migrate.php`
(after the POS migrations). Add an **Inventory & Assets** section to `CLAUDE.md`
(one write path, POS listing over `inv_item`, counts flag not adjust, never mix
currencies, owning-venue seam).

## 8. Accounting seam (not built here)

Accounting (legal entities, KRA PINs, bank accounts, purchases, eTIMS) is a
separate project. This design keeps it cheap to add: every location resolves to an
owning venue; every move is append-only and carries its value snapshot. Accounting
will add `companies`, map venues to them, and read `inv_moves`, `pos_sales` and
`bookings` — a cross-venue transfer then becomes an inter-company movement with no
inventory rework.

## 9. Out of scope (v1)

Barcode/QR scanning, purchase orders and suppliers (beyond consignors),
depreciation, automatic reordering, accounting postings.
