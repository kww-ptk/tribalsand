# Inventory — Shipments: import a supplier list, receive on a phone, put away

> **Scope reduced (2026-09-28, owner):** built = shared stores (§2, shares as
> `inv_locations.share_venue_ids INT[]`, migration `add_inventory_stores.sql`) + the
> Excel reader/parser (§4.1) + a one-step **Import items** page (items only, no
> stock, no preview editing). Shipments, receiving, damage and claims (§3 tables,
> §4.2, §5–§7) were dropped as over-engineering.

**Date:** 2026-09-28 · **Status:** design approved, awaiting spec review
**Depends on:** Inventory & Assets (`add_inventory.sql`, Plans 1 / 2A / 2B, on master)
**First use:** Maya Ilai fit-out — "Inventory List Maya Ilai 4 Containers Shipment 1.xlsx"
(4 containers, 2 master lists, ~200 lines, ~4,300 pieces, no prices), arriving now.

## 1. Goal

Turn a supplier's shipment list into inventory without typing it: import the Excel,
check a preview, count what arrives on a phone (good / damaged / note), and put the
good stock into a store. Keep the ordered-vs-received record for supplier and
insurance claims. Costs come later, when the invoice arrives.

Owner decisions (2026-09-28):

| Question | Decision |
|---|---|
| Where stock lands | A new store **"TD Main Stock"**, owned by **Tribal Dunes** |
| Who uses it | **Managers of the complex** — Tribal Dunes, Maya Ilai, Off-Duty (+ owner) |
| Off-Duty | A **new hidden property** (venue, `is_published = FALSE`) — already added by the owner |
| Same product under several codes | **Merge by name**, editable in the preview (rename splits a line off) |
| Sets ("Outdoor Dining Set (9 pce)") | **Counted as sets**, as on the master list |
| Receiving records | **Good + damaged + note/photo** per line; damaged never goes on the shelf |
| Values | **None now** — cost columns exist, entry is a later step |

## 2. Locations

### 2.1 More than one store, shared stores
Today there is exactly one `kind='store'` (Main stock, `venue_id` NULL), enforced by a
unique partial index (`ON CONFLICT (kind) WHERE kind = 'store'`).

- Drop that one-store index. Main stock is identified by a new column
  `inv_locations.is_main BOOLEAN NOT NULL DEFAULT FALSE` with a unique partial index
  `WHERE is_main`; the migration sets it on the existing store.
  `inv_store_location_id()` reads `WHERE is_main`.
- A store may carry an **owning venue** (`venue_id`, the accounting seam — TD Main
  Stock → Tribal Dunes) and a list of **sharing venues** in a new table
  `inv_location_shares (location_id → inv_locations ON DELETE CASCADE, venue_id → venues
  ON DELETE CASCADE, PRIMARY KEY (location_id, venue_id))`. Only `kind='store'` rows
  have shares (enforced in the writer).
