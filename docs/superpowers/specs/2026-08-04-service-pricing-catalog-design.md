# Service pricing catalog (laundry & transfer)

**Date:** 2026-08-04
**Status:** Approved design — ready for planning
**Sub-project:** A of two (B, the staff Front Desk dashboard, shipped separately).

## Problem

Laundry and transfer options are hardcoded label-only `const` arrays (`LAUNDRY_OPTIONS`, `TRANSFER_OPTIONS` in `includes/booking.php`), with no prices and no way for the owner to edit them. The owner wants to manage these options and their prices, guests should see prices when requesting, and each request should capture the price for future charge-to-room billing.

## Goal

An owner-editable **service pricing catalog** (laundry + transfer) stored in the DB; guests see active options with prices in the portal; each priced request snapshots its price onto the `booking_addon`.

Non-goals: per-property pricing (global only); an actual billing/checkout flow (only the price snapshot is captured now); pricing the free-form concierge tiles (housekeeping/maintenance/restaurant/"make a request") — those stay unpriced.

## Decisions (from brainstorming)

- **Global** price list (one catalog for the whole group).
- **Prices shown to guests** in the portal option dropdowns.
- **Full catalog editing:** owner can add / rename / remove / reorder options and set each price + an active toggle.
- **Snapshot the price** onto `booking_addons` at request time.
- **Reorder = drag** (reuse the property-gallery drag pattern).
- **Price of 0 → show the label only** (no "— KES 0"), both to guests and in admin option labels.
- Currency for display comes from the existing `site_currency` setting (`setting('site_currency','USD')`).

## Data model

New migration `db/migrations/add_service_options.sql` (idempotent):

```sql
CREATE TABLE IF NOT EXISTS service_options (
    id           SERIAL PRIMARY KEY,
    service      VARCHAR(20)   NOT NULL CHECK (service IN ('laundry','transfer')),
    label        VARCHAR(120)  NOT NULL,
    price_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    is_active    BOOLEAN       NOT NULL DEFAULT TRUE,
    sort_order   INT           NOT NULL DEFAULT 0,
    created_at   TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_service_options_service ON service_options (service, sort_order);

-- Price snapshot captured on a request (charge-to-room seed).
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS price_amount NUMERIC(10,2);

-- Seed today's fixed options (prices 0 — owner fills them in). Only when empty,
-- so re-running never duplicates.
INSERT INTO service_options (service, label, price_amount, sort_order)
SELECT * FROM (VALUES
    ('laundry','Wash & fold',0,1),
    ('laundry','Ironing',0,2),
    ('laundry','Dry-clean',0,3),
    ('laundry','Wash & iron',0,4),
    ('transfer','Airport → Property',0,1),
    ('transfer','Property → Airport',0,2),
    ('transfer','Between properties',0,3),
    ('transfer','Custom transfer',0,4)
) AS v(service,label,price_amount,sort_order)
WHERE NOT EXISTS (SELECT 1 FROM service_options);
```

Notes: currency is not stored per row — display uses the global `site_currency`. `booking_addons.price_amount` is a **snapshot** (value copied at request time), so later catalog edits/deletes never change historical requests. No FK to `service_options` (snapshot pattern; catalog rows can be deleted freely).

## Helpers — new `includes/services.php`

- `fetch_service_options(string $service, bool $activeOnly = true): array` — rows for `'laundry'`/`'transfer'` ordered by `sort_order, id`; `$activeOnly` filters `is_active`. Guarded to `[]` if the table is missing (pre-migration).
- `fetch_service_option(int $id): array|false` — one row by id (any service, any active state) for server-side validation.
- `format_price(float|int $amount, ?string $currency = null): string` — `$currency` defaults to `setting('site_currency','USD')`; returns e.g. `"KES 500"` (`number_format($amount, 0)` when whole, else 2 dp). Empty string is never returned; callers decide whether to show it (they suppress it when amount ≤ 0).

`includes/booking.php`: **remove** the `LAUNDRY_OPTIONS` and `TRANSFER_OPTIONS` consts (all reads move to the catalog).

## Guest portal — `includes/app/_services.php`

The laundry and transfer `<select>`s render **active** catalog options:

```php
<?php foreach (fetch_service_options('laundry') as $o): ?>
  <option value="<?= (int)$o['id'] ?>"><?= e($o['label'] . ((float)$o['price_amount'] > 0 ? ' — ' . format_price($o['price_amount']) : '')) ?></option>
<?php endforeach; ?>
```

