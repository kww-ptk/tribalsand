# Email Notifications Center — plan

**Goal:** one admin area (owner) where we can see **every email the system sends**, what
**triggers** it, **preview** it, **edit** its wording, switch it **on/off**, and read a **log**
of every email actually sent (who, when, why, did it deliver).

**Trigger for this work:** some guests received "Booking Confirmed" emails they should not
have. Today we **cannot tell who got which email or why** — nothing is logged except
failures in `logs/mail.log` (which is wiped on each ECS deploy). Fixing that is step one.

---

## 1. What we send today (audit, 29 Sep 2026)

All email code lives in `includes/mail.php`. 23 distinct emails, 40+ call sites.

### Guest-facing

| # | Email (function) | Subject today | Triggered by |
|---|---|---|---|
| G1 | Enquiry / hold acknowledgement (`send_guest_acknowledgement`) | "We received your request…" | Every public form: `api/submit-enquiry.php`, `submit-contact.php`, `submit-agency.php`, `submit-combo.php`, `api/maya-ilai-book.php`, `ghl-submit.php`, `properties.php`, `adwords.php`, trade portal (`includes/agent.php` → goes to the **agent**) |
| G2 | Trip Builder confirmation (`send_trip_builder_emails`, guest half) | — | `api/trip-builder.php` |
| G3 | **Booking Confirmed** (`send_hold_confirmed`) | "Booking Confirmed — {room} — {dates}" | Staff clicks Confirm in **3 places**: `admin/booking.php`, `admin/holds.php`, `admin/hold-action.php` (the link in the staff email). Group bookings: one email for all rooms. **Sent automatically on every confirm — no opt-out.** |
| G4 | Booking cancelled (`send_hold_cancelled 'cancelled'`) | — | Staff decline/cancel (same 3 pages), `admin/conflicts.php` keep-OTA, guest self-cancel `booking.php` |
| G5 | Hold expired (`send_hold_cancelled 'expired'`) | — | **Automatic**, scheduler every 5 min (`expire_stale_holds()` in `includes/db.php`) |
| G6 | Reply to enquiry (`send_admin_reply`) | "Re: Your enquiry — Tribal Sand [TSR-…]" | Staff reply in `admin/submission-view.php` |
| G7 | Table reservation received (`send_reservation_received`, guest half) | — | `api/submit-reservation.php`, `api/reservation-api.php` |
| G8 | Table reservation confirmed (`send_reservation_confirmed`) | — | `admin/reservations.php` Confirm |
| G9 | POS receipt (`send_pos_receipt`) | — | Till "Email" button (`api/pos/receipt-email.php`) |

### Staff / internal (all go to the `notify_email` setting, default reservations@)

| # | Email | Triggered by |
|---|---|---|
| S1 | New lead alert (`send_notification`) | Same forms as G1 + `api/search-lead.php`, `api/submit-event.php` |
| S2 | New hold — needs action (`send_hold_notification`) | Availability-mode booking / combo request |
| S3 | Trip Builder alert | `api/trip-builder.php` |
| S4 | Guest cancelled (`send_admin_guest_cancelled`) | Guest self-cancel |
| S5 | Guest change request (`send_change_request_notification`) | `api/booking-change.php` |
| S6 | Guest add-on request (`send_addon_request_notification`) | `api/booking-addon.php` |
| S7 | New table reservation (staff half of G7) | as G7 |
| S8 | Check-in completed (`send_checkin_completed`) | Guest finishes wizard (`includes/checkin.php`) |
| S9 | Admin password reset | `admin/forgot-password.php` (calls `_dispatch_mail` directly) |

### Problems found during the audit
1. **No send log.** Only failures are written, to a file that doesn't survive deploys.
2. **Booking Confirmed has no "don't email" option.** Every confirm emails the guest —
   including holds staff create to block a room for an OTA / agent / internal booking, and
   trade holds (where `guest_email` is the agent). Most likely source of the complaint.
3. **Confirmation copy is hardcoded "Tribal Sand, Watamu"** — wrong for Kilifi properties.
4. **Two send paths.** `send_notification()` re-implements the driver switch instead of
   using `_dispatch_mail()`, so any logging added to one misses the other.
