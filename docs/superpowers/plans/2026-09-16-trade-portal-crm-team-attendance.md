# Plan — Trade portal polish · enquiry CRM signals · team multi‑property · attendance UX

**Date:** 2026‑09‑16
**Branch to start from:** `master` (all the code referenced below is already committed at `4311a3d`).
**Scope:** 7 requested items across the travel‑agent portal, the enquiry inbox, the team directory and the attendance module. Each item below records **what I verified in the code**, the **goal**, the **approach**, the **files/migrations**, and a **test plan**. Do them as independent PRs where noted — they don't depend on each other except where stated.

> House rules that apply to every item (from `CLAUDE.md`): vanilla PHP 8.2, PDO prepared statements via `db_query()`, **no native selects/date/time inputs** — use the styled components (`.dp-btn` datepicker, `.eselect`, `.inp`), Nairobi‑local dates, every new DB read guarded by a `*_supported()` check so a pre‑migration deploy degrades instead of 500‑ing, and migrations are idempotent and applied to **prod RDS separately** via `/admin/migrate.php` (local `.env` is a different DB).

---

## Verification summary (what's already true today)

| # | Task | Key finding from the code | Migration needed? | Effort |
|---|------|---------------------------|-------------------|--------|
| 1 | Property images in agent availability | `ts_property_configurations()` **already returns a `hero` image URL** per single/entire option (`includes/db.php:1765`). The agent template just never renders it. | No | **S** |
| 2 | Agent request status + comms thread | Agent status is derived **only** from the hold (`agent_request_status()`), ignoring the admin `submissions.status` pipeline that already exists. No agent‑facing thread. | Small (optional) | **M/L** |
| 3 | Admin: open an agent's requests | `admin/agents.php` has no link out; but `agent_requests_filter()` + `agent_submission_agent_id()` already give a safe server‑side "requests for agent N" query. | No | **S/M** |
| 4 | Notify reservations when a guest replies | Inbound replies **already thread** as a `guest_reply` note and nudge status to `to_follow_up` (`api/inbound-mail.php`). What's missing is a **visible unread tag / count** in the inbox + nav. | Small | **M** |
| 5 | One team member → multiple properties | `hr_staff.venue_id` is a **single FK**, used for scope + grouping + attendance. Needs a many‑to‑many. | **Yes** | **M/L** |
| 6 | Attendance quick buttons | The `.att-q` buttons **are** wired in `admin/assets/admin-attendance.js` (`fillShift`). Most likely a stale/uncached asset on prod, or the ask is really "make it a proper picker". | No | **S/M** |
| 7 | Attendance IN/OUT time pickers | IN/OUT are plain free‑text `.att-t` inputs. Need a styled time picker. | No | **M** |

Effort key: S ≈ half‑day, M ≈ 1–2 days, L ≈ 3+ days.

---

## Item 1 — Show property photos in the agent availability results

**Goal:** the `/agent/availability.php` option rows should show a thumbnail like the rest of the site, not text only.

**Verified current state:** each option in `ts_property_configurations()` carries `'hero' => storage_url($r['hero'])` (`includes/db.php:1753‑1766`). `agent/availability.php` renders `singles`, `combos` and `entire` but never outputs `$o['hero']`. **Combos are the exception** — combo rooms come from the `$inventory` array (`includes/db.php:1780‑1787`) which does *not* include `hero`.

**Approach (server render, no JS):**
1. In `agent/availability.php`, add an `<img>` to the `singles` and `entire` option rows, e.g. inside `.ap-opt`, guarded by `if (!empty($o['hero']))`. Give it a fixed thumb size, `loading="lazy"`, `alt="<?= e($o['name']) ?>"`, and a neutral placeholder box when `hero` is null so rows stay aligned.
2. Add a small amount of CSS to `agent/_layout.php`'s stylesheet block (the portal has its own `.ap-*` styles) for `.ap-opt__thumb`.
3. **For combos** (optional, do second): add `'hero'` to the `$inventory` rows in `ts_property_configurations()` (it already has the room row in scope — `$r['hero']`) so `agent_price_configurations()` carries it through, then render a small stacked thumb per combo room chip. This is the only change that touches shared code (`includes/db.php`); keep it purely additive (a new key) so nothing else breaks.

