# Visitor Registration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One general link (`/register`) where a guest with a booking is sent to their normal check-in, and anyone without one registers once (details + ID photo + signed waiver), picks where they are going, and gets a reusable visitor pass that security scans at the gate.

**Architecture:** A visitor registry of its own (`registered_visitors`, `visitor_places`, `visitor_visits`, a hash-chained `visitor_signing_audit`, a `visitor_reg_attempts` rate-limit log) beside the existing gate `visitors` register, which becomes the per-visit in/out log. Pure rules live in `includes/visitor-registration.php`; every DB read/write lives in `includes/visitor-store.php`. Public pages `register.php` + `visitor-pass.php` post to ONE handler `api/visitor-register.php` (post/redirect/get); the gate page reads `VP-…` passes beside booking `GP-…` passes.

**Tech Stack:** PHP 8.2 vanilla, PostgreSQL via `db_query()`, vanilla JS, existing check-in pieces (`js/signature-pad.js`, `js/checkin-passport.js`, `js/checkin-terms.js`, `css/portal-app.css`), `js/vendor/qrcode.js`, `js/vendor/jsqr.js`.

**Spec:** `docs/superpowers/specs/2026-10-14-visitor-registration-design.md`

## Global Constraints

- The whole app runs in **Africa/Nairobi**: "today" is `frontdesk_today_ymd()`; never assume UTC.
- **Never** read `$_SERVER['REMOTE_ADDR']`; use `client_ip()`.
- Prepared statements only (`db_query($sql, $params)`); never interpolate user input into SQL.
- Turnstile is **fail-closed** (`verify_captcha()`); never add a bypass.
- Files are **private**: `storage_put_private()` / `storage_delete_private()`; served only through a signed-in, scoped admin page. Never a public URL for an ID photo.
- Tokens: `VP-<id>-<hmac12>` = HMAC-SHA256 of `visitor:<id>` with `BOOKING_TOKEN_SECRET` (domain-separated from `gate:` and the booking ref).
- The signed record renders **snapshots only**, from the clean URL `/record.php?vp=…` — never under `/admin/`.
- Material identity fields (`full_name`, `id_type`, `id_number`, `nationality`, `id_expiry`) changed after signing **void** the signature (compare-before-void).
- Every email goes through `mail_send()` and has a registry entry in `email_registry()`.
- Admin confirmations use `danger_confirm_attrs()` + `typed_confirmation_ok('DELETE')`; never native `confirm()`.
- New admin pages must be listed in a nav tab's `pages` (`includes/admin-nav.php`) and get a help guide; owner-only sections go in `ACCESS_OWNER_ONLY`.
- New migration = append `<file>  2026-10-14` LAST in `db/migrations/ORDER.txt`.
- Form field names on the visitor form reuse the check-in names (`passport_name`, `passport_number`, `nationality`, `passport_expiry`, `id_type`) so `js/checkin-passport.js` fills them; the server maps them to `full_name`, `id_number`, `nationality`, `id_expiry`, `id_type`.
- Copy: plain words, real button labels ("Take picture", "Register", "Let in"). Minimum input sizes as in check-in.
- Build on the uncommitted check-in ID-type work in the working tree (`add_checkin_id_type.sql`, `CHECKIN_ID_TYPES`, `checkin_id_switch_html()`, `checkin_id_label_html()`); do not revert or re-commit it as part of this work unless the owner says so — stage only the files each task names.

## Review Focus

1. **A guest types a booking code with a slightly different spelling of their name** ("O'Brien" vs "OBrien", accents, two surnames) → still matched on the last word; a wrong name never reveals whose booking it is. (Task 2 tests `visitor_booking_name_match()` with these.)
2. **The visitor waiver wording changes after people signed** → their next scan is a *stop* with "sign again", the pass asks them to re-sign first, and their old record still shows the old terms. (Task 2 verdict test + Task 3 DB test.)
3. **A visitor who forgot to sign out yesterday is scanned today** → Let in refused with "still signed in since …, sign them out first", never a second open row. (Task 3 DB test of the partial unique index.)
4. **Guard of property A scans a pass for a place behind property B** → a stop that names only the place; no ID number, photo or group shown. (Task 2 verdict order test + Task 9 page check.)
5. **Someone hammers booking codes or recovery emails** → after 10 failed codes per IP in 10 minutes the lookup is refused, and a recovery email goes to one address at most once per 10 minutes, with the same neutral answer either way. (Task 3 DB test.)

---

## File structure

| File | Responsibility |
|---|---|
| `db/migrations/add_visitor_registration.sql` | Schema (5 tables + 3 columns on `visitors`), seeds places from published venues |
| `includes/visitor-registration.php` | PURE: tokens, URLs, name/phone matching, waiver version, input cleaning + validation, gate verdict, people count, audit canonical form, share text |
| `includes/visitor-store.php` | DB: support guard, rate-limit log, places, visitors, signing + audit chain, group, visits, booking lookup, gate card + let in, registry list, remove |
| `api/visitor-upload.php` | ID photo upload (session draft key) + AI read |
| `api/visitor-register.php` | The ONE POST handler (lookup · nocode · again · register · update · sign · visit · invite · child · drop) |
| `register.php` + `includes/visitor-form.php` | Public start screen, booking lookup, recovery, the registration / edit form (also the guard's tablet mode) |
| `visitor-pass.php` | The visitor's pass: sign again, today's place + who's coming, QR, group invites |
| `includes/visitor-record.php` | Signed record body, included by `record.php?vp=` |
| `admin/_gate_visitor.php` | The visitor card on the Gate page |
| `admin/visitor-registry.php` | Gate → Visitors list + detail drawer + remove |
| `admin/visitor-file.php` | Private ID photo stream |
| `admin/visitor-places.php` + `admin/visitor-poster-print.php` | Owner: places list · printable A4 QR poster |
| `tests/visitor_registration_logic.php` | All new tests |
| Modified | `js/checkin-passport.js` (endpoint option), `admin/gate.php`, `record.php`, `admin/checkin-settings.php`, `includes/mail.php`, `includes/email-templates.php`, `includes/admin-nav.php`, `includes/access.php`, `includes/help-guides.php`, `db/migrations/ORDER.txt`, `CLAUDE.md` |

---

### Task 1: Migration

**Files:**
- Create: `db/migrations/add_visitor_registration.sql`
- Modify: `db/migrations/ORDER.txt` (append last)
- Test: `tests/migration_order_logic.php` (existing)

**Interfaces:**
- Produces: tables `visitor_places`, `registered_visitors`, `visitor_visits`, `visitor_reg_attempts`, `visitor_signing_audit` (created LAST — the support guard probes it); columns `visitors.registered_visitor_id`, `visitors.visit_id`, `visitors.people`; partial unique index `uq_visitors_open_registered`.

- [ ] **Step 1: Write the migration**

```sql
-- General registration link — visitors register once, sign the waiver, get a pass.
-- Spec: docs/superpowers/specs/2026-10-14-visitor-registration-design.md
-- After add_visitors + add_checkin_id_type. Idempotent — safe to re-run.
-- visitor_signing_audit is created LAST: visitor_reg_supported() probes it.

CREATE TABLE IF NOT EXISTS visitor_places (
    id          SERIAL PRIMARY KEY,
    name        TEXT    NOT NULL,
    venue_id    INT     NOT NULL REFERENCES venues(id) ON DELETE RESTRICT,
    sort_order  INT     NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_visitor_places_name ON visitor_places (LOWER(name));
-- One place per published property to start with; the owner adds the restaurants.
INSERT INTO visitor_places (name, venue_id, sort_order)
SELECT v.name, v.id, v.sort_order FROM venues v
 WHERE v.is_published = TRUE
   AND NOT EXISTS (SELECT 1 FROM visitor_places p WHERE p.venue_id = v.id)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS registered_visitors (
    id                          SERIAL PRIMARY KEY,
    full_name                   TEXT NOT NULL,
    phone                       TEXT,
    phone_key                   VARCHAR(9),
    email                       TEXT,
    id_type                     VARCHAR(16) NOT NULL DEFAULT 'passport' CHECK (id_type IN ('passport', 'kenya_id')),
    id_number                   TEXT,
    nationality                 TEXT,
    id_expiry                   DATE,
    id_photo_key                TEXT,
    parent_id                   INT REFERENCES registered_visitors(id) ON DELETE CASCADE,
    age                         SMALLINT CHECK (age IS NULL OR age BETWEEN 0 AND 17),
    invited_by                  INT REFERENCES registered_visitors(id) ON DELETE SET NULL,
    waiver_signature            TEXT,
    waiver_signed_name          TEXT,
    waiver_signed_at            TIMESTAMPTZ,
    waiver_terms_snapshot       TEXT,
    waiver_version              VARCHAR(12),
    waiver_reference            VARCHAR(24) UNIQUE,
    waiver_name_snapshot        TEXT,
    waiver_id_type_snapshot     VARCHAR(16),
    waiver_id_number_snapshot   TEXT,
    waiver_nationality_snapshot TEXT,
    waiver_id_expiry_snapshot   DATE,
    signed_ip                   TEXT,
    signed_ua                   TEXT,
    signed_via                  VARCHAR(8) CHECK (signed_via IS NULL OR signed_via IN ('self', 'gate')),
    created_via                 VARCHAR(8) NOT NULL DEFAULT 'self' CHECK (created_via IN ('self', 'gate', 'invite')),
    created_by                  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    removed_at                  TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_regvis_phone   ON registered_visitors (phone_key) WHERE removed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_regvis_email   ON registered_visitors (LOWER(email)) WHERE removed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_regvis_parent  ON registered_visitors (parent_id);
CREATE INDEX IF NOT EXISTS idx_regvis_invited ON registered_visitors (invited_by);

CREATE TABLE IF NOT EXISTS visitor_visits (
    id          SERIAL PRIMARY KEY,
    visitor_id  INT  NOT NULL REFERENCES registered_visitors(id) ON DELETE CASCADE,
    place_id    INT  NOT NULL REFERENCES visitor_places(id) ON DELETE RESTRICT,
    visit_date  DATE NOT NULL,
    party       JSONB NOT NULL DEFAULT '{"adults":[],"children":[]}',
    created_via VARCHAR(8) NOT NULL DEFAULT 'self' CHECK (created_via IN ('self', 'gate')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (visitor_id, visit_date)
);

ALTER TABLE visitors ADD COLUMN IF NOT EXISTS registered_visitor_id INT REFERENCES registered_visitors(id) ON DELETE SET NULL;
ALTER TABLE visitors ADD COLUMN IF NOT EXISTS visit_id INT REFERENCES visitor_visits(id) ON DELETE SET NULL;
ALTER TABLE visitors ADD COLUMN IF NOT EXISTS people INT;
-- A registered visitor is on site at most once: a double tap or a forgotten
-- sign-out can never open a second row.
CREATE UNIQUE INDEX IF NOT EXISTS uq_visitors_open_registered
    ON visitors (registered_visitor_id) WHERE time_out IS NULL AND registered_visitor_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS visitor_reg_attempts (
    id          BIGSERIAL PRIMARY KEY,
    ip          TEXT NOT NULL,
    kind        VARCHAR(16) NOT NULL,
    subject     TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_vra_ip      ON visitor_reg_attempts (kind, ip, created_at);
CREATE INDEX IF NOT EXISTS idx_vra_subject ON visitor_reg_attempts (kind, subject, created_at);

-- Append-only, hash-chained evidence of every signing step (own chain — mixing
-- rows into checkin_signing_audit would invalidate its stored hashes).
CREATE TABLE IF NOT EXISTS visitor_signing_audit (
    id             BIGSERIAL PRIMARY KEY,
    reference      VARCHAR(24),
    visitor_id     INT,
    step           VARCHAR(24) NOT NULL,
    waiver_version VARCHAR(12),
    ip             TEXT,
    user_agent     TEXT,
    method         VARCHAR(16),
    detail         TEXT,
    prev_hash      CHAR(64) NOT NULL,
    row_hash       CHAR(64) NOT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_vsa_visitor ON visitor_signing_audit (visitor_id);
```

- [ ] **Step 2: Append the ORDER line**

Add as the LAST line of `db/migrations/ORDER.txt` (same column spacing as the lines above it):

```
add_visitor_registration.sql                         2026-10-14
```

- [ ] **Step 3: Run the order test**

Run: `php tests/migration_order_logic.php`
Expected: `ALL PASS`

- [ ] **Step 4: Apply locally (when a local DB is reachable) and re-run to prove idempotency**

Run: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_visitor_registration.sql")); db()->exec(file_get_contents("db/migrations/add_visitor_registration.sql")); echo "ok\n";'`
Expected: `ok` (twice applied without error). If no DB: skip and note it.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_visitor_registration.sql db/migrations/ORDER.txt
git commit -m "feat(visitors): migration — registry, places, visits, signing audit"
```
Note: `ORDER.txt` already carries uncommitted lines from the check-in ID-type work. If `git diff --cached db/migrations/ORDER.txt` shows those lines too, use `git add -p db/migrations/ORDER.txt` and stage only the new line.

---

### Task 2: Pure core

**Files:**
- Create: `includes/visitor-registration.php`
- Test: `tests/visitor_registration_logic.php`

**Interfaces:**
- Consumes: `parse_env()`, `site_url()`, `setting()`, `generate_access_code()` (`includes/db.php`); `waiver_version()`, `checkin_waiver_text()`, `checkin_valid_signature()`, `checkin_id_type()`, `CHECKIN_ID_TYPES` (`includes/checkin.php`).
- Produces (pure unless noted):
  - `visitor_pass_token(int $id, ?string $secret = null): string`
  - `visitor_pass_verify(string $tok, ?string $secret = null): int|false`
  - `visitor_pass_from_scan(string $text): string`
  - `visitor_pass_url(int $id): string` · `visitor_gate_url(int $id): string` · `visitor_invite_url(int $id): string` (absolute, via `site_url()`)
  - `visitor_phone_digits(string $p): string` · `visitor_phone_key(string $p): string` (last 9 digits or `''`)
  - `visitor_name_words(string $n): array` · `visitor_booking_name_match(string $typed, string $stored): bool`
  - `visitor_waiver_text(): string` (reads settings) · `visitor_signed(?array $v): bool` · `visitor_waiver_current(?array $v, string $version): bool`
  - `visitor_identity_changed(array $stored, array $new): array` (changed material field names)
  - `visitor_clean_input(array $post): array` → keys `full_name, phone, phone_key, email, id_type, id_number, nationality, id_expiry`
  - `visitor_validate(array $in, string $mode, bool $gate, array $placeIds): array` (field ⇒ message)
  - `visitor_make_reference(int $id): string` (`TSV-000012-ABCD`)
  - `visitor_audit_canonical(array $r): string` · `visitor_audit_hash(string $prev, array $r): string`
  - `visitor_gate_verdict(array $v, ?array $visit, array $adults, bool $termsCurrent, bool $inScope): array{level:string,msg:string}`
  - `visitor_people_count(array $adults, array $children): int`
  - `visitor_invite_share_text(string $name, string $place, string $link): string`
  - constants `VISITOR_PASS_PREFIX = 'VP'`, `VISITOR_MATERIAL_FIELDS`

- [ ] **Step 1: Write the failing test file (pure section)**

Create `tests/visitor_registration_logic.php`:

```php
<?php
declare(strict_types=1);
// Visitor registration (general registration link).
// Pure always: tokens, scan, name/phone matching, cleaning + validation, waiver
// version, identity-change detection, the gate verdict, the audit hash.
// With a reachable DB and add_visitor_registration.sql applied, the DB block runs
// inside ONE transaction that is rolled back.
// Run: php tests/visitor_registration_logic.php
require_once __DIR__ . '/../includes/visitor-registration.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Token ──
$sec = 'test-secret';
$t = visitor_pass_token(12, $sec);
check('token: shape VP-<id>-<12 hex>', (bool)preg_match('/^VP-12-[0-9a-f]{12}$/', $t));
check('token: verifies', visitor_pass_verify($t, $sec) === 12);
check('token: upper case still verifies', visitor_pass_verify(strtoupper($t), $sec) === 12);
check('token: other id refused', visitor_pass_verify('VP-13-' . substr($t, 6), $sec) === false);
check('token: other secret refused', visitor_pass_verify($t, 'other') === false);
check('token: no secret = no token', visitor_pass_token(12, '') === '' && visitor_pass_verify($t, '') === false);
check('token: domain-separated from the gate pass', substr($t, 6) !== substr(hash_hmac('sha256', 'gate:12', $sec), 0, 12));
check('token: a GP- pass is not a visitor pass', visitor_pass_verify('GP-12-' . substr($t, 6), $sec) === false);
check('scan: token inside the gate link', visitor_pass_from_scan('https://tribalsand.com/admin/gate.php?vp=' . $t) === $t);
check('scan: GP pass is not read as VP', visitor_pass_from_scan('GP-12-0123456789ab') === '');

// ── Names and phones ──
check('name: same surname matches', visitor_booking_name_match('anna smith', 'Anna Smith'));
check('name: surname only matches', visitor_booking_name_match('Smith', 'Anna Smith'));
check('name: apostrophe ignored', visitor_booking_name_match("Sean O'Brien", 'Sean OBrien') || visitor_booking_name_match("Sean O'Brien", "Sean O'Brien"));
check('name: accents folded', visitor_booking_name_match('Muller', 'Jürgen Müller'));
check('name: second surname matches', visitor_booking_name_match('Garcia', 'Maria Lopez Garcia'));
check('name: other surname refused', !visitor_booking_name_match('Jones', 'Anna Smith'));
check('name: one letter refused', !visitor_booking_name_match('S', 'Anna S'));
check('name: empty refused', !visitor_booking_name_match('', 'Anna Smith'));
check('phone: digits only', visitor_phone_digits('+254 712-345 678') === '254712345678');
check('phone: key = last 9', visitor_phone_key('+254 712 345 678') === '712345678' && visitor_phone_key('0712345678') === '712345678');
check('phone: too short = no key', visitor_phone_key('12345') === '');

// ── Cleaning + validation ──
$in = visitor_clean_input(['passport_name' => '  Anna  Smith ', 'phone' => '+254712345678', 'email' => ' Anna@Example.COM ',
    'id_type' => 'kenya_id', 'passport_number' => 'ab 12-345 6', 'nationality' => 'Kenya', 'passport_expiry' => '2030-02-30']);
check('clean: name squeezed', $in['full_name'] === 'Anna Smith');
check('clean: email lower-cased', $in['email'] === 'anna@example.com');
check('clean: ID number upper alnum', $in['id_number'] === 'AB123456');
check('clean: bad date dropped', $in['id_expiry'] === '');
check('clean: phone key', $in['phone_key'] === '712345678');
check('clean: unknown id type → passport', visitor_clean_input(['id_type' => 'x'])['id_type'] === 'passport');
$good = $in + ['place_id' => 3, 'photo' => true, 'signed_name' => 'Anna Smith', 'signature' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40)), 'agree' => true];
check('valid: complete registration', visitor_validate($good, 'register', false, [3, 4]) === []);
check('valid: place must be offered', isset(visitor_validate(['place_id' => 9] + $good, 'register', false, [3, 4])['place_id']));
check('valid: photo required', isset(visitor_validate(['photo' => false] + $good, 'register', false, [3])['photo']));
check('valid: email required in public mode', isset(visitor_validate(['email' => ''] + $good, 'register', false, [3])['email']));
check('valid: email optional on the gate tablet', !isset(visitor_validate(['email' => ''] + $good, 'register', true, [3])['email']));
check('valid: bad email refused even on the tablet', isset(visitor_validate(['email' => 'nope'] + $good, 'register', true, [3])['email']));
check('valid: phone required', isset(visitor_validate(['phone_key' => ''] + $good, 'register', false, [3])['phone']));
check('valid: short ID number refused', isset(visitor_validate(['id_number' => 'AB1'] + $good, 'register', false, [3])['id_number']));
check('valid: signature required', isset(visitor_validate(['signature' => ''] + $good, 'register', false, [3])['signature']));
check('valid: agree required', isset(visitor_validate(['agree' => false] + $good, 'register', false, [3])['agree']));
check('valid: edit mode needs no place or signature', visitor_validate(['place_id' => 0, 'signature' => '', 'agree' => false, 'signed_name' => ''] + $good, 'edit', false, [3]) === []);

// ── Waiver + identity ──
$signed = ['waiver_signed_at' => '2026-10-14 10:00', 'waiver_signed_name' => 'Anna Smith', 'waiver_signature' => 'data:x', 'waiver_version' => 'abc123abc123'];
check('signed: all three present', visitor_signed($signed));
check('signed: missing signature = not signed', !visitor_signed(['waiver_signature' => ''] + $signed));
check('current: same version', visitor_waiver_current($signed, 'abc123abc123'));
check('current: changed terms = not current', !visitor_waiver_current($signed, 'fff000fff000'));
$stored = ['full_name' => 'Anna Smith', 'id_type' => 'passport', 'id_number' => 'AB123456', 'nationality' => 'Kenya', 'id_expiry' => '2030-01-01'];
check('identity: nothing changed', visitor_identity_changed($stored, $stored) === []);
check('identity: case of number ignored', visitor_identity_changed($stored, ['id_number' => 'ab123456']) === []);
check('identity: new number', visitor_identity_changed($stored, ['id_number' => 'ZZ999999']) === ['id_number']);
check('identity: absent fields not compared', visitor_identity_changed($stored, ['phone' => '1']) === []);
check('reference: TSV-<6 digits>-<4>', (bool)preg_match('/^TSV-000012-[A-Z0-9]{4}$/', visitor_make_reference(12)));

