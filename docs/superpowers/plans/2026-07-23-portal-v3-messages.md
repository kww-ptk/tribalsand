# Portal v3 — Concierge-first + Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Land the portal on Concierge, merge Booking+Stay into Stay, add guest↔staff Messages (per-request + general threads), and remove Turnstile from portal guest flows only.

**Architecture:** Reuse `booking_addons`. Add `booking_messages` (thread = messages sharing `hold_id`+`addon_id`; `addon_id` NULL = general thread). Guest sends via a new ref-gated endpoint (no Turnstile); admin replies from a new admin page (auth+CSRF). Views become concierge/activities/messages/stay; default concierge.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via `db_query()`, vanilla CSS/JS.

---

## Task 1: Migration + schema

**Files:** Create `db/migrations/add_messages.sql`; Modify `db/schema.sql`.

- [ ] **Step 1: Write `db/migrations/add_messages.sql`**
```sql
-- Migration: guest ↔ staff messages (per-request + general threads)
-- Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS booking_messages (
    id            SERIAL PRIMARY KEY,
    hold_id       INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    addon_id      INT REFERENCES booking_addons(id) ON DELETE CASCADE,  -- NULL = general thread
    sender        TEXT NOT NULL CHECK (sender IN ('guest','admin')),
    body          TEXT NOT NULL,
    read_by_guest BOOLEAN NOT NULL DEFAULT FALSE,
    read_by_admin BOOLEAN NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_bmsg_thread ON booking_messages (hold_id, addon_id, created_at);
```

- [ ] **Step 2: Apply locally**
Run: `php -r "require 'includes/db.php'; db()->exec(file_get_contents('db/migrations/add_messages.sql')); echo 'ok';"` → `ok`. Re-run once → still `ok`.

- [ ] **Step 3: Verify**
Run: `php -r "require 'includes/db.php'; var_dump(db_query('SELECT COUNT(*) FROM booking_messages')->fetchColumn());"` → `int(0)`, no error.

- [ ] **Step 4: Append the same CREATE TABLE + INDEX to the end of `db/schema.sql`** (booking-related tables live in migrations; append here for fresh installs, matching how `guest_board_posts` was appended).

- [ ] **Step 5: Commit**
```bash
git add db/migrations/add_messages.sql db/schema.sql
git commit -m "feat(messages): booking_messages table + migration"
```

---

## Task 2: Message helpers + tests

**Files:** Modify `includes/booking.php`, `tests/portal_logic.php`.