**Files:** `agent/availability.php` (required), `agent/_layout.php` (CSS), `includes/db.php` (only if you want combo thumbnails).

**Test:**
- Log in at `/agent/login.php` (e.g. the Patrik/KlicKenya account in the screenshots), search dates, confirm each room/whole‑property option shows its hero photo and unpriced/placeholder rows still align.
- Prod dependency: hero images resolve through `storage_url()` → needs R2/S3 (same dependency as venue galleries). On a box without object storage the thumbs 404 gracefully — keep the placeholder.

**Risk:** low. Adding a key to `$inventory` is the only shared‑code touch; grep for consumers of `ts_rank_combos()` before shipping the combo part.

---

## Item 2 — Agent request status that actually moves + a per‑request conversation

**Goal:** (a) the "Status" column on `/agent/requests.php` should reflect what reservations are doing, even **before** a hold exists; (b) a lightweight thread so the agent and reservations can communicate per request instead of only loose email.

**Verified current state:**
- Agent status is computed by `agent_request_status()` (`includes/agent.php:227`) **purely from the latest hold's status** — so until reservations click *Convert to Hold*, every request reads "Sent" forever.
- Admin **already** has a lead‑status pipeline on the submission (`includes/submission-status.php`: received → answered → option_sent → waiting → to_follow_up → booked / not_interested / dates_unavailable) and it's editable in `admin/submission-view.php` (`set_status` handler, line 51).
- Admin **already** has a conversation thread per submission (`submission_notes`, kinds `note` / `reply` / `guest_reply`), rendered in `admin/submission-view.php`. `reply` = staff→guest (optionally emailed), `guest_reply` = inbound.

**Approach — two layers:**

**2a. Reflect the admin status (no migration).**
- Extend `agent_request_status($row)` to accept the submission's `status` and use it when there is **no hold yet** (hold status still wins once a hold exists, because that carries the countdown/confirmation). Map the internal lead slugs to trade‑friendly labels, e.g.:
  - `received` → "Sent" · `answered`/`option_sent`/`waiting`/`to_follow_up` → "In review" · `dates_unavailable` → "Dates unavailable" · `not_interested` → "Closed" · `booked` → "Booked".
- Add `s.status AS sub_status` to the `SELECT` in `agent_requests()` (`includes/agent.php:604`, guarded by `submission_status_supported()`), and pass it into `agent_request_status()` from `agent/requests.php:42`.
- Keep it **pure + covered** in `tests/agent_portal_logic.php`.

**2b. Per‑request thread visible to the agent (small, careful).**
- New portal page `agent/request-view.php?id=<submission_id>`: authenticate with `agent_require_login()`, then **re‑verify ownership** with `agent_requests_filter()` / `agent_submission_agent_id()` (never trust the id from the URL — this is the same guard the rest of the portal uses). Show the request facts + status + the thread.
- Which notes the agent may see: **only** `reply` (staff→agent) and the agent's own messages — **never internal `note` rows.** Add a helper `fetch_agent_visible_thread($submissionId)` in `includes/agent.php` that queries `submission_notes` filtered to `kind IN ('reply','guest_reply')`. (Internal `note` stays staff‑only.)
- Agent reply box → posts to a new `api/agent-message.php` (agent‑session‑authed, CSRF, ownership re‑checked) that writes an `add_submission_note($id, null, $body, 'guest_reply', '<Agent name>')`. That reuses the exact same blue‑bubble the inbound‑mail path uses, so **reservations see the agent's reply in `admin/submission-view.php` with zero extra work**, and it also fires the Item‑4 unread signal. Best‑effort email to reservations optional.
- Link "Status" / a "View / message" action from each row in `agent/requests.php` to `agent/request-view.php`.

