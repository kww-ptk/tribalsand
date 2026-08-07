# Tribal Sand admin — UI cleanup spec

Verified audit of the admin UI. Every item is checked against the actual code, with
`file:line` cited, the current behaviour, and the concrete change. All 8 judgment
calls have been decided (marked **Decided**) using the recommended option.

- **38 changes** across **15 pages / areas**
- **Confirmed** = verified in code, ready to build
- **Decided** = a judgment call, resolved with the recommended option
- **✅ Done** = built on branch `feature/admin-ui-cleanup` (2026-08-07)
- Verification method: read against the rendered PHP markup + `admin/assets/admin.css`.
  No live screenshots (admin is login-gated); markup + styles give an exact read of the current look.

Suggested build order: start with the **table toolkit (T1–T4)** — Tours, Rooms, For Sale,
the Gate log and Messages all inherit from it.

## Build progress (branch `feature/admin-ui-cleanup`)

**✅ Done (phases 0–7):** Reusable table toolkit (T1–T4) · Toasts (N1–N2) ·
Tours (R1–R5) · Rooms (RM1) · For Sale (S1–S2) · Properties grid (P1–P2) ·
Messages (M1–M5) · **Front desk (F1–F5)** · **Dashboard (D1–D2)** · **Holds (H1)** ·
**Concierge (C1–C2)** · **Tasks (TK1)** · **Gate (G1–G2)** · **Submissions (SB1–SB3)**.
Phase 7 (2026-08-07) was visually verified in a logged-in preview pass: every
page rendered real data at 200, no 500s / PHP warnings, and the interactive
bits (kanban card links, form-mode chip, create-task / sign-in toggles, gate
log filters, in-house From/To datepicker auto-submit) were exercised live.

**✅ Done (phase 8):** Staff font (U1) — self-hosted Inter, verified live.
**Extended beyond U1 (by request):** Inter is now the font for the **entire
admin**, not just the Staff page — `body` + form controls + `<code>` on Inter,
sidebar included; stylistic access/booking-code monospace dropped. Genuinely
technical fields (iCal URLs, embed snippets, migrate output) keep their inline
monospace.

**⏳ Pending:** nothing — all 38 changes across the audit are built.

---

## Reusable table toolkit — global ✅ Done
`includes/admin-pagination.php` · `admin/assets/admin.css`
Built: `dt_empty()` helper; `.dt-body` min-height; pager band + top divider.

| # | Change | Status |
|---|--------|--------|
| **T1** | Always render the table (header + footer + a centred "Nothing to show") even with zero rows. Today it's inconsistent: Holds & Submissions print a bare `<p>` *outside* the table (`holds.php:131`, `submissions.php:134`); Tours/Tasks/Concierge/Gate/Messages drop an ad-hoc empty `<tr>`. Add one toolkit empty-state used by every list. | Confirmed |
| **T2** | Dynamic min-height — a short/empty table still fills a comfortable height with room above the footer, never flush to the bottom. Give `.dt-body` a min-height so the pager settles at a consistent baseline. | Confirmed |
| **T3** | Give the pager footer a background band + top divider matching the header. `.dt-pager-wrap` is just `padding:14px 16px` — no fill/rule (`admin.css:499`); the header row has `background:#f9fafb` + border (`admin.css:79`). | Confirmed |
| **T4** | Standing rule: any list you touch gets the filter section + search + pagination + this table. (Through-line for Tours, Rooms, For Sale, the Visitor log and the Messages thread list.) | Confirmed |

## Toasts — global ✅ Done
`admin/assets/admin.css:541` · `admin/_layout_end.php:149`
Built as pure CSS (toast JS was already position-agnostic).

| # | Change | Status |
|---|--------|--------|
| **N1** | Move toasts to the top-right. Currently bottom-centre — `#ts-toasts { left:50%; bottom:20px }` (`admin.css:541`). Slide/fade in from the top edge. | Confirmed |
| **N2** | Colour toasts to match the sidebar. Fill with the sidebar deep-teal `--sidebar-bg #102F3A`, light text + icon. **Decided:** card stays teal; success vs error reads via the coloured left-border accent (green / error-red) plus a tinted icon. | Decided |

