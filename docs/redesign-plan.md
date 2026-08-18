# Tribal Sand — Admin & Guest-Portal Redesign Plan

> Ordering is my recommendation; each item lists the files it touches, the
> approach, effort and risk so we can pick what to build and in what order.
>
> **Status (2026-08-10): Phases 1–4 (items 1–17) are implemented, and item 18
> Stage 1 (the sidebar navigation shell) is live.** Remaining: item 18 Stage 2
> (in-content action forms) + Stage 3 (in-content links) — see the item-18
> breakdown below.
>
> Follow-up polish landed alongside #18 Stage 1: service-pricing padding; a thin
> non-native sidebar scrollbar; the **Operations** nav group open by default
> (others closed unless active, user overrides remembered); and `admin/staff.php`
> + `admin/guest-board.php` rebuilt on the reusables (dt table/filter/pager
> already present; native radios/checkboxes/selects/file/date inputs replaced with
> `.optchip` / `.filter-select`→`.eselect` / `.toggle` / `.filefield` / `.dp-btn`
> + a styled time select, and row actions moved to `.btn-icon`). `js/datepicker.js`
> now exposes `window.initDatepickers` so pickers re-bind after a shell/table swap.
>
> Phase 4 landing notes: #14 service pricing → card-per-service with the shared
> `.inp`/`.btn-icon`/`.toggle` language (POST + reorder contract unchanged); #15
> submissions → internal staff-only notes thread (new `submission_notes` table +
> `add_submission_notes.sql` migration, `includes/submission-notes.php`, all
> `*_supported()`-guarded; note-count badge in the list); #16 guest portal →
> in-page section tabs inside Home (Your stay / What's on / Calendar / Requests;
> bottom tab bar unchanged; "What's on" tab only when the board has posts) and the
> whole portal standardized on self-hosted Inter; #17 sidebar → collapsible
> Operations / Bookings / Catalog / Admin groups (styled `<details>`,
> localStorage state, active group auto-opens, empty groups suppressed per role).

## How I ordered this

Three principles drive the ordering:

1. **Build shared foundations before the things that ride on them.** Two of your
   asks — *no page flicker* and *skeleton loading* — are cross-cutting
   infrastructure. If we redesign the booking-workspace tabs first with plain
   full-page reloads and *then* add no-flicker navigation, we redo the tabs. So a
   thin "content-swap + skeleton" shell comes early, and the tab/reusable work
   rides on it.
2. **Quick, low-risk, high-visibility polish first** — the workspace tab
   restyle, the copy-portal-link, the task double-create bug. These are small,
   safe, and you see them immediately.
3. **Bigger redesigns and net-new features last** — service pricing, the
   submissions conversation feature, the full guest-portal redesign.