- [ ] **Step 1: Add helpers to `includes/booking.php`** (after `fetch_booking_addons`)
```php
/** Threads for a guest: the general thread + one per request, with latest message + unread-by-guest count. */
function fetch_message_threads(int $holdId): array {
    $threads = [['addon_id'=>null,'kind'=>'general','details'=>'','status'=>'','tour_name'=>null]];
    $addons = db_query(
        "SELECT ba.id AS addon_id, ba.kind, ba.details, ba.status, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :h ORDER BY ba.created_at DESC", [':h'=>$holdId]
    )->fetchAll();
    foreach ($addons as $a) { $threads[] = $a; }
    foreach ($threads as &$th) {
        $aid  = $th['addon_id'];
        $cond = $aid === null ? 'addon_id IS NULL' : 'addon_id = :aid';
        $p    = [':h'=>$holdId]; if ($aid !== null) $p[':aid'] = $aid;
        $last = db_query("SELECT body, sender, created_at FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at DESC LIMIT 1", $p)->fetch();
        $th['last_body']    = $last['body'] ?? '';
        $th['last_at']      = $last['created_at'] ?? null;
        $th['unread_guest'] = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND $cond AND sender='admin' AND read_by_guest=FALSE", $p)->fetchColumn();
    }
    unset($th);
    return $threads;
}

/** All messages in one thread, oldest → newest. */
function fetch_thread_messages(int $holdId, ?int $addonId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    return db_query("SELECT * FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at ASC", $p)->fetchAll();
}

/** Mark a thread's admin messages read by the guest. */
function mark_thread_read_by_guest(int $holdId, ?int $addonId): void {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    db_query("UPDATE booking_messages SET read_by_guest=TRUE WHERE hold_id=:h AND $cond AND sender='admin' AND read_by_guest=FALSE", $p);
}

/** Total admin messages unread by this guest (nav badge). */
function count_unread_guest(int $holdId): int {
    return (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND sender='admin' AND read_by_guest=FALSE", [':h'=>$holdId])->fetchColumn();
}

/** Admin: all threads across holds, unread-by-admin first, with guest/venue context. */
function fetch_admin_threads(): array {
    return db_query(
        "SELECT m.hold_id, m.addon_id,
                h.guest_name, v.name AS venue_name,
                ba.kind, ba.details, ba.status, t.name AS tour_name,
                MAX(m.created_at) AS last_at,
                SUM(CASE WHEN m.sender='guest' AND m.read_by_admin=FALSE THEN 1 ELSE 0 END) AS unread_admin,
                (SELECT body FROM booking_messages m2 WHERE m2.hold_id=m.hold_id AND ((m2.addon_id IS NULL AND m.addon_id IS NULL) OR m2.addon_id=m.addon_id) ORDER BY m2.created_at DESC LIMIT 1) AS last_body
         FROM booking_messages m
         JOIN holds h  ON h.id = m.hold_id
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         LEFT JOIN booking_addons ba ON ba.id = m.addon_id
         LEFT JOIN tours t ON t.id = ba.tour_id
         GROUP BY m.hold_id, m.addon_id, h.guest_name, v.name, ba.kind, ba.details, ba.status, t.name
         ORDER BY unread_admin DESC, last_at DESC"
    )->fetchAll();
}

/** Admin: total guest messages unread by staff (nav badge). */
function count_unread_admin(): int {
    return (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE sender='guest' AND read_by_admin=FALSE")->fetchColumn();
}

/** Mark a thread's guest messages read by admin. */
function mark_thread_read_by_admin(int $holdId, ?int $addonId): void {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    db_query("UPDATE booking_messages SET read_by_admin=TRUE WHERE hold_id=:h AND $cond AND sender='guest' AND read_by_admin=FALSE", $p);
}

/** Human title for a thread row (from fetch_message_threads / fetch_admin_threads). */
function thread_title(array $th): string {
    if (($th['addon_id'] ?? null) === null) return 'Message the team';
    $label = addon_label($th); // tour_name + details
    $kind  = ucfirst((string)($th['kind'] ?? 'Request'));
    return $label !== '' ? "{$kind} · {$label}" : $kind;
}
```

- [ ] **Step 2: Tests in `tests/portal_logic.php`** (before the final summary)
```php
// ── messages ─────────────────────────────────────────────────
$mhid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($mhid) {
    db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,NULL,'guest','Hello team',TRUE,FALSE)", [':h'=>$mhid]);
    db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,NULL,'admin','Hi there',FALSE,TRUE)", [':h'=>$mhid]);
    $threads = fetch_message_threads($mhid);
    check('threads include the general thread', $threads[0]['addon_id'] === null);
    $gen = $threads[0];
    check('general thread unread_guest = 1', $gen['unread_guest'] === 1);
    check('general thread last message is the admin reply', $gen['last_body'] === 'Hi there');
    $msgs = fetch_thread_messages($mhid, null);
    check('thread has 2 messages in order', count($msgs) >= 2 && $msgs[0]['body'] === 'Hello team');
    check('count_unread_guest ≥ 1 before read', count_unread_guest($mhid) >= 1);
    mark_thread_read_by_guest($mhid, null);
    check('unread_guest cleared after read', count_unread_guest($mhid) === 0);
    check('count_unread_admin ≥ 1 (guest msg unread by admin)', count_unread_admin() >= 1);
    db_query("DELETE FROM booking_messages WHERE hold_id=:h", [':h'=>$mhid]);
}
```

- [ ] **Step 3: Run + lint** — `php tests/portal_logic.php` → `ALL PASS`; `php -l includes/booking.php`.

- [ ] **Step 4: Commit**
```bash
git add includes/booking.php tests/portal_logic.php
git commit -m "feat(messages): thread helpers (guest + admin) + tests"
```

---

## Task 3: Guest message endpoint + Turnstile removal from endpoints

**Files:** Create `api/booking-message.php`; Modify `api/booking-addon.php`, `api/booking-change.php`.