// ── Gate verdict ──
$v = $signed + ['full_name' => 'Anna Smith', 'removed_at' => null];
$visit = ['place_name' => 'Tribal Table', 'venue_id' => 7];
$ok = visitor_gate_verdict($v, $visit, [['name' => 'Ben', 'current' => true]], true, true);
check('verdict: ok', $ok['level'] === 'ok' && str_contains($ok['msg'], 'Tribal Table'));
check('verdict: removed = stop', visitor_gate_verdict(['removed_at' => '2026-10-01'] + $v, $visit, [], true, true)['level'] === 'stop');
$far = visitor_gate_verdict(['waiver_signature' => ''] + $v, $visit, [['name' => 'Ben', 'current' => false]], false, false);
check('verdict: other property checked FIRST and names only the place', $far['level'] === 'stop' && str_contains($far['msg'], 'Tribal Table') && !str_contains($far['msg'], 'Ben'));
check('verdict: unsigned = stop', visitor_gate_verdict(['waiver_signature' => ''] + $v, $visit, [], false, true)['level'] === 'stop');
$terms = visitor_gate_verdict($v, $visit, [], false, true);
check('verdict: changed terms = stop, says sign again', $terms['level'] === 'stop' && str_contains($terms['msg'], 'sign again'));
$wait = visitor_gate_verdict($v, $visit, [['name' => 'Ben Ode', 'current' => false]], true, true);
check('verdict: an unsigned adult = stop naming them', $wait['level'] === 'stop' && str_contains($wait['msg'], 'Ben Ode'));
check('verdict: no place today = warn', visitor_gate_verdict($v, null, [], true, true)['level'] === 'warn');
check('people: lead + adults + children', visitor_people_count([['name' => 'a']], [['id' => 1], ['id' => 2]]) === 4);

// ── Audit hash ──
$row = ['reference' => 'TSV-000012-ABCD', 'visitor_id' => 12, 'step' => 'signed', 'waiver_version' => 'abc', 'ip' => '1.2.3.4', 'user_agent' => 'UA', 'method' => 'self', 'detail' => 'x'];
check('audit: hash is stable', visitor_audit_hash(str_repeat('0', 64), $row) === visitor_audit_hash(str_repeat('0', 64), $row));
check('audit: an edit changes the hash', visitor_audit_hash(str_repeat('0', 64), $row) !== visitor_audit_hash(str_repeat('0', 64), ['detail' => 'y'] + $row));
check('audit: int and string ids hash the same', visitor_audit_hash('p', $row) === visitor_audit_hash('p', ['visitor_id' => '12'] + $row));

// ── Share text ──
$s = visitor_invite_share_text('Ben Ode', 'Tribal Table', 'https://x/register.php?t=VP-1-a');
check('share: first name, place and link', str_contains($s, 'Ben') && !str_contains($s, 'Ode') && str_contains($s, 'Tribal Table') && str_contains($s, 'https://x/register.php?t=VP-1-a'));

// ── DB (Task 3 adds this block) ──

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it to see it fail**

Run: `php tests/visitor_registration_logic.php`
Expected: fatal error "Failed opening required … includes/visitor-registration.php".

- [ ] **Step 3: Write `includes/visitor-registration.php`**

```php
<?php
/**
 * General registration link — visitors with no booking register once, sign the
 * waiver and get a reusable pass. PURE part: tokens, matching, cleaning +
 * validation, waiver version, the gate verdict, the audit hash. Every DB read
 * and write lives in includes/visitor-store.php.
 * Spec: docs/superpowers/specs/2026-10-14-visitor-registration-design.md
 * Test: php tests/visitor_registration_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/checkin.php';   // waiver_version(), checkin_waiver_text(), checkin_valid_signature(), CHECKIN_ID_TYPES

const VISITOR_PASS_PREFIX     = 'VP';
/** Identity fields whose change after signing voids the signature. */
const VISITOR_MATERIAL_FIELDS = ['full_name', 'id_type', 'id_number', 'nationality', 'id_expiry'];

function visitor_secret(?string $secret): string {
    return $secret ?? (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '');
}

/** The pass token for a visitor: "VP-<id>-<hmac12>". '' when BOOKING_TOKEN_SECRET is unset. */
function visitor_pass_token(int $id, ?string $secret = null): string {
    $secret = visitor_secret($secret);
    if ($secret === '' || $id <= 0) return '';
    return VISITOR_PASS_PREFIX . '-' . $id . '-' . substr(hash_hmac('sha256', "visitor:{$id}", $secret), 0, 12);
}

function visitor_pass_verify(string $tok, ?string $secret = null): int|false {
    $secret = visitor_secret($secret);
    if ($secret === '') return false;
    if (!preg_match('/^VP-(\d{1,10})-([0-9a-f]{12})$/i', trim($tok), $m)) return false;
    $id = (int)$m[1];
    return hash_equals(visitor_pass_token($id, $secret), VISITOR_PASS_PREFIX . '-' . $id . '-' . strtolower($m[2])) ? $id : false;
}

/** The visitor token inside whatever a scanner read (the gate link or the bare token). '' when none. */
function visitor_pass_from_scan(string $text): string {
    return preg_match('/\bVP-\d{1,10}-[0-9a-fA-F]{12}\b/', $text, $m) ? $m[0] : '';
}

function visitor_link(string $path, int $id): string {
    $t = visitor_pass_token($id);
    return $t === '' ? '' : site_url($path . rawurlencode($t));
}
/** The visitor's own pass page. */
function visitor_pass_url(int $id): string   { return visitor_link('/visitor-pass.php?t=', $id); }
/** What the QR carries: the guard's Gate page. */
function visitor_gate_url(int $id): string   { return visitor_link('/admin/gate.php?vp=', $id); }
/** An invited adult's link to register and sign. */
function visitor_invite_url(int $id): string { return visitor_link('/register.php?t=', $id); }

function visitor_phone_digits(string $p): string { return preg_replace('/\D+/', '', $p) ?? ''; }

/** Last 9 digits — "+254 712…" and "0712…" are the same phone. '' when shorter. */
function visitor_phone_key(string $p): string {
    $d = visitor_phone_digits($p);
    return strlen($d) >= 9 ? substr($d, -9) : '';
}

/** Lower-case, accent-free words of a name ("O'Brien" → "o", "brien"). */
function visitor_name_words(string $name): array {
    $s = mb_strtolower(trim($name), 'UTF-8');
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = strtolower($t);
    }
    $s = str_replace(["'", '’', '`', '"'], '', $s);   // O'Brien = OBrien
    return preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** The last word typed (the surname) is one of the words of the stored name, ≥ 2 letters. */
function visitor_booking_name_match(string $typed, string $stored): bool {
    $t = visitor_name_words($typed);
    $s = visitor_name_words($stored);
    if (!$t || !$s) return false;
    $last = end($t);
    return strlen($last) >= 2 && in_array($last, $s, true);
}

/** The terms visitors sign: Settings → Pre-Check-in "Visitor waiver", else the check-in waiver. */
function visitor_waiver_text(): string {
    $w = trim((string) setting('visitor_waiver_text', ''));
    return $w !== '' ? $w : checkin_waiver_text();
}

function visitor_signed(?array $v): bool {
    return $v !== null
        && !empty($v['waiver_signed_at'])
        && trim((string)($v['waiver_signed_name'] ?? '')) !== ''
        && trim((string)($v['waiver_signature'] ?? '')) !== '';
}

/** Signed, and for the terms in force today ($version = waiver_version(visitor_waiver_text())). */
function visitor_waiver_current(?array $v, string $version): bool {
    return visitor_signed($v) && $version !== '' && hash_equals($version, (string)($v['waiver_version'] ?? ''));
}

/** Material identity fields that differ. Only keys present in $new are compared. */
function visitor_identity_changed(array $stored, array $new): array {
    $changed = [];
    foreach (VISITOR_MATERIAL_FIELDS as $f) {
        if (!array_key_exists($f, $new)) continue;
        $a = trim((string)($stored[$f] ?? ''));
        $b = trim((string)($new[$f] ?? ''));
        if ($f === 'id_expiry') { $a = substr($a, 0, 10); $b = substr($b, 0, 10); }
        if ($f === 'id_number') { $a = strtoupper($a); $b = strtoupper($b); }
        if ($a !== $b) $changed[] = $f;
    }
    return $changed;
}

/**
 * The form's fields → stored values. The form uses the check-in field names
 * (so js/checkin-passport.js fills them): passport_name → full_name,
 * passport_number → id_number, passport_expiry → id_expiry.
 */
function visitor_clean_input(array $p): array {
    $s = fn(string $k): string => trim((string)($p[$k] ?? ''));
    $exp = $s('passport_expiry');
    $okDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $exp, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    $phone = mb_substr($s('phone'), 0, 30);
    return [
        'full_name'   => mb_substr(preg_replace('/\s+/u', ' ', $s('passport_name')) ?? '', 0, 120),
        'phone'       => $phone,
        'phone_key'   => visitor_phone_key($phone),
        'email'       => mb_strtolower(mb_substr($s('email'), 0, 160)),
        'id_type'     => checkin_id_type($s('id_type')),
        'id_number'   => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s('passport_number')) ?? ''),
        'nationality' => mb_substr($s('nationality'), 0, 60),
        'id_expiry'   => $okDate ? $exp : '',
    ];
}

/**
 * Check the form. $in = visitor_clean_input() + place_id, photo (bool),
 * signed_name, signature, agree. $mode 'register' (details + place + signature)
 * or 'edit' (details only). $gate = the guard's tablet (email optional).
 * Returns field => message ([] = fine).
 */
function visitor_validate(array $in, string $mode, bool $gate, array $placeIds): array {
    $e = [];
    $reg = $mode === 'register';
    if ($reg && !in_array((int)($in['place_id'] ?? 0), array_map('intval', $placeIds), true)) $e['place_id'] = 'Choose where you are going.';
    if (count(visitor_name_words((string)($in['full_name'] ?? ''))) < 2) $e['passport_name'] = 'Enter your full name, as on your ID.';
    if (($in['phone_key'] ?? '') === '') $e['phone'] = 'Enter a phone number we can reach you on.';
    $email = (string)($in['email'] ?? '');
    if ($email === '' && !$gate) $e['email'] = 'Enter your email — we send your pass there.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'That email address doesn’t look right.';
    $num = (string)($in['id_number'] ?? '');
    if (strlen($num) < 5 || strlen($num) > 20) $e['passport_number'] = 'Enter your passport or ID number.';
    if (trim((string)($in['nationality'] ?? '')) === '') $e['nationality'] = 'Enter your nationality.';
    if (empty($in['photo'])) $e['photo'] = 'Take a picture of your passport or ID.';
    if ($reg) {
        if (trim((string)($in['signed_name'] ?? '')) === '') $e['waiver_signed_name'] = 'Type your full name to sign.';
        if (!checkin_valid_signature((string)($in['signature'] ?? ''))) $e['signature'] = 'Sign in the box with your finger.';
        if (empty($in['agree'])) $e['agree'] = 'Tick that you have read and agree to the terms.';
    }
    return $e;
}

/** A signed record's reference: TSV-<6-digit id>-<4 random>. */
function visitor_make_reference(int $id): string {
    return sprintf('TSV-%06d-%s', $id, generate_access_code(4));
}

/** The exact string an audit row is hashed over. FIXED order — changing it breaks every stored hash. */
function visitor_audit_canonical(array $r): string {
    $f = fn(string $k): string => (string)($r[$k] ?? '');
    return implode("\x1f", [$f('reference'), $f('visitor_id'), $f('step'), $f('waiver_version'),
                            $f('ip'), $f('user_agent'), $f('method'), $f('detail')]);
}

function visitor_audit_hash(string $prev, array $r): string {
    return hash('sha256', $prev . "\x1e" . visitor_audit_canonical($r));
}

/**
 * What the guard does. $v = the visitor row; $visit = today's visit
 * (place_name, venue_id) or null; $adults = [['name', 'current' => signed for
 * today's terms]] coming with them; $termsCurrent = the visitor's own signature
 * is for today's terms; $inScope = today's place is behind a property this
 * account covers. Scope is checked FIRST so a guard elsewhere learns nothing
 * but the place.
 */
function visitor_gate_verdict(array $v, ?array $visit, array $adults, bool $termsCurrent, bool $inScope): array {
    if (!empty($v['removed_at'])) return ['level' => 'stop', 'msg' => 'This pass has been withdrawn. Do not admit — call reception.'];
    if ($visit !== null && !$inScope) {
        return ['level' => 'stop', 'msg' => 'This pass is for ' . ((string)($visit['place_name'] ?? '') ?: 'another place') . ', which you don’t cover. Do not admit — call reception.'];
    }
    if (!visitor_signed($v)) return ['level' => 'stop', 'msg' => 'Not registered yet — the waiver isn’t signed. Ask them to finish on their phone, or register them on this tablet.'];
    if (!$termsCurrent) return ['level' => 'stop', 'msg' => 'Our terms have changed since they signed. They must sign again on their pass before entering.'];
    $waiting = [];
    foreach ($adults as $a) if (empty($a['current'])) $waiting[] = (string)($a['name'] ?? 'Guest');
    if ($waiting) return ['level' => 'stop', 'msg' => 'Still to sign: ' . implode(', ', $waiting) . '. Every adult must sign before the group enters.'];
    if ($visit === null) return ['level' => 'warn', 'msg' => 'No place chosen for today — pick where they are going, then let them in.'];
    return ['level' => 'ok', 'msg' => 'Registered — going to ' . (string)$visit['place_name'] . '.'];
}

function visitor_people_count(array $adults, array $children): int {
    return 1 + count($adults) + count($children);
}

/** The message a lead sends another adult with their link. */
function visitor_invite_share_text(string $name, string $place, string $link): string {
    $first = trim($name) !== '' ? explode(' ', trim($name))[0] : '';
    $where = trim($place) !== '' ? ' to ' . trim($place) : '';
    return 'Hi' . ($first !== '' ? ' ' . $first : '') . ', everyone who goes' . $where
         . ' has to register with Tribal Sand first. It takes 2 minutes — add your details and sign here: ' . $link;
}
```

- [ ] **Step 4: Run the tests**

Run: `php tests/visitor_registration_logic.php`
Expected: `ALL PASS`. If `name: accents folded` fails on this machine's iconv, keep the test and make `visitor_name_words()` fold with `strtr()` over the common Latin accents (`äàáâãå→a, ëèéê→e, ïìíî→i, öòóôõ→o, üùúû→u, ñ→n, ç→c, ß→ss`) before the iconv line.

- [ ] **Step 5: Commit**

```bash
git add includes/visitor-registration.php tests/visitor_registration_logic.php
git commit -m "feat(visitors): pure core — pass token, matching, validation, gate verdict"
```

---

### Task 3: Store (DB layer)

**Files:**
- Create: `includes/visitor-store.php`
- Modify: `tests/visitor_registration_logic.php` (replace the `// ── DB (Task 3 adds this block) ──` line)

**Interfaces:**
- Consumes: everything from Task 2; `db()`, `db_query()`, `client_ip()`, `audit_log()` (`includes/auth.php`), `storage_delete_private()` (`includes/storage.php`), `resolve_booking_by_code_only()`, `make_guest_ref()`, `make_guest_pass_token()`, `fetch_hold_for_guest()` (`includes/booking.php`), `fetch_checkin_guests()`, `checkin_required()` (`includes/checkin.php`).
- Produces:
  - `visitor_reg_supported(): bool`
  - `visitor_session_verified(): bool` · `visitor_mark_verified(): void`
  - `visitor_attempt_log(string $kind, string $subject = ''): void` · `visitor_attempt_count(string $kind, int $minutes, ?string $subject = null): int`
  - constants `VISITOR_CODE_FAIL_MAX = 10` (per IP / 10 min), `VISITOR_REGISTER_MAX = 30` (per IP / 60 min), `VISITOR_RECOVER_MAX = 5` (per IP / 10 min), `VISITOR_UPLOAD_MAX = 30` (per IP / 60 min), `VISITOR_VERIFIED_TTL = 7200`
  - `visitor_places(bool $activeOnly = true, ?array $venueIds = null): array` · `visitor_place(int $id): ?array` · `visitor_place_save(?int $id, string $name, int $venueId, int $sort, bool $active): int` (throws `InvalidArgumentException`)
  - `visitor_find(int $id, bool $withRemoved = false): ?array` · `visitor_find_by_token(string $tok): ?array` · `visitor_find_by_contact(string $s): ?array`
  - `visitor_create(array $d, string $via, ?int $createdBy = null): int` (`$d` = `visitor_clean_input()` + `photo_key`)
  - `visitor_update_identity(int $id, array $d, string $actor): bool` (true = a signature was voided)
  - `visitor_sign(int $id, string $signedName, string $signature, string $via): string` (returns the reference)
  - `visitor_log_step(string $step, array $ctx): void` · `visitor_audit_verify(?int $visitorId = null): array{ok:bool,checked:int,bad_id:?int}`
  - `visitor_add_child(int $parentId, string $name, int $age): int` · `visitor_invite_adult(int $leadId, string $name): int` · `visitor_remove_member(int $leadId, int $memberId): bool` · `visitor_group(int $leadId): array{adults:array,children:array}`
  - `visitor_choose_visit(int $visitorId, int $placeId, string $date, array $adultIds, array $childIds, string $via): int` · `visitor_visit_on(int $visitorId, string $date): ?array` · `visitor_latest_visit(int $visitorId): ?array` · `visitor_visit_party(?array $visit, int $leadId): array{adults:array,children:array}`
  - `visitor_booking_lookup(string $code, string $name, string $today): array{kind:string,url:string}` (`kind` ∈ lead · guest · nomatch · notfound)
  - `visitor_booking_links_for_email(string $email, string $today): array` (list of `['hold' => row, 'url' => string]`)
  - `visitor_on_site(int $visitorId): ?array`
  - `visitor_gate_card(int $visitorId, string $today, ?array $venueIds): ?array` → keys `visitor, visit, adults, children, verdict, in_scope, on_site, people`
  - `visitor_let_in(int $visitorId, string $today, ?int $placeId, int $byAdmin, ?array $venueIds): array{ok:bool,msg:string}`
  - `visitor_in_scope(int $id, ?array $venueIds, int $me): bool` · `visitor_registry_list(?array $venueIds, int $me, string $q, int $per, int $offset): array{rows:array,total:int}` · `visitor_visit_history(int $id, ?array $venueIds): array`
  - `visitor_remove(int $id): bool`

- [ ] **Step 1: Write the failing DB tests**

In `tests/visitor_registration_logic.php`, replace the line `// ── DB (Task 3 adds this block) ──` with:

```php
// ── DB (rolled back) ──
require_once __DIR__ . '/../includes/visitor-store.php';
try { $pdo = db(); $pdo->query('SELECT 1'); } catch (Throwable $e) { $pdo = null; }
if (!$pdo) {
    echo "SKIP  DB checks (no database)\n";
} elseif (!visitor_reg_supported()) {
    echo "SKIP  DB checks (run add_visitor_registration.sql)\n";
} else {
    $pdo->beginTransaction();
    try {
        $_SERVER['HTTP_USER_AGENT'] = 'visitor-test';
        $venue = (int)db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
        $placeId = visitor_place_save(null, 'Test Bar ' . bin2hex(random_bytes(3)), $venue, 99, true);
        check('db place: saved and listed', (bool)array_filter(visitor_places(true, [$venue]), fn($p) => (int)$p['id'] === $placeId));
        $dup = false; try { visitor_place_save(null, strtoupper((string)visitor_place($placeId)['name']), $venue, 1, true); } catch (InvalidArgumentException $e) { $dup = true; }
        check('db place: duplicate name refused (any case)', $dup);

        $d = visitor_clean_input(['passport_name' => 'Anna Smith', 'phone' => '0712 345 678', 'email' => 'anna.vtest@example.com',
            'id_type' => 'passport', 'passport_number' => 'AB123456', 'nationality' => 'Kenya']) + ['photo_key' => 'visitors/test.jpg'];
        $id = visitor_create($d, 'self');
        check('db create: found by token', ($r = visitor_find_by_token(visitor_pass_token($id))) && (int)$r['id'] === $id);
        check('db create: found by phone in another format', ($r = visitor_find_by_contact('+254712345678')) && (int)$r['id'] === $id);
        check('db create: found by email any case', ($r = visitor_find_by_contact('ANNA.VTEST@example.com')) && (int)$r['id'] === $id);

        $sig = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 40));
        $ref = visitor_sign($id, 'Anna Smith', $sig, 'self');
        $v = visitor_find($id);
        check('db sign: reference + snapshot', str_starts_with($ref, 'TSV-') && $v['waiver_name_snapshot'] === 'Anna Smith' && $v['waiver_id_number_snapshot'] === 'AB123456');
        check('db sign: current for today\'s terms', visitor_waiver_current($v, waiver_version(visitor_waiver_text())));

        check('db identity: unchanged details keep the signature', visitor_update_identity($id, $d, 'visitor') === false && visitor_signed(visitor_find($id)));
        check('db identity: new ID number voids', visitor_update_identity($id, ['id_number' => 'ZZ999999'] + $d, 'visitor') === true && !visitor_signed(visitor_find($id)));
        check('db identity: reference kept after void', visitor_find($id)['waiver_reference'] === $ref);
        visitor_sign($id, 'Anna Smith', $sig, 'self');
        check('db identity: re-sign snapshots the new number', visitor_find($id)['waiver_id_number_snapshot'] === 'ZZ999999');

        $kid = visitor_add_child($id, 'Mia Smith', 6);
        $ben = visitor_invite_adult($id, 'Ben Ode');
        $g = visitor_group($id);
        check('db group: one adult, one child', count($g['adults']) === 1 && count($g['children']) === 1);
        $visitId = visitor_choose_visit($id, $placeId, '2026-10-14', [$ben, 999999], [$kid], 'self');
        $visit = visitor_visit_on($id, '2026-10-14');
        check('db visit: foreign ids dropped from the party', $visit && $visit['party']['adults'] === [$ben] && $visit['party']['children'] === [$kid]);
        check('db visit: choosing again updates the same row', visitor_choose_visit($id, $placeId, '2026-10-14', [], [], 'self') === $visitId);
        visitor_choose_visit($id, $placeId, '2026-10-14', [$ben], [$kid], 'self');

        $card = visitor_gate_card($id, '2026-10-14', [$venue]);
        check('db gate: unsigned adult in the party = stop', $card['verdict']['level'] === 'stop' && str_contains($card['verdict']['msg'], 'Ben Ode'));
        check('db gate: let in refused while an adult hasn\'t signed', !visitor_let_in($id, '2026-10-14', null, 0, [$venue])['ok']);
        visitor_update_identity($ben, visitor_clean_input(['passport_name' => 'Ben Ode', 'phone' => '0799 000 111', 'email' => 'ben.vtest@example.com',
            'passport_number' => 'CD654321', 'nationality' => 'Kenya']) + ['photo_key' => 'visitors/ben.jpg'], 'visitor');
        visitor_sign($ben, 'Ben Ode', $sig, 'self');
        $card = visitor_gate_card($id, '2026-10-14', [$venue]);
        check('db gate: everyone signed = ok, 3 people', $card['verdict']['level'] === 'ok' && $card['people'] === 3);
        check('db gate: other property sees a stop', visitor_gate_card($id, '2026-10-14', [-1])['verdict']['level'] === 'stop');
        $in = visitor_let_in($id, '2026-10-14', null, 0, [$venue]);
        check('db gate: let in writes the register row', $in['ok'] && ($os = visitor_on_site($id)) && (int)$os['people'] === 3 && (int)$os['visit_id'] === $visitId);
        $again = visitor_let_in($id, '2026-10-15', $placeId, 0, [$venue]);   // next day, a place picked: only the open sign-in blocks it
        check('db gate: next day refused while still signed in', !$again['ok'] && str_contains($again['msg'], 'sign them out first'));

        set_setting('visitor_waiver_text', 'Changed terms ' . bin2hex(random_bytes(3)));
        check('db terms: changed waiver = stop "sign again"', str_contains(visitor_gate_card($id, '2026-10-14', [$venue])['verdict']['msg'], 'sign again'));

        $audit = visitor_audit_verify($id);
        check('db audit: chain verifies', $audit['ok'] && $audit['checked'] >= 4);
        db_query("UPDATE visitor_signing_audit SET detail = 'edited' WHERE id = (SELECT MAX(id) FROM visitor_signing_audit WHERE visitor_id = :v)", [':v' => $id]);
        check('db audit: an edited row is detected', !visitor_audit_verify($id)['ok']);

        for ($i = 0; $i < VISITOR_CODE_FAIL_MAX; $i++) visitor_attempt_log('code_fail');
        check('db limit: code failures counted per IP', visitor_attempt_count('code_fail', 10) >= VISITOR_CODE_FAIL_MAX);
        visitor_attempt_log('recover_mail', 'anna.vtest@example.com');
        check('db limit: counted per subject', visitor_attempt_count('recover_mail', 10, 'anna.vtest@example.com') === 1);

        check('db lookup: unknown code = notfound', visitor_booking_lookup('ZZZZZZZZ', 'Smith', '2026-10-14')['kind'] === 'notfound');
        $h = db_query("SELECT id, access_code, guest_name FROM holds WHERE status IN ('pending','confirmed') AND check_out >= CURRENT_DATE AND access_code IS NOT NULL AND guest_name <> '' ORDER BY id DESC LIMIT 1")->fetch();
        if ($h) {
            $words = visitor_name_words((string)$h['guest_name']);
            $hit = visitor_booking_lookup((string)$h['access_code'], (string)end($words), date('Y-m-d'));
            check('db lookup: code + surname = the lead\'s link', $hit['kind'] === 'lead' && str_contains($hit['url'], 'ref='));
            check('db lookup: code + wrong name = nomatch, no link', visitor_booking_lookup((string)$h['access_code'], 'Qqqzzx', date('Y-m-d')) === ['kind' => 'nomatch', 'url' => '']);
        } else {
            echo "SKIP  booking lookup (no current booking with a code)\n";
        }

        check('db remove: removed', visitor_remove($id));
        check('db remove: token stops working', visitor_find_by_token(visitor_pass_token($id)) === null);
        $rm = visitor_find($id, true);
        check('db remove: photo key cleared, signature evidence kept', $rm['id_photo_key'] === null && $rm['waiver_reference'] !== null);
    } catch (Throwable $e) {
        check('db checks ran without error: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
```

Note: `visitor_remove()` deletes the file with `storage_delete_private()`; with the fake key `visitors/test.jpg` the delete is a no-op failure that must be swallowed (the function wraps it in `try`).

- [ ] **Step 2: Run it to see it fail**

Run: `php tests/visitor_registration_logic.php`
Expected: fatal error "Failed opening required … includes/visitor-store.php".

- [ ] **Step 3: Write `includes/visitor-store.php`**

```php
<?php
/**
 * Visitor registration — the DB side. Every read is pre-migration-safe
 * (visitor_reg_supported()). Pure rules: includes/visitor-registration.php.
 * Test: php tests/visitor_registration_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/visitor-registration.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/storage.php';

const VISITOR_CODE_FAIL_MAX = 10;    // failed booking-code tries per IP per 10 minutes
const VISITOR_REGISTER_MAX  = 30;    // registrations per IP per hour (a bar's wifi is one IP)
const VISITOR_RECOVER_MAX   = 5;     // "no code" / "registered before" requests per IP per 10 minutes
const VISITOR_UPLOAD_MAX    = 30;    // ID photo uploads per IP per hour
const VISITOR_VERIFIED_TTL  = 7200;  // seconds a Turnstile pass is trusted in this session
const VISITOR_AUDIT_LOCK    = 734120;   // pg advisory lock key: one audit writer at a time

/** True once add_visitor_registration.sql has run (probes its LAST table). */
function visitor_reg_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { return $ok = (bool) db_query("SELECT to_regclass('public.visitor_signing_audit')")->fetchColumn(); }
    catch (Throwable $e) { return $ok = false; }
}

function visitor_session_verified(): bool {
    return (int)($_SESSION['vr_verified_at'] ?? 0) > time() - VISITOR_VERIFIED_TTL;
}
function visitor_mark_verified(): void { $_SESSION['vr_verified_at'] = time(); }

// ── Rate-limit log ──────────────────────────────────────────────────────────
function visitor_attempt_log(string $kind, string $subject = ''): void {
    try {
        db_query('INSERT INTO visitor_reg_attempts (ip, kind, subject) VALUES (:ip, :k, :s)',
            [':ip' => client_ip(), ':k' => $kind, ':s' => $subject !== '' ? $subject : null]);
    } catch (Throwable $e) { /* never block on a log failure */ }
}

/** Attempts of $kind in the last $minutes — by this IP, or by $subject when given. Fails open (0). */
function visitor_attempt_count(string $kind, int $minutes, ?string $subject = null): int {
    $sql = "SELECT COUNT(*) FROM visitor_reg_attempts WHERE kind = :k AND created_at > now() - (:m || ' minutes')::interval AND ";
    $p = [':k' => $kind, ':m' => (string)$minutes];
    if ($subject !== null) { $sql .= 'subject = :s'; $p[':s'] = $subject; }
    else                   { $sql .= 'ip = :ip';     $p[':ip'] = client_ip(); }
    try { return (int) db_query($sql, $p)->fetchColumn(); } catch (Throwable $e) { return 0; }
}

/** " AND <col> IN (…)" for a venue scope; '' for null (owner). */
function visitor_venue_in(string $col, ?array $venueIds, array &$p, string $pfx = 'vv'): string {
    if ($venueIds === null) return '';
    $ids = $venueIds ?: [-1]; $ph = [];
    foreach (array_values($ids) as $i => $v) { $n = ":{$pfx}{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    return " AND {$col} IN (" . implode(',', $ph) . ')';
}

// ── Places ──────────────────────────────────────────────────────────────────
function visitor_places(bool $activeOnly = true, ?array $venueIds = null): array {
    if (!visitor_reg_supported()) return [];
    $p = [];
    $sql = 'SELECT p.*, v.name AS venue_name FROM visitor_places p JOIN venues v ON v.id = p.venue_id WHERE TRUE'
         . ($activeOnly ? ' AND p.is_active' : '') . visitor_venue_in('p.venue_id', $venueIds, $p)
         . ' ORDER BY p.sort_order, p.name';
    try { return db_query($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}

function visitor_place(int $id): ?array {
    if (!visitor_reg_supported() || $id <= 0) return null;
    $r = db_query('SELECT p.*, v.name AS venue_name FROM visitor_places p JOIN venues v ON v.id = p.venue_id WHERE p.id = :id', [':id' => $id])->fetch();
    return $r ?: null;
}

/** Add ($id null) or edit a place. Returns its id. */
function visitor_place_save(?int $id, string $name, int $venueId, int $sort, bool $active): int {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Give the place a name (up to 80 letters).');
    if (!db_query('SELECT 1 FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn()) throw new InvalidArgumentException('Choose the property whose gate it is behind.');
    $sp = db()->inTransaction();
    if ($sp) db()->exec('SAVEPOINT visitor_place');
    try {
        if ($id) {
            db_query('UPDATE visitor_places SET name = :n, venue_id = :v, sort_order = :s, is_active = :a WHERE id = :id',
                [':n' => $name, ':v' => $venueId, ':s' => $sort, ':a' => $active ? 't' : 'f', ':id' => $id]);
        } else {
            $id = (int) db_query('INSERT INTO visitor_places (name, venue_id, sort_order, is_active) VALUES (:n, :v, :s, :a) RETURNING id',
                [':n' => $name, ':v' => $venueId, ':s' => $sort, ':a' => $active ? 't' : 'f'])->fetchColumn();
        }
        if ($sp) db()->exec('RELEASE SAVEPOINT visitor_place');
        return (int)$id;
    } catch (PDOException $e) {
        if ($sp) db()->exec('ROLLBACK TO SAVEPOINT visitor_place');
        if ($e->getCode() === '23505') throw new InvalidArgumentException('There is already a place called “' . $name . '”.');
        throw $e;
    }
}

// ── Visitors ────────────────────────────────────────────────────────────────
function visitor_find(int $id, bool $withRemoved = false): ?array {
    if (!visitor_reg_supported() || $id <= 0) return null;
    $r = db_query('SELECT * FROM registered_visitors WHERE id = :id' . ($withRemoved ? '' : ' AND removed_at IS NULL'), [':id' => $id])->fetch();
    return $r ?: null;
}

function visitor_find_by_token(string $tok): ?array {
    $id = visitor_pass_verify($tok);
    return $id ? visitor_find($id) : null;
}

/** An adult visitor by email or phone (any format). Signed ones first, newest first. */
function visitor_find_by_contact(string $s): ?array {
    if (!visitor_reg_supported()) return null;
    $s = trim($s);
    if (str_contains($s, '@')) { $where = 'LOWER(email) = :x'; $x = mb_strtolower($s); }
    else { $x = visitor_phone_key($s); if ($x === '') return null; $where = 'phone_key = :x'; }
    $r = db_query("SELECT * FROM registered_visitors WHERE {$where} AND parent_id IS NULL AND removed_at IS NULL
                    ORDER BY (waiver_signed_at IS NULL), id DESC LIMIT 1", [':x' => $x])->fetch();
    return $r ?: null;
}

/** $d = visitor_clean_input() + photo_key. */
function visitor_create(array $d, string $via, ?int $createdBy = null): int {
    return (int) db_query(
        'INSERT INTO registered_visitors (full_name, phone, phone_key, email, id_type, id_number, nationality, id_expiry, id_photo_key, created_via, created_by)
         VALUES (:n, :ph, :pk, :em, :t, :num, :nat, :exp, :photo, :via, :by) RETURNING id',
        [':n' => $d['full_name'], ':ph' => $d['phone'] ?: null, ':pk' => $d['phone_key'] ?: null, ':em' => $d['email'] ?: null,
         ':t' => $d['id_type'], ':num' => $d['id_number'] ?: null, ':nat' => $d['nationality'] ?: null,
         ':exp' => $d['id_expiry'] ?: null, ':photo' => ($d['photo_key'] ?? '') ?: null,
         ':via' => in_array($via, ['self', 'gate', 'invite'], true) ? $via : 'self', ':by' => $createdBy ?: null])->fetchColumn();
}

/**
 * Save new details. A signed visitor whose material identity changed loses the
 * signature (reference + audit rows kept) and must sign again. A new photo
 * (photo_key) replaces the old file. Returns true when a signature was voided.
 */
function visitor_update_identity(int $id, array $d, string $actor): bool {
    $v = visitor_find($id);
    if (!$v) throw new RuntimeException('Visitor not found.');
    $changed = visitor_signed($v) ? visitor_identity_changed($v, $d) : [];
    $newPhoto = (string)($d['photo_key'] ?? '');
    db_query('UPDATE registered_visitors SET full_name = :n, phone = :ph, phone_key = :pk, email = :em, id_type = :t,
                     id_number = :num, nationality = :nat, id_expiry = :exp, id_photo_key = COALESCE(:photo, id_photo_key)
               WHERE id = :id',
        [':n' => $d['full_name'], ':ph' => $d['phone'] ?: null, ':pk' => $d['phone_key'] ?: null, ':em' => $d['email'] ?: null,
         ':t' => $d['id_type'], ':num' => $d['id_number'] ?: null, ':nat' => $d['nationality'] ?: null,
         ':exp' => $d['id_expiry'] ?: null, ':photo' => $newPhoto !== '' ? $newPhoto : null, ':id' => $id]);
    if ($newPhoto !== '' && !empty($v['id_photo_key']) && $v['id_photo_key'] !== $newPhoto) {
        try { storage_delete_private((string)$v['id_photo_key']); } catch (Throwable $e) {}
    }
    if (!$changed) return false;
    db_query('UPDATE registered_visitors SET waiver_signature = NULL, waiver_signed_name = NULL, waiver_signed_at = NULL,
                     waiver_terms_snapshot = NULL, waiver_version = NULL, waiver_name_snapshot = NULL, waiver_id_type_snapshot = NULL,
                     waiver_id_number_snapshot = NULL, waiver_nationality_snapshot = NULL, waiver_id_expiry_snapshot = NULL,
                     signed_ip = NULL, signed_ua = NULL, signed_via = NULL
               WHERE id = :id', [':id' => $id]);
    visitor_log_step('signature_voided', ['reference' => (string)$v['waiver_reference'], 'visitor_id' => $id,
        'waiver_version' => (string)$v['waiver_version'], 'method' => $actor,
        'detail' => 'Signature voided — identity changed after signing: ' . implode(', ', $changed)]);
    return true;
}

/** Sign today's terms: frozen terms + identity snapshot + evidence. Returns the reference. */
function visitor_sign(int $id, string $signedName, string $signature, string $via): string {
    $v = visitor_find($id);
    if (!$v || !empty($v['parent_id'])) throw new RuntimeException('Visitor not found.');
    $signedName = mb_substr(trim($signedName), 0, 120);
    if ($signedName === '' || !checkin_valid_signature($signature)) throw new InvalidArgumentException('A typed name and a drawn signature are needed.');
    $terms = visitor_waiver_text();
    $ver   = waiver_version($terms);
    $ref   = trim((string)($v['waiver_reference'] ?? '')) ?: visitor_make_reference($id);   // the id makes it unique
    db_query('UPDATE registered_visitors SET waiver_signature = :sig, waiver_signed_name = :n, waiver_signed_at = now(),
                     waiver_terms_snapshot = :terms, waiver_version = :ver, waiver_reference = :ref,
                     waiver_name_snapshot = full_name, waiver_id_type_snapshot = id_type, waiver_id_number_snapshot = id_number,
                     waiver_nationality_snapshot = nationality, waiver_id_expiry_snapshot = id_expiry,
                     signed_ip = :ip, signed_ua = :ua, signed_via = :via
               WHERE id = :id AND removed_at IS NULL',
        [':sig' => $signature, ':n' => $signedName, ':terms' => $terms, ':ver' => $ver, ':ref' => $ref,
         ':ip' => client_ip(), ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
         ':via' => $via === 'gate' ? 'gate' : 'self', ':id' => $id]);
    visitor_log_step('signed', ['reference' => $ref, 'visitor_id' => $id, 'waiver_version' => $ver,
        'method' => $via === 'gate' ? 'gate' : 'self', 'detail' => 'Signed as ' . $signedName]);
    return $ref;
}

/**
 * Append one step to the visitor signing chain. One writer at a time (advisory
 * lock), in a SAVEPOINT when inside the caller's transaction, so a logging
 * failure never aborts the signing.
 */
function visitor_log_step(string $step, array $ctx): void {
    if (!visitor_reg_supported()) return;
    $row = [
        'reference'      => (string)($ctx['reference'] ?? ''),
        'visitor_id'     => (int)($ctx['visitor_id'] ?? 0),
        'step'           => $step,
        'waiver_version' => (string)($ctx['waiver_version'] ?? ''),
        'ip'             => client_ip(),
        'user_agent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        'method'         => (string)($ctx['method'] ?? ''),
        'detail'         => (string)($ctx['detail'] ?? ''),
    ];
    $sp = db()->inTransaction();
    try {
        if ($sp) db()->exec('SAVEPOINT visitor_audit'); else db()->beginTransaction();
        db()->query('SELECT pg_advisory_xact_lock(' . VISITOR_AUDIT_LOCK . ')');
        $prev = (string)(db_query('SELECT row_hash FROM visitor_signing_audit ORDER BY id DESC LIMIT 1')->fetchColumn() ?: str_repeat('0', 64));
        db_query('INSERT INTO visitor_signing_audit (reference, visitor_id, step, waiver_version, ip, user_agent, method, detail, prev_hash, row_hash)
                  VALUES (:ref, :v, :step, :ver, :ip, :ua, :m, :d, :ph, :rh)',
            [':ref' => $row['reference'], ':v' => $row['visitor_id'], ':step' => $step, ':ver' => $row['waiver_version'],
             ':ip' => $row['ip'], ':ua' => $row['user_agent'], ':m' => $row['method'], ':d' => $row['detail'],
             ':ph' => $prev, ':rh' => visitor_audit_hash($prev, $row)]);
        if ($sp) db()->exec('RELEASE SAVEPOINT visitor_audit'); else db()->commit();
    } catch (Throwable $e) {
        try { if ($sp) db()->exec('ROLLBACK TO SAVEPOINT visitor_audit'); elseif (db()->inTransaction()) db()->rollBack(); } catch (Throwable $e2) {}
        error_log('[visitor-audit] ' . $e->getMessage());
    }
}

/** Walk the chain; on the unfiltered walk also check each row links to the one before. */
function visitor_audit_verify(?int $visitorId = null): array {
    if (!visitor_reg_supported()) return ['ok' => true, 'checked' => 0, 'bad_id' => null];
    $p = [];
    $sql = 'SELECT * FROM visitor_signing_audit' . ($visitorId !== null ? ' WHERE visitor_id = :v' : '') . ' ORDER BY id';
    if ($visitorId !== null) $p[':v'] = $visitorId;
    $prev = str_repeat('0', 64); $n = 0;
    foreach (db_query($sql, $p)->fetchAll() as $r) {
        if (!hash_equals((string)$r['row_hash'], visitor_audit_hash((string)$r['prev_hash'], $r))) return ['ok' => false, 'checked' => $n, 'bad_id' => (int)$r['id']];
        if ($visitorId === null) {
            if (!hash_equals((string)$r['prev_hash'], $prev)) return ['ok' => false, 'checked' => $n, 'bad_id' => (int)$r['id']];
            $prev = (string)$r['row_hash'];
        }
        $n++;
    }
    return ['ok' => true, 'checked' => $n, 'bad_id' => null];
}

// ── Group ───────────────────────────────────────────────────────────────────
function visitor_add_child(int $parentId, string $name, int $age): int {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 120);
    if ($name === '' || $age < 0 || $age > 17) throw new InvalidArgumentException('Give the child’s name and an age under 18.');
    return (int) db_query("INSERT INTO registered_visitors (full_name, parent_id, age, created_via) VALUES (:n, :p, :a, 'self') RETURNING id",
        [':n' => $name, ':p' => $parentId, ':a' => $age])->fetchColumn();
}

function visitor_invite_adult(int $leadId, string $name): int {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 120);
    if ($name === '') throw new InvalidArgumentException('Give the person’s name.');
    return (int) db_query("INSERT INTO registered_visitors (full_name, invited_by, created_via) VALUES (:n, :l, 'invite') RETURNING id",
        [':n' => $name, ':l' => $leadId])->fetchColumn();
}

/** Take someone out of the lead's group: a child or an unsigned invite is removed; a signed adult just leaves the group. */
function visitor_remove_member(int $leadId, int $memberId): bool {
    $m = visitor_find($memberId);
    if (!$m) return false;
    if ((int)($m['parent_id'] ?? 0) === $leadId || ((int)($m['invited_by'] ?? 0) === $leadId && !visitor_signed($m))) {
        return db_query('UPDATE registered_visitors SET removed_at = now() WHERE id = :id AND removed_at IS NULL', [':id' => $memberId])->rowCount() > 0;
    }
    if ((int)($m['invited_by'] ?? 0) === $leadId) {
        return db_query('UPDATE registered_visitors SET invited_by = NULL WHERE id = :id', [':id' => $memberId])->rowCount() > 0;
    }
    return false;
}

function visitor_group(int $leadId): array {
    $rows = db_query('SELECT * FROM registered_visitors WHERE (invited_by = :l OR parent_id = :l) AND removed_at IS NULL ORDER BY id', [':l' => $leadId])->fetchAll();
    return ['adults'   => array_values(array_filter($rows, fn($r) => empty($r['parent_id']))),
            'children' => array_values(array_filter($rows, fn($r) => !empty($r['parent_id'])))];
}

// ── Visits ──────────────────────────────────────────────────────────────────
/** Today's (or $date's) place and who comes along. Ids outside the lead's group are dropped. */
function visitor_choose_visit(int $visitorId, int $placeId, string $date, array $adultIds, array $childIds, string $via): int {
    $g = visitor_group($visitorId);
    $keep = fn(array $ids, array $rows): array => array_values(array_intersect(array_map('intval', $ids), array_map(fn($r) => (int)$r['id'], $rows)));
    $party = json_encode(['adults' => $keep($adultIds, $g['adults']), 'children' => $keep($childIds, $g['children'])]);
    return (int) db_query(
        'INSERT INTO visitor_visits (visitor_id, place_id, visit_date, party, created_via) VALUES (:v, :p, :d, CAST(:party AS jsonb), :via)
         ON CONFLICT (visitor_id, visit_date) DO UPDATE SET place_id = EXCLUDED.place_id, party = EXCLUDED.party
         RETURNING id',
        [':v' => $visitorId, ':p' => $placeId, ':d' => $date, ':party' => $party, ':via' => $via === 'gate' ? 'gate' : 'self'])->fetchColumn();
}

function visitor_visit_row(?array $r): ?array {
    if (!$r) return null;
    $party = json_decode((string)$r['party'], true) ?: [];
    $r['party'] = ['adults' => array_map('intval', $party['adults'] ?? []), 'children' => array_map('intval', $party['children'] ?? [])];
    return $r;
}

function visitor_visit_on(int $visitorId, string $date): ?array {
    return visitor_visit_row(db_query(
        'SELECT vv.*, p.name AS place_name, p.venue_id, v.name AS venue_name FROM visitor_visits vv
           JOIN visitor_places p ON p.id = vv.place_id JOIN venues v ON v.id = p.venue_id
          WHERE vv.visitor_id = :v AND vv.visit_date = :d', [':v' => $visitorId, ':d' => $date])->fetch() ?: null);
}

function visitor_latest_visit(int $visitorId): ?array {
    return visitor_visit_row(db_query(
        'SELECT vv.*, p.name AS place_name, p.venue_id FROM visitor_visits vv JOIN visitor_places p ON p.id = vv.place_id
          WHERE vv.visitor_id = :v ORDER BY vv.visit_date DESC LIMIT 1', [':v' => $visitorId])->fetch() ?: null);
}

/** The visit's party as rows still in the lead's group. */
function visitor_visit_party(?array $visit, int $leadId): array {
    if (!$visit) return ['adults' => [], 'children' => []];
    $g = visitor_group($leadId);
    $pick = fn(array $rows, array $ids): array => array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $ids, true)));
    return ['adults' => $pick($g['adults'], $visit['party']['adults']), 'children' => $pick($g['children'], $visit['party']['children'])];
}

// ── Bookings (step 1 of /register) ──────────────────────────────────────────
/** Booking code + typed name → where to go. Nothing about the booking is returned on a miss. */
function visitor_booking_lookup(string $code, string $name, string $today): array {
    $h = resolve_booking_by_code_only($code);
    if (!$h || !in_array((string)$h['status'], ['pending', 'confirmed'], true) || (string)$h['check_out'] < $today) return ['kind' => 'notfound', 'url' => ''];
    $id = (int)$h['id'];
    if (visitor_booking_name_match($name, (string)$h['guest_name'])) {
        return ['kind' => 'lead', 'url' => '/booking.php?ref=' . urlencode(make_guest_ref($id)) . (checkin_required($h) ? '&view=checkin' : '')];
    }
    foreach (fetch_checkin_guests($id) as $g) {
        if (!empty($g['is_lead']) || !empty($g['is_child'])) continue;
        if (visitor_booking_name_match($name, (string)($g['passport_name'] ?? ''))) {
            return ['kind' => 'guest', 'url' => '/booking.php?g=' . urlencode(make_guest_pass_token($id, (int)$g['id']))];
        }
    }
    return ['kind' => 'nomatch', 'url' => ''];
}

/** Current bookings under an email, each with its portal link (for the "no code" email). */
function visitor_booking_links_for_email(string $email, string $today): array {
    $ids = db_query("SELECT id FROM holds WHERE LOWER(guest_email) = :e AND status IN ('pending','confirmed') AND check_out >= :d ORDER BY check_in LIMIT 5",
        [':e' => mb_strtolower(trim($email)), ':d' => $today])->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $id) {
        $h = fetch_hold_for_guest((int)$id);
        if ($h) $out[] = ['hold' => $h, 'url' => site_url('/booking.php?ref=' . urlencode(make_guest_ref((int)$id)))];
    }
    return $out;
}

// ── Gate ────────────────────────────────────────────────────────────────────
/** The visitor's open gate sign-in (any day), or null. */
function visitor_on_site(int $visitorId): ?array {
    if (!visitor_reg_supported()) return null;
    $r = db_query('SELECT * FROM visitors WHERE registered_visitor_id = :v AND time_out IS NULL ORDER BY time_in DESC LIMIT 1', [':v' => $visitorId])->fetch();
    return $r ?: null;
}

/** Everything the guard's card needs. null = not a valid pass (unknown id, or a child row). */
function visitor_gate_card(int $visitorId, string $today, ?array $venueIds): ?array {
    $v = visitor_find($visitorId, true);
    if (!$v || !empty($v['parent_id'])) return null;
    $ver   = waiver_version(visitor_waiver_text());
    $visit = visitor_visit_on($visitorId, $today);
    $party = visitor_visit_party($visit, $visitorId);
    $adults = array_map(fn($a) => ['id' => (int)$a['id'], 'name' => (string)$a['full_name'], 'current' => visitor_waiver_current($a, $ver)], $party['adults']);
    $inScope = $visit === null || $venueIds === null || in_array((int)$visit['venue_id'], array_map('intval', $venueIds), true);
    return [
        'visitor'  => $v, 'visit' => $visit, 'adults' => $adults, 'children' => $party['children'],
        'verdict'  => visitor_gate_verdict($v, $visit, $adults, visitor_waiver_current($v, $ver), $inScope),
        'in_scope' => $inScope, 'on_site' => visitor_on_site($visitorId),
        'people'   => visitor_people_count($adults, $party['children']),
    ];
}

/** Let a visitor (and today's party) in: one row in the gate register. */
function visitor_let_in(int $visitorId, string $today, ?int $placeId, int $byAdmin, ?array $venueIds): array {
    $card = visitor_gate_card($visitorId, $today, $venueIds);
    if (!$card) return ['ok' => false, 'msg' => 'This pass isn’t valid.'];
    if ($card['visit'] === null && $card['verdict']['level'] !== 'stop') {
        $place = $placeId ? visitor_place($placeId) : null;
        $okPlace = $place && !empty($place['is_active']) && ($venueIds === null || in_array((int)$place['venue_id'], array_map('intval', $venueIds), true));
        if (!$okPlace) return ['ok' => false, 'msg' => 'Pick where they are going.'];
        visitor_choose_visit($visitorId, (int)$place['id'], $today, [], [], 'gate');
        $card = visitor_gate_card($visitorId, $today, $venueIds);
    }
    if ($card['verdict']['level'] === 'stop') return ['ok' => false, 'msg' => $card['verdict']['msg']];
    if ($card['on_site']) return ['ok' => false, 'msg' => 'Still signed in since ' . date('D j M H:i', strtotime((string)$card['on_site']['time_in'])) . ' — sign them out first.'];
    $v = $card['visitor']; $visit = $card['visit'];
    $sp = db()->inTransaction();
    try {
        if ($sp) db()->exec('SAVEPOINT visitor_in');
        $newId = (int) db_query(
            "INSERT INTO visitors (venue_id, visitor_name, visiting, purpose, logged_by, registered_visitor_id, visit_id, people)
             VALUES (:ven, :n, :place, 'Registered visitor', :by, :rv, :visit, :ppl) RETURNING id",
            [':ven' => (int)$visit['venue_id'], ':n' => (string)$v['full_name'], ':place' => (string)$visit['place_name'],
             ':by' => $byAdmin ?: null, ':rv' => $visitorId, ':visit' => (int)$visit['id'], ':ppl' => $card['people']])->fetchColumn();
        if ($sp) db()->exec('RELEASE SAVEPOINT visitor_in');
    } catch (PDOException $e) {
        if ($sp) db()->exec('ROLLBACK TO SAVEPOINT visitor_in');
        if ($e->getCode() === '23505') return ['ok' => false, 'msg' => 'Already on site.'];
        throw $e;
    }
    if (function_exists('audit_log')) audit_log('visitor.in', 'visitor', $newId, (string)$v['full_name']);
    $n = $card['people'];
    return ['ok' => true, 'msg' => (string)$v['full_name'] . ' let in — ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' to ' . (string)$visit['place_name'] . '.'];
}

// ── Admin registry ──────────────────────────────────────────────────────────
/** Visitors this account may see: owner all; others = visited one of their properties, or registered on their tablet. */
function visitor_scope_sql(?array $venueIds, int $me, array &$p): string {
    if ($venueIds === null) return '';
    $in = visitor_venue_in('pl.venue_id', $venueIds, $p, 'sc');
    $p[':me'] = $me;
    return " AND (rv.created_by = :me OR EXISTS (SELECT 1 FROM visitor_visits vv JOIN visitor_places pl ON pl.id = vv.place_id
                                                  WHERE vv.visitor_id = rv.id{$in}))";
}

function visitor_in_scope(int $id, ?array $venueIds, int $me): bool {
    if (!visitor_reg_supported()) return false;
    $p = [':id' => $id];
    $sql = 'SELECT 1 FROM registered_visitors rv WHERE rv.id = :id' . visitor_scope_sql($venueIds, $me, $p);
    return (bool) db_query($sql, $p)->fetchColumn();
}

function visitor_registry_list(?array $venueIds, int $me, string $q, int $per, int $offset): array {
    if (!visitor_reg_supported()) return ['rows' => [], 'total' => 0];
    $p = [];
    $where = 'rv.parent_id IS NULL AND rv.removed_at IS NULL' . visitor_scope_sql($venueIds, $me, $p);
    if ($q !== '') {
        $where .= ' AND (rv.full_name ILIKE :q OR rv.email ILIKE :q OR rv.phone ILIKE :q OR rv.waiver_reference ILIKE :q)';
        $p[':q'] = '%' . $q . '%';
    }
    $total = (int) db_query("SELECT COUNT(*) FROM registered_visitors rv WHERE {$where}", $p)->fetchColumn();
    $per = max(1, min(200, $per)); $offset = max(0, $offset);
    $rows = db_query("SELECT rv.*, lv.visit_date AS last_visit, lv.place_name AS last_place
                        FROM registered_visitors rv
                        LEFT JOIN LATERAL (SELECT vv.visit_date, pl.name AS place_name FROM visitor_visits vv
                                             JOIN visitor_places pl ON pl.id = vv.place_id
                                            WHERE vv.visitor_id = rv.id ORDER BY vv.visit_date DESC LIMIT 1) lv ON TRUE
                       WHERE {$where} ORDER BY COALESCE(lv.visit_date, rv.created_at::date) DESC, rv.id DESC
                       LIMIT {$per} OFFSET {$offset}", $p)->fetchAll();
    return ['rows' => $rows, 'total' => $total];
}

/** Gate sign-ins of this visitor, at properties the account covers. */
function visitor_visit_history(int $id, ?array $venueIds): array {
    $p = [':id' => $id];
    $sql = 'SELECT vs.time_in, vs.time_out, vs.visiting, vs.people, v.name AS venue_name FROM visitors vs
              JOIN venues v ON v.id = vs.venue_id WHERE vs.registered_visitor_id = :id'
         . visitor_venue_in('vs.venue_id', $venueIds, $p) . ' ORDER BY vs.time_in DESC LIMIT 50';
    return db_query($sql, $p)->fetchAll();
}

/** Remove a visitor: pass stops working, ID photo deleted; signature evidence and gate log kept. */
function visitor_remove(int $id): bool {
    $v = visitor_find($id);
    if (!$v) return false;
    db_query('UPDATE registered_visitors SET removed_at = now(), id_photo_key = NULL WHERE (id = :id OR parent_id = :id) AND removed_at IS NULL', [':id' => $id]);
    if (!empty($v['id_photo_key'])) { try { storage_delete_private((string)$v['id_photo_key']); } catch (Throwable $e) {} }
    if (function_exists('audit_log')) audit_log('visitor.remove', 'visitor', $id, (string)$v['full_name']);
    return true;
}
```