**Optional migration:** none strictly required (reuse `submission_notes`). If you want the agent's own replies visually distinct from a guest's, add a `kind = 'agent_reply'` — but that means touching `submission_notes` CHECK + `add_submission_note()`'s allow‑list + the bubble map in `submission-view.php`. **Recommendation: reuse `guest_reply` for v1**, label the author (author_name already frozen), and skip the migration.

**Files:** `includes/agent.php` (status + thread helper + SELECT), `agent/requests.php`, new `agent/request-view.php`, new `api/agent-message.php`, `tests/agent_portal_logic.php`.

**Test:** as an agent, send a request; as admin set status to "Option Sent" and confirm the portal shows "In review"; post a staff `reply` and confirm it appears on `agent/request-view.php`; post an agent reply and confirm it lands as a blue bubble in `admin/submission-view.php` **and** raises the Item‑4 unread tag. Verify a second agent cannot open request‑view for the first agent's id (403/redirect).

**Risk:** medium — a **new authenticated write endpoint** on a public surface. Mirror the guards in `api/concierge.php` / existing agent throttles (`agent_request_throttled()`), CSRF token in body, and the ownership re‑check. Do not expose internal notes.

---

## Item 3 — From the admin travel‑agent list, open all of an agent's requests

**Goal:** click an agent in `admin/agents.php` → see every request that agent has sent and its status.

**Verified current state:** `admin/agents.php` lists agents but links nowhere. The safe query already exists: `agent_requests_filter($agentId, 's')` returns a `[whereFragment, params]` keyed on the **server‑written** `submissions.agent_id` (with the signed‑payload fallback for legacy rows). `agent_requests()` is agent‑session shaped; for admin we want the same filter but an admin‑session page.

**Approach:**
- Simplest, most consistent with the codebase: add an **agent filter to the existing `admin/submissions.php`**. Accept `?agent_id=<n>`; when set and `agents_supported()`, AND the `agent_requests_filter($n,'s')` fragment into the WHERE. Add each agent row in `admin/agents.php` a "View requests" `btn-icon` linking to `/admin/submissions.php?agent_id=<id>`. This reuses the whole data‑table toolkit (search/paging/status filter/CSV) for free and respects existing venue scoping.
- Add a small heading/among the filters showing "Requests from **<agent name>**" with a clear‑filter chip when `agent_id` is set.
- (Alternative: a dedicated `admin/agent-requests.php?id=`. More code, less reuse — not recommended.)

**Files:** `admin/submissions.php` (add the `agent_id` filter branch + label), `admin/agents.php` (link per row). No migration.

**Test:** open `/admin/agents.php`, click an agent's "View requests"; confirm only that agent's submissions show; confirm status column matches; confirm a manager (scoped) still only sees rows within their venue scope; confirm the CSV export honours the agent filter.

**Risk:** low. The filter fragment is already parameterised and injection‑safe. Watch the param alias — `submissions.php` aliases submissions as `s`, which matches `agent_requests_filter()`'s default.

---

## Item 4 — Tag / notify reservations when a client replies to an enquiry email

**Goal:** reservations should see at a glance which enquiries have a **new, unread guest reply** — a badge in the list and a count in the nav — instead of discovering it only by opening each thread.

**Verified current state:** the pipe already works end‑to‑end: `api/inbound-mail.php` verifies the SNS message + the `[TSR‑id]` HMAC, writes a `guest_reply` note (`add_submission_note`), and nudges `status = 'to_follow_up'` (unless terminal). What's missing is an **unread** concept — a note count badge exists in the list but doesn't distinguish "new since staff last looked".

**Approach (small migration for a reliable "seen" marker):**
1. **Migration `add_submission_reply_flags.sql`** (idempotent) adding two nullable `TIMESTAMPTZ` columns to `submissions`:
   - `last_guest_reply_at` — set to `now()` whenever a `guest_reply` is threaded.
   - `reply_seen_at` — set to `now()` when a staff member opens `admin/submission-view.php` for that row.
   Guard with a `submission_reply_flags_supported()` helper (mirror `submission_status_supported()`), so pre‑migration everything still renders.
