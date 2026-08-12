# Portal Identity & Party Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the guest portal and the admin inbox say who each guest actually is — fix the co-guest greeting, name message senders everywhere, stop labelling an unnamed lead "Guest", and give the lead a party roster they can re-share links from.

**Architecture:** Three pure helpers carry all the new logic and are unit-tested in isolation. The two message queries gain `sender_is_lead` and `hold_guest_name` so `message_payload()` resolves names at the source and all four render paths inherit the fix. `booking.php` resolves one `$actor` (reading, never writing) that the views use for greetings and "You" badges. A new `_party.php` partial joins the Home in-page tabs.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS, vanilla CSS. Tests are plain PHP scripts with a `check()` helper — no PHPUnit. **No migration, no schema change.**

**Spec:** `docs/superpowers/specs/2026-08-11-portal-identity-party-design.md`

---

## Before you start

Read the spec. Then read these three files end to end:

- `booking.php` (340 lines) — the portal entry point
- `includes/booking.php` (724 lines) — message queries, `message_payload()`, `guest_display_name()`
- `includes/app/home.php` (84 lines) — the in-page section tabs

**Conventions you must follow** (from `CLAUDE.md`):

- Escape all output with `e()`. Use `db_query()` with bound parameters — never interpolate user data.
- Every read of a migration-added column sits behind a `*_supported()` guard. Here that is `message_sender_guest_supported()`.
- "Keep initial render and appended bubbles identical" — the live poll and the server render must produce the same label. That is exactly why the helpers below are shared.
- No build step. Edit CSS and JS directly.

**Baseline** — run these before you change anything:

```bash
php tests/checkin_logic.php && php tests/portal_logic.php
```

Both must end `ALL PASS`. (Note: `php tests/team_logic.php` has one known pre-existing failure, `owner: home = dashboard`, unrelated to this work. Ignore it.)

**Committing:** the working tree has unrelated pre-existing changes — a modified `.claude/launch.json` and two large untracked `Archive*.zip` files. **NEVER use `git add -A` or `git add .`.** Add only the files named in each task.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `includes/booking.php` | Portal domain logic. Gains `attributed_display_name()`, `message_sender_label()`; two queries + `message_payload()` updated | Modify |
| `includes/checkin.php` | Check-in domain logic. Gains `checkin_guest_label()` | Modify |
| `api/booking-message.php` | Guest send endpoint — its hand-built payload needs the new fields | Modify |
| `admin/messages.php` | Global inbox — sender label | Modify |
| `admin/_ws_messages.php` | Workspace tab — sender label (switch to the shared helper) | Modify |
| `includes/app/bill.php` | Guest bill attribution | Modify |
| `admin/_ws_bill.php`, `admin/_ws_requests.php` | Admin attribution | Modify |
| `booking.php` | Resolve `$actor`; greeting uses it | Modify |
| `includes/app/messages.php` | "You" vs another guest's name | Modify |
| `includes/app/_party.php` | New — the roster partial | Create |
| `includes/app/home.php` | Register the `party` section tab | Modify |
| `includes/app/checkin.php` | Confirmation card switches to `checkin_guest_label()` | Modify |
| `css/portal-app.css` | Roster styles | Modify |
| `tests/checkin_logic.php`, `tests/portal_logic.php` | Assertions | Modify |

**Note a small spec gap I found while planning:** the spec's problem statement says an unnamed lead is mis-attributed "on the bill and in the admin request list", but its Files table omits `includes/app/bill.php`, `admin/_ws_bill.php` and `admin/_ws_requests.php`. Task 5 covers them — three near-identical one-liners. Leaving them inconsistent with the message views would be worse than the small scope addition.

---

### Task 1: `attributed_display_name()`

**Files:**
- Modify: `includes/booking.php` (insert after `guest_display_name()`, line 168)
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find these three lines under the `Shared portal: display name (pure)` heading:

```php
check('display name first word',  guest_display_name(['passport_name'=>'Jess Achieng']) === 'Jess');
check('display name blank=Guest',  guest_display_name(['passport_name'=>'']) === 'Guest');
check('display name null=Guest',   guest_display_name(null) === 'Guest');
```

Insert this block immediately after them:

```php
// ── Attribution name: falls back to the booking name for an unnamed lead ────
check('attributed own name wins',        attributed_display_name('Patrik Otieno', false, 'Jessica Mwangi') === 'Patrik');
check('attributed lead uses booking',    attributed_display_name('', true, 'Jessica Mwangi') === 'Jessica');
check('attributed co-guest = Guest',     attributed_display_name('', false, 'Jessica Mwangi') === 'Guest');
check('attributed lead no booking name', attributed_display_name('', true, '') === 'Guest');
check('attributed trims whitespace',     attributed_display_name('   ', true, 'Jessica Mwangi') === 'Jessica');
check('attributed first word only',      attributed_display_name('Anne Marie Wanjiru', false, '') === 'Anne');
check('attributed lead own name beats booking', attributed_display_name('Jess Achieng', true, 'Jessica Mwangi') === 'Jess');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/checkin_logic.php`
Expected: a PHP fatal error — `Call to undefined function attributed_display_name()`.

Confirm you see this before proceeding.

- [ ] **Step 3: Add the helper**

In `includes/booking.php`, insert this immediately after `guest_display_name()` (which ends at line 168) and before the `resolve_portal_actor()` docblock:

```php
/**
 * Display name for an attributed row: the guest's own name, the booking name for
 * an unnamed lead, else "Guest". Returns the first word only, matching
 * guest_display_name(), so attribution reads the same everywhere.
 *
 * The lead fallback exists because a lead only gets a passport_name when the
 * passport step is enabled — without it their own requests read as "Guest". Pure.
 */
function attributed_display_name(string $guestName, bool $isLead, string $bookingName): string {
    $n = trim($guestName);
    if ($n === '' && $isLead) $n = trim($bookingName);
    return $n === '' ? 'Guest' : explode(' ', $n)[0];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/checkin_logic.php`