- [ ] **Step 4: Run the tests**

Run: `php tests/visitor_registration_logic.php`
Expected: `ALL PASS` (DB block runs if the local DB has the migration; otherwise `SKIP  DB checks …`). Apply Task 1's migration locally first if a local DB is reachable, so the DB block really runs.

- [ ] **Step 5: Commit**

```bash
git add includes/visitor-store.php tests/visitor_registration_logic.php
git commit -m "feat(visitors): store — sign + audit chain, group, visits, gate let-in, registry"
```

---

### Task 4: ID photo upload

**Files:**
- Create: `api/visitor-upload.php`
- Modify: `js/checkin-passport.js:58-61` and `:264` (endpoint option)

**Interfaces:**
- Consumes: `visitor_reg_supported()`, `visitor_attempt_count()`, `visitor_attempt_log()`, `VISITOR_UPLOAD_MAX` (Task 3); `checkin_passport_read_prompt()`, `checkin_passport_from_ai()`, `CHECKIN_PASSPORT_READS_MAX`; `ai_read_image_json()`, `ai_assistant_supported()`.
- Produces: `POST /api/visitor-upload.php` (multipart: `passport` file, `csrf_token`, optional `gate=1`) → JSON `{ok, read?, fields?, warnings?}`; the stored key is kept in `$_SESSION['vr_photo']` (read by Task 5). `tsCheckinPassport.bind(form, {endpoint: '/api/visitor-upload.php', formData})`.

- [ ] **Step 1: Make the uploader's endpoint configurable**

In `js/checkin-passport.js` change `send`:

```js
  function send(fd, onUp, url) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      x.open('POST', url || ENDPOINT);
```

and the call at line ~264:

```js
      return send(fd, function (frac, sent) {
```
→ keep the callback body unchanged and add the third argument after the callback's closing `}`:

```js
      }, opts.endpoint);
```

Update the header comment's usage line to:
```
 * Usage: tsCheckinPassport.bind(form, { formData: function (wrap, isDeposit) {…},
 *                                        onDone: function (wrap, d, isDeposit) {…},
 *                                        endpoint: '/api/visitor-upload.php' })   // optional, default check-in
```

- [ ] **Step 2: Write `api/visitor-upload.php`**

```php
<?php
/**
 * Visitor ID photo upload (register.php / the guard's tablet). The photo is
 * stored privately BEFORE the visitor row exists, so the key is random and held
 * in the session ($_SESSION['vr_photo']) until api/visitor-register.php attaches
 * it. Then the photo is read like a check-in passport (same prompt, same never-
 * trust rules, same per-session cap). JPEG/PNG only.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/visitor-store.php';
session_init();
header('Content-Type: application/json');

$fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'method');
verify_csrf();
if (!visitor_reg_supported()) $fail(503, 'not available');

$gate = !empty($_POST['gate']) && !empty($_SESSION['admin_id']);
if (!$gate) {
    if (visitor_attempt_count('upload', 60) >= VISITOR_UPLOAD_MAX) $fail(429, 'Too many photos — please wait a little and try again.');
    visitor_attempt_log('upload');
}

$f = $_FILES['passport'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $fail(400, 'no file');
if (($f['size'] ?? 0) > 8 * 1024 * 1024) $fail(400, 'too large');
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!isset($allowed[$mime])) $fail(400, 'Please take a photo (JPG or PNG).');

$bytes = (string) @file_get_contents($f['tmp_name']);   // read now: storage may move the temp file
$key = 'visitors/' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!storage_put_private($f['tmp_name'], $key, $mime)) $fail(500, 'store failed');

// A newer photo in this session replaces the unattached older one.
$old = (string)($_SESSION['vr_photo'] ?? '');
if ($old !== '' && $old !== $key) { try { storage_delete_private($old); } catch (Throwable $e) {} }
$_SESSION['vr_photo'] = $key;

$out = ['ok' => true];
$reads = (int)($_SESSION['vr_reads'] ?? 0);
if ($bytes !== '' && ai_assistant_supported() && $reads < CHECKIN_PASSPORT_READS_MAX) {
    $_SESSION['vr_reads'] = $reads + 1;
    $out['read'] = false;
    try {
        $raw = ai_read_image_json($bytes, $mime, checkin_passport_read_prompt());
        if ($raw !== null) {
            $read = checkin_passport_from_ai($raw, date('Y-m-d'));
            $out['read'] = true; $out['fields'] = $read['fields']; $out['warnings'] = $read['warnings'];
        }
    } catch (Throwable $e) { error_log('[visitor-upload] read failed: ' . $e->getMessage()); }
}
echo json_encode($out);
```

- [ ] **Step 3: Lint both files**

Run: `php -l api/visitor-upload.php && node --check js/checkin-passport.js`
Expected: `No syntax errors detected` and no output from node.

- [ ] **Step 4: Confirm check-in still uses its own endpoint**

Run: `grep -n "endpoint" js/checkin-passport.js && grep -rn "tsCheckinPassport.bind" includes/app js | grep -v endpoint`
Expected: the two check-in call sites have no `endpoint` key (they keep `/api/checkin-upload.php`).

- [ ] **Step 5: Commit**

```bash
git add api/visitor-upload.php
git add -p js/checkin-passport.js   # stage only the endpoint hunks; the file also has uncommitted ID-type changes
git commit -m "feat(visitors): ID photo upload endpoint; uploader endpoint option"
```

---

### Task 5: The POST handler

**Files:**
- Create: `api/visitor-register.php`

**Interfaces:**
- Consumes: Tasks 2–4; `verify_captcha()`; `frontdesk_today_ymd()`; `send_visitor_pass()` + `send_visitor_booking_link()` (Task 8 — guard the calls with `function_exists` so this task works before Task 8 lands).
- Produces: `POST /api/visitor-register.php` with `action` ∈ `lookup · nocode · again · register · update · sign · visit · invite · child · drop`; flash in `$_SESSION['vr_flash'] = ['errors' => [...], 'old' => [...], 'notice' => string]`; `ret` = return path (validated).

- [ ] **Step 1: Write the handler**

