# General registration link — visitors register, sign the waiver, get a pass

Date: 2026-10-14 · Status: approved design, not built

## 1. Goal

Nobody enters a Tribal Sand property without being registered. Today only booked
guests are registered (each gets their portal and completes check-in). We add ONE
general link that anyone can open:

- a guest **with a booking** is matched and sent to the normal check-in;
- anyone **without** one (day visitor, someone coming for a drink, a friend of a
  guest, a contractor…) registers, picks where they are going, photographs their
  ID and signs the waiver — once — and gets a reusable visitor pass;
- security scans that pass at the gate, exactly as they scan a booking's gate pass.

### Owner decisions (2026-10-14)

| Question | Decision |
|---|---|
| How long a registration lasts | **Register once, reuse.** Sign once; on later visits open the pass and pick today's place. Re-sign only when the visitor waiver wording changes. |
| Details collected | Name, phone, email, ID type + number, nationality, **and an ID photo** (the check-in uploader that reads it). |
| Groups | **One registers, shares a link.** The lead adds the others; each adult gets their own link to register + sign; children are added by name + age under an adult. The group pass works once every adult has signed. |
| "Where are you going?" | **Owner-editable list**, each place tied to the property whose gate it is behind. |
| Unregistered person at the gate | **Both:** a QR poster at each gate (register on own phone) and a "Register visitor" form on the guard's tablet. The hand-typed "Sign in visitor" stays only as a fallback, marked *Not registered*. |
| ID photo retention | Kept until the visitor is removed. No automatic deletion. |
| Base | Built on top of the uncommitted check-in ID-type work on `dev` (`add_checkin_id_type.sql`, `js/checkin-passport.js`, `record.php`). |

## 2. Approach

A **visitor registry of its own**, with the existing gate `visitors` table as the
per-visit log. Not chosen: turning visitors into unit-less holds (pollutes
bookings, reports, the calendar and email rules; `holds.unit_id` is required) or
adding waiver columns to `visitors` (one row per visit — cannot "sign once").

Reused, never copied: the signature pad (`js/signature-pad.js`,
`checkin_valid_signature()`), the passport uploader + AI read
(`js/checkin-passport.js`, `ai_read_image_json()`, `checkin_passport_from_ai()`),
the terms dialog (`js/checkin-terms.js`), private storage (`storage_put_private()`),
the hash-chain algorithm of the check-in audit, the gate scanner and verdict
pattern (`includes/gate-pass.php`), `mail_send()`.

## 3. The guest-facing flow — `/register`

Public, `noindex`, mobile-first, site header/footer. `?place=<id>` pre-selects a
place (the gate posters use it). `?gate=1` is the guard's tablet mode (§6).

### 3.1 Step 1 — "Do you have a booking with us?"

- **Yes, I have my booking code** → booking code + surname.
  - Code (`holds.access_code`, case-insensitive, stay not ended) + surname matches
    the lead (`holds.guest_name`) → redirect to that booking's check-in
    (`booking.php?ref=…&view=checkin`).
  - Surname matches a guest on the roster (`checkin_guests`) → redirect to that
    guest's own link (`checkin_personal_link()`, `?g=`).
  - Otherwise → "We couldn't match your name to that booking. Ask the person who
    booked to add you, or register as a visitor." (Never says whose booking it is.)
  - Surname match = the last word of the typed name equals any word of the stored
    name, case- and accent-insensitive (pure `visitor_booking_name_match()`).
- **I have a booking but not the code** → email address. If a current booking
  uses that email, its portal link is emailed (`visitor_booking_link` email). The
  page always answers "If we found a booking for that email, we've sent the link."
- **No, I'm visiting** → step 2.
- **I've registered before** → phone or email; if a registered visitor matches,
  their pass link is emailed. Same neutral answer either way. (A visitor
  registered on the gate tablet without an email cannot recover the link this
  way — the guard finds them in Visitors instead.)

A name alone never opens anything.

### 3.2 Step 2 — Register

One page in sections (the check-in look, `ci-*` styles reused where they fit):

1. **Where are you going?** — the active places (`visitor_places`), required.
2. **Your details** — full name, phone, email, ID type + number, nationality, ID
   expiry (optional), **Take picture** of the ID. The photo is read exactly as at
   check-in and fills only empty or previously AI-filled fields.
3. **Who's with you?** (optional) — children: name + age (under 18); adults: name
   only (they register themselves).
4. **Sign** — the visitor waiver in a scroll box, "Type your full name to sign",
   the signature pad, the agree box (unticked → the terms dialog, as at check-in).
5. **Register** → the pass.

Required to finish: place, name, phone, email (except tablet mode — §6), ID type +
number + nationality, ID photo, signature, typed name, agree. Server re-checks all.

### 3.3 The pass — `/visitor-pass?t=<token>`

- Big QR (links to `admin/gate.php?vp=<token>`), name, photo-free (no ID image on
  the pass), today's place, the group with ✓ signed / ⏳ waiting per adult.
- **Where are you going today?** — if no visit exists for today (Nairobi), the
  pass asks for the place first; choosing again changes today's place.