Expected: all seven new `attributed …` lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/booking.php tests/checkin_logic.php
git commit -m "feat(portal): attribution name with a booking-name fallback for the lead"
```

---

### Task 2: `checkin_guest_label()` and switch the confirmation card to it

**Files:**
- Modify: `includes/checkin.php` (insert after `checkin_outstanding_adults()`)
- Modify: `includes/app/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find this line under the `Outstanding adults + waiting-on label (pure)` heading:

```php
check('waiting label empty',  checkin_waiting_on_label([], 0) === '');
```

Insert this block immediately after it:

```php
// ── Guest label: name, else "Guest N" by ROSTER position (pure) ─────────────
$lblRoster = [
    ['id' => 1, 'passport_name' => 'Jessica Mwangi'],
    ['id' => 2, 'passport_name' => ''],
    ['id' => 3, 'passport_name' => 'Patrik Otieno'],
];
check('guest label full name',        checkin_guest_label($lblRoster[0], $lblRoster) === 'Jessica Mwangi');
check('guest label short name',       checkin_guest_label($lblRoster[0], $lblRoster, true) === 'Jessica');
check('guest label unnamed by roster', checkin_guest_label($lblRoster[1], $lblRoster) === 'Guest 2');
check('guest label third keeps name', checkin_guest_label($lblRoster[2], $lblRoster) === 'Patrik Otieno');
check('guest label short of unnamed',  checkin_guest_label($lblRoster[1], $lblRoster, true) === 'Guest 2');
check('guest label absent from roster', checkin_guest_label(['id' => 99, 'passport_name' => ''], $lblRoster) === 'Guest 4');
check('guest label null guest',       checkin_guest_label(null, $lblRoster) === 'Guest 4');
// The bug this replaces: numbering by filtered-list position named a guest who had finished.
$lblFiltered = [$lblRoster[1]];   // only guest 2 outstanding, but roster position is still 2
check('guest label ignores filtered index', checkin_guest_label($lblFiltered[0], $lblRoster) === 'Guest 2');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/checkin_logic.php`
Expected: a PHP fatal error — `Call to undefined function checkin_guest_label()`.

- [ ] **Step 3: Add the helper**

In `includes/checkin.php`, insert this immediately after `checkin_outstanding_adults()` and before `checkin_waiting_on_label()`:

```php
/**
 * A guest's display label: their name, else "Guest N" by ROSTER position — never
 * by position in a filtered list, which would number a guest who had already
 * finished. $short returns the first word only, for sentences. Pure.
 */
function checkin_guest_label(?array $guest, array $adults, bool $short = false): string {
    $g = $guest ?? [];
    $n = trim((string)($g['passport_name'] ?? ''));
    if ($n !== '') return $short ? explode(' ', $n)[0] : $n;
    $gid = (int)($g['id'] ?? 0);
    $pos = null;
    if ($gid > 0) {
        foreach (array_values($adults) as $i => $a) {
            if ((int)($a['id'] ?? 0) === $gid) { $pos = $i; break; }
        }
    }
    return 'Guest ' . ($pos === null ? count($adults) + 1 : $pos + 1);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/checkin_logic.php`
Expected: all eight new `guest label …` lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Switch the confirmation card's sentence to the helper**

In `includes/app/checkin.php`, replace this block (added by the previous group):

```php
// Unnamed guests fall back to their ROSTER number, not their position in the
// filtered outstanding list — otherwise "guest 2 done, guest 3 unnamed" would
// label guest 3 as "Guest 2". $adults is ordered lead-first, so index+1 is the
// number the guest sees.
$__adultPos = [];
foreach ($adults as $__p => $__a) { $__adultPos[(int)($__a['id'] ?? 0)] = $__p; }
$waitingNames = [];
foreach ($outstanding as $__g) {
    $__n = trim((string)($__g['passport_name'] ?? ''));
    if ($__n !== '') { $waitingNames[] = explode(' ', $__n)[0]; continue; }
    $__pos = $__adultPos[(int)($__g['id'] ?? 0)] ?? null;
    $waitingNames[] = 'Guest ' . ($__pos === null ? count($waitingNames) + 2 : $__pos + 1);
}
$waitingLabel = checkin_waiting_on_label($waitingNames, $unnamedSlots);
```

with:

```php
// Short labels for the sentence ("waiting on Patrik and Sarah"); the itemised
// list below uses the full-name form of the same helper.
$waitingNames = [];
foreach ($outstanding as $__g) { $waitingNames[] = checkin_guest_label($__g, $adults, true); }
$waitingLabel = checkin_waiting_on_label($waitingNames, $unnamedSlots);
```

- [ ] **Step 6: Switch the confirmation card's list to the helper**

In `includes/app/checkin.php`, inside the `$leadWaiting` card, replace this line:

```php
      <span><?= e(trim((string)($og['passport_name'] ?? '')) !== '' ? (string)$og['passport_name'] : 'Unnamed guest') ?></span>
```

with:

```php
      <span><?= e(checkin_guest_label($og, $adults)) ?></span>
```

- [ ] **Step 7: Verify the card still behaves**

Run: `php -l includes/app/checkin.php` → expect `No syntax errors detected`.
Run: `php tests/checkin_logic.php` → expect `ALL PASS`.

Then confirm the numbering fix survived the refactor:

```bash
php -r '
require "includes/db.php"; require "includes/checkin.php";
$roster = [["id"=>1,"passport_name"=>"Jessica Mwangi"],["id"=>2,"passport_name"=>"Sarah Kim"],["id"=>3,"passport_name"=>""]];
echo "list form:  " . checkin_guest_label($roster[2], $roster) . "\n";
echo "short form: " . checkin_guest_label($roster[2], $roster, true) . "\n";'
```

Expected: both print `Guest 3` (not `Guest 2`).

- [ ] **Step 8: Commit**

```bash
git add includes/checkin.php includes/app/checkin.php tests/checkin_logic.php
git commit -m "refactor(checkin): one guest-label helper for the card and the roster"
```

