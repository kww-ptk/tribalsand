# Admin Panel Audit — Tribal Sand

_Audit only. No code changed. Date: 2026-06-06._

Scope: every file under `admin/` (UI/UX, flow/logic, consistency, security). The admin
is in **good overall shape** — consistent teal/sand design system, CSRF on authenticated
mutations, audit logging, rate limiting, responsive sidebar. The findings below are the
real gaps, ranked by severity. Each is evidence-backed with file:line.

Legend: 🔴 bug / broken · 🟠 flow or logic risk · 🟡 consistency/UX · 🔵 enhancement

---

## 1. Confirmed bugs — undefined CSS referenced (quick wins)

These classes/variables are used in markup but **do not exist** in `admin/assets/admin.css`,
so those elements render unstyled.

| # | Issue | Evidence |
|---|-------|----------|
| 🔴 1.1 | **`.btn-secondary` is undefined.** The button renders as bare browser default (no padding/colour). | [settings.php:229](admin/settings.php#L229) "Export Active Holds", [audit.php:54](admin/audit.php#L54) "Filter" |
| 🔴 1.2 | **`.admin-table` is undefined.** The design-system class is `.data-table`. These two tables get zero table styling. | [audit.php:66](admin/audit.php#L66), [venue-edit.php:202](admin/venue-edit.php#L202) |
| 🔴 1.3 | **`var(--surface)` is undefined** (not in `:root`). Resolves to nothing → transparent background where a subtle grey was intended. | [audit.php:81](admin/audit.php#L81), [venue-edit.php:162](admin/venue-edit.php#L162), [room-edit.php:295](admin/room-edit.php#L295) |
| 🟡 1.4 | `--brand-dk` is defined in `:root` but never used anywhere. Dead token. | [admin.css:6](admin/assets/admin.css#L6) |

**Fix effort:** ~10 minutes. Add `.btn-secondary`, alias `.admin-table` to `.data-table`
(or rename usages), and add `--surface` to `:root`.

---

## 2. Flow & logic risks

| # | Issue | Detail |
|---|-------|--------|
| 🟠 2.1 | **No Post-Redirect-Get (PRG) on several POST handlers.** After submitting, the page re-renders inline instead of redirecting. A browser **refresh re-submits** the form. | [settings.php](admin/settings.php) (change-password → could change password twice / show stale error), [conflicts.php](admin/conflicts.php) (resolve runs again), [holds.php](admin/holds.php) (confirm/cancel re-fires — currently saved only by the status guard). Compare with [submissions.php:16](admin/submissions.php#L16) and [rooms.php:13](admin/rooms.php#L13), which **do** redirect correctly. |
| 🟠 2.2 | **Reorder AJAX endpoint has no CSRF check.** The `toggle_publish` branch validates CSRF, but the drag-to-reorder branch directly mutates `sort_order` with no token. | [rooms.php:18-25](admin/rooms.php#L18) |
| 🟡 2.3 | **Feedback disappears on redirect where PRG _is_ used.** Because there's no flash mechanism outside of holds, a corrected PRG flow would lose its "Saved" message. Holds already solved this with `$_SESSION['hold_flash']` ([holds.php:15](admin/holds.php#L15)) — that pattern should be generalised. |
| 🟡 2.4 | **`form_mode` validation duplicated** in settings and on each room. Logic is fine but the allow-list (`['enquiry','availability']`) is repeated; worth a shared helper. | [settings.php:50](admin/settings.php#L50) |

---

## 3. Security notes

| # | Issue | Detail |
|---|-------|--------|
| 🟠 3.1 | **Forgot-password has no rate limit and no captcha.** Login is protected by both ([login.php:22-25](admin/login.php#L22)), but the reset endpoint can be hit repeatedly to spam reset emails / probe timing. It correctly avoids user-enumeration in the response, but add rate limiting. | [forgot-password.php:11](admin/forgot-password.php#L11) |
| 🟡 3.2 | **Pre-auth POSTs skip `verify_csrf`** (login, forgot-password, reset-password). Defensible (no session yet), but reset-password operates on a logged-out user with a token — worth confirming the token alone is sufficient. | login/forgot/reset |
| 🟡 3.3 | **`forgot-password.php` calls `session_start()` directly** instead of the project's `session_init()` helper used everywhere else. Minor inconsistency, but bypasses any hardened cookie params set centrally. | [forgot-password.php:6](admin/forgot-password.php#L6) |
| 🔵 3.4 | **Verify `migrate.php` is auth-gated and/or removed in production.** A DB-migration endpoint is high-value to an attacker; confirm `require_login()` + that it's not reachable on the live host. | [migrate.php](admin/migrate.php) |

---

## 4. UI/UX consistency

| # | Issue | Detail |
|---|-------|--------|
| 🟡 4.1 | **Native `confirm()` for every destructive action** — 20 occurrences across 10 files. Unstyled, off-brand, blocks the thread, no focus management. | gantt, conflicts, holds, room-edit, property-edit, tour-edit, venue-edit, submissions, submission-view, migrate |
| 🟡 4.2 | **No toast/inline-success system.** All feedback is a full-page `.alert` banner. Combined with the missing PRG (2.1), success messaging is inconsistent page-to-page. | global |
| 🟡 4.3 | **Filter bars are built three different ways.** Some use the `.filters` component ([submissions.php:139](admin/submissions.php#L139), [holds.php:130](admin/holds.php#L130)); others hand-roll inline-styled `<select>`s ([conflicts.php:146](admin/conflicts.php#L146), [audit.php:48](admin/audit.php#L48), [rooms.php:48](admin/rooms.php#L48)). | — |
| 🟡 4.4 | **Pagination is built two different ways.** `.pagination` component ([submissions.php:229](admin/submissions.php#L229)) vs. hand-rolled inline-styled links ([audit.php:97-105](admin/audit.php#L97)). | — |
| 🟡 4.5 | **Heavy reliance on inline `style="…"`** instead of utility classes throughout. Works, but makes the look hard to evolve and is the root cause of 4.3/4.4. | most pages |
| 🟡 4.6 | **Mobile tables only horizontal-scroll.** Wide tables (holds, submissions, audit) scroll sideways on phones rather than reflowing to stacked cards. Some tables also wrap themselves in ad-hoc `overflow-x:auto` ([audit.php:65](admin/audit.php#L65)) instead of relying on the responsive `.data-table` rule. | — |

---

## 5. Dashboard — thin

[dashboard.php](admin/dashboard.php) currently shows only **3 submission counts** (today / week / total)
and a recent-submissions table. For an operator landing page it's missing the things that
actually need action:

- 🔵 5.1 **Holds at a glance** — pending vs. confirmed counts (already queried on [holds.php:91](admin/holds.php#L91), not surfaced here).
- 🔵 5.2 **Pending channel conflicts** — the count is already computed for the sidebar badge ([_layout.php:70](admin/_layout.php#L70)) but the dashboard never highlights it.
- 🔵 5.3 **"Needs action" panel** — pending holds about to expire, unresolved conflicts, today's check-ins.
- 🔵 5.4 **Quick actions** — jump straight to "Add room", "View holds", "Export".

---

## 6. Accessibility

- 🟡 6.1 Drag handles in [rooms.php:80](admin/rooms.php#L80) are decorative glyphs with no keyboard alternative and no `aria` — reorder is mouse-only.
- 🟡 6.2 Publish toggle is a `<button>` styled as a badge ([rooms.php:98](admin/rooms.php#L98)) with no `aria-pressed`.
- 🟡 6.3 Any future modal (replacing `confirm()`) needs focus-trap + `Esc` + restore-focus to be a real upgrade, not just a prettier blocker.

---

## Suggested sequencing (if/when you greenlight changes)

1. **CSS quick wins (§1)** — define `.btn-secondary`, `--surface`, fix `.admin-table`. ~10 min, zero risk.
2. **Shared UX layer (§4.1, §4.2)** — one styled confirm-modal + toast helper in `_layout_end.php`, opt-in via `data-confirm`. Then retire `confirm()` page by page.
3. **PRG + flash (§2.1, §2.3)** — generalise the `hold_flash` session pattern into a `set_flash()/show_flash()` helper; apply to settings, conflicts, holds.
4. **Dashboard enrichment (§5)** — surface holds + conflicts + needs-action using queries that already exist.
5. **Security hardening (§3.1, §2.2)** — rate-limit forgot-password; add CSRF to the reorder endpoint.
6. **Consistency pass (§4.3–4.6)** — migrate hand-rolled filters/pagination to the shared components; reduce inline styles.

Nothing here is on fire — it's polish and hardening on a solid base. Items in §1 and §3.1
are the highest value-to-effort.