- **Your group** — for each adult not yet signed: **Send on WhatsApp** /
  **Copy link** (their personal invite link, §3.4). Add another adult or child.
- **Update my details** → back to the details form; changing a material identity
  field clears the signature and asks to sign again (§4.3).
- If the visitor waiver wording changed since they signed → "Our terms have
  changed — please sign again" before the QR is shown.
- Emailed on registration (`visitor_pass` email) so they can find it again.
  Web-app meta so it can be added to the home screen.

### 3.4 Invited adults

The lead's "add an adult" creates a `registered_visitors` row with only the name,
`invited_by` = the lead, unsigned. Its invite link is `/register?i=<token>` (the
the invitee's own pass token, `VP-…` — one token per person): it opens step 2 with the name pre-filled
and the place locked to the lead's most recent visit (today's, if any). When they sign they become a full
registered visitor with their own pass (reusable on their own later). An invite
row that never signs is harmless: it shows as "waiting" on the lead's pass.

Text: `visitor_invite_share_text($name, $place, $link)` (pure), mirrored by the JS.

## 4. Data — migration `add_visitor_registration.sql`

After `add_visitors` and `add_checkin_id_type`. Guard `visitor_reg_supported()`
(a `to_regclass` lookup on the LAST table the migration creates). Appended last
to `db/migrations/ORDER.txt` with today's date.

### 4.1 `visitor_places`
`id`, `name` (unique, case-insensitive), `venue_id` (NOT NULL → venues; the gate
it is behind), `sort_order`, `is_active`, `created_at`. Seeded with one row per
published venue (name = venue name). The owner adds Tribal Table, Somewhere Café,
Off Duty, kite school… A place is switched off, never deleted, once a visit uses it.

### 4.2 `registered_visitors`
- identity: `full_name`, `phone`, `phone_digits` (indexed, for "registered before"),
  `email` (lower-cased, indexed), `id_type`, `id_number`, `nationality`,
  `id_expiry`, `id_photo_key` (private: `visitors/<random>.jpg|png` — uploaded before the row exists, so the key is random, never a public URL);
- group: `parent_id` (child → the adult who added them; children have no ID,
  phone, email, signature or pass), `age`, `invited_by` (adult → the lead);
- waiver: `waiver_signature` (PNG data URL), `waiver_signed_name`,
  `waiver_signed_at`, `waiver_terms_snapshot`, `waiver_version` (= `waiver_version()` of the text, the same 12-char helper as check-in),
  `waiver_reference` (`TSV-<id>-<rand>`), `waiver_{name,id_type,id_number,nationality,id_expiry}_snapshot`,
  `signed_ip`, `signed_ua`, `signed_via` (`self` | `gate`);
- `created_via` (`self` | `gate` | `invite`), `created_by` (admin id, tablet mode),
  `created_at`, `removed_at` (soft remove, see §7.2).

### 4.3 Signature rules (same as check-in)
- **Signed = current** when `waiver_signed_at` is set and `waiver_version`
  equals `waiver_version()` of today's visitor waiver text (`visitor_waiver_current()`).
- **Void-on-edit:** `visitor_void_signature_if_identity_changed()` — compare
  before void, only posted fields, material fields = name / ID number /
  nationality / expiry. Clears the signature (keeps `waiver_reference` + audit
  rows) and logs `signature_voided`.
- The record (`/record.php?vp=<token>`) renders the **snapshots only**, never the
  live columns. Same clean-URL rule as check-in.

### 4.4 `visitor_visits`
`id`, `visitor_id` (the lead), `place_id`, `visit_date` (Nairobi), `party` JSONB
(`{"adults":[ids],"children":[ids]}`), `created_via`, `created_at`,
UNIQUE (`visitor_id`, `visit_date`) — picking again updates the place.

### 4.5 `visitors` (existing gate register) gains
`registered_visitor_id` (→ registered_visitors, ON DELETE SET NULL), `visit_id`
(→ visitor_visits, ON DELETE SET NULL), `people` INT. Rows with no
`registered_visitor_id` are the hand-typed fallback = *Not registered*.

### 4.6 `visitor_signing_audit`
Own table, same shape and hash chain as `checkin_signing_audit`
(`prev_hash`/`row_hash` over a fixed canonical field order), keyed on
`visitor_id`. Steps: `signed`, `record_viewed`, `signature_voided`. Its own
`visitor_audit_verify()`. Not mixed into the check-in chain: adding a column to
that canonical form would invalidate every stored hash.

## 5. Pass token

`VP-<visitor id>-<hmac12>`, HMAC of `visitor:<id>` with `BOOKING_TOKEN_SECRET`
(domain-separated from `gate:` and the booking ref). `visitor_pass_token()` /
`visitor_pass_verify()` in **`includes/visitor-registration.php`**, which holds
all the logic (pure helpers + I/O, pre-migration-safe). The token only NAMES the
visitor; it grants nothing at the gate without a signed-in gate account. A
removed visitor's token stops working.

## 6. At the gate — `admin/gate.php`

- The scanner and the code box accept `VP-…` as well as `GP-…` (`?vp=`).
- **Visitor card:** name, ID photo (thumbnail via the private file page), ID type
  + number, today's place, the group (adults ✓/⏳, children with ages), verdict.