```php
<?php
/**
 * The ONE POST handler for /register.php and /visitor-pass.php (post/redirect/get).
 *   lookup  booking code + name → the booking's check-in (or an error)
 *   nocode  email → emails current booking links (neutral answer)
 *   again   phone/email → emails the visitor's pass link (neutral answer)
 *   register details + photo + signature + place (+ children, invited adults)
 *   update  details only (t=) — a material change voids the signature
 *   sign    sign today's terms (t=)
 *   visit   today's place + who comes (t=)
 *   invite / child / drop   the group (t=)
 * Public actions without a pass token: CSRF + honeypot + Turnstile once per
 * session + per-IP limits. Token actions: the unguessable VP token is the key.
 * Tablet mode (gate=1): a signed-in gate account; no Turnstile, no limits.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/visitor-store.php';
session_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /register.php'); exit; }
verify_csrf();

$action = (string)($_POST['action'] ?? '');
$gate   = !empty($_POST['gate']);
if ($gate) { require_login(); require_gate(); }
$today  = frontdesk_today_ymd();
$tok    = trim((string)($_POST['t'] ?? ''));
$me     = $tok !== '' ? visitor_find_by_token($tok) : null;
$ret    = (string)($_POST['ret'] ?? '');
if (!preg_match('#^/(register|visitor-pass)\.php(\?[A-Za-z0-9=&%._~-]*)?$#', $ret)) $ret = '/register.php';
$old    = array_diff_key($_POST, array_flip(['csrf_token', 'waiver_signature', 'cf-turnstile-response', 't']));

$back = function (array $flash = [], ?string $to = null) use ($ret): never {
    if ($flash) $_SESSION['vr_flash'] = $flash;
    header('Location: ' . ($to ?? $ret)); exit;
};
$neutral = fn(string $msg) => $back(['notice' => $msg], '/register.php?step=sent');

if (!visitor_reg_supported()) $back(['errors' => ['general' => 'Registration isn’t available yet. Please ask at the gate.']], '/register.php');
if (!empty($_POST['website'])) $back([], '/register.php');   // honeypot: accept and ignore

$tokenActions = ['update', 'sign', 'visit', 'invite', 'child', 'drop'];
if (in_array($action, $tokenActions, true) && !$me) $back(['errors' => ['general' => 'This link isn’t valid any more. Register again, or ask at the gate.']], '/register.php');

// Turnstile: public actions without a pass token, once per session.
if (!$gate && !$me && !visitor_session_verified()) {
    if (!verify_captcha((string)($_POST['cf-turnstile-response'] ?? ''), client_ip())) {
        $back(['errors' => ['general' => 'Please confirm you’re not a robot, then try again.'], 'old' => $old]);
    }
    visitor_mark_verified();
}

$placeIdsFor = fn(): array => array_map(fn($p) => (int)$p['id'], visitor_places(true, $gate ? admin_venue_ids() : null));
$via = $gate ? 'gate' : 'self';

switch ($action) {
case 'lookup':
    if (visitor_attempt_count('code_fail', 10) >= VISITOR_CODE_FAIL_MAX) {
        $back(['errors' => ['general' => 'Too many tries. Please wait 10 minutes, or register as a visitor.'], 'old' => $old]);
    }
    $res = visitor_booking_lookup((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), $today);
    if ($res['url'] !== '') { header('Location: ' . $res['url']); exit; }
    visitor_attempt_log('code_fail');
    $back(['errors' => ['general' => $res['kind'] === 'nomatch'
        ? 'We couldn’t match your name to that booking. Ask the person who booked to add you, or register as a visitor.'
        : 'We couldn’t find a current booking with that code. Check the code, or register as a visitor.'], 'old' => $old]);

case 'nocode':
case 'again':
    $said = $action === 'nocode'
        ? 'If we found a booking for that email, we’ve sent the link to it. Check your inbox (and spam).'
        : 'If you’re registered with us, we’ve emailed your pass to the address you gave. Check your inbox (and spam).';
    if (visitor_attempt_count('recover', 10) >= VISITOR_RECOVER_MAX) $neutral($said);
    visitor_attempt_log('recover');
    $q = trim((string)($_POST['contact'] ?? ''));
    if ($action === 'nocode') {
        $email = mb_strtolower($q);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && visitor_attempt_count('recover_mail', 10, $email) === 0) {
            $links = visitor_booking_links_for_email($email, $today);
            if ($links && function_exists('send_visitor_booking_link')) {
                visitor_attempt_log('recover_mail', $email);
                send_visitor_booking_link($email, $links);
            }
        }
    } else {
        $v = visitor_find_by_contact($q);
        $to = $v ? mb_strtolower((string)$v['email']) : '';
        if ($v && $to !== '' && visitor_attempt_count('recover_mail', 10, $to) === 0 && function_exists('send_visitor_pass')) {
            visitor_attempt_log('recover_mail', $to);
            send_visitor_pass($v);
        }
    }
    $neutral($said);

case 'register':
case 'update':
    $mode = $action === 'register' ? 'register' : 'edit';
    if ($mode === 'register' && !$gate && !$me && visitor_attempt_count('register', 60) >= VISITOR_REGISTER_MAX) {
        $back(['errors' => ['general' => 'Too many registrations from this connection. Please ask at the gate.'], 'old' => $old]);
    }
    if ($mode === 'register' && $me && visitor_signed($me)) $back([], visitor_pass_url((int)$me['id']));
    $in = visitor_clean_input($_POST);
    $draft = (string)($_SESSION['vr_photo'] ?? '');
    $errors = visitor_validate($in + [
        'place_id' => (int)($_POST['place_id'] ?? 0), 'photo' => $draft !== '' || !empty($me['id_photo_key']),
        'signed_name' => (string)($_POST['waiver_signed_name'] ?? ''), 'signature' => (string)($_POST['waiver_signature'] ?? ''),
        'agree' => !empty($_POST['waiver_agree']),
    ], $mode, $gate, $placeIdsFor());
    if ($errors) $back(['errors' => $errors, 'old' => $old]);
    $d = $in + ['photo_key' => $draft];
    $voided = false;
    db()->beginTransaction();
    try {
        if ($me) { $id = (int)$me['id']; $voided = visitor_update_identity($id, $d, $gate ? 'gate' : 'visitor'); }
        else     { $id = visitor_create($d, $via, $gate ? (int)$_SESSION['admin_id'] : null); }
        if ($mode === 'register') {
            visitor_sign($id, (string)$_POST['waiver_signed_name'], (string)$_POST['waiver_signature'], $via);
            $adultIds = []; $childIds = [];
            if (!$me) {
                foreach ((array)($_POST['child_name'] ?? []) as $i => $cn) {
                    if (trim((string)$cn) === '') continue;
                    $childIds[] = visitor_add_child($id, (string)$cn, (int)(($_POST['child_age'] ?? [])[$i] ?? -1));
                }
                foreach ((array)($_POST['adult_name'] ?? []) as $an) {
                    if (trim((string)$an) !== '') $adultIds[] = visitor_invite_adult($id, (string)$an);
                }
            }
            visitor_choose_visit($id, (int)$_POST['place_id'], $today, $adultIds, $childIds, $via);
        }
        db()->commit();
    } catch (InvalidArgumentException $e) {
        if (db()->inTransaction()) db()->rollBack();
        $back(['errors' => ['general' => $e->getMessage()], 'old' => $old]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[visitor-register] ' . $e->getMessage());
        $back(['errors' => ['general' => 'Something went wrong — nothing was saved. Please try again.'], 'old' => $old]);
    }
    unset($_SESSION['vr_photo']);
    if ($mode === 'register' && !$gate && !$me) visitor_attempt_log('register');
    $v = visitor_find($id);
    if ($mode === 'register' && $v && !empty($v['email']) && function_exists('send_visitor_pass')) send_visitor_pass($v);
    if ($gate) {   // back to the Gate page (its flash slot is hold_flash) with this visitor's card open
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Registered — check the card and let them in.'];
        header('Location: /admin/gate.php?vp=' . rawurlencode(visitor_pass_token($id))); exit;
    }
    $back($voided ? ['notice' => 'Your details changed, so please sign again.'] : ['notice' => $mode === 'register' ? 'You’re registered.' : 'Details saved.'],
          visitor_pass_url($id) . ($mode === 'register' ? '&new=1' : ''));

case 'sign':
    $errors = [];
    if (trim((string)($_POST['waiver_signed_name'] ?? '')) === '') $errors['waiver_signed_name'] = 'Type your full name to sign.';
    if (!checkin_valid_signature((string)($_POST['waiver_signature'] ?? ''))) $errors['signature'] = 'Sign in the box with your finger.';
    if (empty($_POST['waiver_agree'])) $errors['agree'] = 'Tick that you have read and agree to the terms.';
    if ($errors) $back(['errors' => $errors, 'old' => $old]);
    visitor_sign((int)$me['id'], (string)$_POST['waiver_signed_name'], (string)$_POST['waiver_signature'], $via);
    $back(['notice' => 'Thank you — signed.'], visitor_pass_url((int)$me['id']));

case 'visit':
    if (!in_array((int)($_POST['place_id'] ?? 0), $placeIdsFor(), true)) $back(['errors' => ['place_id' => 'Choose where you are going.']]);
    visitor_choose_visit((int)$me['id'], (int)$_POST['place_id'], $today, (array)($_POST['with_adult'] ?? []), (array)($_POST['with_child'] ?? []), $via);
    $back(['notice' => 'Saved — show this pass at the gate.'], visitor_pass_url((int)$me['id']));

case 'invite':
case 'child':
case 'drop':
    if (!visitor_signed($me)) $back([], visitor_pass_url((int)$me['id']));
    try {
        if ($action === 'invite') visitor_invite_adult((int)$me['id'], (string)($_POST['name'] ?? ''));
        elseif ($action === 'child') visitor_add_child((int)$me['id'], (string)($_POST['name'] ?? ''), (int)($_POST['age'] ?? -1));
        else visitor_remove_member((int)$me['id'], (int)($_POST['member_id'] ?? 0));
    } catch (InvalidArgumentException $e) {
        $back(['errors' => ['group' => $e->getMessage()]], visitor_pass_url((int)$me['id']) . '#group');
    }
    $back([], visitor_pass_url((int)$me['id']) . '#group');

default:
    $back([], '/register.php');
}
```

- [ ] **Step 2: Lint**

Run: `php -l api/visitor-register.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/visitor-register.php
git commit -m "feat(visitors): one POST handler — lookup, recovery, register, sign, visit, group"
```

---

### Task 6: The public registration page

**Files:**
- Create: `register.php`, `includes/visitor-form.php`

