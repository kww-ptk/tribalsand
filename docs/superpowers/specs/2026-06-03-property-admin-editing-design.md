# Property Admin Editing — Design

> Date: 2026-06-03
> Status: Approved (design). Builds on the completed backend bolt-on.

## Goal

Give the Tribal Sand admin full management of **Properties** (the 7 accommodations, currently stored in the `venues` table and labelled "Venues"), mirroring how **Rooms** are edited today. Surface the natural hierarchy **Property → Rooms → Availability/Calendar**, and rename the UI from "Venue" to "Property".

## Hard constraint (non-negotiable)

**No public URL may change.** Each property maps to an existing hand-coded page (e.g. `zuri` → `/zuri`, `my-amani-premium-sea-view-twin` → `/my-amani-premium-sea-view-twin`). These pages already rank on Google. Therefore:

- The **slug is READ-ONLY** in both the property edit page and the room edit page. It is displayed for reference but cannot be changed from admin, so editing can never alter a ranking URL or break the booking-widget→room linkage.
- No page files are renamed or moved. Admin edits only DB metadata.

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Edit scope | **Settings + rooms/calendar** only. Page photos/marketing copy stay in the hand-coded `.php` files (NOT a CMS). |
| Naming | **UI relabel only.** `venues` → "Property/Properties"; the unused for-sale `properties` section → "For Sale Listings". No DB table renames. |
| For-Sale / Tours admin sections | **Kept** (relabelled for-sale to "For Sale Listings"); otherwise untouched. |
| Slug editing | **Read-only** in admin (property + room) to protect URLs. |

## Naming map (UI label ↔ code, unchanged)

| UI label | DB table | Admin files |
|---|---|---|
| **Properties** (accommodations) | `venues` | `admin/venues.php` (list), `admin/venue-edit.php` (NEW) |
| **For Sale Listings** | `properties` | `admin/properties.php`, `admin/property-edit.php` (relabelled only) |
| Rooms | `rooms` | `admin/rooms.php`, `admin/room-edit.php` |

Internal file/table names stay as-is (the name `property-edit.php` is already taken by For-Sale; renaming tables would require touching the ported backend and risks the working booking engine). Users only ever see "Property" in the interface.

## Components

### 1. `admin/venue-edit.php` (NEW) — full create/edit/delete, modelled on `room-edit.php`
- **Auth:** `require_login()` + CSRF on POST + `audit_log()` (same as room-edit).
- **Fields:**
  - Name (text, required)
  - Location (text)
  - Slug — **read-only** display field, with hint "tied to the live page `/<slug>` — cannot be changed".
  - Sort order (int)
  - Published (checkbox → `is_published`)
- **Create mode:** a "+ New Property" entry point. For NEW properties only, the slug IS editable (a new property has no existing page yet); on edit it becomes read-only. New-property slug input validated to `^[a-z0-9_-]+$`.
- **"Rooms in this Property" section:** table of rooms where `rooms.venue_id = this venue`, columns: Name, Price, Published, with per-row **Edit** (→ `room-edit.php?id=`) and **Availability** (→ the room's calendar, see §4) links, plus a **"+ Add room to this property"** button (→ `room-edit.php` new-room form with this `venue_id` preselected).
- **Save:** UPDATE `venues` SET name, location, sort_order, is_published (NOT slug on edit); INSERT for new. Redirect back to the list with a success flash, matching room-edit's pattern.
- **Delete:** guarded (confirm), sets rooms' `venue_id` to NULL via the existing FK `ON DELETE SET NULL` (rooms are not deleted).

### 2. `admin/venues.php` (MODIFY) — relabel + actions
- Page title/header "Properties". Keep the room-count column.
- Add a per-row **Edit** link (→ `venue-edit.php?id=`) and a **"+ New Property"** button.
- Nav label "Properties".

### 3. `admin/room-edit.php` (MODIFY) — slug read-only + calendar link
- Make the **slug field read-only on edit** (same URL-protection rationale; editable only when creating a brand-new room).
- Add a **"Manage availability / calendar"** link to the room's availability view (§4).
- The Property dropdown (added in the bolt-on Phase 5) stays.

### 4. Room availability / "calendar"
- Reuse the existing availability tooling rather than build new: link each room to **`admin/gantt.php`** scoped to that room's unit(s) (add a `?room=<id>` or `?unit=<id>` filter to gantt if not present), and to **`admin/holds.php`** for that room. "Availability" links from the property page and room edit point here.
- If `gantt.php` lacks a per-room filter, add one (read its current query first; keep the change minimal).

### 5. `admin/rooms.php` (MODIFY) — show parent property
- Add a **"Property"** column (join `venues` on `rooms.venue_id`), and a simple filter/dropdown by property. Lets you see the hierarchy from the flat rooms list too.

### 6. `admin/_layout.php` (MODIFY) — nav labels
- "Venues" → "Properties"; the for-sale link → "For Sale Listings". Keep `$activeMenu` highlighting working for both.

## Out of scope (YAGNI)

- No page-content CMS (no editing headlines/photos/marketing copy — those stay in the hand-coded property pages).
- No property images table/upload (pages are hand-coded; an admin image would render nowhere).
- No DB table renames; no changes to the booking engine, API, or front-end pages.
- For-Sale Listings and Tours functionality unchanged beyond the relabel.

## Files

**Create:** `admin/venue-edit.php`
**Modify:** `admin/venues.php`, `admin/room-edit.php`, `admin/rooms.php`, `admin/_layout.php`, `admin/gantt.php` (only if a per-room filter is needed)
**DB:** none (uses existing `venues` + `rooms.venue_id`).

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Admin edit changes a slug → breaks URL / booking widget | Slug read-only on edit (both property + room). |
| Deleting a property orphans/deletes rooms | FK is `ON DELETE SET NULL`; rooms survive with `venue_id=NULL`; delete is confirm-gated. |
| Naming confusion with For-Sale "Properties" | For-sale relabelled "For Sale Listings"; accommodations are "Properties". |
| Touching `room-edit.php`'s save (regression) | Slug change is display-only; the INSERT/UPDATE column/param balance must stay intact (verify counts, as in the bolt-on). |

## Success criteria

1. Admin nav shows "Properties" (accommodations) and "For Sale Listings"; no "Venue" wording remains.
2. From Properties → open a property → edit Name/Location/Sort/Published and Save; the change persists; the **slug cannot be changed** on an existing property.
3. The property edit page lists its rooms with working Edit + Availability links and an "Add room" action that preselects the property.
4. A new property can be created (slug editable only here), and a room can be added under a property.
5. Rooms list shows the parent Property and can be filtered by it.
6. Room edit slug is read-only on existing rooms; a "calendar/availability" link works.
7. Every existing public URL is unchanged; the booking widget still resolves on all 18 pages; room-edit save still works (no SQL regression).
