# Site Menu — UI/UX Redesign Plan + Outstanding To-Dos

> Handoff doc. Self-contained so a fresh Claude Code session (no prior chat context) can pick it up.
> Project: Tribal Sand (PHP 8.2, no framework, PostgreSQL/PDO, vanilla JS/CSS, Apache on AWS ECS).
> See `CLAUDE.md` for full project conventions.

---

## 0. Background — what already exists (read first)

An admin-editable **mega menu** was built this session. The site's top nav + mobile drawer now
render from the DB, edited at **Admin → Site Menu** (`admin/nav-menu.php`, owner-only).

- **Data model** (migration `db/migrations/add_nav_menu.sql`):
  `nav_items` (top-level button; `layout` = simple|wide2|wide3; `auto_source`; `sort_order`; `is_published`)
  → `nav_groups` (a column/section; optional `label`)
  → `nav_links` (`label`, `href`, `sublabel`, `image_key` thumbnail, `tag` ''|open|soon,
     `role` row|footer_link|cta_button, `cta_note`, `target_blank`, `is_published`).
- **Read model + renderers:** `includes/nav-data.php` (`nav_supported()`, `fetch_nav_tree()`,
  `nav_desktop_html()`, `nav_drawer_html()`, `nav_img_url()`).
- **Render/fallback:** `includes/header.php` renders from the DB when seeded, else falls back to the
  original hardcoded nav kept inline (pre-migration-safe — never blanks). Restaurants stays
  auto-driven (`auto_source='restaurants'`, locked in the builder).
- **Seed:** `db/seeds/seed_nav_menu.php` rebuilds the current nav 1:1 (idempotent).
- **Admin page:** `admin/nav-menu.php` — PRG, CSRF, `require_owner()`. All CRUD works server-side
  (add/edit/remove/reorder items·columns·links, upload/replace thumbnails). Reorder currently uses
  ▲▼ buttons. **This is the page being redesigned.**
- **Tests:** `php tests/nav_menu_logic.php` (28 pass).

**The problem:** `admin/nav-menu.php` uses raw **native** controls (plain-border text inputs, native
`<select>`, native checkboxes, native "Choose File", ▲▼ arrows, text "Delete" buttons). It does not
use the admin design system. This violates the project's **no-native-UI** rule (see CLAUDE.md /
memory: never use native selects/checkboxes/file inputs/arrows — always the styled house components).

---

## PART A — Redesign `admin/nav-menu.php` (view layer only)

**Goal:** every control uses the existing admin design system; reordering becomes smooth drag-and-drop
with a 6-dot grip; delete becomes an icon button with the house tooltip. **No server-side/model changes**
except making the reorder endpoints also accept an AJAX order payload (see A2).

### A1. Swap native controls for house primitives (all already in `admin/assets/admin.css`)

| Native control today | Replace with | Reference |
|---|---|---|
| Text inputs (Label, URL, Sub-label, Button note, "New link label") | `.inp` (add `.inp--sm` for compact rows) | `admin.css` ~L778 |
| `<select>` (Layout / Tag / Type) | Native `<select>` **enhanced to `.eselect`** via `admin-select.js` (progressive enhancement — keep the real `<select>` in markup, add the class/hook the enhancer expects) | `admin.css` ~L216–263; see any filter `<select class="filter-select">` usage |
| Checkboxes (Visible, Open in new tab) | `.togglerow` (styled toggle) or `.optchip` (styled chip) | `admin.css` ~L927 (`.optchip`), ~L963 (`.togglerow`) |
| "Choose File" (thumbnail) | `.filefield` (hidden native input + styled button + filename), with a live preview `<img>` | `admin.css` ~L939; and `admin/venue-edit.php` gallery uploader for the JS/markup |
| "Save" / "Save link" / "Save heading" (text buttons) | `.btn-icon .btn-icon--primary` + `admin_icon('check')` + `data-tip="Save"` | `admin.css` ~L198–213 |
| "Delete menu/column/link" + "remove" thumbnail (text buttons) | `.btn-icon .btn-icon--danger` + `admin_icon('trash')` + `data-tip="Delete menu"` etc. Keep `data-confirm="…"` on destructive ones | same |
| "Add link / Add column / Add menu" | Keep as **labelled** styled buttons `.btn .btn-primary` + `admin_icon('plus')` (adding is a deliberate action, not an icon-only) | existing `.btn` classes |

**Rule:** after this pass there must be **zero** raw native inputs/selects/checkboxes/file buttons with
default browser chrome (plain 1px borders). Everything matches the `.eselect` / `.inp` / `.btn-icon`
visual language used across the admin.

