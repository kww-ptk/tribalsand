# Request Lifecycle & Notifications — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A status change auto-posts a message the guest is notified of (Messages-tab badge), the request thread bumps to the top and shows its current status, and opening a request thread shows the request's full info.

**Architecture:** Pure additions on the existing `booking_addons.status` + `booking_messages`. Helpers in `includes/booking.php` (post an admin message, status→template, fetch the addon for the header, reorder threads by activity); `admin/booking-request-action.php` posts the auto-message after a status update; the portal `includes/app/messages.php` renders a request header. No DB migration.

**Tech Stack:** Vanilla PHP 8.2, PDO `db_query()`. Portal classes `.pa-card`, `.pa-pill pa-pill--<status>`, `.pa-card__title/__meta`, `--pa-muted`. `format_price()` (services.php), `addon_label()`/`addon_status_label()` (booking.php).

**Conventions:** Escape output `e()`; ids/pax `(int)`, prices `(float)`. Prepared statements only. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Branch `feature/request-lifecycle` — no branch switch, no push.

---

## File map

| File | Change |
|------|--------|
| `includes/booking.php` | **Modify** — `post_admin_message`, `request_status_message`, `fetch_addon_for_thread`; reorder `fetch_message_threads` |
| `tests/portal_logic.php` | **Modify** — lifecycle helper + ordering tests |
| `admin/booking-request-action.php` | **Modify** — auto-post the status message |
| `includes/app/messages.php` | **Modify** — request header in the conversation |

---

## Task 1: Helpers + thread reorder (TDD)

**Files:**
- Modify: `includes/booking.php`
- Test: `tests/portal_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/portal_logic.php`, add this block immediately before the final summary/`echo` line:

```php
// ── request lifecycle: status templates, admin post, header, ordering ──
check('status msg: confirmed non-empty', request_status_message('confirmed') !== '');
check('status msg: declined non-empty',  request_status_message('declined')  !== '');
check('status msg: distinct per status', request_status_message('confirmed') !== request_status_message('completed'));
check('status msg: unknown is empty',    request_status_message('weird')     === '');

$rlHold = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($rlHold) {
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status) VALUES (:h,'other','ZZ RL A','requested')", [':h'=>$rlHold]);
    $rlA = (int)db()->lastInsertId();
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status) VALUES (:h,'other','ZZ RL B','requested')", [':h'=>$rlHold]);
    $rlB = (int)db()->lastInsertId();   // created after A (newer)

    $before = count_unread_guest($rlHold);
    post_admin_message($rlHold, $rlA, 'ZZ admin update');
    check('post_admin_message increments guest unread', count_unread_guest($rlHold) === $before + 1);

    $hdr = fetch_addon_for_thread($rlHold, $rlA);
    check('fetch_addon_for_thread returns the row', $hdr && (int)$hdr['id'] === $rlA);
    check('fetch_addon_for_thread is hold-scoped', fetch_addon_for_thread($rlHold + 999999, $rlA) === false);

    $threads = fetch_message_threads($rlHold);
    check('threads: general pinned first', $threads[0]['addon_id'] === null);
    $ids  = array_map(fn($t) => $t['addon_id'], array_slice($threads, 1));
    $posA = array_search($rlA, $ids, true);
    $posB = array_search($rlB, $ids, true);
    check('threads: messaged request bumps above a newer idle one', $posA !== false && $posB !== false && $posA < $posB);

    db_query("DELETE FROM booking_messages WHERE hold_id=:h AND body='ZZ admin update'", [':h'=>$rlHold]);
    db_query("DELETE FROM booking_addons WHERE id IN (:a,:b)", [':a'=>$rlA, ':b'=>$rlB]);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/portal_logic.php`
Expected: fatal `Call to undefined function request_status_message()` (or a FAIL block).

- [ ] **Step 3: Add the helpers to `includes/booking.php`**

Add near `seed_request_message()`:

```php
/** Post an admin (staff/system) message into a request/general thread. Unread for the guest. */
function post_admin_message(int $holdId, ?int $addonId, string $body): void {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'admin', :b, FALSE, TRUE)",
        [':h' => $holdId, ':a' => $addonId, ':b' => $body]
    );
}

/** Fixed auto-message posted when staff change a request's status. '' = post nothing. */
function request_status_message(string $status): string {
    return [
        'confirmed' => 'Confirmed ✓ — we’ll take care of it.',
        'completed' => 'Marked as done ✓',
        'declined'  => 'Sorry, we can’t fulfil this request.',
        'cancelled' => 'This request was cancelled.',
    ][$status] ?? '';
}

/** The addon behind a request thread (with tour name), hold-scoped — for the conversation header. */
function fetch_addon_for_thread(int $holdId, int $addonId): array|false {
    $r = db_query(
        "SELECT ba.*, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.id = :a AND ba.hold_id = :h",
        [':a' => $addonId, ':h' => $holdId]
    )->fetch();
    return $r ?: false;
}
```

- [ ] **Step 4: Reorder `fetch_message_threads()`**

In `includes/booking.php`, add `ba.created_at` to the addons SELECT — change:

```php
        "SELECT ba.id AS addon_id, ba.kind, ba.details, ba.status, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :h ORDER BY ba.created_at DESC", [':h'=>$holdId]
```

to:

```php
        "SELECT ba.id AS addon_id, ba.kind, ba.details, ba.status, ba.created_at, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :h ORDER BY ba.created_at DESC", [':h'=>$holdId]
```

