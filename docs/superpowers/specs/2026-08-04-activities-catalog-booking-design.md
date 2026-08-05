# Activities catalog & booking (per-property, priced, pax + date)

**Date:** 2026-08-04
**Status:** Approved design — ready for planning
**Sub-project:** B of five. (A shipped; C/D/E later.)

## Problem

Activities come from the existing global `tours` catalog. Tours aren't linked to properties (every activity shows at every booking), their `price` is free-text marketing copy (not a bookable amount), and the guest "Request this activity" is a single button — no party size, no date, and nothing captured for billing.

## Goal

Owner picks which activities are available **per property**, sets a **numeric price (per-person or flat) + max pax + "what's included"**; guests pick **pax + a date within their stay** and see a **live total**; each request **snapshots pax + date + computed price** onto the `booking_addon` (feeding the future room bill). Reuses the sub-project A "Request sent" popup.

Non-goals: touching the public marketing tour pages or their free-text `price`; a payment/checkout flow (only the snapshot); per-property *pricing* (price is global per activity — availability is per-property).

## Decisions (from brainstorming)

- **Availability:** an activity with **no** properties ticked shows at **all** properties; ticking restricts it. (Existing activities keep showing.)
- **"What's included":** a **new dedicated field** on the activity (separate from highlights).
- **Date:** the guest's date picker is **limited to check-in…check-out**.
- **Price display:** **unit price + live total** as pax changes; the computed total is snapshotted.
- **Per-person vs flat:** a **per-activity toggle** (`price_per_person`, default on); a flat activity's total ignores pax.

## Data model — migration `db/migrations/add_activity_booking.sql` (idempotent)

```sql
-- Per-property availability. No rows for a tour = available at all properties.
CREATE TABLE IF NOT EXISTS tour_venues (
    tour_id  INT NOT NULL REFERENCES tours(id)  ON DELETE CASCADE,
    venue_id INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    PRIMARY KEY (tour_id, venue_id)
);

-- Structured booking price + capacity + inclusions (separate from the marketing `price` varchar).
ALTER TABLE tours
  ADD COLUMN IF NOT EXISTS price_amount     NUMERIC(10,2),
  ADD COLUMN IF NOT EXISTS price_per_person BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS max_pax          INT,
  ADD COLUMN IF NOT EXISTS whats_included   TEXT;

-- Guest-chosen party size on a request (date reuses scheduled_for; price reuses price_amount).
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS pax INT;
```

Notes: `booking_addons.price_amount` already exists (service-pricing feature) and is the snapshot target for the computed activity total; `scheduled_for` already exists and holds the activity date. `pax` is new.

## Helpers — `includes/booking.php`

- **`fetch_portal_activities(?int $venueId = null): array`** — *change signature* (currently no arg; default `null` keeps other callers working). Published tours scoped to the venue:
  ```sql
  WHERE t.is_published = TRUE
    AND (:vid IS NULL
         OR NOT EXISTS (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id)
         OR EXISTS     (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id AND tv.venue_id = :vid))
  ```
  Select the existing fields **plus** `price_amount, price_per_person, max_pax, whats_included, long_desc`. (Passing `:vid` as an int param; `null` → all. Wrap in try/catch → `[]` pre-migration, matching today.)