---

### Task 3: Message plumbing — queries, payload, shared label

**Files:**
- Modify: `includes/booking.php`
- Modify: `api/booking-message.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test for the shared label**

In `tests/checkin_logic.php`, immediately after the `attributed …` block added in Task 1, insert:

```php
// ── Message sender label (pure, takes a raw fetch_thread_messages row) ──────
check('sender label admin = Staff',   message_sender_label(['sender' => 'admin']) === 'Staff');
check('sender label named guest',     message_sender_label(['sender' => 'guest', 'sender_name' => 'Patrik Otieno']) === 'Patrik');
check('sender label unnamed lead',    message_sender_label(['sender' => 'guest', 'sender_name' => '', 'sender_is_lead' => true, 'hold_guest_name' => 'Jessica Mwangi']) === 'Jessica');
check('sender label unknown = Guest', message_sender_label(['sender' => 'guest']) === 'Guest');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function message_sender_label()`.

- [ ] **Step 3: Update the two message queries**

In `includes/booking.php`, replace `fetch_thread_messages()`:

```php
/** All messages in one thread, oldest → newest. Empty if the table is absent (pre-migration). */
function fetch_thread_messages(int $holdId, ?int $addonId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    $sel  = message_sender_guest_supported() ? ", cg.passport_name AS sender_name" : "";
    $join = message_sender_guest_supported() ? "LEFT JOIN checkin_guests cg ON cg.id = bm.sender_guest_id" : "";
    try {
        return db_query("SELECT bm.*{$sel} FROM booking_messages bm {$join} WHERE bm.hold_id=:h AND bm.$cond ORDER BY bm.created_at ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}
```

with:

```php
/**
 * All messages in one thread, oldest → newest. Empty if the table is absent.
 * Also selects the sender's is_lead and the booking's guest_name so an unnamed
 * lead can be attributed by name — see attributed_display_name().
 */
function fetch_thread_messages(int $holdId, ?int $addonId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    $on   = message_sender_guest_supported();
    $sel  = $on ? ", cg.passport_name AS sender_name, cg.is_lead AS sender_is_lead, h.guest_name AS hold_guest_name" : "";
    $join = $on ? "LEFT JOIN checkin_guests cg ON cg.id = bm.sender_guest_id JOIN holds h ON h.id = bm.hold_id" : "";
    try {
        return db_query("SELECT bm.*{$sel} FROM booking_messages bm {$join} WHERE bm.hold_id=:h AND bm.$cond ORDER BY bm.created_at ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}
```

Then replace `fetch_thread_messages_since()`:

```php
/** New messages in a thread with id greater than $afterId, oldest → newest. Powers live polling. */
function fetch_thread_messages_since(int $holdId, ?int $addonId, int $afterId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId, ':after'=>$afterId]; if ($addonId !== null) $p[':aid'] = $addonId;
    $sel  = message_sender_guest_supported() ? ", cg.passport_name AS sender_name" : "";
    $join = message_sender_guest_supported() ? "LEFT JOIN checkin_guests cg ON cg.id = bm.sender_guest_id" : "";
    try {
        return db_query("SELECT bm.*{$sel} FROM booking_messages bm {$join} WHERE bm.hold_id=:h AND bm.$cond AND bm.id > :after ORDER BY bm.id ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}
```

with:

```php
/**
 * New messages in a thread with id greater than $afterId, oldest → newest. Powers
 * live polling. Selects the same attribution columns as fetch_thread_messages()
 * so a polled bubble and a server-rendered one carry identical labels.
 */
function fetch_thread_messages_since(int $holdId, ?int $addonId, int $afterId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId, ':after'=>$afterId]; if ($addonId !== null) $p[':aid'] = $addonId;
    $on   = message_sender_guest_supported();
    $sel  = $on ? ", cg.passport_name AS sender_name, cg.is_lead AS sender_is_lead, h.guest_name AS hold_guest_name" : "";
    $join = $on ? "LEFT JOIN checkin_guests cg ON cg.id = bm.sender_guest_id JOIN holds h ON h.id = bm.hold_id" : "";
    try {
        return db_query("SELECT bm.*{$sel} FROM booking_messages bm {$join} WHERE bm.hold_id=:h AND bm.$cond AND bm.id > :after ORDER BY bm.id ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}
```

- [ ] **Step 4: Add `message_sender_label()` and update `message_payload()`**

In `includes/booking.php`, replace `message_payload()`:

```php
/** Shape a booking_messages row for a JSON poll/send response (id, sender, body, time_label). */
function message_payload(array $m): array {
    $name = trim((string)($m['sender_name'] ?? ''));
    return [
        'id'          => (int)$m['id'],
        'sender'      => (string)$m['sender'],
        'sender_name' => $name === '' ? '' : guest_display_name(['passport_name' => $name]),
        'body'        => (string)$m['body'],
        'time_label'  => message_time_label($m['created_at'] ?? 'now'),
    ];
}
```

with:

```php
/**
 * Sender label for a raw fetch_thread_messages() row: 'Staff' for admin, else the
 * guest's name (booking name for an unnamed lead, 'Guest' when unresolvable).
 * Shared by every admin render path so they cannot drift. Pure.
 */
function message_sender_label(array $m): string {
    if (($m['sender'] ?? '') === 'admin') return 'Staff';
    return attributed_display_name(
        (string)($m['sender_name'] ?? ''),
        !empty($m['sender_is_lead']),
        (string)($m['hold_guest_name'] ?? '')
    );
}

/**
 * Shape a booking_messages row for a JSON poll/send response.
 * sender_name is '' when the sender cannot be attributed at all — the views then
 * apply their own vocabulary ('You' on the guest side, 'Guest' on the admin side),
 * which is why this does not hardcode a fallback here.
 */
function message_payload(array $m): array {
    $name   = trim((string)($m['sender_name'] ?? ''));
    $isLead = !empty($m['sender_is_lead']);
    $known  = $name !== '' || ($isLead && trim((string)($m['hold_guest_name'] ?? '')) !== '');
    return [
        'id'          => (int)$m['id'],
        'sender'      => (string)$m['sender'],
        'sender_name' => $known ? attributed_display_name($name, $isLead, (string)($m['hold_guest_name'] ?? '')) : '',
        'body'        => (string)$m['body'],
        'time_label'  => message_time_label($m['created_at'] ?? 'now'),
        'guest_id'    => (int)($m['sender_guest_id'] ?? 0),
    ];
}
```

`guest_id` is added so the guest-side poll can tell "my message" from another guest's — Task 6 uses it.

- [ ] **Step 5: Update the hand-built payload in the send endpoint**

In `api/booking-message.php`, replace this block:

```php
    $id = (int)db()->lastInsertId();
    $__sn = db_query('SELECT passport_name FROM checkin_guests WHERE id=:g', [':g'=>(int)$actor['guest_id']])->fetchColumn();
    echo json_encode(['ok'=>true, 'message'=>message_payload([
        'id'=>$id, 'sender'=>'guest', 'body'=>$body, 'created_at'=>'now', 'sender_name'=>$__sn ?: '',
    ])]);
```

with:

```php
    $id = (int)db()->lastInsertId();
    // Same three attribution inputs the queries select, so the echoed bubble
    // matches what a later poll or page load will render for this message.
    $__me = db_query('SELECT passport_name, is_lead FROM checkin_guests WHERE id=:g', [':g'=>(int)$actor['guest_id']])->fetch() ?: [];
    echo json_encode(['ok'=>true, 'message'=>message_payload([
        'id'              => $id,
        'sender'          => 'guest',
        'body'            => $body,
        'created_at'      => 'now',
        'sender_name'     => (string)($__me['passport_name'] ?? ''),
        'sender_is_lead'  => !empty($__me['is_lead']),
        'hold_guest_name' => (string)($hold['guest_name'] ?? ''),
        'sender_guest_id' => (int)$actor['guest_id'],
    ])]);
```

- [ ] **Step 6: Run the tests**

Run: `php tests/checkin_logic.php && php tests/portal_logic.php`
Expected: the four new `sender label …` lines PASS; both suites end `ALL PASS`.

Run: `php -l includes/booking.php && php -l api/booking-message.php`
Expected: no syntax errors.

- [ ] **Step 7: Verify the queries actually run**

The new `JOIN holds h` must not break either query. Exercise both against real data:

```bash
php -r '
require "includes/db.php"; require "includes/booking.php";
$h = (int)db_query("SELECT hold_id FROM booking_messages ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$h) { echo "no messages in the local DB — skipped\n"; exit; }
$all = fetch_thread_messages($h, null);
$since = fetch_thread_messages_since($h, null, 0);
printf("thread rows=%d since rows=%d\n", count($all), count($since));
if ($all) { $k = array_keys($all[0]);
  foreach (["sender_name","sender_is_lead","hold_guest_name"] as $c)
    printf("  %-16s %s\n", $c, in_array($c,$k,true) ? "present" : "MISSING");
  print_r(message_payload($all[0])); }'
```

Expected: non-zero row counts, all three columns `present`, and a payload whose `sender_name` is a first name or `''` (never the literal string `Guest`).

- [ ] **Step 8: Commit**

```bash
git add includes/booking.php api/booking-message.php tests/checkin_logic.php
git commit -m "feat(portal): attribute message senders from one shared resolver"
```

---

### Task 4: Admin views use the shared sender label

**Files:**
- Modify: `admin/messages.php:168`
- Modify: `admin/_ws_messages.php:44`

- [ ] **Step 1: Fix the global inbox**

In `admin/messages.php`, replace this line:

```php
      <div class="am-msg__meta"><?= $adminMsg ? 'Staff' : 'Guest' ?> · <?= e(message_time_label($m['created_at'])) ?></div>
```

with:

```php
      <div class="am-msg__meta"><?= e(message_sender_label($m)) ?> · <?= e(message_time_label($m['created_at'])) ?></div>
```

This is the defect: the inbox hardcoded `'Guest'` while `admin/assets/admin-chat.js:43` already rendered `m.sender_name` on live-polled messages — so one screen showed a name on new messages and not on old ones.

- [ ] **Step 2: Switch the workspace tab to the same helper**

In `admin/_ws_messages.php`, replace this line:

```php
      <div class="am-msg__meta"><?= $am ? 'Staff' : e(trim((string)($m['sender_name'] ?? '')) !== '' ? guest_display_name(['passport_name'=>$m['sender_name']]) : 'Guest') ?> · <?= e(message_time_label($m['created_at'])) ?></div>
```

with:

```php
      <div class="am-msg__meta"><?= e(message_sender_label($m)) ?> · <?= e(message_time_label($m['created_at'])) ?></div>
```

Behaviour is unchanged for a named guest; it now also names an unnamed lead, and there is one definition instead of two.

- [ ] **Step 3: Verify**

Run: `php -l admin/messages.php && php -l admin/_ws_messages.php`
Expected: no syntax errors.

Then confirm both files render the same label for the same row:

```bash
php -r '
require "includes/db.php"; require "includes/booking.php";
foreach ([
  ["sender"=>"admin"],
  ["sender"=>"guest","sender_name"=>"Patrik Otieno"],
  ["sender"=>"guest","sender_name"=>"","sender_is_lead"=>true,"hold_guest_name"=>"Jessica Mwangi"],
  ["sender"=>"guest"],
] as $m) printf("  %-52s -> %s\n", json_encode($m), message_sender_label($m));'
```

Expected: `Staff`, `Patrik`, `Jessica`, `Guest`.

- [ ] **Step 4: Commit**

```bash
git add admin/messages.php admin/_ws_messages.php
git commit -m "fix(admin): name the message sender in the inbox, not just the workspace"
```

---

### Task 5: Bill and request attribution

**Files:**
- Modify: `includes/app/bill.php`
- Modify: `admin/_ws_bill.php`
- Modify: `admin/_ws_requests.php`

All three already select an `is_lead` companion column, so they only need the booking name — which each already has in `$hold`.

- [ ] **Step 1: Guest bill**

In `includes/app/bill.php`, replace this closure:

```php
$__who = function (array $r, string $nameKey, string $leadKey): string {
    $n = trim((string)($r[$nameKey] ?? ''));
    if ($n === '') return '';
    return guest_display_name(['passport_name' => $n]) . (!empty($r[$leadKey]) ? ' (lead)' : '');
};
```

with:

```php
// An unnamed lead falls back to the booking name rather than showing nothing.
$__bookName = (string)($hold['guest_name'] ?? '');
$__who = function (array $r, string $nameKey, string $leadKey) use ($__bookName): string {
    $isLead = !empty($r[$leadKey]);
    $n = trim((string)($r[$nameKey] ?? ''));
    if ($n === '' && !$isLead) return '';
    return attributed_display_name($n, $isLead, $__bookName) . ($isLead ? ' (lead)' : '');
};
```

- [ ] **Step 2: Admin bill**

In `admin/_ws_bill.php`, replace both occurrences of this pattern — there are two, one for request lines and one for manual items:

```php
<?= e(guest_display_name(['passport_name'=>$l['requested_by_name']])) ?><?= !empty($l['requested_by_is_lead']) ? ' (lead)' : '' ?>
```

with:

```php
<?= e(attributed_display_name((string)$l['requested_by_name'], !empty($l['requested_by_is_lead']), (string)($hold['guest_name'] ?? ''))) ?><?= !empty($l['requested_by_is_lead']) ? ' (lead)' : '' ?>
```

and:

```php
<?= e(guest_display_name(['passport_name'=>$it['guest_name']])) ?>
```

with:

```php
<?= e(attributed_display_name((string)$it['guest_name'], !empty($it['guest_is_lead']), (string)($hold['guest_name'] ?? ''))) ?>
```

Leave the guest-picker `<option>` at line 76 alone — it lists `checkin_guests` rows for selection, not attribution of an existing charge.

- [ ] **Step 3: Admin requests**

In `admin/_ws_requests.php`, replace:

```php
Requested by <?= e(guest_display_name(['passport_name'=>$a['requested_by_name']])) ?><?= !empty($a['requested_by_is_lead']) ? ' (lead)' : '' ?>
```

with:

```php
Requested by <?= e(attributed_display_name((string)$a['requested_by_name'], !empty($a['requested_by_is_lead']), (string)($hold['guest_name'] ?? ''))) ?><?= !empty($a['requested_by_is_lead']) ? ' (lead)' : '' ?>
```

- [ ] **Step 4: Verify**

Run: `php -l includes/app/bill.php && php -l admin/_ws_bill.php && php -l admin/_ws_requests.php`
Expected: no syntax errors.

**Check one thing carefully and report it:** `admin/_ws_requests.php` and `admin/_ws_bill.php` wrap their attribution in `if (!empty($a['requested_by_name']))`. With the lead fallback, an unnamed lead has an empty `requested_by_name`, so that guard now hides the very case this task fixes. Read each guard and confirm whether it must be widened to `if (!empty($a['requested_by_name']) || !empty($a['requested_by_is_lead']))`. If so, widen it and say so in your report.

- [ ] **Step 5: Commit**

```bash
git add includes/app/bill.php admin/_ws_bill.php admin/_ws_requests.php
git commit -m "fix(portal): attribute an unnamed lead's charges by the booking name"
```

---

### Task 6: Resolve `$actor` in the portal

**Files:**
- Modify: `includes/booking.php` (new pure helper)
- Modify: `booking.php`
- Modify: `includes/app/messages.php`
- Modify: `js/booking-manage.js`
- Test: `tests/portal_logic.php`

- [ ] **Step 1: Write the failing tests for the pure resolver**

The spec calls for actor resolution to be asserted in `tests/portal_logic.php`. That only works if the rule is a function rather than inline template code — so it is one.

In `tests/portal_logic.php`, insert this immediately before the final `echo $failures ? …` line:

```php
// ── Portal actor resolution (pure) ──────────────────────────────────────────
$paLead  = ['id' => 7, 'passport_name' => 'Jessica Mwangi'];
$paBlank = ['id' => 7, 'passport_name' => ''];
$paCo    = ['id' => 9, 'passport_name' => 'Patrik Otieno'];

$a = portal_actor($paLead, null, false, 'Jessica Booking');
check('actor lead uses passport name', $a['name'] === 'Jessica Mwangi' && $a['first'] === 'Jessica');
check('actor lead is_lead true',       $a['is_lead'] === true && $a['guest_id'] === 7);

$a = portal_actor($paBlank, null, false, 'Jessica Booking');
check('actor unnamed lead uses booking', $a['name'] === 'Jessica Booking' && $a['first'] === 'Jessica');

$a = portal_actor(null, null, false, 'Jessica Booking');
check('actor no lead row still names',  $a['name'] === 'Jessica Booking' && $a['guest_id'] === null);

$a = portal_actor($paLead, $paCo, true, 'Jessica Booking');
check('actor co-guest uses own name',   $a['name'] === 'Patrik Otieno' && $a['first'] === 'Patrik');
check('actor co-guest is_lead false',   $a['is_lead'] === false && $a['guest_id'] === 9);

$a = portal_actor($paLead, ['id' => 9, 'passport_name' => ''], true, 'Jessica Booking');
check('actor unnamed co-guest stays blank', $a['name'] === '' && $a['first'] === '');
check('actor unnamed co-guest not lead',    $a['is_lead'] === false);
```

The last two matter: an unnamed co-guest must **not** inherit the booking name, or we would be back to greeting Patrik as Jessica.

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/portal_logic.php`
Expected: fatal — `Call to undefined function portal_actor()`.

- [ ] **Step 3: Add the pure resolver**

In `includes/booking.php`, insert this immediately after `attributed_display_name()` (added in Task 1):

```php
/**
 * Who is viewing the portal. Pure — callers do the fetching.
 *
 * A co-guest is named only by their own passport_name; they must never inherit
 * the booking name, which is the lead's. The lead falls back to it because a lead
 * has no passport_name unless the passport step is enabled.
 *
 * Returns ['guest_id' => int|null, 'is_lead' => bool, 'name' => string, 'first' => string].
 */
function portal_actor(?array $leadRow, ?array $meRow, bool $isCoGuest, string $bookingName): array {
    if ($isCoGuest && $meRow) {
        $name = trim((string)($meRow['passport_name'] ?? ''));
        $id   = (int)($meRow['id'] ?? 0);
        return ['guest_id' => $id ?: null, 'is_lead' => false, 'name' => $name,
                'first' => $name === '' ? '' : explode(' ', $name)[0]];
    }
    $name = trim((string)($leadRow['passport_name'] ?? ''));
    if ($name === '') $name = trim($bookingName);
    $id = (int)($leadRow['id'] ?? 0);
    return ['guest_id' => $id ?: null, 'is_lead' => true, 'name' => $name,
            'first' => $name === '' ? '' : explode(' ', $name)[0]];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/portal_logic.php`
Expected: all eight new `actor …` lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Initialise `$me` so it survives the co-guest branch**

`$me` is currently assigned only inside the co-guest `if` block. PHP function scope means it leaks out when that branch ran, but is undefined otherwise. Make it explicit.

In `booking.php`, replace:

```php
$isCoGuest = false;
```

with:

```php
$isCoGuest = false;
$me        = null;   // the co-guest's checkin_guests row when one resolved
```

- [ ] **Step 6: Resolve the actor**

In `booking.php`, find this line (near line 126):

```php
// The lead's gate lifts once they've submitted their own part (submitted_at) even
```

Insert this immediately **before** that comment:

```php
// ── Who is actually looking at this page? ───────────────────────────────────
// booking.php resolves a BOOKING; until now it never resolved an ACTOR, which is
// why a co-guest was greeted with the lead's name.
// Deliberately NOT resolve_portal_actor(): that calls checkin_ensure_lead_guest_id(),
// which INSERTs. Correct in a write endpoint, wrong on every page view — so this
// reads the lead row if it exists and writes nothing.
$actor = ['guest_id' => null, 'is_lead' => true, 'name' => '', 'first' => ''];
if ($hold) {
    $__leadRow = (!$isCoGuest && checkin_supported()) ? checkin_lead_guest($holdId) : null;
    $actor = portal_actor($__leadRow, $me, $isCoGuest, (string)($hold['guest_name'] ?? ''));
}
```

- [ ] **Step 7: Greet the actual viewer**

In `booking.php`, replace:

```php
    $__first = trim((string)($hold['guest_name'] ?? ''));
    $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest';
```

with:

```php
    $__first = $actor['first'] !== '' ? $actor['first'] : 'guest';
```

- [ ] **Step 8: Tell "my" messages from another guest's**

In `includes/app/messages.php`, replace this line:

```php
  <?php foreach ($__msgs as $__m): $__me = $__m['sender'] === 'guest'; ?>
```

with:

```php
  <?php foreach ($__msgs as $__m): $__me = $__m['sender'] === 'guest';
        // On a shared booking every guest message is sender='guest'. Bubble
        // alignment still keys on that, but the LABEL distinguishes mine from
        // a co-guest's, which is the whole point of attribution.
        $__mine = $__me && (int)($__m['sender_guest_id'] ?? 0) === (int)($actor['guest_id'] ?? -1); ?>
```

Then replace the label expression:

```php
<?= $__me ? e(trim((string)($__m['sender_name'] ?? '')) !== '' ? guest_display_name(['passport_name'=>$__m['sender_name']]) : 'You') : 'Concierge' ?>
```

with:

```php
<?= !$__me ? 'Concierge' : ($__mine ? 'You' : e(attributed_display_name((string)($__m['sender_name'] ?? ''), !empty($__m['sender_is_lead']), (string)($__m['hold_guest_name'] ?? '')))) ?>
```

- [ ] **Step 9: Keep the live poll in step with the server render**

**This step is not optional cosmetics — without it the two disagree.** `js/booking-manage.js:109` computes `mine` as `m.sender === meSender`, i.e. *any* guest message. Line 121 then renders `m.sender_name || 'You'`. Task 3 starts populating `sender_name` for the lead too, so after that change a guest's **own** polled message would say "Jessica" where the server-rendered one says "You". CLAUDE.md requires the initial render and appended bubbles to be identical.

First, publish the viewer's guest id to the client. In `includes/app/messages.php`, replace:

```php
     data-me="guest"
```

with:

```php
     data-me="guest"
     data-me-guest="<?= (int)($actor['guest_id'] ?? 0) ?>"
```

Then in `js/booking-manage.js`, replace:

```js
    var meSender = thread.dataset.me || 'guest';
```

with:

```js
    var meSender = thread.dataset.me || 'guest';
    var meGuest  = parseInt(thread.dataset.meGuest || '0', 10) || 0;
```

And replace:

```js
      var mine = m.sender === meSender;
```

with:

```js
      var mine = m.sender === meSender;
      // On a shared booking every guest message has sender='guest'. Alignment
      // still keys on that; authorship needs the guest id. Matches the server
      // render in includes/app/messages.php.
      var authored = mine && (!meGuest || !m.guest_id || m.guest_id === meGuest);
```

And replace:

```js
      meta.textContent = (mine ? (m.sender_name || 'You') : 'Concierge') + ' · ' + m.time_label;
```

with:

```js
      meta.textContent = (mine ? (authored ? 'You' : (m.sender_name || 'Guest')) : 'Concierge') + ' · ' + m.time_label;
```

The `!meGuest || !m.guest_id` fallbacks keep today's behaviour on an unmigrated database, where neither id is available.

- [ ] **Step 10: Verify**

Run: `php -l booking.php && php -l includes/app/messages.php`
Expected: no syntax errors.

Run: `node --check js/booking-manage.js`
Expected: no output.

Run: `php tests/checkin_logic.php && php tests/portal_logic.php`
Expected: both `ALL PASS`.

**Reason about and report on these:**
- **i.** `$actor` is resolved inside `if ($hold)`. Trace every view include in `booking.php` and confirm none can run with `$hold` falsy. If one can, `$actor` is the unresolved default (safe) rather than undefined — say what you find.
- **ii.** Confirm `checkin_lead_guest()` adds at most one query per page load and does **not** insert. Read it; it is not the same function as `checkin_ensure_lead_guest_id()`.
- **iii.** On the check-in view, `$actor` is resolved but `includes/app/checkin.php` has its own `$first` from `$hold['guest_name']`. That view is lead-only (co-guests are routed to `checkin-guest.php` and exit earlier), so it is correct — verify that routing actually holds and report.

- [ ] **Step 11: Commit**

```bash
git add booking.php includes/app/messages.php js/booking-manage.js
git commit -m "fix(portal): greet the guest who is actually viewing, not the lead"
```

---

### Task 7: Party roster section on Home

**Files:**
- Modify: `js/checkin-wizard.js`, `js/booking-manage.js` (move the copy handler)
- Create: `includes/app/_party.php`
- Modify: `includes/app/home.php`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Move the document-level copy handler to the portal-wide script**

**Do this first — the roster's Copy button is dead without it.** The document-level `.ci-copy` handler currently lives in `js/checkin-wizard.js`, which is loaded by exactly one place: `includes/app/checkin.php:404`, the check-in view. `js/booking-manage.js` is loaded by `booking.php:335` on *every* portal view. The roster lives on Home, so the handler has to move.

In `js/checkin-wizard.js`, delete this block (added by the previous group, near the bottom):

```js
  // The confirmation card sits outside #ciForm, so its Copy buttons need their
  // own delegation. Same behaviour as the in-form handler.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.classList || !t.classList.contains('ci-copy') || t.closest('#ciForm')) return;
    e.preventDefault();
    var inp = t.closest('.ci-linkrow').querySelector('input'); inp.select();
    try { navigator.clipboard.writeText(inp.value); } catch (_) { try { document.execCommand('copy'); } catch (__) {} }
    var o = t.textContent; t.textContent = 'Copied ✓'; setTimeout(function () { t.textContent = o; }, 1500);
  });
```

Leave the in-form `.ci-copy` handler inside the `#ciForm` click listener exactly as it is.

Then in `js/booking-manage.js`, add the same handler at the top level of the file — inside its outermost IIFE, before the `var thread = document.getElementById('bmThread');` block:

```js
  // Copy buttons for any .ci-linkrow outside the check-in form: the check-in
  // confirmation card and the party roster on Home. booking-manage.js loads on
  // every portal view; checkin-wizard.js only loads on the check-in view, so this
  // cannot live there. In-form buttons are skipped — checkin-wizard.js owns those.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.classList || !t.classList.contains('ci-copy') || t.closest('#ciForm')) return;
    e.preventDefault();
    var row = t.closest('.ci-linkrow'); if (!row) return;
    var inp = row.querySelector('input'); if (!inp) return;
    inp.select();
    try { navigator.clipboard.writeText(inp.value); } catch (_) { try { document.execCommand('copy'); } catch (__) {} }
    var o = t.textContent; t.textContent = 'Copied ✓'; setTimeout(function () { t.textContent = o; }, 1500);
  });
```

Note the added `if (!row) return;` / `if (!inp) return;` guards — now that this runs on every portal page it can see clicks the check-in page never produced.

- [ ] **Step 2: Create the partial**

Create `includes/app/_party.php`:

```php
<?php /** Party roster. Expects $hold, $ref, $actor. Rendered only for multi-adult bookings. */ ?>
<?php
$__pHid    = (int)$hold['id'];
$__pGuests = checkin_supported() ? fetch_checkin_guests($__pHid) : [];
$__pAdults = array_values(array_filter($__pGuests, fn($g) => empty($g['is_child'])));
$__pKids   = [];
foreach ($__pGuests as $__g) if (!empty($__g['is_child'])) $__pKids[(int)($__g['parent_guest_id'] ?? 0)][] = $__g;
$__pNeed   = max(1, (int)($hold['guest_count'] ?? 1));
$__pCfg    = checkin_config();
// Status chips only mean something when the booking actually requires check-in.
$__pShowStatus = checkin_required($hold);
// Only the lead hands out access to the booking.
$__pIsLead = !empty($actor['is_lead']);
?>
<h2 class="pa-h2">Your party</h2>
<p class="pa-sub"><?= $__pIsLead
    ? 'Everyone travelling on this booking. Share a link with anyone still to check in.'
    : 'Everyone travelling on this booking.' ?></p>

<?php if (!$__pAdults): ?>
<div class="pa-card"><div class="pa-card__body">
  <p class="pa-card__meta" style="display:block">Your party will appear here once check-in starts.</p>
</div></div>
<?php else: ?>
<div class="pa-card"><div class="pa-card__body" style="padding:4px 0">
  <?php foreach ($__pAdults as $__a):
      $__aid  = (int)($__a['id'] ?? 0);
      $__isMe = $__aid > 0 && $__aid === (int)($actor['guest_id'] ?? -1);
      $__ok   = checkin_guest_complete($__a, $__pCfg);
      $__mine = $__pKids[$__aid] ?? [];
  ?>
  <div class="pty-row">
    <div class="pty-row__main">
      <span class="pty-name"><?= e(checkin_guest_label($__a, $__pAdults)) ?></span>
      <?php if (!empty($__a['is_lead'])): ?><span class="pty-tag">Lead</span><?php endif; ?>
      <?php if ($__isMe): ?><span class="pty-tag pty-tag--me">You</span><?php endif; ?>
    </div>
    <?php if ($__pShowStatus): ?>
    <span class="ci-chip <?= $__ok ? 'ci-chip--ok' : '' ?>"><?= $__ok ? 'Checked in &#10003;' : 'Pending' ?></span>
    <?php endif; ?>
  </div>
  <?php if ($__mine): ?>
  <div class="pty-kids">
    <?php foreach ($__mine as $__c): ?><span class="ci-kid" style="padding-right:12px"><?= e((string)($__c['passport_name'] ?? 'Child')) ?></span><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($__pIsLead && $__pShowStatus && !$__ok && $__aid > 0 && ($__link = make_guest_pass_url($__pHid, $__aid)) !== ''): ?>
  <div class="ci-linkrow pty-link">
    <input class="ci-in" readonly value="<?= e($__link) ?>" onclick="this.select()">
    <button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if (count($__pAdults) < $__pNeed): ?>
<p class="pa-sub" style="margin-top:10px"><?= (int)($__pNeed - count($__pAdults)) ?> more guest<?= ($__pNeed - count($__pAdults)) === 1 ? '' : 's' ?> still to be added<?= $__pIsLead ? ' — add them from your check-in.' : '.' ?></p>
<?php endif; ?>
```

- [ ] **Step 3: Register the tab**

In `includes/app/home.php`, replace this block:

```php
$__sectabs = ['stay' => 'Your stay'];
if ($__homeBoard) $__sectabs['whatson'] = "What's on";
$__sectabs['calendar'] = 'Calendar';
$__sectabs['requests'] = 'Requests';
```

with:

```php
$__sectabs = ['stay' => 'Your stay'];
// Party only when there is a party — a solo booking gets no empty tab.
$__hasParty = max(1, (int)($hold['guest_count'] ?? 1)) > 1;
if ($__hasParty) $__sectabs['party'] = 'Party';
if ($__homeBoard) $__sectabs['whatson'] = "What's on";
$__sectabs['calendar'] = 'Calendar';
$__sectabs['requests'] = 'Requests';
```

Then find:

```php
<?php if (isset($__sectabs['whatson'])): ?>
<section class="pa-sec" data-sec="whatson" role="tabpanel">
  <?php include __DIR__ . '/_greeting_board.php'; ?>
</section>
<?php endif; ?>
```

and insert this immediately **before** it:

```php
<?php if ($__hasParty): ?>
<section class="pa-sec" data-sec="party" role="tabpanel">
  <?php include __DIR__ . '/_party.php'; ?>
</section>
<?php endif; ?>
```

- [ ] **Step 4: Add the styles**

In `css/portal-app.css`, find this line:

```css
.ci-other__row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 2px;font-size:14px}
```

Insert this immediately after it:

```css
/* ── Party roster ──────────────────────────────────────────────────────────── */
.pty-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid var(--pa-line)}
.pty-row:last-child{border-bottom:0}
.pty-row__main{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap}
.pty-name{font-weight:600;color:var(--pa-ink)}
.pty-tag{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px;border-radius:999px;background:var(--pa-cream);color:var(--pa-muted)}
.pty-tag--me{background:var(--pa-teal);color:#fff}
.pty-kids{padding:0 16px 10px;display:flex;flex-wrap:wrap;gap:6px}
.pty-link{padding:0 16px 14px}
```

- [ ] **Step 5: Verify**

Run: `php -l includes/app/_party.php && php -l includes/app/home.php`
Run: `node --check js/checkin-wizard.js && node --check js/booking-manage.js`
Expected: no syntax errors.

**Do NOT attempt browser verification** — I will do the walkthrough myself in Task 8.

**Reason about and report on these:**
- **i.** `_party.php` uses `$actor`. Confirm `home.php` is only ever included from `booking.php` after `$actor` is set. Grep for every `include` of `home.php`.
- **ii.** Confirm Step 1 actually fixed the dead-button problem: load `booking.php` in your head and verify that on the Home view the only script providing a `.ci-copy` handler is `booking-manage.js`, and that it is unconditionally included. Also confirm the check-in confirmation card still has a working Copy button now that the handler moved out of `checkin-wizard.js`.
- **iii.** A co-guest viewing the roster: confirm no share links render for them, and that their own row gets the **You** badge.

- [ ] **Step 6: Commit**

```bash
git add js/checkin-wizard.js js/booking-manage.js includes/app/_party.php includes/app/home.php css/portal-app.css
git commit -m "feat(portal): party roster on Home with lead-only re-share links"
```

---

### Task 8: Full verification

**Files:** none — this task only runs and observes.

- [ ] **Step 1: Run every suite**

```bash
for f in tests/*_logic.php; do printf "%-32s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done
```

Expected: all `ALL PASS` except `team_logic.php`, which has one known pre-existing failure (`owner: home = dashboard`) that also fails on `master`.

- [ ] **Step 2: Lint everything touched**

```bash
for f in booking.php includes/booking.php includes/checkin.php includes/app/_party.php \
         includes/app/home.php includes/app/messages.php includes/app/bill.php \
         includes/app/checkin.php api/booking-message.php admin/messages.php \
         admin/_ws_messages.php admin/_ws_bill.php admin/_ws_requests.php; do php -l "$f"; done
```

Expected: `No syntax errors detected` thirteen times.

- [ ] **Step 3: Report**

Summarise what passed, what did not, and `git log --oneline` for the tasks. Do **not** claim success for anything you did not actually run.

---

## Notes for the implementer

- **`$val()` in the portal views already escapes.** Never wrap it in `e()`.
- **`$hold` may be `false`** early in `booking.php` — every new read guards with `?? ''` or sits inside `if ($hold)`.
- **Do not touch `resolve_portal_actor()`.** The API endpoints depend on its insert-if-missing behaviour; only the read path avoids it.
- **Do not touch the code-only lookup** (`booking.php:61-85`). Its privilege level was deliberately retained.
- **Do not change bubble alignment** in `includes/app/messages.php`. Only the label changes.