**Interfaces:**
- Consumes: Tasks 2–5; `checkin_id_switch_html()`, `checkin_id_label_html()`; `captcha_site_key()`; `css/portal-app.css` classes (`pa-app`, `pa-topbar`, `pa-wrap`, `pa-card`, `pa-btn`, `ci-*`).
- Produces: `/register.php` with `?step=start|booking|nocode|again|visitor|sent`, `?place=<id>`, `?t=<VP token>` (invitee / `&edit=1`), `?gate=1` (guard's tablet). Form element ids used by the help guide: `data-help="vr-form"`.

- [ ] **Step 1: Write `includes/visitor-form.php`** (the details form, shared by register / invitee / edit / tablet)

```php
<?php
/**
 * The visitor details form. Expects: $mode ('register'|'edit'), $gate (bool),
 * $me (?array, the visitor when opened with ?t=), $places, $prePlace (int),
 * $errors, $old, $ppAi (bool), $waiverText, $tok (string).
 * Field names follow check-in so js/checkin-passport.js fills them.
 */
$val = function (string $k, string $col = '') use ($old, $me): string {
    if (array_key_exists($k, $old)) return e((string)$old[$k]);
    return $me && $col !== '' ? e((string)($me[$col] ?? '')) : '';
};
$err = fn(string $k): string => isset($errors[$k]) ? '<p class="ci-err" role="alert">' . e($errors[$k]) . '</p>' : '';
$reg = $mode === 'register';
$hasPhoto = !empty($me['id_photo_key']) || !empty($_SESSION['vr_photo']);
$idType = (string)($old['id_type'] ?? ($me['id_type'] ?? 'passport'));
?>
<form id="vrForm" method="post" action="/api/visitor-register.php" data-help="vr-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= $reg ? 'register' : 'update' ?>">
  <input type="hidden" name="ret" value="<?= e($_SERVER['REQUEST_URI'] ?? '/register.php') ?>">
  <?php if ($tok !== ''): ?><input type="hidden" name="t" value="<?= e($tok) ?>"><?php endif; ?>
  <?php if ($gate): ?><input type="hidden" name="gate" value="1"><?php endif; ?>
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

  <?php if ($reg): ?>
  <div class="pa-card ci-wg">
    <h3 class="ci-wg__title">Where are you going?</h3>
    <div class="vr-places" role="radiogroup">
      <?php foreach ($places as $p): $pid = (int)$p['id']; ?>
      <label class="vr-place"><input type="radio" name="place_id" value="<?= $pid ?>" <?= $pid === $prePlace ? 'checked' : '' ?>><span><?= e((string)$p['name']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <?= $err('place_id') ?>
  </div>
  <?php endif; ?>

  <div class="pa-card ci-wg">
    <h3 class="ci-wg__title">Your details</h3>
    <div class="ci-pp" data-pp data-pp-ai="<?= $ppAi ? '1' : '0' ?>">
      <?= checkin_id_switch_html($idType, 'name="id_type"') ?>
      <label class="ci-l">Passport or ID photo</label>
      <p class="ci-pp-hint"><?= $ppAi ? 'Take a picture of your passport photo page or the front of your Kenyan ID and we’ll fill in the details — or type them below.' : 'Take a picture of your passport photo page or the front of your Kenyan ID.' ?></p>
      <div class="ci-upload" data-has="<?= $hasPhoto ? '1' : '0' ?>">
        <label class="ci-filebtn">&#128247; <span class="ci-filebtn__t"><?= $hasPhoto ? 'Retake picture' : 'Take picture' ?></span><input type="file" accept="image/jpeg,image/png" capture="environment"></label>
        <span class="ci-upload__state"><?= $hasPhoto ? 'Uploaded &#10003;' : 'No photo yet' ?></span>
      </div>
      <?= $err('photo') ?>
      <div class="ci-pp-note" role="status" hidden></div>
      <div class="ci-grid2">
        <div class="ci-fld"><label class="ci-l">Full name <span class="ci-opt">(as on the document)</span></label>
          <input class="ci-in" name="passport_name" autocomplete="name" value="<?= $val('passport_name', 'full_name') ?>"><?= $err('passport_name') ?></div>
        <div class="ci-fld"><?= checkin_id_label_html('number', $idType) ?>
          <input class="ci-in" name="passport_number" value="<?= $val('passport_number', 'id_number') ?>"><?= $err('passport_number') ?></div>
        <div class="ci-fld"><label class="ci-l">Nationality</label>
          <input class="ci-in" name="nationality" value="<?= $val('nationality', 'nationality') ?>"><?= $err('nationality') ?></div>
        <div class="ci-fld"><?= checkin_id_label_html('expiry', $idType) ?>
          <input class="ci-in" type="date" name="passport_expiry" value="<?= $val('passport_expiry', 'id_expiry') ?>"></div>
        <div class="ci-fld"><label class="ci-l">Phone</label>
          <input class="ci-in" type="tel" name="phone" autocomplete="tel" value="<?= $val('phone', 'phone') ?>"><?= $err('phone') ?></div>
        <div class="ci-fld"><label class="ci-l">Email <?= $gate ? '<span class="ci-opt">(optional)</span>' : '<span class="ci-opt">(we send your pass here)</span>' ?></label>
          <input class="ci-in" type="email" name="email" autocomplete="email" value="<?= $val('email', 'email') ?>"><?= $err('email') ?></div>
      </div>
    </div>
  </div>

  <?php if ($reg && !$me): ?>
  <div class="pa-card ci-wg">
    <h3 class="ci-wg__title">Who's with you? <span class="ci-opt">(optional)</span></h3>
    <p class="ci-pp-hint">Add children under 18 here. Other adults register themselves: after you finish, you get a link to send each of them.</p>
    <div id="vrKids"></div>
    <div id="vrAdults"></div>
    <button type="button" class="pa-btn pa-btn--ghost" data-vr-add="kid">+ Add a child</button>
    <button type="button" class="pa-btn pa-btn--ghost" data-vr-add="adult">+ Add an adult</button>
    <template id="vrKidTpl"><div class="vr-row"><input class="ci-in" name="child_name[]" placeholder="Child’s full name"><input class="ci-in vr-age" name="child_age[]" type="number" min="0" max="17" placeholder="Age"><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></div></template>
    <template id="vrAdultTpl"><div class="vr-row"><input class="ci-in" name="adult_name[]" placeholder="Adult’s full name"><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></div></template>
  </div>
  <?php endif; ?>

  <?php if ($reg): ?>
  <div class="pa-card ci-wg">
    <label class="ci-l">Terms &amp; conditions</label>
    <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
    <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1"> I have read and agree to the terms</label><?= $err('agree') ?>
    <label class="ci-l">Type your full name to sign</label>
    <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name') ?>" placeholder="Full name"><?= $err('waiver_signed_name') ?>
    <label class="ci-l">Sign below with your finger</label>
    <div class="ci-sign"><button type="button" class="ci-sign-clear">Clear</button><canvas class="ci-sign-pad" data-target="#vrSig"></canvas></div>
    <input type="hidden" name="waiver_signature" id="vrSig"><?= $err('signature') ?>
  </div>
  <?php endif; ?>

  <?php if (!$gate && !$me && captcha_site_key() && !visitor_session_verified()): ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:12px 0"></div>
  <?php endif; ?>
  <?= $err('general') ?>
  <div class="ci-err" data-vr-err role="alert" hidden></div>
  <button type="submit" class="pa-btn pa-btn--primary" style="margin-top:12px"><?= $reg ? 'Register' : 'Save my details' ?></button>
</form>
<script>
(function () {
  var form = document.getElementById('vrForm'); if (!form) return;
  var csrf = form.querySelector('input[name=csrf_token]').value;
  if (window.tsCheckinPassport) window.tsCheckinPassport.bind(form, {
    endpoint: '/api/visitor-upload.php',
    formData: function () { var fd = new FormData(); fd.append('csrf_token', csrf); <?= $gate ? "fd.append('gate', '1');" : '' ?> return fd; }
  });
  form.addEventListener('click', function (e) {
    var add = e.target.closest('[data-vr-add]');
    if (add) {
      var kid = add.getAttribute('data-vr-add') === 'kid';
      var tpl = document.getElementById(kid ? 'vrKidTpl' : 'vrAdultTpl');
      var box = document.getElementById(kid ? 'vrKids' : 'vrAdults');
      box.appendChild(tpl.content.cloneNode(true));
      var inp = box.lastElementChild.querySelector('input'); if (inp) inp.focus();
      return;
    }
    if (e.target.classList.contains('ci-kid__x') && e.target.closest('.vr-row')) { e.target.closest('.vr-row').remove(); }
  });
  form.addEventListener('submit', function (e) {
    var err = form.querySelector('[data-vr-err]');
    if (window.tsCheckinPassport && window.tsCheckinPassport.busy(form)) {
      e.preventDefault(); err.textContent = 'Please wait a moment — your photo is still uploading.'; err.hidden = false; return;
    }
    var agree = form.querySelector('.ci-agree'); if (!agree) return;
    if (!agree.checked && window.ciTermsDialog) {
      e.preventDefault();
      window.ciTermsDialog(form.querySelector('.ci-waiver'), function () { agree.checked = true; form.requestSubmit ? form.requestSubmit() : form.submit(); });
      return;
    }
    var missing = [];
    if (!form.querySelector('[name=place_id]:checked')) missing.push('choose where you are going');
    if (form.querySelector('[name=waiver_signed_name]').value.trim() === '') missing.push('type your full name');
    if (document.getElementById('vrSig').value === '') missing.push('draw your signature');
    if (missing.length) { e.preventDefault(); err.textContent = 'Before you register, please ' + missing.join(', ') + '.'; err.hidden = false; err.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  });
})();
</script>
```

- [ ] **Step 2: Write `register.php`**

```php
<?php
/**
 * General registration link (spec 2026-10-14). Everyone who enters a Tribal Sand
 * property is registered: a guest with a booking goes to their own check-in; a
 * visitor registers here once (details, ID photo, signed waiver, where they are
 * going) and gets a reusable pass. ?gate=1 = the same form on the guard's
 * tablet (signed-in gate account). Posts to api/visitor-register.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/visitor-store.php';
session_init();

$gate = !empty($_GET['gate']);
if ($gate) { require_login(); require_gate(); }
$tok  = trim((string)($_GET['t'] ?? ''));
$me   = $tok !== '' ? visitor_find_by_token($tok) : null;
if ($tok !== '' && !$me) $tok = '';
$edit = $me && !empty($_GET['edit']);
if ($me && visitor_signed($me) && !$edit) { header('Location: ' . visitor_pass_url((int)$me['id'])); exit; }

$steps = ['start', 'booking', 'nocode', 'again', 'visitor', 'sent'];
$step  = ($me || $gate) ? 'visitor' : (in_array($_GET['step'] ?? '', $steps, true) ? (string)$_GET['step'] : 'start');

$flash  = $_SESSION['vr_flash'] ?? []; unset($_SESSION['vr_flash']);
$errors = $flash['errors'] ?? []; $old = $flash['old'] ?? []; $notice = (string)($flash['notice'] ?? '');

$places   = visitor_places(true, $gate ? admin_venue_ids() : null);
$prePlace = (int)($old['place_id'] ?? ($_GET['place'] ?? 0));
if (!$prePlace && $me && !empty($me['invited_by']) && ($lv = visitor_latest_visit((int)$me['invited_by']))) $prePlace = (int)$lv['place_id'];
$mode = $edit ? 'edit' : 'register';
$ppAi = ai_assistant_supported();
$waiverText = visitor_waiver_text();
$supported = visitor_reg_supported();
$turnstile = !$gate && captcha_site_key() && !visitor_session_verified();

$page_title = 'Register to visit · Tribal Sand';
$page_desc  = 'Register before you enter a Tribal Sand property — it takes two minutes.';
$page_url   = site_url('/register.php');
$noindex = true; $portal_chrome = true; $hide_floating_chat = true;
include __DIR__ . '/includes/head.php';
$ver = fn(string $f) => @filemtime(__DIR__ . '/' . $f) ?: time();
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= $ver('css/portal-app.css') ?>">
<script src="/js/signature-pad.js?v=<?= $ver('js/signature-pad.js') ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= $ver('js/checkin-terms.js') ?>" defer></script>
<script src="/js/checkin-passport.js?v=<?= $ver('js/checkin-passport.js') ?>"></script>
<style>
  .vr-choices{display:grid;gap:12px}
  .vr-choice{display:block;padding:18px;border:1px solid #e4e1da;border-radius:14px;background:#fff;color:inherit;text-decoration:none}
  .vr-choice b{display:block;font-size:17px;margin-bottom:4px}
  .vr-places{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
  .vr-place{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #e4e1da;border-radius:12px;cursor:pointer;min-height:48px}
  .vr-place:has(input:checked){border-color:#1E5C6B;background:#eef5f6}
  .vr-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}.vr-row .ci-in{flex:1;min-width:0}.vr-row .vr-age{flex:0 0 80px}
  .vr-note{background:#eef5f6;border-radius:12px;padding:12px 14px;margin-bottom:12px}
</style>
<div class="pa-app">
  <div class="pa-topbar"><div class="pa-topbar__inner"><div class="pa-topbar__brand"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title"><?= $gate ? 'Register a visitor' : 'Register to visit' ?></div></div></div></div>
  <div class="pa-wrap" style="padding-top:16px;max-width:720px">
  <?php if (!$supported): ?>
    <div class="pa-card" style="padding:18px">Registration isn’t available yet. Please ask at the gate.</div>
  <?php elseif ($step === 'start'): ?>
    <h1 class="ci-hero__title">Welcome</h1>
    <p class="ci-hero__sub">Everyone who enters a Tribal Sand property registers first. It takes two minutes.</p>
    <div class="vr-choices">
      <a class="vr-choice" href="/register.php?step=booking"><b>I have a booking</b>Go to your booking’s check-in.</a>
      <a class="vr-choice" href="/register.php?step=visitor<?= !empty($_GET['place']) ? '&amp;place=' . (int)$_GET['place'] : '' ?>"><b>I’m visiting (no booking)</b>Coming for a drink, a meal, to see a guest, to work…</a>
      <a class="vr-choice" href="/register.php?step=again"><b>I’ve registered before</b>We’ll email you your pass.</a>
    </div>
  <?php elseif ($step === 'booking' || $step === 'nocode' || $step === 'again'): ?>
    <a href="/register.php" class="pa-note">&larr; Back</a>
    <form method="post" action="/api/visitor-register.php" class="pa-card" style="padding:18px;margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="ret" value="/register.php?step=<?= e($step) ?>">
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <?php if ($step === 'booking'): ?>
        <input type="hidden" name="action" value="lookup">
        <h2 class="ci-wg__title">Find your booking</h2>
        <label class="ci-l">Booking code <span class="ci-opt">(in your booking email)</span></label>
        <input class="ci-in" name="code" autocapitalize="characters" value="<?= e((string)($old['code'] ?? '')) ?>" required>
        <label class="ci-l">Your full name</label>
        <input class="ci-in" name="name" autocomplete="name" value="<?= e((string)($old['name'] ?? '')) ?>" required>
        <p class="pa-note" style="margin-top:8px"><a href="/register.php?step=nocode">I don’t have my code</a></p>
      <?php elseif ($step === 'nocode'): ?>
        <input type="hidden" name="action" value="nocode">
        <h2 class="ci-wg__title">Get your booking link</h2>
        <label class="ci-l">The email the booking was made with</label>
        <input class="ci-in" type="email" name="contact" autocomplete="email" required>
      <?php else: ?>
        <input type="hidden" name="action" value="again">
        <h2 class="ci-wg__title">Get your pass again</h2>
        <label class="ci-l">The phone number or email you registered with</label>
        <input class="ci-in" name="contact" required>
      <?php endif; ?>
      <?php if ($turnstile): ?><div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:12px 0"></div><?php endif; ?>
      <?php if (!empty($errors['general'])): ?><p class="ci-err" role="alert"><?= e($errors['general']) ?></p><?php endif; ?>
      <button class="pa-btn pa-btn--primary" style="margin-top:12px"><?= $step === 'booking' ? 'Continue' : 'Email me the link' ?></button>
    </form>
  <?php elseif ($step === 'sent'): ?>
    <div class="pa-card" style="padding:18px"><p><?= e($notice !== '' ? $notice : 'Done.') ?></p><a class="pa-btn" href="/register.php">Back</a></div>
  <?php else: ?>
    <?php if (!$gate && !$me): ?><a href="/register.php" class="pa-note">&larr; Back</a><?php endif; ?>
    <h1 class="ci-hero__title"><?= $edit ? 'Your details' : ($me ? 'Welcome, ' . e(explode(' ', (string)$me['full_name'])[0]) : 'Register to visit') ?></h1>
    <?php if ($me && !$edit && !empty($me['invited_by'])): ?><p class="ci-hero__sub">You’ve been added to a group. Add your details and sign, and you’re ready to go.</p><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="vr-note"><?= e($notice) ?></div><?php endif; ?>
    <?php if (!$places && $mode === 'register'): ?>
      <div class="pa-card" style="padding:18px">No places are open for registration right now. Please ask at the gate.</div>
    <?php else: include __DIR__ . '/includes/visitor-form.php'; endif; ?>
  <?php endif; ?>
    <p class="ci-help">Questions? <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

- [ ] **Step 3: Lint**

Run: `php -l register.php && php -l includes/visitor-form.php`
Expected: both `No syntax errors detected`.

- [ ] **Step 4: Verify in the browser**

Start the dev server: `preview_start` `{name: "tribal-sand"}`. Open `http://localhost:8765/register.php`:
- the three choices show; "I’m visiting" opens the form with the place list;
- at 375 px wide: no sideways scroll, place tiles wrap;
- `read_console_messages` with `onlyErrors: true`: no errors.
If the local DB lacks the migration, the page says "Registration isn’t available yet" — that is correct pre-migration behaviour; apply the migration locally to see the form.

- [ ] **Step 5: Commit**

```bash
git add register.php includes/visitor-form.php
git commit -m "feat(visitors): /register — booking lookup, recovery, registration form, tablet mode"
```

---

### Task 7: The visitor's pass page

**Files:**
- Create: `visitor-pass.php`

**Interfaces:**
- Consumes: Tasks 2–5; `js/vendor/qrcode.js` (`qrcode(0,'M')`, `createSvgTag`).
- Produces: `/visitor-pass.php?t=<VP token>[&new=1]`; anchors `#group`.

- [ ] **Step 1: Write `visitor-pass.php`**

```php
<?php
/**
 * A visitor's own pass (spec 2026-10-14 §3.3). Sign again when the terms changed
 * or a detail edit voided the signature; choose today's place and who's coming;
 * the QR the guard scans (it links to the Gate page and grants nothing by
 * itself); the group with each adult's invite link.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/frontdesk.php';
require_once __DIR__ . '/includes/visitor-store.php';
session_init();

$tok = trim((string)($_GET['t'] ?? ''));
$me  = $tok !== '' ? visitor_find_by_token($tok) : null;
if (!$me || !empty($me['parent_id'])) { header('Location: /register.php?step=again'); exit; }
if (!visitor_signed($me) && empty($me['waiver_reference'])) { header('Location: ' . visitor_invite_url((int)$me['id'])); exit; }   // never signed: finish registering

$id     = (int)$me['id'];
$today  = frontdesk_today_ymd();
$ver    = waiver_version(visitor_waiver_text());
$current = visitor_waiver_current($me, $ver);
$visit  = visitor_visit_on($id, $today);
$group  = visitor_group($id);
$places = visitor_places(true);
$flash  = $_SESSION['vr_flash'] ?? []; unset($_SESSION['vr_flash']);
$errors = $flash['errors'] ?? []; $notice = (string)($flash['notice'] ?? '');
$first  = explode(' ', (string)$me['full_name'])[0];
$adultCurrent = fn(array $a): bool => visitor_waiver_current($a, $ver);

$page_title = 'Your visitor pass · Tribal Sand';
$page_url = site_url('/visitor-pass.php'); $noindex = true; $portal_chrome = true; $hide_floating_chat = true;
include __DIR__ . '/includes/head.php';
$v = fn(string $f) => @filemtime(__DIR__ . '/' . $f) ?: time();
$err = fn(string $k): string => isset($errors[$k]) ? '<p class="ci-err" role="alert">' . e($errors[$k]) . '</p>' : '';
$hidden = csrf_field() . '<input type="hidden" name="t" value="' . e($tok) . '"><input type="hidden" name="ret" value="/visitor-pass.php?t=' . e(rawurlencode($tok)) . '">';
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= $v('css/portal-app.css') ?>">
<script src="/js/signature-pad.js?v=<?= $v('js/signature-pad.js') ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= $v('js/checkin-terms.js') ?>" defer></script>
<style>
  .vr-places{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
  .vr-place{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #e4e1da;border-radius:12px;min-height:48px}
  .vr-place:has(input:checked){border-color:#1E5C6B;background:#eef5f6}
  .vr-member{display:flex;justify-content:space-between;gap:8px;align-items:center;padding:10px 0;border-top:1px solid #eee;flex-wrap:wrap}
  .vr-note{background:#eef5f6;border-radius:12px;padding:12px 14px;margin-bottom:12px}
</style>
<div class="pa-app">
  <div class="pa-topbar"><div class="pa-topbar__inner"><div class="pa-topbar__brand"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title">Visitor pass</div></div></div></div>
  <div class="pa-wrap" style="padding-top:16px;max-width:620px">
    <?php if ($notice !== ''): ?><div class="vr-note"><?= e($notice) ?></div><?php endif; ?>

    <?php if (!$current): ?>
    <form method="post" action="/api/visitor-register.php" class="pa-card ci-wg" id="vrSign">
      <?= $hidden ?><input type="hidden" name="action" value="sign">
      <h2 class="ci-wg__title"><?= visitor_signed($me) ? 'Our terms have changed' : 'Please sign again' ?></h2>
      <p class="pa-note">Read the terms and sign before your next visit.</p>
      <div class="ci-waiver"><?= nl2br(e(visitor_waiver_text())) ?></div>
      <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1"> I have read and agree to the terms</label><?= $err('agree') ?>
      <label class="ci-l">Type your full name to sign</label>
      <input class="ci-in" name="waiver_signed_name" value="<?= e((string)$me['full_name']) ?>"><?= $err('waiver_signed_name') ?>
      <label class="ci-l">Sign below with your finger</label>
      <div class="ci-sign"><button type="button" class="ci-sign-clear">Clear</button><canvas class="ci-sign-pad" data-target="#vrSig2"></canvas></div>
      <input type="hidden" name="waiver_signature" id="vrSig2"><?= $err('signature') ?>
      <button class="pa-btn pa-btn--primary" style="margin-top:12px">Sign</button>
    </form>
    <?php else: ?>

    <?php if (!$visit || !empty($_GET['change'])): ?>
    <form method="post" action="/api/visitor-register.php" class="pa-card ci-wg">
      <?= $hidden ?><input type="hidden" name="action" value="visit">
      <h2 class="ci-wg__title">Where are you going today?</h2>
      <div class="vr-places">
        <?php foreach ($places as $p): ?>
        <label class="vr-place"><input type="radio" name="place_id" value="<?= (int)$p['id'] ?>" <?= $visit && (int)$visit['place_id'] === (int)$p['id'] ? 'checked' : '' ?> required><span><?= e((string)$p['name']) ?></span></label>
        <?php endforeach; ?>
      </div><?= $err('place_id') ?>
      <?php if ($group['adults'] || $group['children']): ?>
      <p class="ci-l" style="margin-top:14px">Who’s coming with you?</p>
      <?php foreach ($group['adults'] as $a): ?><label class="ci-radio"><input type="checkbox" name="with_adult[]" value="<?= (int)$a['id'] ?>" checked> <?= e((string)$a['full_name']) ?></label><?php endforeach; ?>
      <?php foreach ($group['children'] as $c): ?><label class="ci-radio"><input type="checkbox" name="with_child[]" value="<?= (int)$c['id'] ?>" checked> <?= e((string)$c['full_name']) ?> (<?= (int)$c['age'] ?>)</label><?php endforeach; ?>
      <?php endif; ?>
      <button class="pa-btn pa-btn--primary" style="margin-top:12px">Save</button>
    </form>
    <?php else: $party = visitor_visit_party($visit, $id); $waiting = array_filter($party['adults'], fn($a) => !$adultCurrent($a)); ?>
    <section class="pa-pass">
      <div class="pa-pass__eyebrow">Visitor pass · <?= e(date('D j M', strtotime($today))) ?></div>
      <div class="pa-pass__qr" data-gp-qr="<?= e(visitor_gate_url($id)) ?>" role="img" aria-label="Visitor pass QR code"></div>
      <h2 class="pa-pass__name"><?= e((string)$me['full_name']) ?></h2>
      <dl class="pa-pass__facts"><div><dt>Going to</dt><dd><?= e((string)$visit['place_name']) ?> · <a href="?t=<?= e(rawurlencode($tok)) ?>&amp;change=1">Change</a></dd></div></dl>
      <?php if ($party['adults'] || $party['children']): ?>
      <ul class="pa-pass__names">
        <?php foreach ($party['adults'] as $a): ?><li><?= e((string)$a['full_name']) ?> <?= $adultCurrent($a) ? '✓' : '— waiting to sign' ?></li><?php endforeach; ?>
        <?php foreach ($party['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child)</li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p class="pa-pass__hint"><?= $waiting ? 'Your group can enter once everyone above has signed.' : 'Show this screen to security at the gate. Turn your screen brightness up if the scan doesn’t work.' ?></p>
    </section>
    <?php endif; ?>

    <div class="pa-card ci-wg" id="group">
      <h2 class="ci-wg__title">Your group</h2>
      <?= $err('group') ?>
      <?php foreach ($group['adults'] as $a): $link = visitor_invite_url((int)$a['id']); $txt = visitor_invite_share_text((string)$a['full_name'], (string)($visit['place_name'] ?? ''), $link); ?>
      <div class="vr-member">
        <span><?= e((string)$a['full_name']) ?> · <?= $adultCurrent($a) ? 'Signed ✓' : 'Waiting to sign' ?></span>
        <?php if (!$adultCurrent($a)): ?>
        <span><a class="pa-btn pa-btn--ghost" href="https://wa.me/?text=<?= e(rawurlencode($txt)) ?>" target="_blank" rel="noopener">Send on WhatsApp</a>
          <button type="button" class="pa-btn pa-btn--ghost" data-copy="<?= e($link) ?>">Copy link</button></span>
        <?php endif; ?>
        <form method="post" action="/api/visitor-register.php" style="display:inline"><?= $hidden ?><input type="hidden" name="action" value="drop"><input type="hidden" name="member_id" value="<?= (int)$a['id'] ?>"><button class="ci-kid__x" aria-label="Remove <?= e((string)$a['full_name']) ?>">&times;</button></form>
      </div>
      <?php endforeach; ?>
      <?php foreach ($group['children'] as $c): ?>
      <div class="vr-member"><span><?= e((string)$c['full_name']) ?> (<?= (int)$c['age'] ?>)</span>
        <form method="post" action="/api/visitor-register.php" style="display:inline"><?= $hidden ?><input type="hidden" name="action" value="drop"><input type="hidden" name="member_id" value="<?= (int)$c['id'] ?>"><button class="ci-kid__x" aria-label="Remove <?= e((string)$c['full_name']) ?>">&times;</button></form></div>
      <?php endforeach; ?>
      <form method="post" action="/api/visitor-register.php" class="vr-member"><?= $hidden ?><input type="hidden" name="action" value="invite">
        <input class="ci-in" name="name" placeholder="Another adult’s full name" style="flex:1;min-width:0" required><button class="pa-btn pa-btn--ghost">+ Add adult</button></form>
      <form method="post" action="/api/visitor-register.php" class="vr-member"><?= $hidden ?><input type="hidden" name="action" value="child">
        <input class="ci-in" name="name" placeholder="Child’s full name" style="flex:1;min-width:0" required><input class="ci-in" type="number" name="age" min="0" max="17" placeholder="Age" style="width:80px" required><button class="pa-btn pa-btn--ghost">+ Add child</button></form>
    </div>
    <?php endif; ?>

    <p class="pa-note" style="margin-top:12px">
      <a href="<?= e(visitor_invite_url($id)) ?>&amp;edit=1">Update my details</a>
      <?php if (visitor_signed($me)): ?> · <a href="/record.php?vp=<?= e(rawurlencode($tok)) ?>" target="_blank">My signed waiver</a><?php endif; ?>
    </p>
  </div>
</div>
<script src="/js/vendor/qrcode.js?v=<?= $v('js/vendor/qrcode.js') ?>"></script>
<script>
(function () {
  document.querySelectorAll('[data-gp-qr]').forEach(function (el) {
    if (el.firstChild || !window.qrcode) return;
    var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-gp-qr')); qr.make();
    el.innerHTML = qr.createSvgTag({ scalable: true, margin: 8 });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b || !navigator.clipboard) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.textContent = 'Copied ✓'; });
  });
  var sign = document.getElementById('vrSign');
  if (sign) sign.addEventListener('submit', function (e) {
    var agree = sign.querySelector('.ci-agree');
    if (!agree.checked && window.ciTermsDialog) { e.preventDefault(); window.ciTermsDialog(sign.querySelector('.ci-waiver'), function () { agree.checked = true; sign.submit(); }); }
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Note on the redirect at the top: an invited adult who never signed has no `waiver_reference` → sent to `/register.php?t=…` to finish; a visitor whose signature was voided keeps their reference → stays here and sees "Please sign again".

- [ ] **Step 2: Lint**

Run: `php -l visitor-pass.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify end to end in the browser** (local DB with the migration)

1. `/register.php?step=visitor`: choose a place, fill details (type them if no AI key; upload any small JPEG), add one child and one adult, tick the terms, type the name, draw a signature, press **Register**.
2. Expected: lands on `/visitor-pass.php?t=VP-…&new=1` with the QR, the place, the child, and the adult as "waiting to sign" with **Send on WhatsApp** / **Copy link**.
3. Open the copied link in a private window: the form opens with the adult's name, the place pre-chosen; complete and register → their own pass.
4. Back on the lead's pass (reload): the adult now shows ✓ and the hint says to show the screen at the gate.
5. Resize to 375 px: no sideways scroll. `read_console_messages` (`onlyErrors`): none.

- [ ] **Step 4: Commit**

```bash
git add visitor-pass.php
git commit -m "feat(visitors): visitor pass — sign again, today's place, QR, group invites"
```

---

### Task 8: Emails

**Files:**
- Modify: `includes/mail.php` (append two senders after `send_staff_request_waiting()`)
- Modify: `includes/email-templates.php` (two registry entries before `'password_reset' => [`)
- Test: `tests/email_log_logic.php` (existing)

**Interfaces:**
- Consumes: `visitor_pass_url()`, `visitor_pass_token()`; `email_field()`, `_email_*` helpers, `mail_send()`, `email_guest_reply_to()`, `_mail_site()`.
- Produces: `send_visitor_pass(array $v, array $ctx = []): bool` · `send_visitor_booking_link(string $to, array $links, array $ctx = []): bool`; registry keys `visitor_pass`, `visitor_booking_link` (topic `Visitors`).

- [ ] **Step 1: Add the senders to `includes/mail.php`**

```php
/** A registered visitor's pass link (after registering, or "I've registered before"). */
function send_visitor_pass(array $v, array $ctx = []): bool {
    $to = (string)($v['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    require_once __DIR__ . '/visitor-registration.php';
    $key  = 'visitor_pass';
    $url  = visitor_pass_url((int)$v['id']);
    if ($url === '') return false;
    $vars = ['name' => explode(' ', trim((string)$v['full_name']))[0]];
    $intro  = email_field($key, 'intro', $vars);
    $footer = email_field($key, 'footer_note', $vars);
    $text = _email_plain($intro) . "\n\nYour pass: {$url}\n\n" . _email_plain($footer) . "\n\nTribal Sand";
    $inner = _email_lead_rich($intro) . _email_button('Open my pass', $url) . _email_note($footer);
    $r = mail_send($key, ['to' => $to, 'subject' => email_field($key, 'subject', $vars), 'text' => $text,
                          'html' => _email_shell(email_field($key, 'heading', $vars), $inner, _mail_site()), 'reply_to' => email_guest_reply_to()], $ctx);
    return $r['ok'];
}

/** "I have a booking but not the code": the current bookings under that email, each with its link. */
function send_visitor_booking_link(string $to, array $links, array $ctx = []): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !$links) return false;
    $key = 'visitor_booking_link';
    $vars = ['count' => (string)count($links)];
    $intro = email_field($key, 'intro', $vars);
    $rows = []; $lines = [];
    foreach ($links as $l) {
        $h = $l['hold'];
        $label = trim(((string)($h['venue_name'] ?? '')) . ' · ' . date('j M', strtotime((string)$h['check_in'])) . ' – ' . date('j M Y', strtotime((string)$h['check_out'])), ' ·');
        $rows[] = [$label, $l['url']];   // _email_detail_block() escapes values — a plain link
        $lines[] = $label . ': ' . $l['url'];
    }
    $text = _email_plain($intro) . "\n\n" . implode("\n", $lines) . "\n\nTribal Sand";
    $inner = _email_lead_rich($intro) . _email_detail_block($rows, 'Your bookings');
    $r = mail_send($key, ['to' => $to, 'subject' => email_field($key, 'subject', $vars), 'text' => $text,
                          'html' => _email_shell(email_field($key, 'heading', $vars), $inner, _mail_site()), 'reply_to' => email_guest_reply_to()], $ctx);
    return $r['ok'];
}
```

- [ ] **Step 2: Add the registry entries in `includes/email-templates.php`** (just before `'password_reset' => [`)

```php
        'visitor_pass' => [
            'name' => 'Visitor pass', 'audience' => 'guest', 'topic' => 'Visitors',
            'trigger' => 'A visitor registers on the general registration link (or the gate tablet with an email), or asks for their pass again.',
            'sources' => ['api/visitor-register.php'], 'recipients' => 'The visitor.',
            'default_on' => true,
            'fields' => [
                'subject' => 'Your Tribal Sand visitor pass',
                'heading' => 'You’re registered',
                'intro'   => 'Hi {{name}}, thank you for registering. Keep this email: open your pass whenever you visit, choose where you are going, and show it at the gate.',
                'footer_note' => 'If our terms change, your pass will ask you to sign again before your next visit.',
            ],
            'placeholders' => ['name' => 'The visitor’s first name'],
            'sample' => fn() => send_visitor_pass(['id' => 1, 'full_name' => 'Anna Smith', 'email' => 'you@example.com']),
        ],
        'visitor_booking_link' => [
            'name' => 'Booking link (no code)', 'audience' => 'guest', 'topic' => 'Visitors',
            'trigger' => 'Someone on the registration page says they have a booking but not the code, and types the booking email.',
            'sources' => ['api/visitor-register.php'], 'recipients' => 'The email typed, only when a current booking uses it.',
            'default_on' => true,
            'fields' => [
                'subject' => 'Your Tribal Sand booking link',
                'heading' => 'Your booking',
                'intro'   => 'Here is the link to your booking. Open it to finish your check-in before you arrive.',
            ],
            'placeholders' => ['count' => 'How many bookings are listed'],
            'sample' => fn() => send_visitor_booking_link('you@example.com', [['hold' => ['venue_name' => 'Maya Kobe', 'check_in' => '2026-11-02', 'check_out' => '2026-11-06'], 'url' => 'https://tribalsand.com/booking.php?ref=TS-1-sample']]),
        ],
```

- [ ] **Step 3: Run the email test**

Run: `php tests/email_log_logic.php`
Expected: `ALL PASS` (the registry has both keys; both senders call `mail_send()`).

- [ ] **Step 4: Commit**

```bash
git add includes/mail.php includes/email-templates.php
git commit -m "feat(visitors): visitor pass + booking link emails"
```
(`includes/mail.php` has uncommitted ID-type changes: stage with `git add -p includes/mail.php`, only the two new functions.)

---

### Task 9: Signed record

**Files:**
- Create: `includes/visitor-record.php`
- Modify: `record.php` (dispatch at the top, before the check-in logic)

**Interfaces:**
- Consumes: `visitor_find_by_token()`, `visitor_signed()`, `visitor_find()`, `visitor_log_step()`; `can_view_guest_docs()` is NOT used — admin viewers go through `require_gate()` + `visitor_in_scope()`.
- Produces: `/record.php?vp=<VP token>` (the visitor) and `/record.php?vid=<id>` (signed-in admin in scope).

- [ ] **Step 1: Add the dispatch to `record.php`** — directly after the four `require_once` lines:

```php
// A visitor's signed record (general registration link): same clean URL, own body.
if (isset($_GET['vp']) || isset($_GET['vid'])) { require __DIR__ . '/includes/visitor-record.php'; exit; }
```

- [ ] **Step 2: Write `includes/visitor-record.php`**

```php
<?php
/**
 * A visitor's signed waiver record, served from /record.php (never /admin/).
 * Auth: the visitor's own pass token (?vp=), else a signed-in gate account
 * that covers them (?vid=). Renders the FROZEN snapshots only.
 */
declare(strict_types=1);
require_once __DIR__ . '/visitor-store.php';

$vp = trim((string)($_GET['vp'] ?? ''));
$viewer = 'visitor';
if ($vp !== '') {
    $v = visitor_find_by_token($vp);
} else {
    require_login(); require_gate();
    $vid = (int)($_GET['vid'] ?? 0);
    if (!visitor_in_scope($vid, admin_venue_ids(), (int)($_SESSION['admin_id'] ?? 0))) { http_response_code(403); exit('Forbidden'); }
    $v = visitor_find($vid, true);
    $viewer = 'admin';
}
if (!$v || !visitor_signed($v)) { http_response_code(404); exit('Record not found.'); }

visitor_log_step('record_viewed', ['reference' => (string)$v['waiver_reference'], 'visitor_id' => (int)$v['id'],
    'waiver_version' => (string)$v['waiver_version'], 'method' => $viewer, 'detail' => 'Record viewed']);

$num  = trim((string)($v['waiver_id_number_snapshot'] ?? ''));
$mask = $num === '' ? '—' : (strlen($num) <= 2 ? str_repeat('•', strlen($num)) : substr($num, 0, 1) . str_repeat('•', max(1, strlen($num) - 2)) . substr($num, -1));
$doc  = checkin_id_type_label((string)($v['waiver_id_type_snapshot'] ?? 'passport'));
$at   = (string)$v['waiver_signed_at'];
$how  = ($v['signed_via'] ?? '') === 'gate' ? 'On a Tribal Sand device at the gate' : 'Own device, via the registration link';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Signed consent · <?= e((string)$v['waiver_name_snapshot']) ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .bd{padding:26px 30px 30px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:22px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:12.5px} td{padding:4px 0;vertical-align:top} td.k{color:#666;width:150px}
  .terms{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;white-space:pre-wrap}
  .sigbox{border:1px solid #d8dbd8;border-radius:4px;padding:8px;max-width:280px} .sigbox img{display:block;max-width:100%}
  code{background:#eee;padding:1px 5px;border-radius:3px}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>
<div class="head"><div class="brand">TRIBAL SAND</div><div style="text-align:right;font-size:11px;line-height:1.5">Visitor Indemnity &amp; Waiver<br><strong>Signed Consent Record</strong></div></div>
<div class="bd">
  <table><tr><td class="k">Record reference</td><td><strong><?= e((string)$v['waiver_reference']) ?></strong></td></tr></table>
  <div class="sec">1 · Signatory</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e((string)$v['waiver_name_snapshot']) ?></strong></td></tr>
    <tr><td class="k">Role</td><td>Visitor</td></tr>
    <tr><td class="k">Nationality</td><td><?= e((string)($v['waiver_nationality_snapshot'] ?: '—')) ?></td></tr>
    <tr><td class="k"><?= e($doc) ?></td><td>No. <?= e($mask) ?></td></tr>
  </table>
  <div class="sec">2 · Terms agreed</div>
  <div class="terms"><?= e((string)$v['waiver_terms_snapshot']) ?></div>
  <div style="font-size:10.5px;color:#888;margin-top:8px">Waiver version <code><?= e((string)$v['waiver_version']) ?></code> — the exact terms shown at signing.</div>
  <div class="sec">3 · Signature</div>
  <div class="sigbox"><img src="<?= e((string)$v['waiver_signature']) ?>" alt="Signature"></div>
  <p style="font-size:12px">Signed by <strong><?= e((string)$v['waiver_signed_name']) ?></strong> · &#9745; “I have read and agree”</p>
  <div class="sec">4 · Evidence of signing</div>
  <table>
    <tr><td class="k">Date &amp; time</td><td><strong><?= e(date('j M Y, H:i:s', strtotime($at))) ?></strong></td></tr>
    <tr><td class="k">IP address</td><td><?= e((string)($v['signed_ip'] ?: '—')) ?></td></tr>
    <tr><td class="k">Device</td><td><?= e((string)($v['signed_ua'] ?: '—')) ?></td></tr>
    <tr><td class="k">Method</td><td><?= e($how) ?></td></tr>
  </table>
</div>
</body></html>
```

- [ ] **Step 3: Lint and check the guard order**

Run: `php -l record.php && php -l includes/visitor-record.php && grep -n "visitor-record" record.php`
Expected: no syntax errors; the dispatch line appears BEFORE the `if (!checkin_signature_supported())` line.

- [ ] **Step 4: Verify in the browser**

Open "My signed waiver" from the pass of Task 7's visitor: shows the snapshot name, masked ID, the terms, the signature, the evidence; the URL is `/record.php?vp=…`. Then edit the visitor's ID number via "Update my details": the pass asks to sign again, and the OLD record link returns "Record not found." until they sign again (voided); after signing, the record shows the new number.

- [ ] **Step 5: Commit**

```bash
git add includes/visitor-record.php
git add -p record.php   # only the dispatch hunk; record.php also has uncommitted ID-type changes
git commit -m "feat(visitors): signed visitor record at /record.php?vp="
```

---

### Task 10: Gate page

**Files:**
- Create: `admin/_gate_visitor.php`
- Modify: `admin/gate.php` (signed-out redirect, POST action `visitor_in`, `?vp=` handling, scanner regex, card include, Register button, *Not registered* badge, relabel)

**Interfaces:**
- Consumes: `visitor_pass_from_scan()`, `visitor_pass_verify()`, `visitor_gate_card()`, `visitor_let_in()`, `visitor_places()`, `visitor_pass_token()`, `visitor_reg_supported()`.
- Produces: `/admin/gate.php?vp=<VP token>`; POST `action=visitor_in` (`vp`, optional `place_id`); `data-help="gate-register"` on the Register visitor button.

- [ ] **Step 1: Wire `admin/gate.php`**

1. After `require_once __DIR__ . '/../includes/gate-pass.php';` add:
```php
require_once __DIR__ . '/../includes/visitor-store.php';
```
2. After the `$__passIn` lines (signed-out scan), add:
```php
$__vpIn = visitor_pass_from_scan((string)($_GET['vp'] ?? ''));
if (empty($_SESSION['admin_id']) && $__vpIn !== '') $_SESSION['admin_after_login'] = '/admin/gate.php?vp=' . $__vpIn;
```
3. Inside `if ($_SERVER['REQUEST_METHOD'] === 'POST') {`, right after `$action = $_POST['action'] ?? '';`, add:
```php
    // ── Let a registered visitor in (scanned VP pass) ──
    if ($action === 'visitor_in') {
        $vpTok = visitor_pass_from_scan((string)($_POST['vp'] ?? ''));
        $vid   = $vpTok !== '' ? visitor_pass_verify($vpTok) : false;
        $res   = $vid ? visitor_let_in((int)$vid, frontdesk_today_ymd(), (int)($_POST['place_id'] ?? 0) ?: null, $meId, $venueIds)
                      : ['ok' => false, 'msg' => 'This visitor pass isn’t valid.'];
        $_SESSION['hold_flash'] = ['type' => $res['ok'] ? 'success' : 'error', 'msg' => $res['msg']];
        header('Location: /admin/gate.php' . ($vpTok !== '' && !$res['ok'] ? '?vp=' . $vpTok : '')); exit;
    }
```
4. After the `$passVerdict = …` line, add:
```php
// ── A scanned visitor pass (?vp=VP-…) ──
$vpTok = $__vpIn; $vpCard = null; $vpErr = '';
if ($vpTok !== '') {
    $vid = visitor_pass_verify($vpTok);
    $vpCard = $vid ? visitor_gate_card((int)$vid, $today, $venueIds) : null;
    if (!$vpCard) $vpErr = 'This visitor pass isn’t valid. Ask them to register, or register them on this tablet.';
}
$vpPlaces = $vpCard && !$vpCard['visit'] ? visitor_places(true, $venueIds) : [];
```
5. In the page body, right after the booking-pass card block (the `<?php elseif ($passBk): … ?>` … `<?php endif; ?>` that renders `.gt-pass`), add:
```php
<?php if ($vpErr !== ''): ?>
<div class="alert alert--error" style="margin-bottom:16px"><?= e($vpErr) ?></div>
<?php elseif ($vpCard): include __DIR__ . '/_gate_visitor.php'; endif; ?>
```
6. Next to the existing **Sign in visitor** button (line ~311), add the register button and relabel the old one:
```php
<?php if (visitor_reg_supported()): ?>
<a class="btn btn-sm" href="/register.php?gate=1" data-no-shell data-help="gate-register"><?= admin_icon('plus', 15) ?> Register visitor</a>
<?php endif; ?>
```
and change the old button's text from `Sign in visitor` to `No phone? Sign in without registering`.
7. In the visitor log row, after `<strong><?= e($vr['visitor_name']) ?></strong>`, add:
```php
<?php if (visitor_reg_supported()): ?> <?= !empty($vr['registered_visitor_id']) ? '<span class="badge badge--green">Registered</span>' : '<span class="badge">Not registered</span>' ?><?php endif; ?>
```
8. In the scanner script, replace the two lines:
```js
        var m = /GP-\d{1,10}-[0-9a-fA-F]{12}/.exec(code.data);
        if (m) { go(m[0]); return; }
```
with
```js
        var m = /GP-\d{1,10}-[0-9a-fA-F]{12}/.exec(code.data);
        if (m) { go(m[0]); return; }
        var vp = /VP-\d{1,10}-[0-9a-fA-F]{12}/.exec(code.data);
        if (vp) { stop(); var vh = '/admin/gate.php?vp=' + encodeURIComponent(vp[0]); if (window.tsShellGo) window.tsShellGo(vh); else window.location.href = vh; return; }
```
and change the message to `'That QR code isn’t a Tribal Sand pass.'`.

- [ ] **Step 2: Write `admin/_gate_visitor.php`**

```php
<?php
/**
 * The Gate page card for a scanned visitor pass. Expects $vpCard (visitor_gate_card()),
 * $vpTok, $vpPlaces (places the guard may pick when none is chosen for today).
 * Out of scope: only the place is shown (the verdict says so) — no ID, photo or group.
 */
$v  = $vpCard['visitor']; $vd = $vpCard['verdict']; $vis = $vpCard['visit'];
$tone = ['ok' => 'green', 'warn' => 'orange', 'stop' => 'red'][$vd['level']] ?? 'orange';
$show = $vpCard['in_scope'];
?>
<div class="card gt-pass" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Visitor pass</span> <span class="badge badge--<?= $tone ?>"><?= e(['ok' => 'OK to enter', 'warn' => 'Check', 'stop' => 'Do not admit'][$vd['level']] ?? '') ?></span></div>
  <div class="card__body card__body--pad">
    <p class="gt-pass__name"><?= e((string)$v['full_name']) ?></p>
    <p><?= e($vd['msg']) ?></p>
    <?php if ($show): ?>
    <dl class="gt-facts">
      <?php if ($vis): ?><div><dt>Going to</dt><dd><?= e((string)$vis['place_name']) ?></dd></div><?php endif; ?>
      <div><dt><?= e(checkin_id_type_label((string)$v['id_type'])) ?></dt><dd><?= e((string)($v['id_number'] ?: '—')) ?> · <?= e((string)($v['nationality'] ?: '')) ?></dd></div>
      <div><dt>Phone</dt><dd><?= e((string)($v['phone'] ?: '—')) ?></dd></div>
      <div><dt>People</dt><dd><?= (int)$vpCard['people'] ?></dd></div>
    </dl>
    <?php if (!empty($v['id_photo_key'])): ?><img src="/admin/visitor-file.php?id=<?= (int)$v['id'] ?>" alt="ID photo" style="max-width:260px;border-radius:8px;margin:8px 0"><?php endif; ?>
    <?php if ($vpCard['adults'] || $vpCard['children']): ?>
    <ul style="margin:8px 0 12px 18px">
      <?php foreach ($vpCard['adults'] as $a): ?><li><?= e($a['name']) ?> — <?= $a['current'] ? 'signed ✓' : '<strong>not signed</strong>' ?></li><?php endforeach; ?>
      <?php foreach ($vpCard['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child, <?= (int)$c['age'] ?>)</li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($vpCard['on_site']): ?>
      <p class="text-muted">On site since <?= e(date('D j M H:i', strtotime((string)$vpCard['on_site']['time_in']))) ?> — sign them out in the log below.</p>
    <?php elseif ($vd['level'] !== 'stop'): ?>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?= csrf_field() ?><input type="hidden" name="action" value="visitor_in"><input type="hidden" name="vp" value="<?= e($vpTok) ?>">
      <?php if (!$vis): ?>
      <select name="place_id" class="inp" required><option value="">Where are they going?</option>
        <?php foreach ($vpPlaces as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?></option><?php endforeach; ?></select>
      <?php endif; ?>
      <button class="btn"><?= admin_icon('check', 15) ?> Let in</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
```

If `admin_icon('check')` is not in the icon set (`grep -n "'check'" includes/icons.php`), use `'plus'`.

- [ ] **Step 3: Lint**

Run: `php -l admin/gate.php && php -l admin/_gate_visitor.php`
Expected: no syntax errors.

- [ ] **Step 4: Verify in the browser** (signed in as the owner; local DB)

1. Open `/admin/gate.php?vp=<token of Task 7's lead>`: the card shows OK, place, ID, people = 3; **Let in** → flash "Anna Smith let in — 3 people to …", the log shows the row with a *Registered* badge.
2. Press **Let in** again via the same URL: refused "Still signed in since … — sign them out first."
3. Sign out from the log, then on the visitor's pass press **Change**, untick nothing, and set Settings visitor waiver text to something new (Task 12) → reload the gate URL: *Do not admit* "Our terms have changed…".
4. **Register visitor** opens `/register.php?gate=1` with places limited to the account's properties; registering returns to the Gate page with that visitor's card.
5. `read_console_messages` (`onlyErrors`): none.

- [ ] **Step 5: Commit**

```bash
git add admin/gate.php admin/_gate_visitor.php
git commit -m "feat(visitors): gate — scan visitor passes, let in, register on the tablet"
```

---

### Task 11: Admin — Visitors, ID photo, Places & posters

**Files:**
- Create: `admin/visitor-registry.php`, `admin/visitor-file.php`, `admin/visitor-places.php`, `admin/visitor-poster-print.php`

**Interfaces:**
- Consumes: Task 3 registry functions; `paginate_params()`, `paginate_meta()`, `dt_pager()`, `dt_empty()`; `danger_confirm_attrs()`, `typed_confirmation_ok()`; `storage_signed_get_url()`, `storage_local_path()`.
- Produces: `data-help="vr-list"` (registry table), `data-help="vr-places"` (places table).

- [ ] **Step 1: Write `admin/visitor-file.php`**

```php
<?php
/** A registered visitor's ID photo. Private: gate accounts that cover the visitor only. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login(); require_gate();

$id = (int)($_GET['id'] ?? 0);
if (!visitor_in_scope($id, admin_venue_ids(), (int)($_SESSION['admin_id'] ?? 0))) { http_response_code(403); exit('Forbidden'); }
$v = visitor_find($id, true);
$key = (string)($v['id_photo_key'] ?? '');
if ($key === '') { http_response_code(404); exit('No file'); }
audit_log('visitor.file_view', 'visitor', $id, '');

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$ct  = ['jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
$signed = storage_signed_get_url($key);
$data = $signed !== '' ? @file_get_contents($signed) : @file_get_contents(storage_local_path($key));
if ($data === false) { http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); exit('Could not load the file.'); }
header('Content-Type: ' . $ct);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $data;
```

Before writing, check how `admin/checkin-file.php` reads a file when `storage_signed_get_url()` returns `''` (`sed -n 36,70p admin/checkin-file.php`) and mirror that fallback exactly.

- [ ] **Step 2: Write `admin/visitor-registry.php`**

```php
<?php
/**
 * Gate → Visitors: registered visitors this account covers. Search, a drawer
 * with details / ID photo / group / visits / signed record, and Remove (owner +
 * manager, typed DELETE): the pass stops working and the ID photo is deleted;
 * the signed record and the gate log stay.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/confirm.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login(); require_gate();

$pageTitle = 'Visitors'; $activeMenu = 'gate';
$venueIds = admin_venue_ids();
$me = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'remove' && (is_owner() || is_manager()) && visitor_in_scope($id, $venueIds, $me) && typed_confirmation_ok('DELETE')) {
        visitor_remove($id);
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Visitor removed. Their pass no longer works.'];
    } else {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Nothing was removed.'];
    }
    header('Location: /admin/visitor-registry.php'); exit;
}

// ── Drawer: one visitor ──
if (isset($_GET['drawer']) && ($id = (int)($_GET['id'] ?? 0))) {
    if (!visitor_in_scope($id, $venueIds, $me) || !($v = visitor_find($id))) { http_response_code(404); exit('Not found'); }
    $g = visitor_group($id); $hist = visitor_visit_history($id, $venueIds);
    $cur = visitor_waiver_current($v, waiver_version(visitor_waiver_text()));
    ?>
    <div class="adrawer__head"><h2><?= e((string)$v['full_name']) ?></h2></div>
    <div class="adrawer__body">
      <p><?= $cur ? '<span class="badge badge--green">Signed</span>' : (visitor_signed($v) ? '<span class="badge badge--orange">Terms changed — must sign again</span>' : '<span class="badge">Not signed</span>') ?>
         <?php if (visitor_signed($v)): ?> · <a href="/record.php?vid=<?= $id ?>" target="_blank">Signed record <?= e((string)$v['waiver_reference']) ?></a><?php endif; ?></p>
      <dl class="gt-facts">
        <div><dt><?= e(checkin_id_type_label((string)$v['id_type'])) ?></dt><dd><?= e((string)($v['id_number'] ?: '—')) ?></dd></div>
        <div><dt>Nationality</dt><dd><?= e((string)($v['nationality'] ?: '—')) ?></dd></div>
        <div><dt>Phone</dt><dd><?= e((string)($v['phone'] ?: '—')) ?></dd></div>
        <div><dt>Email</dt><dd><?= e((string)($v['email'] ?: '—')) ?></dd></div>
        <div><dt>Registered</dt><dd><?= e(date('j M Y H:i', strtotime((string)$v['created_at']))) ?> · <?= e((string)$v['created_via']) ?></dd></div>
      </dl>
      <?php if (!empty($v['id_photo_key'])): ?><img src="/admin/visitor-file.php?id=<?= $id ?>" alt="ID photo" style="max-width:100%;border-radius:8px;margin:8px 0"><?php endif; ?>
      <?php if ($g['adults'] || $g['children']): ?><h3>Group</h3><ul>
        <?php foreach ($g['adults'] as $a): ?><li><?= e((string)$a['full_name']) ?> — <?= visitor_signed($a) ? 'signed' : 'not signed' ?></li><?php endforeach; ?>
        <?php foreach ($g['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child, <?= (int)$c['age'] ?>)</li><?php endforeach; ?></ul><?php endif; ?>
      <h3>Visits</h3>
      <?php if (!$hist): ?><p class="text-muted">No visits yet.</p><?php else: ?><ul>
        <?php foreach ($hist as $h): ?><li><?= e(date('j M Y H:i', strtotime((string)$h['time_in']))) ?> · <?= e((string)$h['visiting']) ?> · <?= (int)$h['people'] ?> people<?= $h['time_out'] ? '' : ' · on site' ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php if (is_owner() || is_manager()): ?>
    <div class="adrawer__foot">
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-outline btn-danger" <?= danger_confirm_attrs('Remove ' . (string)$v['full_name'] . '? Their pass stops working and their ID photo is deleted. The signed record stays.', 'Remove visitor', 'Remove', 'DELETE') ?>>Remove visitor</button></form>
    </div>
    <?php endif;
    exit;
}

$pg = paginate_params(25);
$list = visitor_registry_list($venueIds, $me, $pg['q'], $pg['per'], 0);
$meta = paginate_meta($list['total'], $pg['page'], $pg['per']);
$list = visitor_registry_list($venueIds, $me, $pg['q'], $meta['per'], $meta['offset']);
$ver = waiver_version(visitor_waiver_text());

ob_start(); ?>
<div class="card"><div class="card__body" style="padding:0">
  <?php if (!$list['rows']): dt_empty($pg['q'] !== '' ? 'No visitors match your search.' : 'No registered visitors yet.'); else: ?>
  <div class="table-wrap"><table class="data-table" data-help="vr-list">
    <thead><tr><th>Visitor</th><th>Phone</th><th>Last place</th><th>Last visit</th><th>Waiver</th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $r): ?>
      <tr data-drawer="/admin/visitor-registry.php?id=<?= (int)$r['id'] ?>" data-drawer-key="visitor-<?= (int)$r['id'] ?>" style="cursor:pointer">
        <td><strong><?= e((string)$r['full_name']) ?></strong></td>
        <td><?= e((string)($r['phone'] ?: '—')) ?></td>
        <td><?= e((string)($r['last_place'] ?: '—')) ?></td>
        <td class="text-muted"><?= $r['last_visit'] ? e(date('j M Y', strtotime((string)$r['last_visit']))) : '—' ?></td>
        <td><?= visitor_waiver_current($r, $ver) ? '<span class="badge badge--green">Signed</span>' : (visitor_signed($r) ? '<span class="badge badge--orange">Out of date</span>' : '<span class="badge">Not signed</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; dt_pager($meta); ?>
</div></div>
<?php $dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Visitors</h1></div>
<?php if (!visitor_reg_supported()): ?>
<div class="alert alert--error">Run add_visitor_registration.sql (Settings → Your account → Migrations) to turn on visitor registration.</div>
<?php else: dt_toolbar(['q' => $pg['q'], 'placeholder' => 'Search name, phone, email or reference']); echo $dtBody; endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

Before writing: check the exact helper names/signatures used by `admin/gate.php`'s log (`dt_empty`, `dt_pager`, the search toolbar helper — `grep -n "function dt_" includes/admin-pagination.php`) and whether `_layout_end.php` is included by other pages (`grep -l "_layout_end" admin/*.php | head -3`); use what exists,. `danger_confirm_attrs($message, $title, $label, $typeWord)` — the 4th argument `'DELETE'` makes the person type the word.

- [ ] **Step 3: Write `admin/visitor-places.php`** (owner)

```php
<?php
/** Gate → Places & posters (owner): the "Where are you going?" list and a QR poster per place. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login(); require_owner();

$pageTitle = 'Places & posters'; $activeMenu = 'gate';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $id = (int)($_POST['id'] ?? 0) ?: null;
        visitor_place_save($id, (string)($_POST['name'] ?? ''), (int)($_POST['venue_id'] ?? 0), (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']));
        audit_log('visitor.place_save', 'visitor_place', (int)$id, (string)($_POST['name'] ?? ''));
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Place saved.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: /admin/visitor-places.php'); exit;
}
$places = visitor_places(false);
$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
$venueSelect = function (int $sel) use ($venues): string {
    $o = '<select name="venue_id" class="inp" required><option value="">Behind the gate of…</option>';
    foreach ($venues as $v) $o .= '<option value="' . (int)$v['id'] . '"' . ((int)$v['id'] === $sel ? ' selected' : '') . '>' . e((string)$v['name']) . '</option>';
    return $o . '</select>';
};
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Places &amp; posters</h1>
  <button type="button" class="btn btn-sm" aria-controls="vpNew" onclick="document.getElementById('vpNew').style.display='block'"><?= admin_icon('plus', 15) ?> Add a place</button></div>
<?php if (!visitor_reg_supported()): ?>
<div class="alert alert--error">Run add_visitor_registration.sql to turn on visitor registration.</div>
<?php else: ?>
<div class="card" id="vpNew" data-inline-form style="display:none;margin-bottom:16px">
  <div class="card__head"><span class="card__title">New place</span></div>
  <form method="POST" class="card__body card__body--pad" style="display:flex;gap:8px;flex-wrap:wrap">
    <?= csrf_field() ?><input class="inp" name="name" placeholder="e.g. Tribal Table" required><?= $venueSelect(0) ?>
    <input class="inp" type="number" name="sort_order" value="<?= count($places) + 1 ?>" style="width:90px"><input type="hidden" name="is_active" value="1">
    <button class="btn">Add</button>
  </form>
</div>
<div class="card"><div class="card__body" style="padding:0"><div class="table-wrap">
<table class="data-table" data-help="vr-places">
  <thead><tr><th>Place</th><th>Gate of</th><th>Order</th><th>On</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($places as $p): ?>
    <tr><form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <td><input class="inp" name="name" value="<?= e((string)$p['name']) ?>" required></td>
      <td><?= $venueSelect((int)$p['venue_id']) ?></td>
      <td><input class="inp" type="number" name="sort_order" value="<?= (int)$p['sort_order'] ?>" style="width:80px"></td>
      <td><label class="optchip"><input type="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> Shown</label></td>
      <td style="white-space:nowrap;text-align:right"><button class="btn-icon btn-icon--outline" title="Save"><?= admin_icon('check') ?></button>
        <a class="btn-outline btn-sm" href="/admin/visitor-poster-print.php?place=<?= (int)$p['id'] ?>" target="_blank">Print poster</a></td>
    </form></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div></div>
<p class="text-muted" style="margin-top:12px">The general link is <strong><?= e(site_url('/register.php')) ?></strong>. A poster’s QR opens it with that place already chosen.</p>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

A `<form>` directly inside `<tr>` is invalid HTML; if the rows don't submit in the browser, give each row's inputs `form="vpRow<id>"` and put `<form id="vpRow<id>" method="POST">` (with the CSRF and id fields) in the last cell instead.

- [ ] **Step 4: Write `admin/visitor-poster-print.php`**

```php
<?php
/** A4 poster for one place: "Register before you enter" + QR → /register.php?place=<id>. Owner. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login(); require_owner();
$p = visitor_place((int)($_GET['place'] ?? 0));
if (!$p) { http_response_code(404); exit('Place not found.'); }
$url = site_url('/register.php?place=' . (int)$p['id']);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Poster · <?= e((string)$p['name']) ?></title>
<style>
  @page{size:A4;margin:0} body{margin:0;font-family:Georgia,serif;color:#102F3A;text-align:center}
  .pg{width:210mm;height:297mm;box-sizing:border-box;padding:24mm 18mm;display:flex;flex-direction:column;align-items:center;justify-content:space-between}
  h1{font-size:44px;margin:0} .sub{font:20px/1.4 -apple-system,Segoe UI,sans-serif;color:#444;max-width:150mm}
  .qr{width:120mm;height:120mm} .qr svg{width:100%;height:100%} .url{font:18px -apple-system,sans-serif;letter-spacing:.5px}
  .brand{letter-spacing:6px;font-weight:700} .bar{position:fixed;top:10px;right:10px} @media print{.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print</button></div>
<div class="pg">
  <div class="brand">TRIBAL SAND</div>
  <div><h1>Register before you enter</h1><p class="sub">Everyone visiting <?= e((string)$p['name']) ?> registers first. Scan with your phone camera — it takes two minutes.</p></div>
  <div class="qr" data-qr="<?= e($url) ?>"></div>
  <div class="url"><?= e(preg_replace('~^https?://~', '', site_url('/register'))) ?></div>
</div>
<script src="/js/vendor/qrcode.js"></script>
<script>(function(){var el=document.querySelector('[data-qr]');var q=qrcode(0,'M');q.addData(el.getAttribute('data-qr'));q.make();el.innerHTML=q.createSvgTag({scalable:true,margin:2});})();</script>
</body></html>
```

`/register` (no `.php`) on the poster is the short form people type; confirm it resolves locally (`curl -sI localhost:8765/register | head -1`). If it 404s, print `/register.php` instead.

- [ ] **Step 5: Lint and verify**

Run: `for f in admin/visitor-registry.php admin/visitor-file.php admin/visitor-places.php admin/visitor-poster-print.php; do php -l $f; done`
Expected: no syntax errors.
Browser (owner): Gate → Visitors lists Task 7's visitors; clicking a row opens the drawer with the ID photo; **Remove visitor** asks to type DELETE, then the visitor disappears and their pass URL redirects to "registered before". Places: add "Tribal Table" behind Tribal Dunes, it appears on `/register.php?step=visitor`; **Print poster** shows the A4 page with a scannable QR.

- [ ] **Step 6: Commit**

```bash
git add admin/visitor-registry.php admin/visitor-file.php admin/visitor-places.php admin/visitor-poster-print.php
git commit -m "feat(visitors): admin — visitors list + drawer, ID photo, places and posters"
```

---

### Task 12: Settings, navigation, access, help, docs

**Files:**
- Modify: `admin/checkin-settings.php`, `includes/admin-nav.php`, `includes/access.php`, `includes/help-guides.php`, `CLAUDE.md`
- Test: `tests/admin_nav_logic.php`, `tests/help_logic.php`, `tests/access_logic.php` (existing)

**Interfaces:**
- Consumes: env flag `visitorReg` = `$has('visitor_signing_audit')`.
- Produces: tabs Gate · Visitors · Places & posters; setting `visitor_waiver_text`; guide `register-visitors`.

- [ ] **Step 1: Visitor waiver setting** — in `admin/checkin-settings.php`:
  - in the POST block after the `checkin_waiver_text` line: `set_setting('visitor_waiver_text', trim((string)($_POST['visitor_waiver_text'] ?? '')));`
  - after `$waiver = setting('checkin_waiver_text', '');`: `$visitorWaiver = setting('visitor_waiver_text', '');`
  - after the "Waiver / indemnity terms" card, add:
```php
  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Visitor waiver</span></div>
    <div class="card__body card__body--pad">
      <p class="text-muted" style="margin:0 0 12px;font-size:13px">What visitors without a booking sign on the registration link. Leave empty to use the guest waiver above. Changing it asks every visitor to sign again on their next visit.</p>
      <textarea name="visitor_waiver_text" rows="6" class="inp inp--area" style="width:100%;min-height:160px" placeholder="Leave empty to use the guest waiver"><?= e($visitorWaiver) ?></textarea>
    </div>
  </div>
```

- [ ] **Step 2: Navigation** — in `includes/admin-nav.php`:
  - in `admin_nav_flags()` return array add `'visitorReg' => $e('visitorReg'),` and make the Gate item:
```php
            $item('Gate', 'gate', [
                $tab('Gate', 'gate.php', $on('gate')),
                $tab('Visitors', 'visitor-registry.php', $on('gate') && $on('visitorReg')),
                $tab('Places & posters', 'visitor-places.php', $on('owner') && $on('visitorReg')),
            ]),
```
  - add the key `'visitorReg'` to the list of flag names on line ~72 (the array that starts `'gate', 'mywork', 'assistant', …`).
  - in `ADMIN_NAV_SEARCH_WORDS` add:
```php
    'visitor-registry.php' => 'visitor visitors register registration waiver walk-in day pass guest list',
    'visitor-places.php'   => 'visitor places poster qr register gate restaurant tribal table',
```
- [ ] **Step 3: Access** — in `includes/access.php`: add `'visitorReg' => $has('visitor_signing_audit'),` to `access_env()`, and `'visitor-places.php'` to `ACCESS_OWNER_ONLY`.

- [ ] **Step 4: Help guide** — in `includes/help-guides.php`, after the `gate-checkin` guide:
```php
        ['slug' => 'register-visitors', 'area' => 'guests', 'minutes' => 3, 'pages' => ['gate.php', 'visitor-registry.php', 'visitor-places.php'],
         'title' => 'Register visitors (no booking)',
         'summary' => 'Everyone who enters is registered: visitors sign once on their phone and show a pass at the gate.',
         'steps' => [
            ['text' => 'Visitors scan the **poster** at the gate (or open tribalsand.com/register), pick where they are going, take a picture of their ID and sign. They get a **visitor pass**.'],
            ['text' => 'Someone with no phone? Press **Register visitor** and they fill the same form on this tablet.', 'target' => '[data-help="gate-register"]'],
            ['text' => 'Scan their pass. **OK to enter** → press **Let in**. **Do not admit** says why: not signed, terms changed, someone in the group hasn’t signed, or another property’s place.'],
            ['text' => '**Visitors** lists everyone registered. Open one to see their ID photo, group, visits and signed record.', 'target' => '[data-help="vr-list"]'],
            ['text' => 'The owner edits the **Places** list and prints a **poster** for each gate.', 'target' => '[data-help="vr-places"]'],
         ],
         'tips' => ['A guest with a booking chooses “I have a booking” and goes to their own check-in.',
                    'Changing the visitor waiver (Settings → Pre-Check-in) asks everyone to sign again on their next visit.']],
```

- [ ] **Step 5: Run the admin tests**

Run: `php tests/admin_nav_logic.php && php tests/help_logic.php && php tests/access_logic.php`
Expected: each `ALL PASS`. If `admin_nav_logic` reports `visitor-poster-print.php` or `visitor-file.php` as "uses the layout and belongs to no tab", confirm neither includes `_layout.php` (they must not).

- [ ] **Step 6: CLAUDE.md** — add a section after "Gate pass + check-in at the gate":

```markdown
### Visitor registration — one general link, sign once, pass at the gate (Oct 2026)
Everyone who enters is registered. **`/register.php`** (public, noindex; `?place=<id>` from the gate posters; `?gate=1` = the guard's tablet): **I have a booking** (booking code + name → that booking's check-in, lead or co-guest link; a name alone never opens anything) · **I'm visiting** (place, details, ID photo read like check-in, children + adults to invite, the waiver) · **I've registered before** (pass re-emailed). Pure rules `includes/visitor-registration.php`, DB `includes/visitor-store.php`, ONE POST handler `api/visitor-register.php`, photo `api/visitor-upload.php`, pass `visitor-pass.php`. Migration `add_visitor_registration.sql` (guard `visitor_reg_supported()`). Spec `docs/superpowers/specs/2026-10-14-visitor-registration-design.md`. Test: `php tests/visitor_registration_logic.php`.
- **Register once, reuse:** the pass (`VP-<id>-<hmac12>`, HMAC of `visitor:<id>`) asks where they're going today (`visitor_visits`, one per person per day). Signed = `waiver_version` equals today's `waiver_version(visitor_waiver_text())` (setting `visitor_waiver_text`, blank = the check-in waiver) — changing the wording makes everyone re-sign.
- **Groups:** the lead adds children (name + age) and adults; each adult gets a WhatsApp / Copy link (`/register.php?t=<their VP>`) and signs for themselves; the group is a *stop* at the gate until every adult has signed.
- **Signed record** `/record.php?vp=` (visitor) or `?vid=` (gate account in scope) renders snapshots only; a material identity change voids the signature (`visitor_update_identity()`); own hash chain `visitor_signing_audit` (advisory lock, savepoint) — never mixed into `checkin_signing_audit`.
- **Gate:** scanner reads `VP-` beside `GP-`; `visitor_gate_verdict()` checks scope FIRST (another property's place shows only the place); **Let in** writes one `visitors` row (`registered_visitor_id`, `visit_id`, `people`); a partial unique index keeps a visitor on site once. Hand-typed sign-ins remain as "No phone? Sign in without registering" (*Not registered* badge).
- **Admin:** Gate → **Visitors** (`admin/visitor-registry.php`, require_gate, scope = visited your properties or registered on your tablet; Remove = typed DELETE, deletes the ID photo, keeps the record + log) · **Places & posters** (`admin/visitor-places.php`, owner; poster `admin/visitor-poster-print.php`). ID photos only through `admin/visitor-file.php`. Emails `visitor_pass`, `visitor_booking_link`.
- **Limits:** Turnstile once per session on public actions without a token; 10 failed booking codes / IP / 10 min; recovery emails ≤ 1 per address / 10 min with the same neutral answer; 30 registrations / IP / hour (a bar's wifi is one IP).
```

Add rows to the File Map table:
```markdown
| `includes/visitor-registration.php` · `includes/visitor-store.php` | Visitor registration — pure rules (tokens, matching, validation, gate verdict) · DB (sign + audit chain, group, visits, let in, registry) |
| `register.php` · `visitor-pass.php` · `api/visitor-register.php` · `api/visitor-upload.php` | The general registration link · the visitor's pass · the ONE POST handler · ID photo upload |
| `admin/visitor-registry.php` · `admin/visitor-places.php` · `admin/visitor-file.php` | Gate → Visitors · Places & posters (owner) · private ID photo |
```

- [ ] **Step 7: Commit**

```bash
git add includes/admin-nav.php includes/access.php includes/help-guides.php
git add -p admin/checkin-settings.php CLAUDE.md   # only this task's hunks; both files have other uncommitted work
git commit -m "feat(visitors): settings, navigation, access, help guide, docs"
```

---

### Task 13: Full check

- [ ] **Step 1: Run every related test**

Run:
```bash
for t in visitor_registration_logic gate_pass_logic checkin_logic checkin_passport_read_logic checkin_consent email_log_logic admin_nav_logic help_logic access_logic migration_order_logic; do echo "== $t"; php tests/$t.php | tail -1; done
```
Expected: every line `ALL PASS` (the CLAUDE.md "known pre-existing failures" may still show; compare against `git stash`-free main behaviour by noting any failure that also fails on `master` before blaming this work).

- [ ] **Step 2: Browser walk-through at 375 px and desktop**

Using `preview_start` `{name: "tribal-sand"}`:
1. `/register.php` → booking path with a wrong code: "We couldn’t find…"; 11 wrong codes: "Too many tries".
2. Visitor path end to end (Task 7 steps) incl. an invitee.
3. Gate: scan URL → Let in → on site; out-of-scope account (a manager of another property) sees only the place.
4. Change the visitor waiver → pass asks to sign again; gate says *Do not admit*.
5. `read_console_messages` (`onlyErrors`) on each page: none; no sideways scroll at 375 px.
Screenshot the pass and the gate card as proof.

- [ ] **Step 3: Production note for the owner**

Run on production via `/admin/migrate.php`: `add_visitor_registration.sql` (after `add_checkin_id_type.sql`, which is part of the uncommitted check-in work and must ship first). Then Gate → Places & posters: add the restaurants, print the posters.