## Front desk ✅ Done
`admin/frontdesk.php`
Kanban day-view (Arriving / In house / Departing), whole card links to the booking
(inner badge/phone links still work), one control row (tabs + live date + property
picker), topline and side Open button removed. Week view keeps its by-day list.

| # | Change | Status |
|---|--------|--------|
| **F1** | Arriving / In house / Departing → a Kanban board (3 columns), **not** draggable; clicking a card opens the booking. Today: three stacked lists via `$section()` (`frontdesk.php:198–200`), each card has a separate "Open →" button. Make the whole card the link to `/admin/booking.php?hold=`. | Confirmed |
| **F2** | Remove the "All properties · Fri 7 Aug 2026" line (`.fd-topline`, `frontdesk.php:152–156`). | Confirmed |
| **F3** | Put Today / Tomorrow / This week **and** the "All properties" dropdown on one row. Today they're two rows — property `<form>` filter (`:157`) above the `.fd-seg` tabs (`:170`). | Confirmed |
| **F4** | Keep the date (Fri 7 Aug 2026), dynamic, in that one row. F2 removes the old line, so re-home the live day label (`$dayLabel`, `:52`) into the F3 control row. | Confirmed |
| **F5** | Remove the divider rule on the card beside the Open button (`.fd-card__side`, `:96–99`). Dissolved by F1 — with the whole card clickable the Open button and its rule go; keep the phone link in the card body. | Confirmed |

## Dashboard ✅ Done
`admin/dashboard.php`
Form-mode moved into the header as a chip (`.fm-chip`) + `admin_icon('settings')`
gear linking to Settings; the full-width `.alert--info` banner removed.

| # | Change | Status |
|---|--------|--------|
| **D1** | Move Form-mode next to the "All Submissions" button; drop the standalone banner. Today it's a full-width `.alert--info` below the KPIs (`dashboard.php:56–59`); header only holds "All Submissions" (`:31–33`). | Confirmed |
| **D2** | The indicator shows the current mode + a settings gear linking to Settings. Mode is `enquiry` / `availability` (`settings.php:51`) — yours reads **Availability**. Show it as a chip + `admin_icon('settings')` link to `settings.php` (replaces the "Change in Settings" text). | Confirmed |

## Tours & Excursions ✅ Done
`admin/tours.php` — Rooms gets the same set; build once, apply twice.
Drag folded into shared `admin/assets/admin-dt-drag.js`; locks while filtered.

| # | Change | Status |
|---|--------|--------|
| **R1** | Rebuild on the reusable table (filter, search, pagination). Fully bespoke `<table id="toursTable">` today, no `dt` toolbar/pager (`tours.php:49–98`). Move query to `paginate_params()` + `search_where()`. | Confirmed |
| **R2** | Add drag-to-reorder as a **variant** of the reusable table (only Tours & Rooms). Drag works today via inline JS (`tours.php:100–127`) but is per-page and wiped by the AJAX body-swap. Fold it into the toolkit, re-binding after each swap, persisting via the existing `reorder` POST. **Decided:** with the drag variant on, the list defaults to a high per-page (effectively "All") so reordering spans the whole list, not just page 1. | Decided |
| **R3** | Photo column: centred "no photo" icon when there's no image. Today an empty grey box (`tours.php:69`). | Confirmed |
| **R4** | Add an "Actions" column header (last `<th>` is empty, `tours.php:58`). | Confirmed |
| **R5** | Swap the Edit/View text buttons (`tours.php:87–88`) for reusable icon buttons — `btn-icon btn-icon--outline` + `admin_icon('edit')` / eye or external-link — matching Holds & Concierge. | Confirmed |

## Rooms ✅ Done
`admin/rooms.php`