5. **Content is PHP-only.** Every wording change is a code deploy.
6. Several emails `From`/`Reply-To` differ (some reply to `MAIL_FROM` = noreply) — guests
   replying to a confirmation hit a no-reply address.

---

## 2. Design principles

- **Triggers stay in code; content becomes data.** A trigger is an event in the system
  (a confirm, a form post, the expiry job). The admin can switch a trigger's email on/off,
  change who gets it and change its wording — but a brand-new *trigger* is a code change.
  "Add an email" in the admin = a **manual/scheduled template** (Phase 5), not a new event.
- **One send path.** Every email goes through one function that checks on/off, renders,
  sends and logs. No exceptions (this is how we guarantee the log is complete).
- **Editable text, fixed shell.** Admins edit subject + body blocks with `{{placeholders}}`
  inside the existing branded `_email_shell()`. No raw-HTML editing — keeps emails on-brand,
  avoids broken layouts and XSS in admin-authored content.
- **Code defaults are the fallback.** A missing migration or an empty override sends
  exactly today's email (same pattern as nav menu / reviews / venue content).
- **Pre-migration-safe, fail-soft.** A logging failure never blocks a send; a send failure
  never blocks the booking.

---

## 3. Phases

### Phase 1 — Send log + single send path *(do first; answers "who got what, and why")*
- Migration `add_email_log.sql`: `email_log` (`id`, `template_key`, `audience`
  guest|staff, `to_email`, `subject`, `status` sent|failed|suppressed|skipped|delivered|bounced|complained,
  `provider` smtp(SES)|log|mail|resend, `provider_id` (SES Message-ID), `error`,
  `hold_id`, `submission_id`, `reservation_id`, `pos_sale_id`, `triggered_by`
  (admin id / `system` / `guest`), `trigger_source` (e.g. `admin/holds.php:confirm`),
  `html_snapshot` (rendered body, so we can see exactly what they got), `created_at`).