- [ ] **Step 1: Create `api/booking-message.php`**
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$str  = fn($v) => is_scalar($v) ? trim((string)$v) : '';

$hold = resolve_booking_by_ref($str($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }

$body = $str($data['body'] ?? '');
if ($body === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please type a message.'])); }
if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);

$addonId = null;
if (($data['addon_id'] ?? '') !== '' && $data['addon_id'] !== null) {
    $addonId = (int)$data['addon_id'];
    $own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$addonId, ':h'=>$hold['id']])->fetchColumn();
    if (!$own) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown request.'])); }
}

// Rate limit: max 20 messages per hold / 10 min
$cnt = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND created_at > NOW() - INTERVAL '10 minutes'", [':h'=>$hold['id']])->fetchColumn();
if ($cnt >= 20) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many messages. Please wait a few minutes.'])); }

try {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
        [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body]
    );
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[booking-message] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not send your message. Please try again.']);
}
```
(No Turnstile — ref-gated + rate-limited, matching the spec.)

- [ ] **Step 2: Remove Turnstile from `api/booking-addon.php`**
Delete the block:
```php
if (!verify_captcha($str($data['cf-turnstile-response'] ?? ''), $ip)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Security check failed. Please try again.']));
}
```
Leave the ref check, status check, rate-limit, and the rest intact. (`$ip` may become unused; leave the `$ip = client_ip();` line — it's harmless — or remove it if nothing else uses it. Grep the file: if `$ip` is now unused, delete the `$ip = client_ip();` line too.)

- [ ] **Step 3: Remove Turnstile from `api/booking-change.php`**
Find and delete the equivalent `if (!verify_captcha(...)) { ... }` block there. Keep ref/status/rate-limit. Same `$ip` cleanup rule.

- [ ] **Step 4: Lint** — `php -l api/booking-message.php && php -l api/booking-addon.php && php -l api/booking-change.php`.

- [ ] **Step 5: Commit**
```bash
git add api/booking-message.php api/booking-addon.php api/booking-change.php
git commit -m "feat(messages): guest message endpoint; drop Turnstile from portal endpoints"
```

---

## Task 4: Nav + routing (concierge-first, 4 tabs, unread badge)

**Files:** Modify `booking.php`, `includes/app/nav.php`; Delete `includes/app/home.php`.

- [ ] **Step 1: `booking.php` — view whitelist + default**
Replace the `$view = ...` line (currently whitelisting home/concierge/stay/manage/activities, default home) with:
```php
$view = in_array($_GET['view'] ?? '', ['concierge','activities','messages','stay'], true) ? $_GET['view'] : 'concierge';
```
(Legacy `home`/`manage` fall through to `concierge`.)

- [ ] **Step 2: `booking.php` — titles + status-header gating + view switch**
Replace `$__titles` with:
```php
$__titles = ['concierge'=>'Concierge','activities'=>'Activities','messages'=>'Messages','stay'=>'Your stay'];
```
Gate the status-header include so it only shows on concierge + stay:
```php
<?php if (in_array($view, ['concierge','stay'], true)): ?>
<?php include __DIR__ . '/includes/app/status-header.php'; ?>
<?php endif; ?>
```
Replace the whole view switch (`if ($view==='home') … elseif 'manage' …`) with:
```php
    <?php if ($view === 'concierge'): ?>
      <?php include __DIR__ . '/includes/app/concierge.php'; ?>
    <?php elseif ($view === 'activities'): ?>
      <?php include __DIR__ . '/includes/app/activities.php'; ?>
    <?php elseif ($view === 'messages'): ?>
      <?php include __DIR__ . '/includes/app/messages.php'; ?>
    <?php elseif ($view === 'stay'): ?>
      <?php include __DIR__ . '/includes/app/stay.php'; ?>
      <?php /* Booking management (change is inside stay.php); cancel below */ ?>
      <?php if ($can_cancel): ?>
      <div class="pa-card" style="padding:16px">
        <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
        <p style="margin:0 0 20px;font-size:14px;color:var(--pa-muted);line-height:1.65">If your plans have changed you can cancel now. The dates will be freed and you will receive a cancellation confirmation by email.</p>
        <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking? This cannot be undone.')">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="ref" value="<?= e($ref) ?>">
          <button type="submit" class="pa-btn pa-btn--danger">Cancel My Booking</button>
        </form>
      </div>
      <?php elseif ($cancel_blocked_reason): ?>
      <div class="pa-card" style="padding:16px">
        <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
        <p style="margin:0 0 20px;font-size:14px;color:var(--pa-muted);line-height:1.65"><?= e($cancel_blocked_reason) ?></p>
        <p style="margin:0;font-size:14px;color:var(--pa-muted)">Email us at <a href="mailto:reservations@tribalsand.com" style="color:var(--pa-teal)">reservations@tribalsand.com</a> or call <a href="tel:+254115115247" style="color:var(--pa-teal)">+254 115 115 247</a></p>
      </div>
      <?php endif; ?>
    <?php endif; ?>