### A2. Drag-and-drop reorder (replaces the ▲▼ buttons)

**This pattern already exists — copy it from `admin/services.php` (the "Service pricing" page).**
That page does exactly the requested interaction:

- **Handle:** `admin_icon('grip', 18)` → the **6-dot** icon, wrapped in `<span class="svc-grip"
  data-tip="Drag to reorder" aria-hidden="true">`. (`.svc-grip` gives `cursor: grab`; `:active` →
  `cursor: grabbing`. `admin.css` ~L879.)
- **Row:** `draggable="true"` with a stable `data-id`.
- **On hold:** row gets `.is-dragging` → highlighted **brand border + lift shadow** (already styled,
  `admin.css` ~L878 `.svc-row.is-dragging`; reuse or clone as `.nv-*`).
- **Smooth reposition:** the JS listens for `dragstart`/`dragend`/`dragover`/`dragenter` and calls
  `list.insertBefore(dragged, …)` in `dragenter` so rows slide into place live under the cursor.
- **Save on drop:** `dragend` POSTs the new order via `fetch()` (FormData with `action=reorder`,
  `order=JSON.stringify(ids)`, `csrf_token`) — **no page reload**, instant feel.
- **Guard:** inputs/toggles inside a row call `e.stopPropagation()` on `mousedown` so typing/clicking
  a field never starts a drag.

**Copy the full JS from `admin/services.php` lines ~133–153** and adapt selectors.

**Three independent sortable scopes** (each its own list + reorder endpoint):
1. **Menus** (top-level `nav_items`) — grip on each menu card header.
2. **Columns** (`nav_groups`) within a menu — grip on each column header.
3. **Links** (`nav_links`) within a column — grip on each link row.

Server side: add/extend a `reorder` action per scope that takes `order` (array of ids) and rewrites
`sort_order`. The existing `item_up/down`, `group_up/down`, `link_up/down` handlers in
`admin/nav-menu.php` can be replaced by these AJAX reorder handlers (or kept as a no-JS fallback — see
decision D2). The `nav_move()` helper already in the file renumbers siblings; a reorder-by-array is a
small addition.

**Locked auto item (Restaurants):** keep it draggable (reposition only) but non-editable. Give it a
distinct locked style (dashed border + lock icon + `data-tip` "Filled automatically from your published
restaurant menus"). Server already blocks edits to `auto_source` items — keep those guards.

### A3. Layout / hierarchy polish (not just restyle)

- **Collapsible menu cards:** each top-level menu collapses to a one-line header (grip + name +
  visible toggle + delete icon); expand to reveal its columns/links. Removes the current "wall of
  forms". Use `<details>`/`<summary>` or a small toggle (the sidebar already uses `<details>` groups —
  match that).
- **Compact link row:** `grip │ thumbnail(+filefield) │ fields grid │ action icons` on a tidy line,
  not today's oversized full-width inputs. Two-column field grid (`.inp` fields) that collapses to one
  column on narrow widths.
- **Consistent spacing/cards** matching `admin/menus.php` and `admin/venue-edit.php`.

### A4. Guarantees / constraints

- **No-native check** is the acceptance bar: inspect the rendered page — no default select arrows, no
  plain-border inputs, no browser checkbox squares, no "Choose File" button.