- `mail_send(string $key, array $msg, array $ctx)` in new `includes/mail-log.php`:
  the ONE entry point. Wraps `_dispatch_mail()`, writes the log row. Production sends via
  **AWS SES SMTP** (`MAIL_DRIVER=smtp`; the Resend branch is a dormant Render-era leftover —
  never set `RESEND_API_KEY`, it would short-circuit SES). `send_smtp()` captures the SES
  Message-ID from the final `250 Ok <id>` reply (today it's discarded) into `provider_id`.
- Refactor every `send_*` to call it with a stable `template_key` (`hold_confirmed`,
  `hold_expired`, …). Fold `send_notification()` and `admin/forgot-password.php` onto it.
- Admin page **Settings → Emails → Log** (`admin/email-log.php`, owner + manager scoped by
  venue via hold/submission): dt_* table, filters (template, status, date, recipient
  search), row → detail with rendered preview + "what triggered this".
- Show the log inline where it matters: booking workspace + submission view get an
  "Emails sent" list.
- Retention: prune `html_snapshot` after 180 days (scheduler), keep the row.
- **No history exists before this ships.** SES keeps no per-message sent list (only
  aggregate metrics), so past wrong confirmations can only be traced by asking staff /
  guests. The log covers sends from deploy onward.

### Phase 1b — Delivery status from SES
- SES **configuration set** with event publishing (Delivery, Bounce, Complaint, Reject)
  → SNS topic → new `api/ses-events.php`, reusing `sns_verify_signature()` +
  auto-confirm from `includes/inbound-mail.php`. Matches on `provider_id`, updates
  `email_log.status` to delivered | bounced | complained. `send_smtp()` adds the
  `X-SES-CONFIGURATION-SET` header. Log page shows "Delivered / Bounced".
- AWS setup steps go in `docs/email-events-setup.md` (like `docs/inbound-mail-setup.md`).

### Phase 2 — Template registry + catalogue page
- `includes/email-templates.php`: `email_registry()` — one entry per template: key, name,
  audience, **trigger description in plain words**, trigger call sites, default subject,
  recipients rule, available placeholders, and a **sample-data builder** for preview.
- `admin/emails.php` (**owner-only**): card list grouped Guest / Staff; each shows
  trigger, recipients, on/off, last sent, 30-day count, failures.
- **Preview** (`admin/email-preview.php?key=`): renders the real template with sample data
  in an iframe, desktop/mobile toggle, "Send test to me".
- **On/off toggle** per template → `settings` KV (`email_enabled_<key>`), enforced inside
  `mail_send()` (logged as `suppressed`). Staff alerts get a **recipient override**
  (e.g. change requests → front desk instead of reservations@). Some templates are
  **locked on** (password reset, admin reply — the staff action *is* sending it).

### Phase 3 — Fix the Booking Confirmed problem (small, can ship with Phase 1)
- Confirm actions (booking workspace, holds list) get **"Email the guest"** checkbox,
  default on; unticked → logged `skipped` with who unticked it. The email-link confirm
  (`hold-action.php`) lands on a confirm screen with the same choice instead of acting
  immediately.
- Default **off** automatically when the hold is an OTA/import block, has no real guest
  email, or is a trade hold (agent gets it only if we decide so — **question for owner**).
- Property-aware copy: property name + town from the venue, not "Watamu".
- Guest-facing Reply-To → reservations@ everywhere (not noreply).

### Phase 4 — Editable content
- Migration `add_email_templates.sql`: `email_template_overrides` (`template_key`,
  `subject`, `heading`, `intro`, `body`, `footer_note`, `is_active`, `updated_by`,
  `updated_at`) + `email_template_versions` (append-only history → "restore previous").
- Editor (`admin/email-edit.php`): fields with a placeholder picker
  (`{{guest_name}}`, `{{room_name}}`, `{{check_in}}`, `{{manage_url}}`, …) — only the
  placeholders that template supports; unknown ones are refused on save. Live preview
  side-by-side. **Reset to default** button.
- Rendering: override text → `e()` → placeholder substitution → dropped into the fixed
  blocks (detail table, buttons, manage link stay code-rendered so data is always right).
  Plain-text part derived from the same fields.
- Per-property variants (optional): override row can carry `venue_id`; resolution =
  venue override → global override → code default.
- House UI rules: `.inp`, `.eselect`, `.optchip`, no native chrome.

### Phase 5 — New emails the admin can add (optional, confirm scope with owner)
- **Scheduled guest emails** on existing events, run by the scheduler loop (idempotent,
  de-duped via `email_log`): pre-arrival (N days before check-in, with check-in link),
  post-stay thank-you / review request. Admin creates them from a template + offset.
- **Manual send** from the booking workspace: pick a template, preview, send (logged).

---

## 4. Order, size, tests

| Phase | Size | Ships |
|---|---|---|
| 1 Log + one send path | M (touches all 23 senders) | first |
| 1b SES delivery/bounce events | S (+ AWS console setup) | with or right after 1 |
| 3 Confirm opt-out + property copy | S | with 1 |
| 2 Catalogue, preview, on/off | M | second |
| 4 Editable content + history | M–L | third |
| 5 Scheduled / manual emails | M | if wanted |

Tests: `tests/email_log_logic.php` (every `send_*` writes exactly one row with its key;
suppressed/skipped paths; logging failure doesn't block send; `send_*` with driver `log`),
`tests/email_templates_logic.php` (registry completeness — every key used in code is
registered; placeholder validation; override → default fallback; escaping).
A grep-based test asserts **no `_dispatch_mail(` / `mail(` / `send_resend(` call exists
outside `mail_send()`** so a future email can't bypass the log.

Prod: run `add_email_log.sql` (then `add_email_templates.sql`) via `/admin/migrate.php`.

## 5. Questions for the owner
1. The wrong "Booking Confirmed" emails — which bookings? (OTA/agent holds, test holds,
   a group booking, or the Watamu wording?) SES keeps no per-message history, so this
   has to come from staff / the guests.
2. Trade (agent) holds: should the agent get the confirmation, or nobody?
3. Who may edit templates — owner only, or managers too (per property)?
4. Phase 5 wanted now (pre-arrival / review-request emails)?
5. Anything we send that should stop entirely?