```
(Removes the `home` and `manage` branches; relocates the cancel section under `stay`. The `is_file(...)` guards are dropped now that these includes are guaranteed to exist.)

- [ ] **Step 3: `includes/app/nav.php` — 4 tabs + Messages unread badge**
Replace the file with:
```php
<?php /** Bottom tab bar. Expects $ref, $view, $hold. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__svg = fn(string $paths) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$__unread = 0;
try { $__unread = count_unread_guest((int)$hold['id']); } catch (Throwable $e) { $__unread = 0; }
$__tabs = [
  'concierge'  => ['Concierge',  $__svg('<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 20a2 2 0 0 0 4 0"/>')],
  'activities' => ['Activities', $__svg('<circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>')],
  'messages'   => ['Messages',   $__svg('<path d="M4 5h16v11H8l-4 4z"/>')],
  'stay'       => ['Stay',       $__svg('<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 9h16"/><path d="M8 3v4M16 3v4"/>')],
];
?>
<nav class="pa-nav">
  <?php foreach ($__tabs as $__k => $__t): ?>
  <a class="pa-nav__item <?= $view === $__k ? 'is-active' : '' ?>" href="<?= e($__u) ?>&amp;view=<?= e($__k) ?>">
    <span class="pa-nav__ico" style="position:relative;display:inline-block">
      <?= $__t[1] ?>
      <?php if ($__k === 'messages' && $__unread > 0): ?><span class="pa-nav__badge"><?= (int)$__unread ?></span><?php endif; ?>
    </span><?= e($__t[0]) ?>
  </a>
  <?php endforeach; ?>
</nav>
```
Add to `css/portal-app.css`:
```css
.pa-nav__badge{position:absolute;top:-6px;right:-10px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#dc2626;color:#fff;font-size:10px;line-height:16px;text-align:center;font-weight:600;}
```

- [ ] **Step 4: Delete `includes/app/home.php`**
Run: `git rm includes/app/home.php`. Then grep to confirm nothing else includes it: `grep -rn "app/home.php" .` → only expected removals.

- [ ] **Step 5: Lint** — `php -l booking.php && php -l includes/app/nav.php`.

- [ ] **Step 6: Commit**
```bash
git add booking.php includes/app/nav.php css/portal-app.css
git commit -m "feat(portal): concierge-first routing, 4-tab nav with Messages badge, remove Home/Booking"
```

---

## Task 5: Concierge becomes home (greeting + board; drop back link / recent list / Turnstile)

**Files:** Modify `includes/app/concierge.php`.

- [ ] **Step 1: Prepend greeting + guest board; remove the back link.**
In `includes/app/concierge.php`, at the top PHP block add the board fetch (mirror the old home.php):
```php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__board = fetch_guest_board($__venue); } catch (Throwable $e) { $__board = []; }
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion'];
$__first = trim((string)$hold['guest_name']); $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest';
```
Replace the current header block (the `<p ...>← Back to home</p>` + `<h2 class="pa-h2">Concierge</h2>` + sub) with a greeting, the board, then the Concierge heading:
```php
<div style="font-family:'Cormorant Garamond',serif;font-size:24px;margin:4px 0 12px">Karibu, <?= e($__first) ?></div>
<?php if ($__board): ?>
<div class="pa-grid" style="margin:0 0 16px">
  <?php foreach ($__board as $p): $bimg = trim((string)($p['image_filename'] ?? '')); ?>
  <div class="pa-card">
    <?php if ($bimg !== ''): ?><div class="pa-media" style="background-image:url('<?= e(storage_url($bimg)) ?>')"></div><?php endif; ?>
    <div class="pa-card__body">
      <span class="pa-tag <?= e($__tagClass[$p['category']] ?? '') ?>"><?= e($p['category']) ?></span>
      <p class="pa-card__title" style="margin-top:8px"><?= e($p['title']) ?></p>
      <?php if (($p['body'] ?? '') !== ''): ?><p class="pa-card__meta" style="display:block;margin-top:4px;line-height:1.5"><?= e($p['body']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<h2 class="pa-h2">Concierge</h2>
<p class="pa-sub">Tap what you need — our team confirms by return.</p>
```

- [ ] **Step 2: Remove the Turnstile widget from every concierge form.**
Delete each line `<div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" ...></div>` in this file (the free-text forms loop, the laundry form, the transfer form).

- [ ] **Step 3: Remove the on-page "Recent requests" block** (`<?php if ($__addons): ?> … Recent requests … <?php endif; ?>`) and the now-unused `$__addons = fetch_booking_addons(...)` line — requests live in Messages now.

- [ ] **Step 4: Lint** — `php -l includes/app/concierge.php`.

- [ ] **Step 5: Commit**
```bash
git add includes/app/concierge.php
git commit -m "feat(portal): concierge is home — greeting + guest board; drop back link, recent list, Turnstile"
```

---

## Task 6: Stay merge (change form + cancel; drop Turnstile; delete manage include)

**Files:** Modify `includes/app/stay.php`; Delete `includes/booking-manage-actions.php`.

- [ ] **Step 1: Append the change-request form to `includes/app/stay.php`.**
After the existing stay-info cards / empty-state, add (this is the change form from `booking-manage-actions.php`, minus Turnstile, only for actionable bookings):
```php
<?php if (in_array($status ?? '', ['pending','confirmed'], true)): ?>
<div class="pa-card">
  <div class="pa-card__body">
    <h2 class="pa-h2" style="font-size:18px">Request a change</h2>
    <p class="pa-sub">Update your dates or guest count — our team confirms availability by email.</p>
    <form data-bm action="/api/booking-change.php">
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <label class="pa-field">New check-in (optional)<input type="date" name="check_in"></label>
      <label class="pa-field">New check-out (optional)<input type="date" name="check_out"></label>
      <label class="pa-field">Guests (optional)<input type="number" name="guests" min="1" max="30"></label>
      <label class="pa-field">Notes<textarea name="note" rows="3" placeholder="Tell us what you’d like to change"></textarea></label>
      <button type="submit" class="pa-btn pa-btn--primary">Send change request</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
  </div>
</div>
<?php endif; ?>
```
(Note: no `cf-turnstile` div. `$ref` and `$status` are in scope from booking.php.) Remove any "← Back to home" link at the top of stay.php if present (nav handles it).

- [ ] **Step 2: Delete `includes/booking-manage-actions.php`.**
Run: `git rm includes/booking-manage-actions.php`. Grep to confirm nothing includes it any more: `grep -rn "booking-manage-actions" .` → no live references (the `manage` branch was removed in Task 4).

- [ ] **Step 3: Lint** — `php -l includes/app/stay.php`.

- [ ] **Step 4: Commit**
```bash
git add includes/app/stay.php
git rm includes/booking-manage-actions.php
git commit -m "feat(portal): merge Booking into Stay (change form + cancel); drop Turnstile + manage include"
```

---

## Task 7: Guest Messages view

**Files:** Create `includes/app/messages.php`.

- [ ] **Step 1: Create `includes/app/messages.php`**
```php
<?php /** Messages view. Expects $hold, $ref. */ ?>
<?php
$__hid = (int)$hold['id'];
$__threadParam = $_GET['thread'] ?? null;               // 'general' | "<addon_id>" | null (list)
$__u = '/booking.php?ref=' . urlencode($ref) . '&amp;view=messages';