There's one dependency worth calling out up front: **the workspace-tab restyle
(#4) and the no-flicker shell (#1) are coupled.** Doing the shell first means the
new tabs are AJAX-swapped from birth. **Decision: shell-first** — restyling the
tabs first would mean redoing them once the swap layer lands, so we pay that cost
once. See "Decisions" at the end for the full set of resolved choices.

---

## What "our reusables" are (shared vocabulary for this doc)

| Reusable | Where | Used by |
|----------|-------|---------|
| `.tabs` / `.tab-btn.is-active` (underline tabs) | `admin/assets/admin.css:333` | *nowhere yet in the workspace* — this is the component your screenshot #2 shows |
| `dt_toolbar()` / `dt_pager()` / `dt_empty()` (search + per-page + pager + empty state) | `includes/admin-pagination.php` | Holds, Submissions, Tasks, Messages-list |
| `admin-table.js` (AJAX body swap, no reload) | `admin/assets/admin-table.js` | same list pages |
| Enhanced select (`.filter-select` → `admin-select.js`) | `admin/assets/admin-select.js` | all filter dropdowns |
| Datepicker button (`.dp-btn` + `js/datepicker.js`) | `js/datepicker.js` | Holds/Submissions/Tasks/Convert |
| `.btn-icon` / `.btn-icon--primary|danger|outline` | `admin.css:174` | row actions |
| Styled confirm + toast + "Working…" arm | `admin/_layout_end.php` | site-wide |
| `admin_icon()` (Lucide set) | `includes/icons.php` | everywhere |

The recurring rule from `MEMORY.md` → **no native selects / date inputs / dialogs /
number-spinners**; always the styled equivalents above.

---

## Phase 1 — Foundations (build these first)

### 1. No-flicker navigation shell (content swap, not full reload)
**Why first:** every tab click and most button actions currently do a full-page
`GET`/`POST` reload — that's the flicker you're seeing. Fixing it once, centrally,
means every later redesign inherits smooth navigation.

**Current state:** `admin-table.js` already swaps *list bodies* (`.dt-body`) via
`?ajax=1` fragments with `history.pushState`. We extend that same, proven pattern
to two more cases:
- **Tab navigation** inside `admin/booking.php` (Requests/Messages/Plan/Bill/…):
  intercept the tab links, fetch `?ajax=1`, swap only the tab-panel container.
- **PRG action forms** (Accept/Done/Confirm price/etc.): submit via `fetch`,
  then swap the affected panel instead of a full redirect. The server already
  redirects with a flash → we surface that flash as the existing toast.

**Files:** new `admin/assets/admin-nav.js`; small refactor in `admin/booking.php`
to wrap each tab body in a swappable container and honor `?ajax=1`; reuse the
toast in `_layout_end.php`.

**Effort:** M–L · **Risk:** M (touches navigation; must keep the no-JS fallback
working — every link/form still works on full reload).

**Guardrail:** keep server-rendered fallback intact (progressive enhancement, same
as `admin-table.js` does today).

### 2. Skeleton-loading system
**Why here:** it plugs directly into the swap points from #1 — show a skeleton in
the target container the moment a swap starts, replace it when the fragment lands.

**Approach:** one small CSS utility (`.skeleton`, shimmer keyframe, theme-aware)
plus a couple of skeleton *templates* per content shape (table rows, card grid,
chat bubbles, KPI tiles). The swap code inserts the right template based on a
`data-skeleton="table|cards|chat|detail"` hint on the container.

**Files:** `admin.css` (+ `css/portal-app.css` for the guest side), the swap JS
from #1, `data-skeleton` hints on each swappable container.

**Effort:** M · **Risk:** L (purely additive/visual).

### 3. Tooltip system (site-wide, replaces native `title=`)
**Why here:** it's a shared primitive many later items want. Today tooltips are
native `title=` (slow, ugly, inconsistent). Build one lightweight CSS/JS tooltip
and adopt it everywhere.

**Approach:** a `data-tip="…"` attribute + a single delegated JS listener that
positions a styled bubble; theme-aware; respects touch (no hover on mobile).
Migrate existing `title="…"` on `.btn-icon`, tab badges, status chips, etc. to
`data-tip` (can be a scripted find/replace pass, keeping `aria-label` for a11y).

**Scope (decided):** admin now. The guest portal is touch-first and hover
tooltips don't fire on touch, so we only extend it to the portal's genuine
hover/desktop controls if any remain after #16 — not a blanket rollout.

**Files:** `admin.css` + new `admin/assets/admin-tip.js`; sweep across admin
templates.

**Effort:** M · **Risk:** L.

---

## Phase 2 — Booking workspace (`admin/booking.php`) — the core of your asks

### 4. Restyle the workspace tabs (Requests / Messages / Plan / Bill / Check-in / Details)
**This is your #1 request.** Replace the button-styled tabs sitting in a white
`.card` (screenshot #1) with the existing underline `.tabs` / `.tab-btn`
component (screenshot #2 look).

**Change:** `admin/booking.php:158-168` — drop the `<div class="card">…btn-primary/
btn-outline…</div>` and render `<nav class="tabs"><a class="tab-btn is-active">`.
Move the count badges (e.g. Requests (3), Messages (2)) into a small
`.tab-btn__count` pill. Remove the white card background entirely.

**Files:** `admin/booking.php`; minor `.tab-btn__count` CSS in `admin.css`.
**Effort:** S · **Risk:** L. Rides on #1 for the no-reload swap.

### 5. Messages tab — thread list uses the data-table toolkit; real chat area
Two parts:

**5a. Thread list** (`admin/_ws_messages.php`, list branch): today it's a bare
`.data-table` with a plain underlined link per thread and no search/pager. Wrap it
in the `dt` toolkit (`dt_toolbar` + `dt_pager` + `dt_empty`) exactly like
`admin/messages.php` already does, and make each row a `.dt-rowlink` (whole-row
click) instead of a lone underlined link.

**5b. Chat area** (thread branch): give it a proper chat surface —
- a scrollable message region with real padding (fix the "no padding" issue),
- consistent guest/staff bubbles (reuse the `.am-msg` styles; lift the inline
  styles into CSS classes so the initial render and the poll-appended bubbles
  match — CLAUDE.md already calls this out),
- a styled composer (textarea + send) that isn't flush to the edges.

**Files:** `admin/_ws_messages.php`, `admin.css` (new `.am-thread` / `.am-msg` /
`.am-composer` classes), reuse `admin-chat.js`. **Effort:** M · **Risk:** L.

### 6. Plan tab — reusables + kill native controls
`admin/_ws_plan.php` currently uses native `<select>` (Day, Category), native
`<input type=time>`, and bare text inputs. Convert to:
- enhanced selects (`.filter-select`)/(the `eselect` style),
- the styled datepicker/time control instead of native `type=time`/`type=date`,
- style the "Add item" form and the per-day list to match card/table language.

**New reusable variant needed:** a compact **inline "add-row" form** pattern and a
**timeline/day-grouped list** style — I'd add these as documented variants so Bill
and Check-in can reuse them too. **Files:** `admin/_ws_plan.php`, `admin.css`.
**Effort:** M · **Risk:** L.

### 7. Bill tab — reusables
`admin/_ws_bill.php`: the two tables become the shared `.data-table` styling with
consistent right-aligned money columns; the inline price inputs and "Add charge"
row use the styled number field (no native spinner) and `.btn-icon` actions;
"set a price" badge and totals row get consistent treatment. No pager needed
(short lists) — that's fine per your "tables that don't need it, don't add it."
**Files:** `admin/_ws_bill.php`, `admin.css`. **Effort:** M · **Risk:** L.

### 8. Check-in tab — reusables
`admin/_ws_checkin.php` (multi-guest, recently merged): audit for native
controls and inline styles; apply the styled selects/inputs, `.btn-icon` actions,
and card/table language. Roster of party guests → shared table styling.
**Files:** `admin/_ws_checkin.php`, `admin.css`. **Effort:** M · **Risk:** M
(newest, most logic — tread carefully, keep `checkin_supported()` guards).

### 9. Details tab — reusables, no native inputs
In `admin/booking.php:181-198` the Details panel uses a raw `<input readonly>` for
the portal link and bare confirm/cancel buttons. Convert to the detail-grid
pattern (like `submission-view.php`), a proper **copy-link control** (shared with
#10), and styled action buttons with confirm. No table needed beyond the
detail grid. **Files:** `admin/booking.php`, `admin.css`. **Effort:** S · **Risk:** L.

---

## Phase 3 — List pages & shared UI

### 10. Guest column — better "Copy portal link"
On `admin/holds.php` (and the same block in `submission-view.php`) the copy link is
a tiny grey button crammed onto the code line. Redesign: put the code on its own
line, and the portal link on the **next row** with a clear **icon button**
(`admin_icon('copy')` / `admin_icon('link')`) + tooltip ("Copy guest portal
link"), reusing the existing `.copy-link` JS (already handles clipboard + "Copied!"
feedback). Make it a shared partial so Holds, Submission-view and the Details tab
all render it identically. **Files:** `admin/holds.php`, `admin/submission-view.php`,
new `includes/copy-link.php` partial, `admin.css`. **Effort:** S · **Risk:** L.

### 11. Task double-creation bug — investigate + fix
**Hypothesis (from code):** `admin/tasks.php` create is a single clean `INSERT`
with PRG redirect, so the DB path is correct. But the create form has **no
submit-guard** — the shared arming in `_layout_end.php` only wires
`data-confirm` buttons and `booking-request-action.php` forms. A fast double-click
(or Enter + click) posts twice → two tasks. Also worth ruling out a duplicate
event binding on the toggle.

**Plan:** (a) reproduce (double-click Create), (b) add a generic "disable submit
button + guard against re-submit" to the shared layer so *every* create form is
covered, not just tasks. **Files:** `_layout_end.php` (shared arm), verify on
`admin/tasks.php`. **Effort:** S · **Risk:** L. *Reproduction needed to confirm.*

### 12. Messages page — highlight unread
`admin/messages.php` thread list already shows an orange unread count badge, and
the sidebar shows a total. Add clearer **row-level emphasis** for threads with
unread guest messages (bold guest name + subtle background/left-accent), and make
the "Unread only" filter more prominent. Same treatment for the workspace thread
list (#5a). **Files:** `admin/messages.php`, `admin/_ws_messages.php`, `admin.css`.
**Effort:** S · **Risk:** L.

### 13. Channel conflicts — adopt the reusables
`admin/conflicts.php` uses a native `<select>` filter and hand-rolled cards. Bring
it onto the same language: styled filter select, and — since it's a list — either
the `dt` toolkit (search + pager over conflicts) or at minimum the shared card /
detail-grid / badge styling and `dt_empty` empty state. Keep the resolve logic and
audit trail untouched. **Files:** `admin/conflicts.php`, `admin.css`.
**Effort:** M · **Risk:** L.

---

## Phase 4 — Bigger redesigns & new features

### 14. Service pricing — UI/UX redesign (keep all logic)
`admin/services.php` works (add/rename/price/toggle/drag-reorder for Laundry &
Transfer) but the UI is a stack of flat form-rows with its own ad-hoc `<style>`.
Redesign into a cleaner card-per-service layout with the shared table/`.btn-icon`
language, styled number fields (no native spinner), a nicer drag handle, and the
`dt_empty` empty state — **without changing** the POST actions
(`add`/`save`/`delete`/`reorder`) or the reorder JSON contract. Reuse the shared
drag helper (`admin-dt-drag.js`) if it fits, rather than the bespoke drag code.
**Files:** `admin/services.php`, `admin.css`. **Effort:** M · **Risk:** M
(preserve the reorder/save semantics exactly).

### 15. Submissions — conversation / notes thread (like Seven Island)
Net-new feature. `admin/submission-view.php` today shows guest details, tracking,
convert-to-hold, delete — but **no internal notes / conversation log**. Add an
internal, staff-only notes thread per submission (who wrote what, when), matching
the Seven Island pattern.

**Needs a new DB table**, e.g. `submission_notes (id, submission_id, admin_id,
body, created_at)` via a migration in `db/migrations/` (follow the existing
`*_supported()` pre-migration-safe guard pattern so older deploys don't 42P01).
UI: a thread panel on the submission view + an "add note" composer; optionally a
note-count badge in the submissions list.

**Scope (decided): internal staff-only notes thread.** A guest-facing
conversation already exists via the Messages surface, so this is the CRM-style
internal log (who wrote what, when) — not a second guest channel. Optional
`@mention`/assignment can come later; v1 is a plain timestamped note thread with
an author.
**Files:** new migration, `includes/` helper, `admin/submission-view.php`,
`admin/submissions.php`. **Effort:** L · **Risk:** M (schema + new surface).

### 16. Guest booking portal redesign (`booking.php` + `includes/app/*`)
Reference: `https://tribalsand.onrender.com/booking?ref=…&view=home`.

Today the guest portal is a long scroll per view, navigated by a **bottom tab bar**
(`includes/app/nav.php`: Home / Activities / Messages) with **full page reloads**
per tab. Your asks:
- **Tabbed sections so the guest doesn't scroll the whole thing** — break the long
  Home view into in-page tabbed sections (e.g. Stay / Trip / Essentials /
  Requests) with an active-tab highlight. **Decided: keep the bottom tab bar**
  (Home / Activities / Messages — the correct mobile pattern) **and add the
  in-page section tabs inside Home**, since the scroll problem is *within* Home,
  not across the top-level views.
- **Apply the admin font** across the entire guest portal (currently Cormorant/
  Jost/Inter mix) — **decided: standardize on the admin's self-hosted Inter**.
- **No native components** — same rule as admin.
- **Active tab highlighted** (the bottom nav already has `.is-active`; carry that
  into the new in-page tabs).
- Rides on the **no-flicker shell (#1)** and **skeletons (#2)** so tab switches are
  instant.

**Files:** `booking.php`, `includes/app/nav.php`, `includes/app/home.php` (+ the
`_trip.php` / `_services.php` / `_stay_essentials.php` / `status-header.php`
partials it pulls in), `css/portal-app.css`, `js/booking-manage.js`.
**Effort:** L · **Risk:** M (it's the guest-facing surface; test the check-in
gate and pending-countdown paths).

### 17. Sidebar nav — group into collapsible sections
`admin/_layout.php` renders one long flat nav (Front desk, Dashboard, Tours,
Rooms, Properties, For-Sale, Calendar, Holds, Concierge, Tasks, Gate, Messages,
Submissions, Conflicts, Audit, Service pricing, Settings, Guest board, Staff).
Group into a few collapsible sections with headers, e.g.:
- **Operations:** Front desk, Concierge, Messages, Tasks, Gate, My work
- **Bookings:** Holds, Calendar, Submissions, Conflicts
- **Catalog:** Rooms, Properties, Tours, For-Sale, Service pricing
- **Admin:** Dashboard, Staff, Settings, Audit, Guest board

Each group is a `<details>`-style disclosure (styled, not native chrome),
remembering open/closed state in `localStorage`, and auto-opening the group that
contains the active page. Keep the role/job visibility logic exactly as is.
**Files:** `admin/_layout.php`, `admin.css`, small nav JS. **Effort:** M ·
**Risk:** L (structure keeps the same links; just reorganizes).

---

## Phase 5 — Admin-wide shell rollout (deferred — do after Phases 1–4)

### 18. Extend the no-flicker shell + skeletons across the entire admin
**Why:** Phases 1–3 delivered no-flicker on the booking-workspace tabs/actions
(#1) and skeletons on those swaps (#2). The list pages already swap only their
table body via `admin-table.js` (with a plain opacity fade, **not** skeletons),
and every other admin page — Dashboard, Rooms, Venues, Settings, Services,
Gantt, Conflicts-resolve, Staff, etc. — still does a full-page reload on every
navigation and action. The goal now: **make every admin navigation and action
feel instant** with one app-shell that swaps `.admin-content` and shows a
skeleton, so the sidebar/topbar never repaint.

**Current coverage (baseline this item builds on):**
- No-flicker shell (`admin/assets/admin-nav.js`) → **booking workspace only**
  (`[data-ws]`): tab switches + in-panel action forms.
- No-reload body swap (`admin/assets/admin-table.js`) → **list pages** (Holds,
  Messages list, Tasks, Submissions): search / filter / pager only, opacity fade.
- Skeletons → **booking-workspace swaps only**. Tooltips are already site-wide.

**Approach:**
- **Shell-wide navigation:** intercept `.sidebar__link` clicks (and in-admin
  links) → fetch the target with `?ajax=1`, swap only `.admin-content`, update
  `history` + the active nav highlight (+ the mobile drawer close). Each admin
  page grows an `?ajax=1` branch that emits just its content region — factor the
  buffer-and-emit trick `holds.php`/`booking.php` already use into a shared
  helper (e.g. `admin_content_fragment()` in a new `includes/admin-shell.php`)
  so pages opt in uniformly rather than each hand-rolling it.
- **Action forms everywhere:** generalise `admin-nav.js`'s fetch-submit →
  follow-PRG-redirect → swap → toast so it works for **any** admin page's forms,
  not just `[data-ws]` (Settings, Rooms, Services, Conflicts-resolve, Staff…).
  Keep the styled-confirm path and the no-JS full-reload fallback.
- **Skeletons on every swap:** give each swappable region a `data-skeleton` hint
  (`table | cards | detail | form | chat | kpi`) and reuse the `admin-nav.js`
  template builder for list-page swaps too — retire the plain
  `.dt-body.is-loading` opacity fade in favour of real skeletons.

**Files:** `admin/assets/admin-nav.js` (promote from workspace-only to an
admin-wide shell), `admin/_layout.php` (mark `.admin-content` swappable + the nav
as `data-nav`), new `includes/admin-shell.php` (shared content-fragment helper),
an `?ajax=1` content branch per admin page, `admin.css` (more skeleton
templates). Reconcile ownership with `admin-table.js`.

**Effort:** L · **Risk:** M–H — touches **every** admin page's top-level render
and navigation. Must keep each page's full-reload fallback working, preserve deep
links / back-forward, and avoid double-binding the existing `admin-table.js`
body-swap on list pages.

**Guardrails / decisions to make during build:**
- Progressive enhancement: every link and form still works on a full reload.
- **One swap owner per region:** the shell owns `.admin-content`; either
  `admin-table.js` keeps owning the inner `.dt-body`, **or** it's folded into the
  shell. Pick one explicitly so the two layers never fight over the same swap.
- `prefers-reduced-motion` respected (already handled in the skeleton CSS).
- Roll out page-by-page (each page's `?ajax=1` branch is independent), so a page
  without the branch simply falls back to a full navigation — no big-bang cutover.

#### Stage breakdown (how #18 is actually being rolled out)

- **Stage 1 — sidebar navigation shell. ✅ DONE.** New param **`?shell=1`** (kept
  distinct from `?ajax=1`, which still means "inner `.dt-body`/`[data-ws-panel]`
  only" — that's the resolved swap-ownership boundary). It's implemented at the
  **layout level**, not per page: `includes/admin-shell.php` +
  `_layout.php`/`_layout_end.php` capture just `.admin-content` (with
  `data-menu`/`data-title`) on `?shell=1`, so **every** admin page inherits it with
  no per-page branch. `admin/assets/admin-nav.js` grew a shell layer that
  intercepts sidebar links → page skeleton → swap `.admin-content` → update title +
  active nav (+ open active group) → re-execute the page's inline `<script>`s →
  re-enhance (tips/selects/tables/drag/**datepickers**) → re-init the workspace →
  pushState; cross-page back/forward re-swaps (same-path pops stay with the
  workspace/data-table layers). All 19 sidebar targets verified to emit a clean
  fragment; full render byte-identical without `?shell=1`. Interactive
  (logged-in) confirmation still pending.
- **Stage 2 — in-content action forms. ✅ MECHANISM BUILT + enabled on Settings
  (opt-in).** `admin-nav.js` gained `shellSubmit`: any POST form marked
  `[data-shell-form]` inside `.admin-content` submits via fetch → follows the PRG /
  inline re-render → swaps `.admin-content` from the response → toasts the flash
  (and strips the now-redundant inline banner). Bound in the **capture phase** on
  document so it precedes the bubble-phase site-wide double-submit guard +
  styled-confirm listeners in `_layout_end.php` (both early-return on
  `e.defaultPrevented`, so no collision). Reuses the workspace submit's exact
  post-commit safety (rejection handler as the 2nd `.then` arg = fetch-failure only
  → safe native resubmit; try/catch around rendering → full-nav, never resubmit) +
  its own in-flight guard (double-click safe). No-JS fallback intact (plain POST).
  **Enabled on `admin/settings.php`'s two save forms** (`save_general`,
  `change_password`); the CSV-export form is deliberately left as a full submit
  (it streams a download). Rollout to Rooms/Staff/Services/Conflicts = add
  `data-shell-form` to each PRG save form (never to file-download/streaming forms).
  ⚠️ **Interactive logged-in confirmation still pending** (no local admin login /
  prod DB) — see the test steps handed to the owner.
- **Stage 3 — in-content links + real skeletons on list swaps. ◐ PARTIAL.** The
  shell click interception now also catches opt-in in-content links
  (`a[data-shell-link]`, `/admin/` GET) → `shellNavigate`, so pages can bring row
  "View" / back links into the shell page-by-page (mechanism live, dormant until a
  link opts in). **Deferred:** retiring `admin-table.js`'s opacity fade for
  `data-skeleton` templates — that touches the list-swap owner and the plan warns
  against double-binding it; left for a pass that can be interactively verified.

---

## Recommended build order (summary)

| # | Item | Phase | Effort | Risk | Rides on |
|---|------|-------|--------|------|----------|
| 1 | No-flicker content-swap shell | 1 | M–L | M | — |
| 2 | Skeleton-loading system | 1 | M | L | 1 |
| 3 | Tooltip system | 1 | M | L | — |
| 4 | Workspace tabs → underline component | 2 | S | L | 1 |
| 5 | Messages tab: dt list + real chat area | 2 | M | L | 1,2 |
| 6 | Plan tab reusables | 2 | M | L | — |
| 7 | Bill tab reusables | 2 | M | L | — |
| 8 | Check-in tab reusables | 2 | M | M | — |
| 9 | Details tab reusables | 2 | S | L | 10 |
| 10 | Copy-portal-link redesign (shared partial) | 3 | S | L | — |
| 11 | Task double-create fix | 3 | S | L | — |
| 12 | Unread-message highlight | 3 | S | L | — |
| 13 | Channel conflicts reusables | 3 | M | L | — |
| 14 | Service pricing redesign | 4 | M | M | — |
| 15 | Submissions conversation/notes | 4 | L | M | — |
| 16 | Guest portal redesign (tabs + font) | 4 | L | M | 1,2 |
| 17 | Sidebar nav grouping | 4 | M | L | — |
| 18 | Admin-wide no-flicker shell + skeletons | 5 | L | M–H | 1,2 |

**Decided order: 1 → 2 → 3 first** (foundations), then Phase 2 onward. Building
the no-flicker shell and skeletons first means every tab/reusable redesign lands
on smooth navigation from the start, with no rework. The two quick fixes that are
fully independent — **#11 (task double-create)** and **#12 (unread highlight)** —
can be pulled forward and shipped any time without waiting on the foundations,
since they touch nothing the shell changes.

---

## Decisions (resolved — no longer open)

1. **Sequencing → shell-first.** Build #1 (no-flicker shell) and #2 (skeletons)
   before the workspace tab restyle (#4), so tabs are AJAX-swapped from birth and
   we don't redo them. Independent quick fixes (#11, #12) may go earlier.
2. **Submissions (#15) → internal staff-only notes thread.** Not a second
   guest-facing conversation (Messages already covers guest↔staff). CRM-style
   timestamped notes with an author; new `submission_notes` table, migration-safe.
3. **Guest portal (#16) → keep the bottom tab bar AND add in-page section tabs
   inside Home.** The scroll problem is within Home, so that's where the tabs go.
   Font standardizes on the admin's **self-hosted Inter**.
4. **Tooltip scope (#3) → admin first.** Guest portal is touch-first (hover
   tooltips don't fire on touch); extend only to any genuine desktop hover
   controls that survive #16, not a blanket rollout.
5. **Verification → build migration-safe, apply the migration locally, then test.**
   Every DB-touching change uses the `*_supported()` guard pattern so it can't
   break a pre-migration deploy. Locally I'll apply `add_multiguest_checkin.sql`
   (and the new `submission_notes` migration) before testing against
   `D:\php84\php.exe -S localhost:8765`, so testing doesn't depend on the DB's
   current migration state.
6. **No-flicker + skeletons → go admin-wide, but later (Phase 5, #18).** Phases
   1–3 scoped these to the booking workspace (plus the pre-existing list-page
   body swaps). The agreed direction is to extend the content-swap shell and
   skeletons to the *entire* admin, rolled out page-by-page after Phase 4, with
   a clear swap-ownership boundary against `admin-table.js`.

---

*Files reviewed for this plan: `admin/booking.php`, `admin/_ws_{requests,messages,
plan,bill,checkin}.php`, `admin/holds.php`, `admin/messages.php`,
`admin/submissions.php`, `admin/submission-view.php`, `admin/services.php`,
`admin/conflicts.php`, `admin/tasks.php`, `admin/task-action.php`,
`admin/_layout.php`, `admin/_layout_end.php`, `includes/admin-pagination.php`,
`admin/assets/admin-table.js`, `booking.php`, `includes/app/nav.php`.*