| # | Change | Status |
|---|--------|--------|
| **RM1** | Apply everything from Tours (R1–R5). Same bespoke table + inline drag (`rooms.php:65–113`, `:115–145`); fold the existing venue filter (`:48–56`) into the `dt` filter row. | Confirmed |

## Holds ✅ Done
`admin/holds.php`
"Active" status option removed; default landing filter is now "Pending only".

| # | Change | Status |
|---|--------|--------|
| **H1** | Remove the "Active (pending + confirmed)" item from the Status filter (`holds.php:354`) — also the page's default view (`:64`, `:76`). **Decided:** new default landing filter = "Pending only" (the queue that needs action). | Decided |

## Properties ✅ Done
`admin/venues.php` · nav "Properties" (a card grid — **not** the For Sale page).

| # | Change | Status |
|---|--------|--------|
| **P1** | Add the reusable filter section + pagination — but keep the card grid (no table). Plain `.prop-grid` today, no filter/search/pager (`venues.php:74–121`). **Decided:** generalise the `dt` toolkit so its swappable body can hold a card grid, not only a table — search + pagination then work over the grid. | Decided |
| **P2** | Remove the card footer; put an edit/view icon at the top-right of the image. Edit/View live in a bordered `.prop-card__footer` today (`venues.php:113–116`). **Decided:** icon overlays the image top-right — reveal on hover on desktop, always visible on touch. | Decided |

## For Sale listings ✅ Done
`admin/properties.php` · nav "For Sale" (the table — **not** the Properties grid).

| # | Change | Status |
|---|--------|--------|
| **S1** | Add the reusable filter section + search + pagination + table. Bespoke table + inline drag today, no `dt` toolkit (`properties.php:62–116`, `:118–148`). **Decided:** no drag on For Sale — it moves onto the standard `dt` toolkit; ordering stays by the existing columns. | Decided |
| **S2** | When there are no listings, still show the table (full height) with a centred "Nothing to show" — inherits toolkit T1 + T2. | Confirmed |

