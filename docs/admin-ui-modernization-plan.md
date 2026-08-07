# Admin UI Modernization — Plan & Task Breakdown

**Status:** Phases 0–4 implemented & browser-tested (2026-08-07). Phase 5 (datepicker) not started.
**Branch:** `feature/admin-ui-dropdowns-icons`
**Guiding rule:** No native/browser-default UI anywhere in the admin route. Every control uses a reusable, styled component. (Saved as a standing preference in memory.)

---

## Implementation status (2026-08-07)

**Phase 0 — Cleanup:** ✅ Done. `admin-table`→`data-table` fixed (audit, venue-edit); `.table-wrap` added where needed; `admin_action_btn()` helper added to `includes/icons.php`.

**Phase 2 — Lucide icons / arrows:** ✅ Done. Public booking calendar arrows → inline Lucide SVG (`includes/booking-widget.php`, verified: calendar opens + month nav works). Admin route swept — all glyph/emoji/unicode icon-controls and back-link/CTA arrows → `admin_icon()` (audit ✕, image-delete ✕, settings ↓, gantt nav/sync + datepicker, drag handles ⋮⋮, and ~20 `←`/`→` links). Glyphs added: search, chevron-left/right/down, chevrons-left/right, plus, minus, filter, calendar, arrow-left/right, download, grip.

**Phase 1 — Data-table search + pagination + count:** ✅ Toolkit built & tested; applied across the main list pages.
- Reusable toolkit: `includes/pagination.php` (`paginate_params`/`search_where`/`paginate_meta`), `includes/admin-pagination.php` (`dt_toolbar`/`dt_pager`/`dt_url`), styles in `admin.css` (`.dt-*`), `admin/assets/admin-table.js` (debounced search, per-page, pager → `&ajax=1` fragment swap + `pushState`, no-JS GET fallback, re-wires styled-confirm + re-enhances selects on swapped rows via `window.tsAdminWire` / `enhanceSelects`).
- Converted (all browser-tested — search, paging, per-page, count, filter coexistence, empty state, AJAX swap): **audit.php**, **submissions.php**, **concierge-desk.php**, **tasks.php**, **holds.php** (parent bookings paginated; nested request sub-rows stay grouped), **staff.php**, **guest-board.php**. DB-level pagination where the list is a direct query; PHP-side slice for aggregate-helper lists (tasks). Empty tables show a friendly "Nothing to show yet." note.
- Deliberately NOT paginated (verified per-page decision): sortable drag tables (rooms/tours/properties — pagination would break drag-reorder; small sets), the dashboard "recent" teaser, mywork (personal grouped board), messages (thread list) and the gate today-visitor log (small/daily). Any can adopt the toolkit in ~15 lines using the audit.php template.

**UI fixes (follow-up round):**
- **In-table dropdowns now overlay the table** and no longer clip: the enhanced `.eselect__menu` is `position: fixed` (z-index 900), positioned from the button on open (with flip-up near the viewport bottom) and re-positioned on scroll/resize. This also removed the **phantom vertical scrollbar** on `.table-wrap` (the old absolute menu, sitting inside `overflow-x:auto`, promoted `overflow-y` to auto). Verified on concierge-desk incl. AJAX-swapped rows.
- **Gate "Sign in a visitor" form** switched from a squeezed grid to a flex row (`flex:1 1 200px` per field) so every field fills the row width and shows its full placeholder, wrapping gracefully on narrow screens.

**Phase 3 — All dropdowns use the reusable dropdown:** ✅ Done. `admin-select.js` now enhances **every** `<select>` in `.admin-content` (opt-out via `data-no-enhance`; skips `multiple`/listboxes), preserves each context's width by measuring the native select first, and renders `<optgroup>` labels. Covers all 22 files via the one enhancer change (no per-file edits). AJAX-swapped content is re-enhanced.

**Phase 4 — "Enter …" placeholders:** ✅ Done. Added human placeholders to every text/email/tel/number/password/textarea field that lacked one (hold-new, submission-view, gate, guest-board, itinerary, services, staff, tasks, tour-edit, venue-edit, reset-password, _ws_plan, _ws_bill). Fields with existing descriptive `e.g.` placeholders were left as-is (more helpful than "Enter X"). Date inputs left for Phase 5.

**Known pre-existing bug (out of scope, flag for follow-up):** the public availability booking page (e.g. `maya-kobe-cottages.php`) logs `Uncaught TypeError: Cannot read properties of null (reading 'getAttribute')` on load — confirmed present with all this branch's changes reverted, so not introduced here. Calendar still functions.