if ($__threadParam === null):
    // ── Thread list ──
    $__threads = fetch_message_threads($__hid);
?>
<h2 class="pa-h2">Messages</h2>
<p class="pa-sub">Chat with our team about your requests.</p>
<?php foreach ($__threads as $__th):
    $__tid   = $__th['addon_id'] === null ? 'general' : (int)$__th['addon_id'];
    $__title = thread_title($__th);
    $__snip  = trim((string)($__th['last_body'] ?? ''));
?>
<a class="pa-card" style="display:block;text-decoration:none;color:inherit" href="<?= $__u ?>&amp;thread=<?= e((string)$__tid) ?>">
  <div class="pa-card__body" style="display:flex;align-items:center;gap:10px">
    <div style="flex:1;min-width:0">
      <p class="pa-card__title"><?= e($__title) ?></p>
      <p class="pa-card__meta" style="display:block;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $__snip !== '' ? e($__snip) : '<span style="color:var(--pa-muted)">No messages yet — say hello</span>' ?></p>
    </div>
    <?php if (($__th['addon_id'] ?? null) !== null && !empty($__th['status'])): ?><span class="pa-pill pa-pill--<?= e($__th['status']) ?>"><?= e(addon_status_label($__th['status'])) ?></span><?php endif; ?>
    <?php if (($__th['unread_guest'] ?? 0) > 0): ?><span class="pa-nav__badge" style="position:static"><?= (int)$__th['unread_guest'] ?></span><?php endif; ?>
  </div>