Then replace the function's ending `unset($th);\n    return $threads;` with a general-pinned, activity-sorted return:

```php
    unset($th);

    // General thread stays pinned; request threads sort by most-recent activity
    // (last message, falling back to the request's creation time).
    $general = array_shift($threads);
    usort($threads, function ($a, $b) {
        $ka = (string)($a['last_at'] ?? $a['created_at'] ?? '');
        $kb = (string)($b['last_at'] ?? $b['created_at'] ?? '');
        return strcmp($kb, $ka); // most recent first
    });
    array_unshift($threads, $general);
    return $threads;
```

- [ ] **Step 5: Run to verify it passes**

Run: `php tests/portal_logic.php`
Expected: all new checks PASS, existing checks still PASS, `ALL PASS`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes/booking.php tests/portal_logic.php
git commit -m "feat(portal): request lifecycle helpers + activity-sorted threads, with tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Auto-message on status change

**Files:** Modify `admin/booking-request-action.php`

- [ ] **Step 1: Require booking.php**

At the top of `admin/booking-request-action.php`, after the existing requires, add:

```php
require_once __DIR__ . '/../includes/booking.php';
```

- [ ] **Step 2: Post the status message after a successful addon update**

In `admin/booking-request-action.php`, in the `type === 'addon'` block, immediately after `audit_log('booking_addon.' . $status, ...);` and before `$ok = true;`, add:

```php
    $__statusMsg = request_status_message($status);
    if ($__statusMsg !== '') {
        try { post_admin_message((int)$cur['hold_id'], $id, $__statusMsg); }
        catch (Throwable $e) { error_log('[request-action] status message failed: ' . $e->getMessage()); }
    }
```

(This runs only after the guarded status transition, so an already-actioned request — which exits earlier — never double-posts. Change-request actions are untouched.)

- [ ] **Step 3: Lint**

Run: `php -l admin/booking-request-action.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add admin/booking-request-action.php
git commit -m "feat(admin): approving/declining a request auto-messages + notifies the guest

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Request header in the conversation

**Files:** Modify `includes/app/messages.php`

- [ ] **Step 1: Render the request header**

In `includes/app/messages.php`, in the conversation branch (the `else:` after the thread list), immediately after the `<p style="margin:0 0 14px"><a href="… pa-back">&larr; All messages</a></p>` line, insert:

```php
<?php if ($__addonId !== null && ($__addon = fetch_addon_for_thread($__hid, $__addonId))): ?>
<div class="pa-card" style="margin-bottom:14px">
  <div class="pa-card__body">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <p class="pa-card__title" style="margin:0"><?= e(addon_label($__addon)) ?></p>
      <?php if (!empty($__addon['status'])): ?><span class="pa-pill pa-pill--<?= e($__addon['status']) ?>"><?= e(addon_status_label($__addon['status'])) ?></span><?php endif; ?>
    </div>
    <?php
      $__meta = [];
      if (($__addon['kind'] ?? '') === 'tour' && (int)($__addon['pax'] ?? 0) > 0) $__meta[] = (int)$__addon['pax'] . ' pax';
      if (!empty($__addon['scheduled_for'])) $__meta[] = date('D j M, H:i', strtotime((string)$__addon['scheduled_for']));
      if (isset($__addon['price_amount']) && $__addon['price_amount'] !== null && (float)$__addon['price_amount'] > 0) $__meta[] = format_price((float)$__addon['price_amount']);
    ?>
    <?php if ($__meta): ?><p class="pa-card__meta" style="display:block;margin-top:4px;color:var(--pa-muted)"><?= e(implode(' · ', $__meta)) ?></p><?php endif; ?>
  </div>
</div>
<?php endif; ?>
```

(General threads — `$__addonId === null` — render no header. `pax`/`price_amount` are guarded with `?? 0`/`?? null` so a pre-activities-migration DB doesn't notice.)

- [ ] **Step 2: Lint**

Run: `php -l includes/app/messages.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify (local, in-app browser)**

Start the dev server + a seeded portal. Create a request (e.g. Make a request); as owner on the Concierge Desk **Accept** it. As the guest, the **Messages tab shows an unread badge**, the request thread is **at the top** with an **In progress** pill, and opening it shows the **"Confirmed ✓ …" admin message** plus a **header card** (label, status pill, and pax/date/price where applicable). Decline another and confirm the "Sorry…" message + Declined pill. Clean up test requests.

- [ ] **Step 4: Commit**

```bash
git add includes/app/messages.php
git commit -m "feat(portal): request chat shows the request header (status, pax, date, price)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Full regression + verification

- [ ] **Step 1: Test suites** — `php tests/portal_logic.php`, `php tests/activities_logic.php`, `php tests/services_logic.php`, `php tests/frontdesk_logic.php` → all `ALL PASS`.
- [ ] **Step 2: Lint** — `for f in includes/booking.php admin/booking-request-action.php includes/app/messages.php; do php -l "$f"; done` → clean.
- [ ] **Step 3: End-to-end (in-app browser)** — the Task 3 Step 3 walkthrough, plus: confirm the Messages **thread list** shows status pills + unread counts and the updated thread ordering; confirm the **nav Messages badge** reflects the unread status message; open a **general** thread and confirm no header renders. Clean up seeded requests/messages.
- [ ] **Step 4: Final review** — use superpowers:requesting-code-review against this plan + spec; fix findings before finishing.

---

## Rollout

No migration. Ship: staff Accept/Decline/Mark-done now messages + notifies the guest in-app; request threads show status + info and bump on activity.