- **`fetch_tour_for_booking(string $slug, ?int $venueId): array|false`** — the tour row (incl. new fields) **iff** published AND available at `$venueId` (same availability rule); else `false`. Used by the request API. Guarded → `false` on error.
- **`activity_venue_ids(int $tourId): array`** — the venue ids ticked for a tour (admin checklist state).
- **`activity_price_total(array $tour, int $pax): ?float`** — `price_amount` is null → `null` (unpriced); else `price_per_person` ? `price_amount * max(1,$pax)` : `price_amount`.
- **`insert_booking_addon(array $d): int`** — a single insert helper replacing the two-branch insert in `api/booking-addon.php`. Base columns `hold_id, kind, tour_id, details, scheduled_for`; conditionally appends `price_amount` (when `addon_price_supported()`) and `pax` (when `addon_pax_supported()`), so every kind works pre- and post-migration. Returns `lastInsertId()`.
- **`addon_pax_supported(): bool`** — memoised `information_schema` check for `booking_addons.pax` (mirrors `addon_price_supported()` in `includes/services.php`; put this one beside it in `services.php` or in `booking.php` — implementer's call, keep them together).

## Guest — `includes/app/activities.php`

- Fetch `fetch_portal_activities(isset($hold['venue_id']) ? (int)$hold['venue_id'] : null)` — only this property's activities.
- Each card shows the **unit price** when set: `format_price($a['price_amount'])` + `' / person'` when `price_per_person`. When `whats_included` is set, show it (small "What's included" block).
- Replace the single "Request this activity" button with a **"Request" button that reveals an inline form** (hidden until tapped, like the concierge tiles), containing:
  - **Pax** `<select>` `1…($a['max_pax'] ?: 8)`.
  - **Date** `<input type="date" name="at_date" required min="<check_in>" max="<check_out>">`.
  - **Notes** (optional) text input.
  - A **live total** line — JS multiplies unit × pax for per-person activities (flat shows the flat price); updates on pax change. Data attributes (`data-price`, `data-perperson`) on the form feed the JS; currency label from a `data-cur` attribute (PHP passes `setting('site_currency')`).
  - Submit `.pa-btn pa-btn--primary` → `data-bm` form posting `kind=tour`, `tour_slug`, `pax`, `at_date`, `details` to `/api/booking-addon.php`.
- On success the existing `js/booking-manage.js` shows the **"Request sent" popup** (the API returns the thread redirect). One small script (in `activities.php`) wires the live-total updates.

## Request API — `api/booking-addon.php` (the `tour` branch)

```php
} elseif ($kind === 'tour') {
    $slug = $str($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_for_booking($slug, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That activity isn’t available for your stay.'])); }
    $tour_id = (int)$tour['id'];
    $cap = (int)($tour['max_pax'] ?? 0);
    $pax = (int)($data['pax'] ?? 1); if ($pax < 1) $pax = 1; if ($cap > 0 && $pax > $cap) $pax = $cap;
    // Date must fall within the stay (inclusive).
    $atDate = $str($data['at_date'] ?? '');
    $ts = $atDate !== '' ? strtotime($atDate) : false;
    if ($ts === false || $atDate < $hold['check_in'] || $atDate > $hold['check_out']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a date within your stay.']));
    }
    $schedSql = date('Y-m-d H:i:s', $ts);           // activity date → scheduled_for
    $priceSnapshot = activity_price_total($tour, $pax);
    $note = $str($data['details'] ?? '');
    $details = $tour['name'] . ' · ' . $pax . ' pax' . ($note !== '' ? ' — ' . $note : '');
}
```

The generic `$schedSql`/`$sched` handling below the branches is bypassed for tours (the branch sets `$schedSql` directly — reconcile so the tour date isn't overwritten; simplest: for `tour`, set a flag or set `$sched=''` so the later block leaves `$schedSql` alone). The final insert uses `insert_booking_addon([... 'price_amount'=>$priceSnapshot, 'pax'=>($kind==='tour'?$pax:null)])`. Thread-seed + notification unchanged; the API still returns the `redirect` → popup fires.

## Admin — `admin/tour-edit.php`

In the **`save_details`** handler and its form, add:
- **Booking price** — `price_amount` (number, step 0.01) + **"Price is per person"** checkbox (`price_per_person`) + **Max pax** (`max_pax`, number). Save into the tours INSERT/UPDATE.
- **What's included** — `whats_included` textarea. Save into tours.
- **Available at** — a checklist of all venues (`SELECT id,name FROM venues ORDER BY sort_order,name`), each checked when in `activity_venue_ids($id)`. On save (existing venues only), sync `tour_venues`: `DELETE FROM tour_venues WHERE tour_id=:id` then insert each checked `venue_id`. A note explains "none ticked = shown at every property."
- Keep `verify_csrf()` + `require_owner()` + `audit_log('tour.save', …)` + PRG (already present).

(The marketing `price` varchar field stays as-is for the public pages.)

## Admin display of the snapshot

No change needed: the Concierge Desk and workspace requests already show `format_price($a['price_amount'])` (service-pricing feature), so a tour request's computed total shows there. Optionally, the `pax` also reads through `ba.*`; showing "· N pax" next to an activity request in those views is a nice touch (append when `kind='tour'` and `pax` present).

## Error handling / edge cases

- **Pre-migration** (`tour_venues` / new tours cols / `booking_addons.pax` absent): `fetch_portal_activities` and `fetch_tour_for_booking` catch → `[]`/`false` (Activities tab shows "Experiences will appear here soon."; booking → 422). `insert_booking_addon` omits `pax`/`price_amount` when unsupported, so laundry/transfer/other requests keep working. No 500s.
- **Unpriced activity** (`price_amount` null): card shows no price; request still allowed; snapshot `price_amount` = null (shows nothing on the desk — priced later or billed manually).
- **max_pax null/0:** treated as no cap; pax select offers 1…8.
- **Stale/removed activity or wrong property:** `fetch_tour_for_booking` returns false → 422.
- **Date out of range:** server-validated against `check_in`/`check_out` (client `min`/`max` is convenience only).
- **Escaping:** all output `e()`; ids/pax cast int, price cast float; prepared statements; the live-total JS reads numeric data-attributes (no injection).

## Testing — `tests/activities_logic.php` (`check()` style, self-cleaning)

- Seed a venue-linked unit + two published tours: T1 (no `tour_venues`), T2 (assigned to venue B only).
- `fetch_portal_activities(null)` includes both; `fetch_portal_activities(A)` includes T1, excludes T2; `fetch_portal_activities(B)` includes both.
- `fetch_tour_for_booking(T2.slug, A)` === false; `(T2.slug, B)` returns the row; unpublished tour → false.
- `activity_price_total`: per-person (amount 1000, pax 3) === 3000.0; flat (per_person false) === 1000.0; null price → null.
- `insert_booking_addon(['...','pax'=>2,'price_amount'=>3000])` stores `pax=2` and `price_amount=3000` on the row; clean up.

## Rollout

Run `db/migrations/add_activity_booking.sql` via **/admin/migrate.php** after deploy. Then, per activity on `admin/tour-edit.php`, set the booking price / max pax / what's included and tick the properties it's offered at. Guests then see priced activities on their property with pax + date; requests snapshot the total.