</a>
<?php endforeach; ?>

<?php else:
    // ── Conversation ──
    $__addonId = $__threadParam === 'general' ? null : (int)$__threadParam;
    if ($__addonId !== null) {
        $__own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$__addonId, ':h'=>$__hid])->fetchColumn();
        if (!$__own) { echo '<p class="pa-sub">Conversation not found.</p>'; return; }
    }
    mark_thread_read_by_guest($__hid, $__addonId);
    $__msgs = fetch_thread_messages($__hid, $__addonId);
?>
<p style="margin:0 0 14px"><a href="<?= $__u ?>" class="pa-back">&larr; All messages</a></p>
<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
  <?php if (!$__msgs): ?><p class="pa-sub">No messages yet. Send the first one below.</p><?php endif; ?>
  <?php foreach ($__msgs as $__m): $__me = $__m['sender'] === 'guest'; ?>
  <div style="max-width:80%;<?= $__me ? 'align-self:flex-end;background:var(--pa-teal-d);color:#fff;border-radius:12px 12px 2px 12px' : 'align-self:flex-start;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px 12px 12px 2px' ?>;padding:9px 12px;font-size:14px;line-height:1.5">
    <?= e($__m['body']) ?>
    <div style="font-size:11px;margin-top:4px;<?= $__me ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)' ?>"><?= $__me ? 'You' : 'Concierge' ?> · <?= e(date('j M, H:i', strtotime((string)$__m['created_at']))) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<form data-bm action="/api/booking-message.php">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="addon_id" value="<?= $__addonId === null ? '' : (int)$__addonId ?>">
  <label class="pa-field">Your message<textarea name="body" rows="3" required placeholder="Type a message…"></textarea></label>
  <button type="submit" class="pa-btn pa-btn--primary">Send</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>