## Concierge desk ✅ Done
`admin/concierge-desk.php`
Empty assignee option now reads "Unassigned". Fixed 170px width applied to the
enhanced (`.eselect`) cell dropdown via `:has(> .cell-select)`, so every row's
control is uniform regardless of the selected name (the native `.cell-select`
width alone didn't reach the styled widget).

| # | Change | Status |
|---|--------|--------|
| **C1** | Kill the "— —" dashes around Unassigned in the Assigned column — the dropdown's empty option reads `— Unassigned —` (`concierge-desk.php:138`). Change to "Unassigned". | Confirmed |
| **C2** | Make the assign dropdown a uniform size. `.cell-select` has `max-width:170px` but no fixed width, so each row sizes to its selected name (`admin.css:128`). Give it a fixed width. | Confirmed |

## Tasks ✅ Done
`admin/tasks.php`
New-task form collapsed behind a "Create task" header button (reveals inline,
focuses the title; auto-opens if a create attempt just failed).

| # | Change | Status |
|---|--------|--------|
| **TK1** | Hide the New-task form; add a "Create task" button top-right of the header that reveals the form inline, above the table. The form is always open today (`tasks.php:207–249`); header has no action button (`:201–203`). Form already posts `action=create` — just gate its visibility. | Confirmed |

## Gate ✅ Done
`admin/gate.php`
"Sign in visitor" header button reveals the sign-in form inline. Visitor log now
uses the `dt` toolkit: filter (On site now / All visitors) + date + search +
pagination, powered by the new `fetch_visitors_paged()` in `includes/team.php`.

| # | Change | Status |
|---|--------|--------|
| **G1** | Same pattern as Tasks: a "Sign in visitor" button top-right that reveals the sign-in form inline, before the Arriving / Departing section. Form is always open today (`gate.php:136–166`); header is title + date (`:92–95`). | Confirmed |
| **G2** | Give the visitor log a filter section + pagination. Plain `data-table` today, no toolkit/pager; data from `fetch_visitors()` (`gate.php:168–202`, `:86`). **Decided:** filter = "On site / All" + a date; `fetch_visitors()` gains a paged/searchable variant so history (not just today) is reachable. | Decided |

## Messages ✅ Done
`admin/messages.php`

| # | Change | Status |
|---|--------|--------|
| **M1** | Remove the "Manage booking →" link + arrow in the thread list (`messages.php:74`). | Confirmed |
| **M2** | Make the whole thread row clickable — hover highlight + pointer cursor. Only the "Thread" cell is a link today (`:75`). | Confirmed |
| **M3** | Don't underline the thread link — global `.data-table a:hover { text-decoration:underline }` (`admin.css:84`) underlines it. Suppress for these rows; rely on the M2 hover state. | Confirmed |
| **M4** | Add padding to the chat area. The `#amThread` bubbles run edge-to-edge in the card (`messages.php:92–105`). | Confirmed |
| **M5** | Give the thread list the filter section + pagination + our table. Plain `data-table` from `fetch_admin_threads()` today, no toolkit (`messages.php:65–82`). | Confirmed |

## Submissions ✅ Done
`admin/submissions.php`
CDN flatpickr (CSS + JS) removed — resolves the "no CDN / no npm" violation.
**Deviation from SB1:** implemented as two independent single-date `dp-btn`
pickers (From / To) rather than a locked ci→co range — a submissions filter
often needs a from-date alone, and single pickers give clean per-field
auto-submit (the SB2 goal). Apply button gone; Clear link kept.

| # | Change | Status |
|---|--------|--------|
| **SB1** | One reusable range datepicker for From→To, using the in-house component (not flatpickr). Today two text inputs driven by **flatpickr from a CDN** (`submissions.php:231–232`, `:244–245`) — breaks the "no CDN / no npm" rule. Use `js/datepicker.js` range mode (`data-dp-role="ci"/"co"` + shared `data-dp-pair`) to drive `date_from`/`date_to`; delete the flatpickr CSS/JS. | Confirmed |
| **SB2** | Remove the "Apply" button (`:234`) — filtering fires on selection. Auto-submit when a date is picked or cleared; keep the "Clear" link. | Confirmed |
| **SB3** | Align the filter row with search + pagination. Already on the `dt` toolkit (`:212–242`), but the raw flatpickr inputs + Apply break the aligned control row; SB1/SB2 fix it. | Confirmed |

## Staff page — the "users" page ✅ Done
`admin/staff.php` · `admin/assets/admin.css` · `fonts/inter-var.woff2`
Self-hosted **Inter** (no CDN): one subsetted variable `.woff2` (Latin +
Latin-ext, weights 100–900, 122 KB) added at `fonts/inter-var.woff2` with the
SIL OFL license (`fonts/Inter-OFL.txt`). `@font-face` in `admin.css`, with
`body` set to Inter and `input, select, textarea, button, code, kbd, samp`
pulled onto `inherit` — so the **whole admin** (nav included) is one family.
Stylistic access/booking-code monospace removed (holds, submission-view,
front-desk `.fd-code`); technical URL/embed/migrate fields keep monospace.
Verified live: `document.fonts.check('16px Inter')` true, woff2 served 200, and
body / sidebar / headings / table / dropdowns / inputs / buttons / codes all
compute to Inter.

| # | Change | Status |
|---|--------|--------|
| **U1** | Use one font only — Inter — across the whole page. There's no `users.php`; closest is `staff.php` (nav "Staff"), which manages `admin_users`. It carries no inline `font-family` — admin is globally `system-ui` (`admin.css:21`), with `monospace` only for codes. **Decided:** target = the Staff page; Inter is self-hosted via `@font-face` (no CDN, per `CLAUDE.md`) and applied to the whole page, replacing the system-ui + monospace mix with one family. **One dependency:** the Inter `.woff2` file gets added to the repo. | Decided |

---

*Artifact (visual version): https://claude.ai/code/artifact/06dc762e-0151-417d-85c2-9f9eadc3f87f*