(Same for `transfer`.) The option `value` is now the **service_option id** (previously a slug). The surrounding tile/form markup is unchanged. If a service has no active options, the select shows a single disabled "— none available —" option.

## Request flow — `api/booking-addon.php`

For `kind = 'laundry'` and `kind = 'transfer'`, the posted field (`service` for laundry, `transfer` for transfer) now carries the **option id**:

```php
$optId = (int)($data['service'] ?? 0);           // laundry  (or $data['transfer'] for transfer)
$opt   = fetch_service_option($optId);
if (!$opt || $opt['service'] !== 'laundry' || !$opt['is_active']) {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid option.']));
}
$label = $opt['label'];
$details = $details === '' ? $label : "{$label} — {$details}";
$priceSnapshot = (float)$opt['price_amount'];    // null-safe: 0 stays 0
```

The `INSERT INTO booking_addons` gains `price_amount`:

```php
"INSERT INTO booking_addons (hold_id, kind, tour_id, details, scheduled_for, price_amount)
 VALUES (:h, :k, :t, :d, :sf, :price)"
```

`:price` = the snapshot for laundry/transfer, `null` for every other kind. The `price_amount` column/param is included only when `addon_price_supported()` is true (see Edge cases) so the endpoint still works pre-migration. The existing thread-seeding + notification behaviour is unchanged.

## Admin — new owner-only `admin/services.php`

`require_login()` + `require_owner()`. `$activeMenu = 'services'`, title "Service pricing". Two sections (Laundry, Transfer), each:
- a list of rows, each an inline form: **label** (text), **price** (number, step 0.01), **active** (checkbox), a **Save** button, and a **Delete** button (confirm). Reorder by **drag** (mirrors the `admin/venue-edit.php` gallery drag → posts a new `sort_order` list).
- an **"+ Add option"** form (label + price) appending a new active row at the end.

All writes are POST with `verify_csrf()`, `audit_log('service_option.<action>', 'service_option', $id)`, and PRG. Actions: `save` (update label/price/active), `add`, `delete`, `reorder` (JSON list of ids → sort_order). Server validates `service ∈ {laundry,transfer}`, `label` non-empty, `price_amount >= 0`.

**Nav:** add a "Service pricing" link in `admin/_layout.php` inside the owner-only (`if (!is_staff())`) block, near Settings, with `$activeMenu==='services'`.

## Admin display of the snapshot

Where concierge requests are listed with their details, show the captured price when present:
- `admin/concierge-desk.php` and the per-booking requests view (`admin/_ws_requests.php`) — append `format_price($row['price_amount'])` next to the request when `price_amount` is not null and > 0. (Read-only; no editing of a captured price.)

## Error handling / edge cases

- **Pre-migration** (`service_options` / `booking_addons.price_amount` absent): `fetch_service_options` returns `[]` (guarded); the guest selects show "— none available —". To keep **all** request kinds working before the column exists, `includes/services.php` provides `addon_price_supported(): bool` (queries `information_schema.columns` for `booking_addons.price_amount`, memoised in a `static`, returns false on any error). `api/booking-addon.php` includes the `price_amount` column/param in the INSERT **only when** `addon_price_supported()` is true; otherwise it inserts without it. The portal never 500s before the migration runs.
- **Deleted/inactive option chosen** (stale form): server-side `fetch_service_option` + active check → 422 "Please choose a valid option."
- **Price 0**: never rendered as a price (label only), guest and admin.
- **Escaping**: all labels via `e()`; ids cast to int; prices cast to float; prepared statements only.
- **Historical addons**: existing rows have `price_amount = NULL` → treated as "no price", render nothing.

## Testing — new `tests/services_logic.php` (`check()` style, self-cleaning)

- `format_price`: whole number → "USD 500" style; respects a passed currency; 2-dp for fractional.
- `fetch_service_options('laundry')`: seeded active rows returned in `sort_order`; an inactive row excluded when `$activeOnly=true`, included when false.
- `fetch_service_option($id)`: returns the row incl. `service`, `price_amount`, `is_active`; false for a missing id.
- Snapshot: insert a `service_options` row, simulate the addon insert with its `price_amount`, and assert the stored `booking_addons.price_amount` equals the option price; clean up.

## Rollout

Run `db/migrations/add_service_options.sql` via **/admin/migrate.php** after deploy (creates the table + `price_amount` column + seeds the options at price 0). Then the owner sets prices on `admin/services.php`. Guests immediately see priced options; new requests capture the snapshot.