<?php endif; ?>
```
Note: `js/booking-manage.js` (the `data-bm` handler) reloads on success, so a sent message re-renders the conversation. Confirm it reloads (read the file); if it only clears the form, the new message still appears after a manual reload — acceptable, but prefer reload.

- [ ] **Step 2: Lint** — `php -l includes/app/messages.php`.

- [ ] **Step 3: Commit**
```bash
git add includes/app/messages.php
git commit -m "feat(messages): guest Messages view (thread list + conversation)"
```

---

## Task 8: Admin Messages page

**Files:** Create `admin/messages.php`; Modify `admin/_layout.php`, `admin/concierge-desk.php`.

- [ ] **Step 1: Create `admin/messages.php`**
```php
<?php
/** Admin: guest ↔ staff messages. Thread list + conversation + reply (PRG). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$pageTitle  = 'Messages';
$activeMenu = 'messages';

$holdId  = isset($_GET['hold']) ? (int)$_GET['hold'] : 0;
$threadP = $_GET['thread'] ?? null;
$addonId = ($threadP === null || $threadP === 'general') ? null : (int)$threadP;
$inThread = $holdId > 0 && $threadP !== null;

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ph = (int)($_POST['hold_id'] ?? 0);
    $pa = ($_POST['addon_id'] ?? '') === '' ? null : (int)$_POST['addon_id'];
    $body = trim((string)($_POST['body'] ?? ''));
    if ($ph && $body !== '') {
        if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
        db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,:a,'admin',:b,FALSE,TRUE)",
            [':h'=>$ph, ':a'=>$pa, ':b'=>$body]);
        audit_log('booking_message.admin_reply', 'hold', $ph, '');
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Reply sent.'];
    }
    $q = '?hold=' . $ph . '&thread=' . ($pa === null ? 'general' : $pa);
    header('Location: /admin/messages.php' . $q); exit;
}

if ($inThread) { mark_thread_read_by_admin($holdId, $addonId); }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Messages</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$inThread):
  $threads = fetch_admin_threads();
?>
<div class="card"><div class="card__body" style="padding:0">
  <table class="data-table">
    <thead><tr><th>Guest</th><th>Thread</th><th>Latest</th><th>Unread</th></tr></thead>
    <tbody>
      <?php if (!$threads): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">No messages yet.</td></tr>
      <?php else: foreach ($threads as $t):
        $tid = $t['addon_id'] === null ? 'general' : (int)$t['addon_id'];
      ?>
      <tr>
        <td><strong><?= e($t['guest_name'] ?: 'Guest') ?></strong><br><span class="text-muted" style="font-size:12px"><?= e($t['venue_name'] ?? '') ?></span></td>
        <td><a href="?hold=<?= (int)$t['hold_id'] ?>&thread=<?= e((string)$tid) ?>"><?= e(thread_title($t)) ?></a></td>
        <td class="text-muted" style="font-size:13px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)($t['last_body'] ?? '')) ?></td>
        <td><?php if ((int)$t['unread_admin'] > 0): ?><span class="badge badge--orange"><?= (int)$t['unread_admin'] ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>

<?php else:
  $msgs = fetch_thread_messages($holdId, $addonId);
  $ctx  = db_query("SELECT h.guest_name FROM holds h WHERE h.id=:h", [':h'=>$holdId])->fetch();
?>
<p style="margin:0 0 14px"><a href="/admin/messages.php" class="btn-outline btn-sm">← All threads</a></p>
<div class="card"><div class="card__body">
  <p style="margin:0 0 12px;font-weight:600"><?= e($ctx['guest_name'] ?? 'Guest') ?></p>
  <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
    <?php if (!$msgs): ?><p class="text-muted">No messages in this thread yet.</p><?php endif; ?>
    <?php foreach ($msgs as $m): $adminMsg = $m['sender'] === 'admin'; ?>
    <div style="max-width:80%;<?= $adminMsg ? 'align-self:flex-end;background:#102F3A;color:#fff' : 'align-self:flex-start;background:#f3f4f6;color:#111' ?>;border-radius:12px;padding:9px 12px;font-size:14px;line-height:1.5">
      <?= e($m['body']) ?>
      <div style="font-size:11px;margin-top:4px;opacity:.7"><?= $adminMsg ? 'Staff' : 'Guest' ?> · <?= e(date('j M, H:i', strtotime((string)$m['created_at']))) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
    <input type="hidden" name="addon_id" value="<?= $addonId === null ? '' : (int)$addonId ?>">
    <textarea name="body" rows="3" required placeholder="Reply to the guest…" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px"></textarea>
    <button type="submit" class="btn-primary" style="margin-top:8px">Send reply</button>
  </form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Nav link in `admin/_layout.php`** (after the Concierge desk link), with an unread badge:
```php
      <a href="/admin/messages.php" class="sidebar__link <?= ($activeMenu??'')==='messages' ? 'is-active':'' ?>">Messages<?php $u=function_exists('count_unread_admin')?count_unread_admin():0; if($u>0): ?> <span class="badge badge--orange" style="margin-left:6px"><?= (int)$u ?></span><?php endif; ?></a>
```
Ensure `admin/_layout.php` has access to `count_unread_admin()` — it requires `includes/booking.php`? If not already required by the layout or the pages that include it, guard with `function_exists` (done above). If the badge count is always 0 because booking.php isn't loaded on some admin pages, that's acceptable (the link still works); prefer requiring `includes/booking.php` in `_layout.php` only if it's safe — otherwise leave the `function_exists` guard.

- [ ] **Step 3: Link from the Concierge Desk row into the thread.**
In `admin/concierge-desk.php`, in the Actions cell (or a new small cell), add a link per row:
```php
<a href="/admin/messages.php?hold=<?= (int)$a['hold_id'] ?>&thread=<?= (int)$a['id'] ?>" class="btn-outline btn-sm">Message</a>
```
Place it alongside the Accept/Done/Decline forms so staff can open the guest conversation for that request.

- [ ] **Step 4: Lint + smoke** — `php -l admin/messages.php && php -l admin/_layout.php && php -l admin/concierge-desk.php`; `php -r "\$_SERVER['REQUEST_METHOD']='GET'; require 'admin/messages.php';" 2>&1 | head -3` (login redirect/exit is fine, no fatal).

- [ ] **Step 5: Commit**
```bash
git add admin/messages.php admin/_layout.php admin/concierge-desk.php
git commit -m "feat(messages): admin Messages page + nav badge + desk link"
```

---

## Task 9: E2E + regression + cleanup

**Files:** none (verification only).

- [ ] **Step 1: Tests + lint** — `php tests/portal_logic.php && php tests/manage_logic.php && php tests/convert_logic.php` → three `ALL PASS`. `php -l` on every changed/new PHP file.

- [ ] **Step 2: Turnstile regression (public forms untouched)**
`grep -rl "cf-turnstile" includes/form-enquiry.php` → still matches; `grep -c "verify_captcha" api/submit-enquiry.php api/submit-contact.php api/submit-agency.php` → each ≥1. `grep -rn "cf-turnstile" includes/app/ api/booking-*.php` → NO matches (portal is clean).

- [ ] **Step 3: Browser E2E**
Start `php -S localhost:8765 router.php`; seed a confirmed hold (with venue) + a guest-board post. Then:
  - Visit `booking.php?ref=<REF>` (no view) → lands on **Concierge**: greeting + board + service tiles; bottom nav = Concierge/Activities/Messages/Stay (no Home/Booking); no `cf-turnstile` in the page source.
  - `&view=home` and `&view=manage` → both render Concierge (fallback).
  - Submit a laundry request → succeeds with no Turnstile.
  - **Messages** → shows "Message the team" + the laundry thread. Open the laundry thread → send "please collect at 9am" → it appears as a right-aligned bubble.
  - Simulate an admin reply: `INSERT INTO booking_messages (hold_id, addon_id, sender, body) VALUES (<hold>, <addon>, 'admin', 'Will do')`. Reload Messages list → unread dot; nav shows the Messages badge; open thread → admin bubble on the left; badge clears after viewing.
  - **Stay** → status card + stay info + Request-a-change form + cancel card, all on one tab.
  - Resize desktop → 3-col grids intact; nav centered.

- [ ] **Step 4: Clean up** — delete seeded hold, blocks, addons, board post, messages. Confirm baseline.

- [ ] **Step 5: Done** — if green, ready for final review.

---

## Self-Review Notes
- Spec coverage: migration (T1); helpers+tests (T2); endpoint + Turnstile-off (T3); routing/nav/home-removal (T4); concierge-home (T5); stay-merge (T6); guest messages (T7); admin messages (T8); verification (T9).
- Type consistency: `fetch_message_threads/fetch_thread_messages/mark_thread_read_by_guest/count_unread_guest/fetch_admin_threads/count_unread_admin/mark_thread_read_by_admin/thread_title` used consistently across messages.php, admin/messages.php, nav.php. `addon_id` NULL = general everywhere. Endpoint + admin insert the same columns.
- Reuse: change form reuses `/api/booking-change.php`; message send reuses the `data-bm` JS; admin reply reuses `_layout` + `csrf_field`/`audit_log`.
- Deferred: no email (in-app only, per decision); Messages read paths assume the table exists — nav badge is try/catch-guarded, but the Messages view itself needs the migration (called out for deploy).
