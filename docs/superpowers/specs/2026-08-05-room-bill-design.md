# Room bill — end-of-stay extra charges

**Date:** 2026-08-05
**Status:** Approved design — ready for planning
**Sub-project:** E of five (the last). A, B, D shipped; C (events) still open.

## Problem

Every chargeable request now carries a price snapshot (laundry/transfer/activity) and a status, but there's no way for the manager to **total them into a bill** at checkout. Free-form "make a request" items (e.g. a massage) are accepted with **no price**, so they need pricing at billing time. Ad-hoc charges (minibar, damages) have nowhere to live.

## Goal

A **Bill** tab on the admin booking workspace that lists the booking's confirmed/completed charges, lets the manager price the unpriced ones and add manual line items, shows the total, and prints a clean bill. The charge-to-room payoff.

Non-goals: a guest-facing charges view (manager-only for now); a persisted/locked "settled" bill lifecycle (live bill + print); payment capture.

## Decisions (from brainstorming)

- **Billable = status `confirmed` or `completed`** (what was actually accepted/delivered). Declined/cancelled/requested excluded.
- **Manager-only** (no portal view).
- **Live bill + print** — the tab always reflects current prices + manual charges; "generate" = a print/PDF-friendly view. No saved bill record.
- **Front-desk staff (property-scoped) can use the Bill tab** for their bookings — the workspace already gates access via `staff_can_hold()`.
- **Unpriced confirmed/completed requests appear** in the Bill tab with a "set price" input (flagged), not silently dropped; the **printed** bill lists only lines with a price > 0 (actual charges) plus manual items.

## Data model — migration `db/migrations/add_bill_items.sql` (idempotent)

```sql
-- Ad-hoc bill line items (not tied to a guest request): minibar, damages, etc.
CREATE TABLE IF NOT EXISTS bill_items (
    id         SERIAL PRIMARY KEY,
    hold_id    INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    label      VARCHAR(200)  NOT NULL,
    amount     NUMERIC(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_bill_items_hold ON bill_items (hold_id);
```

Pricing an existing request reuses `booking_addons.price_amount` (already present) — no schema change there.

## Helpers — `includes/booking.php`

- `fetch_bill_lines(int $holdId): array` — the booking's chargeable requests: `SELECT ba.*, t.name AS tour_name FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id WHERE ba.hold_id = :h AND ba.status IN ('confirmed','completed') ORDER BY ba.created_at`. Guarded → `[]`.
- `fetch_bill_items(int $holdId): array` — `SELECT * FROM bill_items WHERE hold_id = :h ORDER BY id`. Guarded → `[]`.
- `bill_total(int $holdId): float` — sum of the bill lines' `price_amount` (null → 0) plus the bill items' `amount`.

## Admin — Bill tab on `admin/booking.php`

- **Tab registration:** add `bill` to the allowed-tabs whitelist and to `$__wtabs` (`… 'bill' => 'Bill'`); include `admin/_ws_bill.php` when `$tab === 'bill'`. Available to owner and property-scoped staff (the page already blocks out-of-scope staff up top). Unlike `details`, `bill` is **not** staff-hidden.
- **POST actions** (each `verify_csrf()` + `audit_log()` + PRG back to `?hold=$holdId&tab=bill`), added to the workspace's POST handler:
  - `bill_set_price` — `addon_id` + `price_amount` (blank → null): `UPDATE booking_addons SET price_amount=:p WHERE id=:a AND hold_id=:h` (hold-scoped so a forged id can't touch another booking).
  - `bill_add` — `label` (≤200) + `amount` (≥0): insert a `bill_items` row for this hold.
  - `bill_del` — `item_id`: `DELETE FROM bill_items WHERE id=:i AND hold_id=:h`.
- **`admin/_ws_bill.php`** renders:
  - **Charges from requests** — a row per `fetch_bill_lines()` item: label (`addon_label`), pax (activities) + date meta, and an inline **price** form (`bill_set_price`, number input prefilled with `price_amount`, Save). Rows with no price are visually **flagged** ("set a price to include").
  - **Other charges** — the `fetch_bill_items()` rows, each with a Delete button, plus an **"+ Add charge"** form (label + amount).
  - **Total** — `bill_total()`, formatted with `format_price()`.
  - **Print bill** button → `admin/bill-print.php?hold=<id>` (new tab).

## Admin — printable bill `admin/bill-print.php`

- `require_login()` + the same staff scope check (`is_staff() && !staff_can_hold($holdId)` → bounce). Standalone page (no admin `_layout`), print-friendly.
- Header: property/venue, guest name, room/unit, check-in–check-out, booking code.
- Itemized table: each request line **with `price_amount` > 0** (label, date, qty/pax, amount) and each manual `bill_item`; then the **Total**. Unpriced/zero lines are omitted from the printed bill.
- A "Print" button (`window.print()`) and `@media print` CSS hiding the button/chrome. Currency from `setting('site_currency')`.
- All output `e()`-escaped; amounts `(float)`.

## Error handling / edge cases

- **Pre-migration** (`bill_items` absent): `fetch_bill_items`/`bill_total` catch → `[]`/lines-only; the Bill tab still renders the request charges; `bill_add`/`bill_del` are owner/staff POST actions that only run post-migration (the tab is new). No 500s on the read path.
- **Unpriced confirmed request:** shown in the tab with a set-price input; excluded from the total (null → 0) and from the printed bill until priced.
- **Hold-scoping:** every mutation is `AND hold_id=:h`; the page already blocks staff outside their venues.
- **Empty bill:** total 0.00; the print view shows the header + "No extra charges."
- **Escaping / validation:** `label` length-capped and `e()`-escaped; `amount`/`price_amount` cast float, negative rejected; prepared statements only.

## Testing — `tests/bill_logic.php` (`check()` style, self-cleaning)

- Seed a hold with addons: a `confirmed` priced (1000), a `completed` priced (500), a `declined` priced (999), a `requested` priced (999), and a `confirmed` **unpriced** (null); plus two `bill_items` (300, 200).
- `fetch_bill_lines`: includes the confirmed + completed (+ the unpriced confirmed), excludes the declined and requested.
- `fetch_bill_items`: returns the two manual rows.
- `bill_total`: `1000 + 500 + 0 (unpriced) + 300 + 200 == 2000.0` (declined/requested not counted).
- Clean up all seeded rows.

## Rollout

Run `db/migrations/add_bill_items.sql` via **/admin/migrate.php** after deploy. Then the **Bill** tab appears on each booking workspace: price any unpriced accepted requests, add ad-hoc charges, and **Print bill** at checkout.