2. **Write path:** in `api/inbound-mail.php` (and the new `api/agent-message.php` from Item 2, since an agent reply is also a "customer reply"), after a successful `add_submission_note(..., 'guest_reply', ...)`, `UPDATE submissions SET last_guest_reply_at = now()`.
3. **Seen path:** in `admin/submission-view.php`, on GET, `UPDATE submissions SET reply_seen_at = now() WHERE id = :id` (only when supported). Opening the thread marks it read.
4. **Surface it:**
   - **List** (`admin/submissions.php`): add a helper `submission_unread_reply_ids($ids)` = rows where `last_guest_reply_at IS NOT NULL AND (reply_seen_at IS NULL OR reply_seen_at < last_guest_reply_at)`, and render a red "New reply" badge in the Guest cell (next to the existing note count). Consider an "Unread replies" quick filter.
   - **Nav badge** (`admin/_layout.php`): add `$__navSubmissionsUnread = count of unread across the account's scope` and show it on the Submissions link (same pattern as `$__navReports` at `admin/_layout.php:243` and the leave `($__leavePending)` badge in attendance). Scope it with the existing submissions venue‑scope SQL.
5. **Optional email ping:** in `api/inbound-mail.php`, after threading, best‑effort `send_notification()`‑style alert to reservations ("Guest replied to enquiry #N"). Gate behind an env flag so it doesn't double up with SES notifications. Recommend shipping the in‑app badge first; add email only if reservations still ask.

**Files:** new `db/migrations/add_submission_reply_flags.sql`; `includes/submission-notes.php` or a new small include for the `*_supported()` + `unread_ids` helpers; `api/inbound-mail.php`; `admin/submission-view.php`; `admin/submissions.php`; `admin/_layout.php`; `tests/inbound_mail_logic.php` (extend).

**Test:** simulate an inbound reply (the inbound‑mail test harness threads in a rolled‑back tx); assert `last_guest_reply_at` set and the row shows unread; open the view, assert `reply_seen_at` updates and the badge clears; assert the nav count decrements; verify pre‑migration (no columns) still renders with no badges and no errors.

**Risk:** medium. Keep every new read guarded; the nav count must reuse the submissions venue‑scope so managers don't see out‑of‑scope counts.

---

## Item 5 — A team member assignable to multiple properties

**Goal:** in the Team directory (`hr_staff`), a person can belong to more than one property (the edit screen currently has a single "Property" select).

**Verified current state:** `hr_staff.venue_id` is a single nullable FK. It drives three things: **scope visibility** (`hr_scope_sql()` → `venue_id IN (...)`), **grouping** ("who works where", `hr_staff_by_property()`), and **attendance** (the daily editor groups staff by `venue_id`, `att_staff_in_scope()` checks it). A person appears under exactly one property card.

**Design decision (recommended): keep `venue_id` as the *home* property, add an additive many‑to‑many for *additional* properties.**
- Rationale: attendance is one row per person per day (not per venue), and the daily/month grids group by a single property card. Making `venue_id` itself multi‑valued would ripple into attendance grouping, the person view, exports and leave. The low‑risk shape is: **home property stays single** (drives grouping + attendance display), **extra properties widen visibility/scope only.**

