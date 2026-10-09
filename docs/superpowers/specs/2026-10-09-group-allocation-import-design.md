# Group allocation import — design

Date: 2026-10-09 · Owner-approved. First use: the Chris & Bini wedding (Maya Kobe + Maya Ilai,
24–30 Oct 2026, 62 guests), from a room-allocation PDF transcribed to CSV.

## Goal
Turn a group's room allocation into real bookings — one per room — so every room has a guest
link (stay page + check-in), then hand staff the links to send.

## Owner decisions
- **One booking per room**; a head with several rooms gets several links, listed together.
- **No price**: billed as a group. Ledger row with gross 0 (source `direct`, `agent` = the batch
  label) so occupancy counts it, revenue shows nothing, and `bookings_sync_hold()` never
  re-prices it (a non-website source is treated as imported).
- **No emails**, no invoices. A links page with WhatsApp / Email (mailto) / Copy + CSV.
- **Names:** booking `guest_name` = the head when they stay in that room, else the room's first
  guest; booking `guest_email` = the head's email. Each room's guests pre-fill its check-in
  roster (lead first). Bracketed nicknames are dropped ("Li (Eric) Shuai" → "Li Shuai"); a
  bare first name matching the head's first name takes the head's full name ("Ravi" → "Ravi Bhatt").

## Page — `admin/import-group.php` (Bookings → Calendar → "Group import")
`require_manager()`-level audience as the existing import (owner + manager), venue-scoped by
`admin_venue_ids()`.

1. **Upload CSV** (`property, room, guests, head, email, check_in, check_out`; dates `Y-m-d` or
   `24 Oct 2026`) + a batch label (e.g. "Chris & Bini wedding").
2. **Preview** — nothing written. Per row: resolved target, guest names, adults, dates, status:
   - `maya-ilai` "Studio No. 0NA" → studio unit N (`maya-ilai-studio` units by sort order).
   - `maya-ilai` "Villa 0N: Room N01|N02|N03" → villa unit N (`maya-ilai-villa`), component
     `double_a` | `double_b` | `bunk`, product room `maya-ilai-double` | `maya-ilai-bunk-room`.
   - other properties: room label → a dropdown of that property's rooms (remembered in setting
     `group_import_room_map` = `{venue_id: {label: room_id}}`); the room's first free active unit.
   - Status: **ready** · **taken** (calendar conflict — unit overlap, or component already taken
     on that villa) · **already imported** (a non-cancelled hold with the same unit, dates and
     email exists → its link is reused) · **skipped** (no dates / no guests) · **needs a room**
     (unmapped label) · **out of scope**.
3. **Create** — one transaction, all ready rows or none. Per row: `create_hold_with_block(unit,
   null, ci, co, name, email, 'confirmed', null, components, productRoomId)`, then
   `require_checkin = TRUE`, `guest_count = adults`, check-in roster rows, the 0 ledger row,
   `audit_log('group_import.create')`. Batch saved in setting `group_import:<slug>` =
   `{label, created_at, hold_ids}`.
4. **Links page** `?batch=<slug>` — grouped by head email: head name, rooms (property · room ·
   dates · who), each link (`make_guest_ref()` → `/booking.php?ref=…`); per head **WhatsApp**,
   **Email** (mailto), **Copy**; `?batch=…&export=csv`.

## Pure helpers (`includes/group-import.php`)
`gi_parse_csv()`, `gi_parse_date()`, `gi_split_names()`, `gi_clean_name()`,
`gi_resolve_label()` (Maya Ilai studio/villa-room patterns), `gi_booking_name()`,
`gi_share_text()`. Test: `php tests/group_import_logic.php` (pure always; create path in a
rolled-back transaction when the DB has the rooms).

## Out of scope
Prices, emails, editing rows on the page (fix the CSV and re-upload), undo (cancel bookings
normally).
