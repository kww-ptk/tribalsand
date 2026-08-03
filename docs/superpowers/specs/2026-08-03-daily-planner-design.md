# Daily Planner / Itinerary — Design Spec

**Date:** 2026-08-03
**Status:** Approved

## Goal
Give each guest a day-by-day itinerary of their stay, shown in the portal **Stay** tab, and let admin build/curate it from a dedicated page. The plan runs from check-in (arrival) to check-out and auto-includes the guest's confirmed scheduled tours/transfers, with admin adding the rest (flights, pickups, dinners).

Reuses `holds` (dates) + `booking_addons` (`scheduled_for`); adds one table (`itinerary_items`).

---

## Data model — `db/migrations/add_itinerary.sql` (+ append to `db/schema.sql`)
```sql
CREATE TABLE IF NOT EXISTS itinerary_items (
    id         SERIAL PRIMARY KEY,
    hold_id    INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    day        DATE NOT NULL,
    at_time    TIME,                      -- nullable: all-day / untimed
    category   TEXT NOT NULL DEFAULT 'note'
               CHECK (category IN ('flight','transfer','tour','dining','activity','checkin','checkout','note')),
    title      TEXT NOT NULL,
    detail     TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_itin_hold_day ON itinerary_items (hold_id, day, at_time);
```
Idempotent. Run on prod via `/admin/migrate.php`.

---

## Shared helper — `includes/booking.php`

### `fetch_itinerary(array $hold): array`
Returns an ordered list of days, each `['date'=>'Y-m-d','label'=>'Day 1 · Wed 10 Sep','is_today'=>bool,'items'=>[...]]`, spanning `check_in`…`check_out` inclusive. Each item: `['sort'=>int,'time'=>'H:i'|null,'category'=>str,'title'=>str,'detail'=>str,'source'=>'auto'|'request'|'admin']`.

Build steps:
1. Iterate each date from `check_in` to `check_out`; seed an empty day bucket keyed by `Y-m-d`. Compute `is_today` against `date('Y-m-d')`.
2. **Auto anchors:** on the check-in day push `{category:'checkin', title:'Check-in', detail: room_name, time:null, sort:0, source:'auto'}`; on the check-out day push `{category:'checkout', title:'Check-out', time:null, sort:2000, source:'auto'}`.
3. **Confirmed scheduled requests:** query `booking_addons` for this hold where `scheduled_for IS NOT NULL AND status IN ('confirmed','completed')`; for each, bucket by `scheduled_for::date` (skip if outside range), item `{category: mapKind(kind), title: addon_label(row), detail:'from your request', time: H:i from scheduled_for, sort: 100 + minutes, source:'request'}`. `mapKind`: tour→tour, transfer→transfer, laundry/housekeeping/amenities/maintenance/restaurant→activity, else note.
4. **Admin items:** query `itinerary_items` for this hold; bucket by `day` (skip if outside range), item `{category, title, detail, time: at_time?H:i:null, sort: at_time? 100+minutes : 1500 + sort_order, source:'admin'}`.
5. Sort each day's items ascending by `sort`, tiebreak by insertion. Return only days within range (all of them, including empty).

Wrap the DB reads in try/catch → treat as no items (so the Stay tab never 500s pre-migration; the anchors still render since they derive from `$hold`).

### Admin helper `fetch_itinerary_items(int $holdId): array`
`SELECT * FROM itinerary_items WHERE hold_id=:h ORDER BY day, at_time NULLS LAST, sort_order`.

Category → icon is a presentation concern (inline SVG map in the views; not in the helper).

---

## Guest — "Your plan" on the Stay tab (`includes/app/stay.php`)
- Rendered at the **top** of the Stay tab, before the stay-info cards (which stay), before the change form. `$hold`, `$ref`, `$status` are in scope from `booking.php`.
- Heading "Your plan" + subtitle. Then, per day from `fetch_itinerary($hold)`:
  - Day header: `Day N · Wed 10 Sep`, with a "Today" pill when `is_today`.
  - A left-border timeline; each item = a small category icon + `HH:MM · Title` (omit time if null) + muted `detail`. `source:'request'` items get a subtle "from your request" tag.
  - Empty day → muted "Nothing planned — browse Activities or ask the concierge."
- New CSS in `css/portal-app.css` (`.pa-plan*`) — day header, timeline rail, item row, category-icon tint. Category icons: an inline-SVG PHP map keyed by category (flight, transfer, tour, dining, activity, checkin, checkout, note).
- All output `e()`-escaped; read-only (no forms).

---

## Admin — planner editor `admin/itinerary.php?hold=<id>` (new)
- `require_login()`. Loads the hold (guest name, room, `check_in`/`check_out`); 404-flash to holds.php if not found.
- **POST actions** (all `verify_csrf()`, then PRG back to `?hold=<id>`):
  - `add`: validate `day` within `[check_in, check_out]`, `category` in the CHECK set, `title` non-empty, optional `at_time` (`H:i`); insert; `audit_log('itinerary.add', 'hold', $holdId, $title)`.
  - `delete`: delete the item by id (scoped to this hold); `audit_log('itinerary.delete', ...)`.
  - `edit` (optional for v1 — a simple delete+re-add is acceptable; include edit if low-cost): update fields.
- **Render:** the full merged timeline via `fetch_itinerary($hold)` for context (auto + request + admin), with admin items showing a **Delete** button. Below it, an **Add item** form: day `<select>` (each stay date as an option, labelled "Wed 10 Sep"), time `<input type=time>` (optional), category `<select>`, title, detail. Uses the admin CSS (`page-header`, `card`, `data-table`/list, `btn-*`, `alert`).
- **Links in:** a **Plan** button per booking on `admin/holds.php` (in the hold's action area) → `/admin/itinerary.php?hold=<id>`; and a **Plan** link on each `admin/concierge-desk.php` row (`?hold=<hold_id>`). Nav: no new sidebar item required (reached from Holds), but add `$activeMenu` handling is unnecessary.

---

## Security
- Guest side is read-only, `e()`-escaped, derives `hold` from the signed ref (no params). SQL parameterized. Helper try/catch-guarded against a missing table.
- Admin editor: `require_login()` + `verify_csrf()` on every mutation; `day` validated within the stay range; `category` validated against the CHECK set; item deletes scoped by `hold_id`; PRG (no re-submit); `audit_log` on writes; output escaped.
- No `$_SERVER['REMOTE_ADDR']`.

## Testing
- `tests/portal_logic.php`: seed a hold with known `check_in`/`check_out`, an admin `itinerary_items` row on day 2, and a confirmed `booking_addons` with a `scheduled_for` on day 2; assert `fetch_itinerary` returns the right number of days, the check-in anchor is on day 1 and check-out on the last day, day 2 contains both the request item and the admin item in time order, and an out-of-range admin item is excluded. Clean up.
- Browser E2E: open the Stay tab → the plan shows arrival/check-out anchors + a confirmed tour on its day; admin adds a "Flight lands 13:40" item on the arrival day and it appears on the guest plan; today-highlight works; empty days show the hint.
- Regression: existing tests pass; `php -l` clean; Stay tab still shows stay info + change + cancel below the plan.

## Deploy
Run `db/migrations/add_itinerary.sql` via `/admin/migrate.php` after deploy. The guest plan's anchors render without the table (guarded), but admin items + the admin page need it — run promptly. Rollback: Render previous deploy; unused table harmless.
