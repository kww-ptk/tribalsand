# Events — "What's on" bookable

**Date:** 2026-08-05
**Status:** Approved design — ready for planning
**Sub-project:** C of five (the last of the batch). A, B, D, E shipped.

## Problem

The guest board ("What's on") supports `update / excursion / promotion` posts, shown read-only in the portal. The owner wants an **Event** category, and guests should be able to **join/request/book** an event from "What's on" — not just read it.

## Goal

An **Event** guest-board category with a date + optional price, and a **Join** action in the portal "What's on" that creates a request — reusing the request pipeline (Concierge Desk, confirm/decline auto-message + notification, room bill).

Non-goals: capacity limits (staff manage numbers); date-filtering which events show; a separate events surface (events live on the existing guest board).

## Decisions (from brainstorming)

- **Join → a request** (`booking_addons`), so it inherits the Concierge Desk, the D lifecycle (auto-message + notify on confirm/decline), Messages, and — if priced — the E room bill.
- **Optional price** per event (free = Join; priced = Request/book + bill).
- **No capacity** cap.
- **No date-filtering** of which events display (staff curate the board).
- **Dedup guard:** once a guest has an active (non-declined/cancelled) join for an event, the card shows "Requested" instead of the Join button.

## Data model — migration `db/migrations/add_events.sql` (idempotent)

```sql
-- 'event' guest-board category, with a date + optional price.
ALTER TABLE guest_board_posts DROP CONSTRAINT IF EXISTS guest_board_posts_category_check;
ALTER TABLE guest_board_posts ADD CONSTRAINT guest_board_posts_category_check
    CHECK (category IN ('update','excursion','promotion','event'));
ALTER TABLE guest_board_posts
    ADD COLUMN IF NOT EXISTS event_date   TIMESTAMP,
    ADD COLUMN IF NOT EXISTS price_amount NUMERIC(10,2);

-- Allow 'event' as a request kind, and link a join back to its board post.
ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other','housekeeping','amenities','maintenance','restaurant','laundry','event'));
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS board_post_id INT REFERENCES guest_board_posts(id) ON DELETE SET NULL;
```

(`price_amount` on the addon — the snapshot — already exists; `board_post_id` is new and links a join to its event for dedup + traceability.)

## Helpers — `includes/booking.php` (+ `includes/services.php`)

- `fetch_guest_board()` — add `g.event_date, g.price_amount` to its SELECT so the portal can render them.
- `fetch_board_event(int $postId, ?int $venueId): array|false` — the post iff `category='event'` AND `is_published` AND available at the venue (`venue_id IS NULL` global, or `= $venueId`; when `$venueId` is null, only global events). Venue clause built in PHP (no reused/`IS NULL` placeholder). Guarded → `false`.
- `guest_joined_event(int $holdId, int $postId): bool` — `SELECT 1 FROM booking_addons WHERE hold_id=:h AND board_post_id=:p AND status NOT IN ('declined','cancelled') LIMIT 1`. Guarded → `false`.
- `addon_board_supported(): bool` — memoised `information_schema` check for `booking_addons.board_post_id` (beside `addon_pax_supported()` in `services.php`).
- `insert_booking_addon()` — append `board_post_id` to the guarded column list (when `addon_board_supported()`), mirroring `price_amount`/`pax`.
- `_itin_map_kind()` — map `'event'` → `'activity'` so a confirmed event join with a date shows on **My Calendar**.

## Request flow — `api/booking-addon.php`

Add `event` to the allowed-kinds list, initialise `$boardPostId = null;` beside the other snapshot vars, and add the branch:

```php
} elseif ($kind === 'event') {
    $postId = (int)($data['board_post_id'] ?? 0);
    $ev = $postId ? fetch_board_event($postId, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$ev) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That event isn’t available.'])); }
    if (guest_joined_event((int)$hold['id'], $postId)) { http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'You’ve already requested to join this.'])); }
    $boardPostId   = $postId;
    $details       = 'Join: ' . $ev['title'];
    if (!empty($ev['event_date'])) { $schedOverride = date('Y-m-d H:i:s', strtotime((string)$ev['event_date'])); }
    $priceSnapshot = ($ev['price_amount'] === null || $ev['price_amount'] === '') ? null : (float)$ev['price_amount'];
}
```

The insert passes `'board_post_id' => $boardPostId`. Thread-seed + notification + the `redirect` (→ the sub-project-A popup) are unchanged. A priced event join snapshots the price → shows on the room bill; a confirmed join with a date → My Calendar.

## Guest — "What's on" (`includes/app/_greeting_board.php`)

- Compute `$__active = in_array($hold['status'] ?? '', ['pending','confirmed'], true);` and `$__cur = setting('site_currency','USD')`; add `'event' => 'pa-tag--event'` to `$__tagClass`.
- For `category === 'event'` cards, below the body render:
  - the **date** (`event_date`, formatted) when set,
  - the **price** (`format_price(price_amount)`) when set,
  - and, when `$__active`: **"Requested" pill** if `guest_joined_event($hold['id'], $p['id'])`, else a `data-bm` **Join form** posting `ref` + `kind=event` + `board_post_id` to `/api/booking-addon.php`. Button label: `Request · <price>` when priced, else `Join event`. On success the A popup fires.
- Non-event cards are unchanged.

## Admin — `admin/guest-board.php`

- Add `'event' => 'Event'` to the `$CATS` map (surfaces it in the category `<select>` and validation).
- The save handler writes `event_date` (parse `datetime-local`; blank → null) and `price_amount` (blank → null) into the INSERT and UPDATE.
- The edit form gains an **Event date** (`<input type="datetime-local">`) and **Price** field, labelled "(events only)", prefilled from `$edit`.
- Owner-only (`require_owner()` already at the top) + existing CSRF/PRG.

## Error handling / edge cases

- **Pre-migration** (category CHECK/columns/`board_post_id` absent): `fetch_board_event`/`guest_joined_event` catch → `false`; `insert_booking_addon` omits `board_post_id` when unsupported; the portal renders events as plain cards (no Join) if the columns aren't there. No 500s.
- **Invalid/foreign event** (wrong venue, unpublished, not an event): `fetch_board_event` → false → 422.
- **Double join:** server-side `guest_joined_event` → 409; the card also hides Join once joined.
- **Free event:** `price_amount` null → snapshot null → not billed; still a request.
- **Escaping:** all output `e()`; ids `(int)`, price `(float)`; the join `board_post_id` validated against the board; prepared statements only.

## Testing — `tests/portal_logic.php` (extend; `check()` style, self-cleaning)

- Seed an `event` post (published, `price_amount` 2000, global `venue_id` NULL) and a `promotion` post.
- `fetch_board_event(eventId, null)` returns the row; `fetch_board_event(promoId, null)` === false (not an event); an unpublished event → false.
- `guest_joined_event(hold, eventId)` === false; insert a `booking_addons` row with that `board_post_id` (status requested); then === true; a declined join → false again.
- Clean up seeded rows.

## Rollout

Run `db/migrations/add_events.sql` via **/admin/migrate.php** after deploy. Then create **Event** posts on the guest board (date + optional price). Guests see them in "What's on" and **Join** → the request flows through the desk, messaging, and the bill (if priced).
