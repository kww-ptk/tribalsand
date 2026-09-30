# Global rate editor — fix every property's rates from the Rates page

**Date:** 2026-09-30 · **Status:** approved by owner (brainstorm) · **Page:** `admin/rates.php`
Builds on `2026-09-28-rates-compare-design.md` (Rate card / Timeline / Calendar, KES | USD).

## Problem

Nightly rates (`rates`: room_id, date_from, date_to EXCLUSIVE, price_amount, label)
can only be edited one room at a time on `admin/venue-edit.php` / `admin/room-edit.php`
(owner-only). Changing "Zuri Mid season" for four suites, moving Peak by two days
across a property, a +5% for next year, or pricing a single event night means many
separate edits. The Rates page that shows everything is read-only.

## Goal

From the Rates page, the **owner** can change any rooms' rates in one go —
a season's price, season dates, a bulk %, single nights — with a **preview before
saving**, a **change log**, and **undo**; and optionally keep a property's
**buyout = sum of its suites** in step.

## Non-goals

- No access for managers / reception (pricing stays owner business; they keep the
  read-only page). Editors are not rendered for them and every endpoint re-checks.
- No change to how prices are resolved or quoted: `rates_nightly_maps()` /
  `room_stay_quotes()` stay the ONE pricing path; the editor only writes `rates` rows.
- No min-stay, occupancy or per-guest pricing (no columns for them).
- The per-room editors on venue-edit / room-edit stay as they are.

## Design

### One editor, three entry points (owner only)

A single **Set rates** panel (a modal on `admin/rates.php`), opened from:
1. **Rate card → click a price cell** (room × season × year). Pre-filled: that room,
   every night of that season label in that year, mode *fixed price* = the current
   price. Offers "also these rooms" = the other rooms at the same property that have
   the same price for that season (pre-ticked).
2. **Timeline → click-drag across day cells** on one or more room rows (click a
   cell, shift-click to extend; drag across rows selects those rooms). Pre-filled:
   those rooms, those nights.
3. **Toolbar "Set rates" button** — empty form.

### The form
- **Rooms:** property chips + room tick-boxes (all in-scope published and
  unpublished rooms of the owner's venues; unpublished marked "hidden").
- **Nights:** one or more date ranges (shared `.dp-btn` range picker, "first
  night → last night"; stored exclusive like everywhere).
- **Season label:** free text with suggestions (labels already in use); for the
  % mode the default is "keep each night's label".
- **Price mode** (one of):
  - **Fixed price** — one amount. All selected rooms must share a currency; if not,
    the form says which rooms differ and asks to split the change.
  - **Change by %** — ±N% on each night's current price, rounded to the nearest
    KES 10 / $1 (rounding shown in the preview).
  - **Match a season** — each room gets its own current price for label X (chosen
    from the labels in use) in the same year as each night; if a room has several
    prices for X that year, the most common one is used; a room with no X price is
    skipped and listed in the preview.
  - **Back to base price** — removes overrides for those nights.
- **Also update buyouts** (shown when a selected room belongs to a property that
  has exactly one whole-property room (`is_entire_place`) plus other priced rooms,
  and that buyout room is not itself selected; ticked by default): for every
  affected night, the buyout's price = the sum of that property's other published
  rooms' nightly prices AFTER the change (base price when a room has no override;
  rooms priced 0 are left out and named in the preview), label = the label most of
  those rooms carry that night.

### Preview → confirm → apply
- **Preview** (server-computed, nothing written): per room — nights affected, current
  price(s) → new price(s) (ranges when they vary), season label, skipped rooms with
  the reason; buyout rows marked; totals "N rooms · M nights". Amounts in each
  room's own currency (the KES | USD switch applies).
- **Apply** re-computes on the server (never trusts preview figures from the
  client), groups each room's nights into contiguous runs with the same price and
  label, and writes them with `rates_apply_ranges()` (trims/splits/deletes
  overlapping rows — no overlaps) inside ONE transaction together with the log row.
- A change that would set a price ≤ 0 (except *back to base*) is refused.

### Change log + undo (migration `add_rate_change_log.sql`)
- Table `rate_change_log`: id, admin_id, created_at, summary (text: "Zuri · 4 rooms ·
  Mid season 2027 → KES 51,000"), rooms (int[]), span_from / span_to (dates),
  before_json (every `rates` row of those rooms overlapping the span, as it was),
  after_json (the rows as written), undone_at, undone_by.
- The Rates page (owner) shows the latest changes with **Undo** on each not-yet-undone
  one. Undo restores `before_json` for those rooms within the span (delete the
  current rows overlapping the span, trim to the span, re-insert the before rows
  clipped to the span) in one transaction — **only when no later, not-undone change
  overlaps the same rooms and nights** (otherwise "Undo the newer change first"),
  and only when the current rows still equal `after_json` (otherwise "These rates
  were edited elsewhere since — undo not possible").
- Pre-migration: the editor still works (preview + apply) but shows "Change log and
  undo need the migration" and writes no log. `rate_log_supported()` via a catalog
  lookup (never a failing SELECT inside a transaction).

### Endpoints
- `api/rate-editor.php` — POST JSON {csrf_token, action: preview | apply | undo, …};
  guards: signed-in admin, **is_owner()**, CSRF in body with non-empty session token.
  Room ids validated against real rooms (owner = all venues).
- `admin/rates.php` renders the editor UI only for the owner.

## Components
| Unit | Responsibility |
|---|---|
| `includes/rate-editor.php` | pure: resolve a request to per-room target nights → new price/label (all 4 modes, rounding, buyout sums), group nights into runs, diff for preview; I/O: `rate_editor_preview()`, `rate_editor_apply()`, `rate_editor_undo()`, log helpers |
| `api/rate-editor.php` | JSON endpoint (owner, CSRF) |
| `admin/rates.php` + `admin/assets/admin-rate-editor.js` (emitted inline) | editor modal, entry points on card / timeline / toolbar, change-log list |
| `db/migrations/add_rate_change_log.sql` | the log table |

## Testing
`tests/rate_editor_logic.php`: pure — each mode (fixed, %, rounding, match-season
with most-common + skip, back-to-base), currency mix refusal, ≤0 refusal, run
grouping, buyout sums (0-priced rooms left out, label majority). DB (one
rolled-back transaction, migration applied inside it): apply writes no overlaps and
`room_stay_quote()` then returns the new totals; buyout updated; log row written;
undo restores the exact previous quotes; undo refused when a newer overlapping
change exists or rows changed since; non-owner refused at the endpoint.
Plus `php tests/rates_logic.php`, `rates_compare_logic`, `quote_builder_logic` still pass.
Browser: owner sees editors, reception doesn't; card click → preview → apply →
figures update → undo; timeline drag → set price.

## Rollout
Branch `feat/rate-editor` from master. Migration `add_rate_change_log.sql` via
/admin/migrate.php (editor works before it, without log/undo).