---

## Context: what we already have (reuse these, don't reinvent)

| Component | Where | Reuse pattern |
|-----------|-------|---------------|
| Icon set | `includes/icons.php` — `admin_icon('name')` | Inline Lucide SVGs (no build step, so real `lucide-react` can't run) |
| Icon buttons | `admin.css` `.btn-icon` (+ `--primary/--outline/--danger`) | Wrap in `.row-actions` |
| Enhanced dropdown | `admin/assets/admin-select.js` | Enhances `select.filter-select`; native stays for no-JS |
| Styled confirm modal | `admin/_layout_end.php` `styledConfirm()` | Auto-upgrades `confirm()` + `data-confirm` |
| Toasts | `admin/_layout_end.php` `toast()` | Auto from `.alert.is-flash` (currently module-scoped) |
| Table styling | `admin.css` `.data-table` + `.table-wrap` | Styling only — no structural component |

Everything is loaded globally via `admin/_layout.php` (head) and `admin/_layout_end.php` (end). Any page using the layout inherits them.

> **Terminology note:** the user asks for "Lucide React" icons. This project has **no npm/build step**, so the `lucide-react` package cannot run. We use the same Lucide glyphs inlined as SVG through `admin_icon()` — visually identical, zero dependencies. All "Lucide" tasks below mean this.

---

## Phase 0 — Cleanup (agreed earlier, small, do first)

- [ ] **0.1** Fix undefined `admin-table` class → `data-table`
  - `admin/audit.php:67`, `admin/venue-edit.php:300` (currently unstyled)
- [ ] **0.2** Add missing `.table-wrap` scroll wrappers where a `data-table` has none
  - Candidates: `dashboard.php`, `staff.php`, `submissions.php`, `rooms.php`, `messages.php`, `guest-board.php` (verify each)
- [ ] **0.3** Add `admin_action_btn()` helper in `includes/icons.php`
  - Emits the repeated `<button class="btn-icon …" title aria-label>…icon…</button>` used in every Actions column; cuts duplication before we touch more tables.

**Est:** ~30–45 min. Low risk. No behavior change.

---

## Phase 1 — Reusable data table: search + pagination + count

**Goal:** every admin list table gets a search bar, pagination with a **10 / 25 / 50** page-size selector, and a "Showing X–Y of Z entries" count. Modern look, no native styling.

### DECISION: server-side pagination (chosen — "one step ahead")

We paginate in the **database**, not the browser. Each list page adds `LIMIT/OFFSET`, a `COUNT(*)`, and a search `WHERE`. This scales to any row count and is the durable choice. Trade-off vs client-side: every list page's query must be touched, so this is more work up front (accepted).

**State via query string (GET)** so it's shareable and coexists with existing filter dropdowns:
- `page` (1-based), `per` (∈ {10, 25, 50}, validated + clamped, default 10), `q` (search text).
- Page/per links **must preserve existing query params** (many pages already filter via GET) — build links from the current query, overriding only the changed key.

**Smooth UX without native full-page jank — AJAX fragment enhancement:**
- Baseline: plain GET reload works with **no JS** (truly server-side, robust).
- Enhanced: `admin-table.js` intercepts search typing (debounced), per-page changes, and pager clicks → `fetch()` the same URL with `&ajax=1`, page echoes **only the table partial** (rows + pager + count), JS swaps it in and updates the URL via `history.pushState`. No full reload, modern feel.

### Reusable server-side toolkit (so we don't reimplement per page)

- [ ] **`includes/pagination.php`** — helpers:
  - `paginate_params()` → reads/validates `page`, `per`, `q` from `$_GET`.
  - `search_where($cols, $q, &$params)` → builds `(col1 ILIKE :q OR col2 ILIKE :q …)` fragment + binds (empty string when no `q`). Each page passes its own searchable columns.
  - `paginate_meta($total, $page, $per)` → `[page, per, pages, from, to, total, offset]`.
- [ ] **`includes/admin-pagination.php`** — render partial: the toolbar (styled **search input** with Lucide `search` icon + **per-page reusable dropdown** 10/25/50) and the footer pager ("Showing X–Y of Z entries" + first/prev/pages/next/last using Lucide `chevrons-left`/`chevron-left`/`chevron-right`/`chevrons-right`). Emits links that preserve current query params.
- [ ] **Per-page AJAX contract** — a tiny shared guard: when `?ajax=1`, the page renders just its `<tbody>` + calls the pager partial and exits (no layout). Factor the row-rendering of each list into a includable chunk so both the full page and the AJAX response reuse it.

### Design/UX
- Toolbar + pager styled in `admin.css` (`.dt-toolbar`, `.dt-search`, `.dt-perpage`, `.dt-pager`, `.dt-count`) using brand tokens; matches `.eselect` / `.btn-icon` language. No native input/select chrome.

### Tasks
- [ ] **1.1** `includes/pagination.php` (params + search-where + meta helpers)
- [ ] **1.2** `includes/admin-pagination.php` (toolbar + pager render partial; per-page uses reusable dropdown)
- [ ] **1.3** `admin.css` toolbar/pager/count/search styles
- [ ] **1.4** `admin/assets/admin-table.js` — AJAX enhancement (debounced search, per-page, pager → fetch `&ajax=1`, swap partial, `pushState`); no-JS falls back to GET reload
- [ ] **1.5** Load `admin-table.js` (deferred, cache-busted) in `_layout.php`
- [ ] **1.6** Add Lucide glyphs `search`, `chevron-left/right`, `chevrons-left/right` to `icons.php`
- [ ] **1.7** Convert each list page: add `LIMIT/OFFSET` + `COUNT(*)` + search cols + AJAX-fragment branch. Per page below.
- [ ] **1.8** Test each: search, paging, per-page switch, count accuracy, filter+pagination coexistence, empty state, no-JS fallback, AJAX swap.

**List pages to convert (1.7):** holds, concierge-desk, tasks, mywork, gate, submissions, staff, rooms, tours, properties, messages, guest-board, audit, dashboard (verify each). **Skip / special:** nested-row tables (Holds request sub-rows stay grouped under their parent — paginate parents), tiny config/summary tables (e.g. `booking.php` 520px summary), print views (`bill-print`, `_ws_*`).

**Est:** ~1–1.5 days (server-side + per-page conversion is the bulk of the whole project).

---

## Phase 2 — Lucide icons across the whole admin route / remove native arrows

- [ ] **2.1** Replace text-character calendar arrows with Lucide
  - `includes/booking-widget.php:92` `&#8249;` → Lucide `chevron-left`; `:104` `&#8250;` → `chevron-right`
  - (This is the calendar in the screenshot; `js/booking-widget.js` wires `#bkPrevMonth`/`#bkNextMonth`.)
- [ ] **2.2** Audit the admin route for any remaining glyph/emoji/text icons or unicode arrows; swap to `admin_icon()`
- [ ] **2.3** Extend `icons.php` with any missing glyphs surfaced during the audit (calendar, search, chevrons, plus, minus, filter, etc.)

**Note:** `booking-widget.php` is shared with the **public** site. Changing its arrows to Lucide is in-scope (visual only), but flag that it touches the public booking flow, not just admin.

---

## Phase 3 — All dropdowns use the reusable dropdown (no native `<select>`)

**Current state:** `admin-select.js` only enhances `select.filter-select`. There are **~48 `<select>` across 22 admin files**; many are form inputs (assignee, settings, room/venue edit), not filters, so they're still native.

**Approach:**
- [ ] **3.1** Generalize the enhancer to target all admin selects — either broaden the selector (e.g. enhance every `<select>` inside `.admin-content` except opt-out `data-no-enhance`) or add a shared class (`ui-select`) and apply it everywhere.
- [ ] **3.2** Handle cases the current engine doesn't: `multiple` selects, very long option lists (add type-ahead / max-height already exists), optgroups.
- [ ] **3.3** Sweep all 22 files; confirm each `<select>` enhances and still submits correctly (the native element stays in DOM, so form posts are unaffected).

**Files with selects:** concierge-desk, hold-new, guest-board, gantt, submissions, holds, submission-view, tasks, staff, itinerary, property-edit, audit, frontdesk, conflicts, gate, room-edit, rooms, tour-edit, `_ws_requests`, `_ws_plan` (+ engine files).

---

## Phase 4 — "Enter …" placeholders on all fields

**Scope:** ~298 `<input>`/`<textarea>` across 33 admin files (many are hidden/checkbox/submit — actual text fields are fewer).

**Approach:**
- [ ] **4.1** Manual pass over each form page: every text/email/tel/number/textarea/search field gets `placeholder="Enter <what it is>"` (e.g. name → "Enter name", email → "Enter email", price → "Enter price").
- [ ] **4.2** Skip non-text inputs (hidden, checkbox, radio, file, submit) and fields where a placeholder is meaningless.
- [ ] **4.3** Keep phrasing consistent and human ("Enter guest name", not "Enter guest_name").

**Files (text fields present):** property-edit, tour-edit, venue-edit, gantt, staff, settings, room-edit, guest-board, holds, `_ws_bill`, services, itinerary, mywork, gate, hold-new, submissions, submission-view, tasks, login, reset-password, forgot-password, tours, rooms, properties, messages, booking, `_ws_requests`, `_ws_messages`, `_ws_plan`, conflicts, frontdesk, migrate.

---

## Phase 5 — Reusable calendar / datepicker (replace native `<input type="date">`)

**Current native date inputs (5):**
- `admin/hold-new.php:90,95` (check-in / check-out)
- `admin/submission-view.php:302,307` (check-in / check-out)
- `admin/tasks.php:172` (due date)

### DECISION: refactor the existing booking calendar into a shared module — public flow must stay intact

The public booking widget (`includes/booking-widget.php` + `js/booking-widget.js`) already is a full custom calendar (the screenshot). We **extract its calendar core** into a reusable shared module (e.g. `js/datepicker.js` + shared CSS) that both the public booking widget and admin consume. Single source of truth, no duplicate calendar.

**Hard constraint — do not regress the public booking flow.** The refactor is a pure extraction: the public widget keeps identical behavior (range select check-in→check-out, min-date/no-past, the 24h-hold logic, `#bkPrevMonth`/`#bkNextMonth` wiring, success modal + countdown). Strategy:
- Extract the calendar render + month navigation + date-selection into a mode-configurable module: `mode: 'range'` (public booking, admin check-in/out) and `mode: 'single'` (tasks due date).
- The public widget becomes a thin caller of the shared module with its existing options/callbacks — same DOM ids/classes preserved so nothing downstream breaks.
- Admin enhances `<input type="date" data-datepicker>` → keeps a hidden field for value/submit + styled popover calendar with **Lucide** arrows (ties into Phase 2).

**Verification (before merge):** exercise the public booking flow end-to-end in the browser preview — pick a range, confirm hold submission, success modal + 24h countdown, no-past enforcement — and confirm no console errors. This is the acceptance gate for Phase 5.

**Tasks:**
- [ ] **5.1** Extract shared datepicker module (`js/datepicker.js`) + shared CSS from the current booking calendar; support `mode: range | single`
- [ ] **5.2** Rewire the **public** `booking-widget.js` to call the shared module; verify identical behavior (regression pass)
- [ ] **5.3** Load the datepicker in admin via `_layout.php` (cache-busted)
- [ ] **5.4** Swap the 5 native admin date inputs to the reusable datepicker (hold-new ×2, submission-view ×2, tasks ×1) — check-in/out use `range`, due-date uses `single`
- [ ] **5.5** Test admin: value submission, required validation, min/max, keyboard access
- [ ] **5.6** Regression-test the public booking flow in browser preview (acceptance gate)

---

## Suggested execution order

1. **Phase 0** (cleanup) — quick, unblocks table work
2. **Phase 2** (Lucide/arrows) — shared glyphs feed Phases 1 & 5
3. **Phase 1** (table search/pagination) — biggest UX win
4. **Phase 3** (dropdowns everywhere)
5. **Phase 5** (datepicker)
6. **Phase 4** (placeholders) — mechanical, can run in parallel / last

## Decisions locked in
- ✅ **Pagination: server-side** (DB `LIMIT/OFFSET` + `COUNT(*)`), state in query string, AJAX-fragment enhancement over a no-JS GET fallback.
- ✅ **Page-size selector: 10 / 25 / 50** (default 10), rendered with the reusable dropdown.
- ✅ **Datepicker: refactor** the existing booking calendar into a shared module (`range` + `single` modes); **public booking flow must remain intact** (regression pass is the acceptance gate).
- ✅ **Lucide** = inline SVG glyphs (no npm/build); `lucide-react` package not usable.

## Remaining open items
- **1.b (revised)** Final list of which tables get pagination vs stay static — confirm during the per-page pass (nested Holds sub-rows, tiny summary/print tables excluded).
- Phase 2 & 5 edit the **public** `booking-widget` (visual arrows + calendar extraction). Confirmed acceptable given the intact-flow constraint + regression gate.

## Cross-cutting
- Cache-bust every new/edited CSS/JS in `_layout.php` (`?v=filemtime()`) per project convention.
- All new components: keyboard-accessible, `aria-*` labelled, no-JS graceful fallback (native element stays in DOM), themed with brand tokens.
- Expose `toast()` on `window` if pages need to fire toasts directly (minor, optional).