- **Scope rule, one definition:** a location's *venue set* = `{venue_id} ∪ shares`.
  - Visible (`inv_location_visible`, `inv_visible_sql`): unchanged for shared (NULL)
    places; for a venue-owned place, visible when the venue set meets the account's
    venues.
  - Moves (`inv_move_in_scope`): a store whose venue set meets the manager's venues
    counts as **theirs** for that move (so a Maya Ilai manager may move TD Main Stock →
    Maya Ilai, but not TD Main Stock → Zuri, and never from a store they don't share).
  - Settings (`inv_location_editable`): a store's name, owner and shares are
    **owner-only**; its count schedule stays editable by managers of the owning venue.
  - Every caller receives the shares already loaded on the row (`share_venue_ids`,
    int[]), so the three pure functions stay pure.
- **Locations page:** owner gets **Add store** (name, owning property or none, shared
  with — multi-select chips). Stores list under their own heading.

### 2.2 Hidden properties get a location
`inv_ensure_default_locations()` and `inv_visible_venues()` drop the
`is_published = TRUE` filter, so Off-Duty (hidden) gets its property location and
appears in filters. Nothing else about the property changes.

## 3. Data model — migration `add_inventory_shipments.sql` (after `add_inventory.sql`, idempotent)

- `inv_locations.is_main` + `inv_location_shares` (§2.1).
- `inv_shipments`: `id, name, supplier, reference, containers TEXT, expected_on DATE NULL,
  to_location_id → inv_locations (the store it lands in), status ('expected' | 'receiving'
  | 'received' | 'cancelled'), source_filename, created_by → admin_users, created_at,
  received_at`.
- `inv_shipment_lines`: `id, shipment_id → inv_shipments ON DELETE CASCADE, sort_order,
  section TEXT (destination hint, e.g. "Off-Duty"), code, hs_code, description (as on the
  list), item_id → inv_items, qty_expected INT > 0, qty_good INT ≥ 0, qty_damaged INT ≥ 0,
  note TEXT, photo_key, unit_cost NUMERIC(12,2) NULL, cost_currency CHAR(3) NULL,
  updated_by, updated_at`.
  There is deliberately **no** CHECK `qty_good + qty_damaged <= qty_expected` —
  over-delivery is allowed after a confirmation (§5).
- `inv_moves.shipment_line_id → inv_shipment_lines` (nullable), set on every receive
  move a shipment makes, so the ledger links back to the line.
- New `inv_items` rows get a category from the preview; `item_type` stays within the
  existing set (décor/linen/furniture/appliances = `operational`, consumables = `spare`).

## 4. Import

`admin/inventory-shipments.php` (list + **Import Excel**) → `admin/inventory-shipment.php`
(one shipment). Owner + managers whose venues meet the target store's venue set.

### 4.1 Reading the file — `includes/inventory-shipment-import.php` (pure)
- `includes/xlsx-reader.php` gains `xlsx_read_sheets($path)` → `[sheet name => rows]`,
  where each cell is `['v' => string, 'b' => bool]` (value + bold), sheet names from
  `workbook.xml` + its rels (the existing `xlsx_read_rows()` = first sheet, plain
  strings, unchanged for the booking importer).
- A **master-list sheet** is one whose header row has `Item No`, `Qty` and
  `Description` and **no** `Length`/`Weight` column; packing-list sheets are skipped.
- Row rules:
  - `Containers` row → the sheet's container names (joined into `inv_shipments.containers`).
  - A row with code + numeric qty + description → a **line**.
  - A row with only a description is either a **section** (destination hint for the
    lines below: "Villas", "Off-Duty") or a **continuation** of the line above (the
    linen spec rows: "Microfibre King 183cm…", "T200 100% Cotton Percale"). In the real
    file the only difference is formatting — sections are **bold** — and "Studio Rooms"
    follows a line directly, so position alone can't tell them apart. The reader
    therefore also returns each cell's bold flag (`styles.xml` → `cellXfs` → `fonts`):
    bold description-only row = section; plain one after a line/continuation =
    continuation, appended to that line's description with " · ". Fallback for a file
    with no bold at all: a description-only row after a blank row = section, otherwise
    continuation. The preview shows sections as headings, so a misread is visible
    before Confirm.
  - HS codes are normalised (`9404,90,90` → `9404.90.90`; numeric cells → string).
  - Codes are trimmed (`"OV003/"` → `OV003`, `"OS001 "` → `OS001`).
- Merge key = normalised description (lower-case, collapsed spaces, trailing
  punctuation removed). Lines keep their own code, section and qty; the **item** is shared.

### 4.2 Preview (PRG, nothing written until Confirm)
- Parsed lines are held in the session (keyed by a random token, 1 h) — the file is
  not stored.
- Shows each proposed **item** with its total and its lines
  (e.g. "Woven Basket Medium · 40 — V003 Villas 16, V023 Villas 16, S004 Studios 8").
- Per proposed item: **name** (edit = rename; a line renamed to a new name becomes its
  own item), **match an existing item** (search picker), **category** (suggestions),
  **type** (`operational` / `spare`), **serial** toggle (appliances), **unit** label.
  Auto-suggest: category from keywords (Linen, Cushions & throws, Rugs, Décor,
  Furniture, Lighting, Appliances, Restaurant, Consumables); serial pre-ticked for
  fridges, washing machines, dryers, microwaves, ovens.
- Shipment fields: name (default from the file name), supplier, reference, expected
  date (`.dp-btn`), **lands in** (store picker — TD Main Stock).
- **Confirm** (one `inv_tx()`): creates the missing items, the shipment
  (`status='expected'`) and its lines. **No stock moves.**
- Re-importing the same file is refused when a non-cancelled shipment already has the
  same `source_filename` + line count (ask to open it instead).

## 5. Receiving — `admin/inventory-receive.php?shipment=<id>` (phone/tablet first)

Same look as the count screen: one card per line — code, description, section hint,
**expected**, **received so far**, and three inputs: **Good**, **Damaged**, note, plus a
photo button for damage. Filter chips: All / Not yet / Short / Damaged; search box.

- **Rounds.** Containers arrive on different days. A save posts only the cards that
  changed; each posted value is the **new total** for that line (not a delta), so a
  retry or double tap can't double-count. The server computes the delta against the
  locked line.
- **Save** (one `inv_tx()`, lines locked in id order, then balance pre-lock
  `inv_lock_balances()` — the global lock order): for each line, `Δgood = new_good −
  old_good`:
  - `Δgood > 0` → `inv_move(reason 'receive', from NULL, to = shipment store,
    qty Δgood, shipment_line_id)`; value = the line's unit cost if set, else the item's
    replacement value (0 today).
  - `Δgood < 0` (a correction) → refused if the store no longer holds it; otherwise an
    `inv_move(reason 'written_off', from store, to NULL, note "Receiving correction")`.
    (Moves are append-only; a correction is a new move.)
  - Damaged counts are stored on the line only — **no move**; damaged items never
    enter usable stock.
- **Serial items:** a card for a serial item asks for one serial per good unit
  (lines of text inputs, can be left blank = "no serial yet"); each unit is created with
  `inv_asset_create()` at the store. Correcting serial good-counts downward is refused
  (write the unit off from its item page).
- **Over-delivery:** good + damaged above expected needs a confirm tick on that card
  ("More than ordered — are you sure?"); the server refuses it without the tick.
- Status moves `expected → receiving` on the first save; **Mark received** (button,
  confirm dialog) sets `received`. A received shipment can still be corrected
  (manager), and reopens to `receiving` if it is.
- A draft of unsaved inputs is kept on the device (the count screen's pattern).

## 6. Shipment page — `admin/inventory-shipment.php?id=<id>`

- Header: name, supplier, containers, lands in, status, progress
  ("1,902 of 4,333 pieces received").
- Lines table grouped by section: code, description (→ item page), expected, good,
  damaged, **short** (`max(0, expected − good − damaged)`), note/photo.
- **Claims report:** only short or damaged lines, with totals; **CSV export** of all lines.
- Actions: **Receive** (opens §5), **Mark received**, **Cancel** (only while nothing
  has been received), **Hand out** (links to the store's stock page, where the existing
  Transfer / Restock-to-par do the distribution — nothing new is built for it).
- The section on each line is shown on the store's stock page as a hint
  ("for: Off-Duty") while the item is still in the store.

## 7. Costs (later — seam only)

`unit_cost` / `cost_currency` stay NULL in v1. The later step ("Enter costs", owner only,
by hand or a second import keyed on code + description) fills them and sets
`inv_items.replacement_value` + `currency` when the item has none. Receive moves already
written keep their value snapshot (0); money is never rewritten.

## 8. Access

- Shipments list / page / receive: owner, and managers whose venues meet the target
  store's venue set (§2.1). Receiving can also be done by staff whose venues meet it
  (same audience as counting a place); the claims report and Mark received / Cancel are
  owner + manager.
- Import: owner + those managers. Adding a store and its shares: owner only.
- Every POST re-checks the shipment's store scope server-side; client-posted ids are
  never trusted. CSRF on every form; JSON-free (PRG).
- Sidebar: **Shipments** under Inventory (owner + manager).

## 9. Testing

- `tests/inventory_shipments_logic.php` — pure, against a fixture **copied from the real
  file** (`tests/fixtures/shipment-maya-ilai.xlsx`, no personal data in it): both master
  sheets found, packing lists skipped, 114 + 85 lines, sections, containers, linen
  continuation joined, HS/code normalisation, merge by name (Woven Basket Medium = one
  item, 40), rename splits, category/serial suggestions.
- DB block (rolled back): confirm creates items + lines and **no moves**; two receive
  rounds post totals and write exactly the deltas; a repeated save writes nothing;
  damaged writes no move; downward correction writes a written-off move and is refused
  when the store is short; over-delivery refused without the tick; serial units created.
- Scope: a Maya Ilai manager sees TD Main Stock and may move it to Maya Ilai, not to
  Zuri; a Zuri manager sees neither the store nor the shipment; Off-Duty (hidden) has a
  property location.
- Existing suites (`inventory_logic`, `inventory_views_logic`, `inventory_counts_logic`,
  `pos_logic`, `booking_import_logic`) still pass — the one-store change and the reader
  addition touch them.
- Browser check at 375 / 768 / desktop for the preview, receive and shipment pages.

## 10. Rollout

Run `add_inventory_shipments.sql` locally and on production (`/admin/migrate.php`),
create **TD Main Stock** (owner → Locations → Add store: Tribal Dunes, shared with
Maya Ilai + Off-Duty), import the Excel, receive on unloading day. Update `CLAUDE.md`
(Inventory section) and the Help guide in the same change.

## 11. Out of scope (v1)

Cost entry (§7), purchase orders / reordering, packing-list (crate/box) tracking,
landed-cost allocation (freight, duty), barcode scanning, automatic distribution to
villas by the section hint.