**Approach:**
1. **Migration `add_hr_staff_venues.sql`** (idempotent): a join table `hr_staff_venues (hr_staff_id INT REFERENCES hr_staff(id) ON DELETE CASCADE, venue_id INT REFERENCES venues(id) ON DELETE CASCADE, PRIMARY KEY (hr_staff_id, venue_id))`. Guard reads with `hr_staff_venues_supported()`.
2. **Scope:** update `hr_scope_sql()` (and `hr_staff_count`, `fetch_hr_staff`) so a scoped account sees a person if **any** of their venues (home `venue_id` OR a `hr_staff_venues` row) is in scope — e.g. `EXISTS (SELECT 1 FROM hr_staff_venues hv WHERE hv.hr_staff_id = hr_staff.id AND hv.venue_id IN (...)) OR venue_id IN (...)`. Keep the pre‑migration branch (no join table) exactly as today.
3. **Edit UI** (`admin/staff.php` directory edit form): keep the single "Property" select as the **home** property, add a multi‑select group of check‑chips (`.optchip`, per house style — **not** a native multi‑select) for "Also works at". Save path writes the home `venue_id` as now and replaces the `hr_staff_venues` rows in a transaction.
4. **Attendance:** `att_staff_in_scope()` should also accept a match on any assigned venue. Grouping stays by home `venue_id` (a person shows on their home card). Document that multi‑venue staff are counted once, on their home property, in the dashboard.
5. **Directory display:** show extra property chips on the person row.

**Files:** new `db/migrations/add_hr_staff_venues.sql`; `includes/hr.php` (supported‑guard, scope, fetch, save helper); `admin/staff.php` (edit form + save); `admin/attendance.php` (`att_staff_in_scope`); `tests/team_logic.php` or a new `tests/hr_logic.php`.

**Test:** assign a person a home + a second property; sign in as a manager of the **second** property and confirm the person is now visible in the directory and attendance; confirm the home‑property manager still sees them; confirm scope math via the logic test; confirm pre‑migration DB still works (no join table → behaves exactly as today).

**Risk:** medium/high — touches scope, which is a security boundary. Add explicit tests: a manager must **not** see a person none of whose venues intersect their scope. Owner (`venueIds === null`) unaffected.

---

## Item 6 — Attendance quick‑fill buttons ("8h / D / N")

**Goal:** the quick buttons should pre‑fill the shift times.

**Verified current state:** they **are** implemented. `admin/attendance.php` renders `.att-q` buttons with `data-shift` (`std`/`secday`/`secnight`), and `admin/assets/admin-attendance.js` (`fillShift()`) fills the four time inputs and recomputes the total. Server `attendance_shifts()` mirrors the same minutes. The JS is included with a `?v=<filemtime>` cache‑bust. So the logic is correct in the repo.

**Most likely real cause on the live site** (the screenshots are from prod):
- **Stale asset:** the deployed `admin-attendance.js` predates the handler, or a CDN/browser cache is serving an old copy. First action: confirm the deployed file matches `HEAD` and that the `?v=` bust is present on prod (view source of `/admin/attendance.php`).
- A JS error earlier on the page aborting `init()` — check the browser console on the live daily view.

**Approach:**
1. **Reproduce first.** Load `/admin/attendance.php?view=daily` on the target environment with devtools open; click 8h/D/N; watch the console + whether inputs fill. Confirm whether this is a prod‑only (asset) issue or a genuine bug.
2. If it's an asset/cache issue: redeploy, verify the `filemtime` version query updates, hard‑refresh.
3. Fold this into **Item 7** — once IN/OUT become styled time pickers, `fillShift()` must set the picker's value + dispatch its `change` (not just assign `input.value`). Update `fillShift`, `setTimesDisabled`, and `recompute` to read/write through the new picker component.

**Files:** `admin/assets/admin-attendance.js` (only if the picker change lands), plus a deploy/verify step.

**Test:** on the running app, click each quick button and confirm times fill and the Total/OT recomputes; confirm the day still saves and reloads with the same times (round‑trips through `attendance_hhmm_to_min` / `attendance_min_to_hhmm`).

**Risk:** low, but **don't assume a code bug** — verify on the live surface before changing working JS.

---

## Item 7 — IN / OUT should be time pickers, not free text

**Goal:** the four time cells (In 1 / Out 1 / In 2 / Out 2) should use a proper time picker in the house style, not open text inputs.