- **Verdict** `visitor_gate_verdict()` (pure):
  - **stop** — removed; waiver not signed or out of date; an adult in today's
    party hasn't signed; today's place is behind a property this account doesn't
    cover (shows only "this pass is for <place>");
  - **warn** — no visit chosen for today → the guard picks a place from their own
    properties' places, then lets in;
  - **ok** otherwise.
- **Let in** (refused server-side on stop) → one `visitors` row: venue = the
  place's venue, `visiting` = place name, `registered_visitor_id`, `visit_id`,
  `people` = 1 + signed adults + children, `logged_by`. Let in is refused while the
  visitor still has an open sign-in (any day — "still signed in since …, sign them
  out first"); a partial unique index makes a double tap harmless. **Sign out** unchanged.
- **Register visitor** button → `/register?gate=1` in the guard's session: same
  form; email optional (no email = no pass email, the pass shows on the tablet);
  the visitor signs on the tablet (`signed_via = gate`, `created_by`); finishing
  returns to the Gate page with that visitor's card open.
- The old **Sign in visitor** form stays, relabelled "No phone? Sign in without
  registering", and those rows show a *Not registered* badge in the log.

## 7. Admin

### 7.1 Navigation
The Gate item gets tabs: **Gate** (`gate.php`) · **Visitors**
(`visitor-registry.php`; `visitor-file.php` is a layout-free stream, in no tab) ·
**Places & posters** (`visitor-places.php`, owner only). Help guide
"Registering visitors" (`help_guides()`), Access-by-role follows the Gate
section, search words "visitor, register, waiver, walk-in, day pass".

### 7.2 Visitors — `admin/visitor-registry.php`
`require_gate()`. Scope: owner all; others see visitors with a visit to a place
behind one of `admin_venue_ids()` (or registered on their tablet). Search by
name / phone / email / reference; columns: name, phone, last place, last visit,
signed ✓ / out of date / not signed. Drawer (`data-drawer`) with details, ID
photo, group, visits, **View signed record**, **Remove** (owner + manager, typed
`DELETE` via `danger_confirm_attrs()` / `typed_confirmation_ok()`): soft-removes
the person (`removed_at`), deletes the ID photo from storage, keeps the signature
snapshot + audit rows (legal evidence) and the gate log.

### 7.3 ID photo — `admin/visitor-file.php`
Private stream, re-checks `require_gate()` + scope on every view. Never a public URL.

### 7.4 Places & posters — `admin/visitor-places.php`
Owner (`require_owner()`): add / rename / reorder / switch off places, pick each
one's property. **Print poster** per place: A4, logo, "Register before you
enter", QR → `/register?place=<id>`, the short URL in text.

### 7.5 Settings
Settings → Pre-Check-in gets **Visitor waiver** (`visitor_waiver_text`; blank =
the check-in waiver `checkin_waiver_text()`). Changing it makes every visitor
re-sign on their next visit (hash changes).

### 7.6 Emails (registry entries in `email_registry()`, through `mail_send()`)
- `visitor_pass` — "Your Tribal Sand visitor pass" + link. Default on.
- `visitor_booking_link` — "Your booking link" for the no-code path. Default on.
Both editable in Admin → Emails, previews via the registry sample.

## 8. Security

- `/register` POSTs: CSRF, honeypot, Turnstile fail-closed on the first POST of
  the session (then trusted for the session, like the concierge), IP rate limit
  via a `visitor_reg_attempts`-style count (fails open on read error).
- **Booking-code lookups** have their own limit: 10 failed tries per IP per
  10 minutes, then "try again in N minutes" — codes cannot be guessed.
- "No code" and "registered before" always give the same answer and send at most
  one email per address per 10 minutes.
- Uploads: the check-in rules (sniffed JPEG/PNG, size cap, browser shrink, AI read
  capped per session). The AI reply is never trusted (MRZ check digit rule).
- Pass / invite / record tokens are HMACs; `client_ip()` for every IP.
- Tablet mode requires a signed-in `require_gate()` session; the visitor never
  sees admin chrome.

## 9. Testing

`php tests/visitor_registration_logic.php`:
- pure: token sign/verify + domain separation from `GP-`; surname match; gate
  verdict matrix (unsigned, out-of-date terms, unsigned adult in party, other
  property, no visit today, ok); people count; share text; waiver-current hash;
  void-on-edit compare;
- DB (one rolled-back transaction, SKIP without DB/migration): register → sign →
  visit → let in writes the `visitors` row; invite → sign → lead's party ok;
  re-signing after terms change; audit chain verifies and detects an edit;
  remove clears the photo key and blocks the token; place switch-off.
Also `tests/admin_nav_logic.php` (new pages in tabs) and `tests/help_logic.php`
(guide targets exist), `tests/email_log_logic.php` (registry entries),
`tests/migration_order_logic.php`.

## 10. Not included

Blocking/banning a visitor, entry fees, a visitor tab at the POS, SMS passes,
automatic deletion of old ID photos, per-visit headcount limits per place.