- **Progressive enhancement:** `<select>`→`.eselect` and drag are JS-enhanced; keep valid native
  `<select>`s in markup so the enhancer can upgrade them (that's how the rest of the admin works).
- **Server logic, data model, ownership/auto guards, PRG endpoints, fallback nav, owner-only gating —
  unchanged.** This is a view-layer + reorder-transport change only.
- Re-run `php tests/nav_menu_logic.php` after; extend it only if a reorder endpoint signature changes.

### A5. Decisions (author's recommendation in **bold**)

- **D1 — Link save model:** explicit **save icon** per row *(recommended)* vs auto-save on blur/change.
- **D2 — No-JS reorder fallback:** **drag-only** *(recommended, matches services.php)* vs keep a
  hidden numeric `sort_order` field as a fallback.

### A6. Files this pass touches

- `admin/nav-menu.php` (markup + inline JS/CSS; reorder handlers).
- `admin/assets/admin.css` (only if new `.nv-*` classes are needed; prefer reusing `.svc-*`,
  `.btn-icon`, `.inp`, `.eselect`, `.optchip`, `.filefield`).
- Possibly `admin/assets/admin-select.js` hook usage (no changes to the enhancer itself).
- `tests/nav_menu_logic.php` (only if reorder endpoint signature changes).

**Reference implementations to copy from:** `admin/services.php` (grip + drag + AJAX reorder + icon
buttons + tooltips), `admin/venue-edit.php` (`.filefield` image upload + preview), any admin filter bar
(`.eselect` enhanced selects).

---

## PART B — Other outstanding to-dos (whole session)

Three requests were worked this session: (1) admin mega-menu builder, (2) editable property galleries,
(3) mobile hero bug. Code is written + locally verified but **nothing is committed or on production yet.**

### B1. Commit the work
Nothing is committed. Create a branch and commit (the repo default branch is `master`).
Changed/new files:
- **Mobile hero fix:** `maya-kobe.php`, `zuri.php`, `enkare-bofa.php`, `maya_ilai.php`,
  `my-amani.php`, `sandbox.php` (added `grid-template-rows:1fr` to the mobile `.gallery` rule).
- **Galleries:** new `gallery.php`; `includes/property-photo-grid.php` (15-cap + "See more");
  6 legacy `*-gallery.php` turned into 301 redirects; `includes/header.php` Gallery links updated.
- **Mega menu:** `db/migrations/add_nav_menu.sql`, `db/seeds/seed_nav_menu.php`,
  `includes/nav-data.php`, `admin/nav-menu.php`, `admin/_layout.php` (sidebar "Site Menu" link),
  `includes/header.php` (DB render + fallback), `tests/nav_menu_logic.php`, `CLAUDE.md` docs.

### B2. Apply DB migration + seed to PRODUCTION
The local `.env` points at a **local Postgres**, NOT production (RDS). Local migrations/seeds do **not**
reach live data. On production, after deploy:
1. Apply `db/migrations/add_nav_menu.sql` to the RDS database.
2. Run `db/seeds/seed_nav_menu.php` against production (or the equivalent insert).
Until then, the live site safely shows the **hardcoded fallback nav** (no breakage) — but the admin
Site Menu won't drive the live nav until seeded.

### B3. Deploy
Auto-deploys from GitHub → AWS ECS on push to `master` (per CLAUDE.md / AWS notes). Confirm the deploy
picks up the new files.

### B4. Verify on the live site (local images are broken locally — expected)
Property images 404 on localhost because the local DB holds production CloudFront keys; they load fine
on the deployed site. So visual checks belong on production:
- **Mobile hero:** reload a property page on a real phone — hero photo fills the section, no gap
  (this was the original reported bug). Test iPhone + Android widths.
- **Galleries:** listing "Photo Gallery" shows ≤15 + "See all N photos →" → opens
  `gallery.php?venue=<slug>` with all photos. Old `*-gallery.php` URLs 301 to it.
- **Mega menu:** desktop dropdowns + mobile drawer look identical to before (they render from the DB
  once seeded). Then edit something in Admin → Site Menu and confirm it changes the live nav.

### B5. Admin form click-through (not done locally)
The admin Site Menu POST handlers were **logic-tested** (SQL) but the forms weren't click-tested over
HTTP (no local login creds). After deploy, log in as owner and exercise: add/edit/delete a menu,
column, link; reorder; upload/replace a thumbnail. (This is superseded by the Part A redesign, which
should be click-tested thoroughly.)

### B6. Optional — fix local image preview
Nice-to-have for local dev only: the local `venue_images` rows point at prod CloudFront keys that 404
locally. Could re-seed local `venue_images` to local `images/…` files, or clear them so the static
fallbacks load. Not required — production is unaffected.

---

## Quick reference — house components (all in `admin/assets/admin.css`)

- `.inp`, `.inp--sm`, `.inp--num` — styled text/number field.
- `.eselect` (+ `admin-select.js`) — styled dropdown replacing native `<select>`.
- `.optchip` — styled checkbox chip. `.togglerow` — styled toggle.
- `.filefield` — styled file input (+ preview pattern in `admin/venue-edit.php`).
- `.btn`, `.btn-primary`, `.btn-sm` — buttons. `.btn-icon`, `.btn-icon--primary`, `.btn-icon--danger`,
  `.btn-icon--outline` — icon buttons.
- `.svc-grip` (grab/grabbing cursor), `.svc-row.is-dragging` (highlighted border+shadow) — drag.
- `data-tip="…"` — the house tooltip (used across venue-edit, services). `data-confirm="…"` — confirm.
- Icons via `admin_icon('grip'|'trash'|'check'|'plus'|'x'|'image'|'link'|'eye'|'copy', size)`
  (`includes/icons.php`).
- Drag reference: `admin/services.php` (markup ~L93–110, JS ~L133–153).