**Verified current state:** they're `<input class="inp inp--sm att-t">` free‑text fields; the JS parses `HH:MM` (and a trailing `+1` for shifts crossing midnight). No native `type="time"` (which is also against the "no native inputs" rule).

**Approach (build a small styled time picker, mirroring the datepicker pattern):**
- The site already ships an in‑house `.dp-btn` datepicker (`js/datepicker.js`) with a hidden input it writes and a `change` it dispatches. Build an analogous **time picker** the same way: a styled button/menu (hour + minute steppers or a 15‑min grid) that writes `HH:MM` into a hidden input and dispatches `change`. Keep the serialized value format identical (`HH:MM`, optional `+1`) so the server (`attendance_hhmm_to_min`) is unchanged.
- Because night shifts cross midnight (`secnight` = 19:00→07:00+1), the picker must support the `+1` marker for Out fields — either an explicit "next day" toggle or accept the existing `+1` suffix. Preserve it in both directions.
- Wire it into the daily editor: replace the `.att-t` text inputs with the picker component; update `admin-attendance.js` (`rowInputs`, `fillShift`, `recompute`, `setTimesDisabled`) to read/write through it and to listen for the picker's `change` instead of `input`.
- Keep a **no‑JS fallback**: the daily editor is a normal form POST today. Simplest is to keep a real input as the picker's backing field so it still works (and validates `HH:MM`) with JS off.

**Files:** new `js/timepicker.js` (or extend `js/datepicker.js`), CSS in `css/main.css` or the admin stylesheet, `admin/attendance.php` (row markup), `admin/assets/admin-attendance.js` (integration). No migration — this is presentation; storage stays minutes.

**Test:** pick times via the new control; confirm the value stored/round‑trips; confirm quick‑fill (Item 6) populates the picker; confirm a night shift keeps the `+1`; confirm totals compute; confirm it still saves with JS disabled.

**Risk:** medium — a new reusable UI component. Reuse the datepicker's conventions so it feels native to the admin and to avoid a second bespoke pattern.

---

## Suggested sequencing (independent PRs)

1. **Item 1** (images) — smallest, visible win, no migration.
2. **Item 3** (admin → agent requests) — small, no migration, reuses the data‑table.
3. **Item 4** (unread‑reply tag) — one small migration; high value for reservations.
4. **Item 2** (agent status + thread) — builds naturally on Items 3 & 4 (shared status + thread plumbing).
5. **Item 7 + Item 6** (attendance pickers + quick‑fill) — do together; verify the prod asset first.
6. **Item 5** (multi‑property team) — biggest blast radius (touches scope); do last, with scope tests.

## Migrations introduced (apply to **prod RDS** via `/admin/migrate.php` after merge)

- `add_submission_reply_flags.sql` (Item 4) — `submissions.last_guest_reply_at`, `submissions.reply_seen_at`.
- `add_hr_staff_venues.sql` (Item 5) — `hr_staff_venues` join table.
- (Item 2 optional) a `submission_notes` kind extension **only** if you choose a distinct `agent_reply` bubble; recommended to skip.

## Tests to run before each PR
`php tests/agent_portal_logic.php` · `php tests/team_logic.php` · `php tests/inbound_mail_logic.php` · plus any new `tests/hr_logic.php`. Several suites do DB assertions inside a rolled‑back transaction and SKIP cleanly with no DB — set `DATABASE_URL` to exercise them.

## Things to watch (from CLAUDE.md, don't regress)
- Agent **identity isolation** — session key `agent_id`, never `admin_id`; the new agent endpoints must satisfy no admin guard and vice‑versa.
- Agent **ONE price** — never store a parallel net rate; keep pricing on the live path.
- A **trade request never places a hold** — Item 2's thread must not create holds.
- **Ownership re‑checks** on every agent write/read of a request id (`agent_requests_filter` / `agent_submission_agent_id`), and per‑venue scope on every new admin count/list.
- **Pre‑migration safety** — guard every new column/table read.
