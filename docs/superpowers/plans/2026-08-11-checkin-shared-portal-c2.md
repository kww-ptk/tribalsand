# Shared Booking Portal — C-2 (Bill + Message Names) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A guest-facing bill view (shown when a booking is shared) that itemizes every charge by the guest's name, and per-sender names on the shared message thread.

**Architecture:** Reuse the C-1 attribution (`booking_addons.requested_by`) for request charges; add `bill_items.guest_id` for manual charges and `booking_messages.sender_guest_id` for message authors. The bill fetch functions LEFT JOIN `checkin_guests` for the name; a new `includes/app/bill.php` renders read-only. For messages, a `sender_name` field flows to all four label sites (two PHP renders + two JS appends); a guest bubble shows the sender's first name when known, else today's default. All new surfaces gated by `share_reservation`.

**Tech Stack:** Vanilla PHP 8.5, PostgreSQL/PDO, server-rendered portal + small vanilla JS, logic tests via `php tests/checkin_logic.php`.

**Spec:** `docs/superpowers/specs/2026-08-11-checkin-shared-portal-design.md` (stage **C-2**; C-1 shipped as PR #53).

**Branch:** to be created `feature/checkin-shared-portal-c2` off `feature/checkin-shared-portal` (C-1). Chain: master ← A(#51) ← B(#52) ← C-1(#53) ← C-2.

**Conventions:** `share_reservation_on()` gating; `guest_display_name()` for first names; `*_supported()` pre-migration guards; `verify_csrf()`; `can_view_guest_docs` for admin.

---

## Task 1: Migration — bill + message attribution columns

**Files:** Create `db/migrations/add_shared_portal_bill_msg.sql`

- [ ] **Step 1:** Create the migration:
```sql
-- Shared booking portal (C-2): attribute manual bill items + message senders to a guest.
-- Idempotent. Both reference the checkin_guests roster.
ALTER TABLE bill_items       ADD COLUMN IF NOT EXISTS guest_id        INT REFERENCES checkin_guests(id) ON DELETE SET NULL;
ALTER TABLE booking_messages ADD COLUMN IF NOT EXISTS sender_guest_id INT REFERENCES checkin_guests(id) ON DELETE SET NULL;
```
- [ ] **Step 2:** Apply: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_shared_portal_bill_msg.sql")); echo "applied\n";'` → `applied`
- [ ] **Step 3:** Verify: `php -r 'require "includes/db.php"; db_query("SELECT guest_id FROM bill_items LIMIT 0"); db_query("SELECT sender_guest_id FROM booking_messages LIMIT 0"); echo "columns ok\n";'` → `columns ok`
- [ ] **Step 4:** Commit:
```bash
git add db/migrations/add_shared_portal_bill_msg.sql
git commit -m "feat(portal): migration for bill + message attribution (C-2)"
```

---

## Task 2: Support guards + bill fetch joins

**Files:** Modify `includes/booking.php`; Test `tests/checkin_logic.php`

- [ ] **Step 1: Failing test** (before the final echo in `tests/checkin_logic.php`):
```php
// ── C-2 support guards return a bool (pure shape) ───────────────────────────
check('bill_item_guest_supported is bool',     is_bool(bill_item_guest_supported()));
check('message_sender_guest_supported is bool', is_bool(message_sender_guest_supported()));
```
- [ ] **Step 2:** Run `php tests/checkin_logic.php` → fatal `Call to undefined function bill_item_guest_supported()`.
- [ ] **Step 3:** In `includes/booking.php`, near `addon_requested_by_supported()`, add:
```php
/** True once bill_items.guest_id exists (C-2). Cached. */
function bill_item_guest_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT guest_id FROM bill_items LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once booking_messages.sender_guest_id exists (C-2). Cached. */
function message_sender_guest_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT sender_guest_id FROM booking_messages LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
```
- [ ] **Step 4:** Replace `fetch_bill_lines()`:
```php
/** A booking's chargeable requests (confirmed/completed), with tour name. For the bill. */
function fetch_bill_lines(int $holdId): array {
    try {
        return db_query(
            "SELECT ba.*, t.name AS tour_name FROM booking_addons ba
             LEFT JOIN tours t ON t.id = ba.tour_id
             WHERE ba.hold_id = :h AND ba.status IN ('confirmed','completed')
             ORDER BY ba.created_at", [':h' => $holdId]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}
```
with:
```php
/** A booking's chargeable requests (confirmed/completed), with tour + requester name. For the bill. */
function fetch_bill_lines(int $holdId): array {
    try {
        $sel  = addon_requested_by_supported() ? ", cg.passport_name AS requested_by_name, cg.is_lead AS requested_by_is_lead" : "";
        $join = addon_requested_by_supported() ? "LEFT JOIN checkin_guests cg ON cg.id = ba.requested_by" : "";
        return db_query(
            "SELECT ba.*, t.name AS tour_name{$sel} FROM booking_addons ba
             LEFT JOIN tours t ON t.id = ba.tour_id
             {$join}
             WHERE ba.hold_id = :h AND ba.status IN ('confirmed','completed')
             ORDER BY ba.created_at", [':h' => $holdId]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}
```
- [ ] **Step 5:** Replace `fetch_bill_items()`:
```php
/** Ad-hoc bill line items for a booking (minibar, damages…). */
function fetch_bill_items(int $holdId): array {
    try { return db_query("SELECT * FROM bill_items WHERE hold_id = :h ORDER BY id", [':h' => $holdId])->fetchAll(); }
    catch (Throwable $e) { return []; }
}
```
with:
```php
/** Ad-hoc bill line items for a booking (minibar, damages…), with guest name when attributed. */
function fetch_bill_items(int $holdId): array {
    try {
        $sel  = bill_item_guest_supported() ? ", cg.passport_name AS guest_name, cg.is_lead AS guest_is_lead" : "";
        $join = bill_item_guest_supported() ? "LEFT JOIN checkin_guests cg ON cg.id = bi.guest_id" : "";
        return db_query("SELECT bi.*{$sel} FROM bill_items bi {$join} WHERE bi.hold_id = :h ORDER BY bi.id", [':h' => $holdId])->fetchAll();
    } catch (Throwable $e) { return []; }
}
```
- [ ] **Step 6:** Run `php tests/checkin_logic.php` → the 2 new lines PASS, `ALL PASS`. Also `php -l includes/booking.php`.
- [ ] **Step 7:** Commit:
```bash
git add includes/booking.php tests/checkin_logic.php
git commit -m "feat(portal): bill fetch joins for per-guest name + C-2 support guards"
```

---

## Task 3: Guest-facing bill view (shown when shared)

**Files:** Create `includes/app/bill.php`; Modify `booking.php` (view whitelist + dispatch), `includes/app/nav.php` (Bill tab)

- [ ] **Step 1:** Create `includes/app/bill.php`:
```php
<?php /** Guest bill view (shown only on shared bookings). Read-only, itemized by guest name. Expects $hold, $ref. */ ?>
<?php
$__hid   = (int)$hold['id'];
$__lines = fetch_bill_lines($__hid);
$__items = fetch_bill_items($__hid);
$__total = bill_total($__hid);
$__cur   = setting('site_currency', 'USD');
$__who = function (array $r, string $nameKey, string $leadKey): string {
    $n = trim((string)($r[$nameKey] ?? ''));
    if ($n === '') return '';
    return guest_display_name(['passport_name' => $n]) . (!empty($r[$leadKey]) ? ' (lead)' : '');
};
?>
<h2 class="pa-h2">Your bill</h2>
<p class="pa-sub">Charges for your stay, itemized by who requested them.</p>
<?php if (!$__lines && !$__items): ?>
<div class="pa-card"><div class="pa-card__body"><p class="pa-sub" style="margin:0">No extra charges yet.</p></div></div>
<?php else: ?>
<div class="pa-card"><div class="pa-card__body" style="padding:0">
  <?php foreach ($__lines as $l): $__w = $__who($l, 'requested_by_name', 'requested_by_is_lead'); ?>
  <div style="display:flex;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--pa-line)">
    <div><div><?= e(addon_label($l)) ?></div><?php if ($__w !== ''): ?><div style="font-size:12px;color:var(--pa-muted);margin-top:2px"><?= e($__w) ?></div><?php endif; ?></div>
    <div style="white-space:nowrap;font-variant-numeric:tabular-nums"><?= (isset($l['price_amount']) && (float)$l['price_amount'] > 0) ? e(format_price((float)$l['price_amount'], $__cur)) : '<span style="color:var(--pa-muted)">—</span>' ?></div>
  </div>
  <?php endforeach; ?>
  <?php foreach ($__items as $it): $__w = $__who($it, 'guest_name', 'guest_is_lead'); ?>
  <div style="display:flex;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--pa-line)">
    <div><div><?= e($it['label']) ?></div><?php if ($__w !== ''): ?><div style="font-size:12px;color:var(--pa-muted);margin-top:2px"><?= e($__w) ?></div><?php endif; ?></div>
    <div style="white-space:nowrap;font-variant-numeric:tabular-nums"><?= e(format_price((float)$it['amount'], $__cur)) ?></div>
  </div>
  <?php endforeach; ?>
  <div style="display:flex;justify-content:space-between;padding:14px 16px;font-weight:700"><span>Total</span><span style="font-variant-numeric:tabular-nums"><?= e(format_price($__total, $__cur)) ?></span></div>
</div></div>
<?php endif; ?>
```
- [ ] **Step 2:** In `booking.php`, the view whitelist (line 132):
```php
$view = in_array($_GET['view'] ?? '', ['home','activities','messages','checkin'], true) ? $_GET['view'] : 'home';
```
becomes:
```php
$__views = ['home','activities','messages','checkin'];
if (share_reservation_on($hold ?: [])) $__views[] = 'bill';
$view = in_array($_GET['view'] ?? '', $__views, true) ? $_GET['view'] : 'home';
```
- [ ] **Step 3:** In `booking.php`, the view dispatch (the `if ($view === 'home') ... elseif ...` block that includes the view partials, around line 272-284): add a branch. Find the `elseif ($view === 'checkin')` include line and add before/after it:
```php
      <?php elseif ($view === 'bill'): ?>
        <?php include __DIR__ . '/includes/app/bill.php'; ?>
```
(match the existing elseif style in that block exactly — each is `<?php elseif ($view === 'X'): ?>` then an include).
- [ ] **Step 4:** In `includes/app/nav.php`, after the `$__tabs = [...]` array (line 7-11), add a Bill tab when shared:
```php
if (share_reservation_on($hold)) {
  $__tabs['bill'] = ['Bill', $__svg('<path d="M6 2h9l3 3v17l-3-2-3 2-3-2-3 2V2z"/><path d="M9 8h6M9 12h6"/>')];
}
```
- [ ] **Step 5:** `php -l booking.php && php -l includes/app/nav.php && php -l includes/app/bill.php` → no errors.
- [ ] **Step 6:** Commit:
```bash
git add includes/app/bill.php booking.php includes/app/nav.php
git commit -m "feat(portal): guest-facing itemized bill view (shown when shared)"
```

---

## Task 4: Admin bill — names + attribute a manual charge

**Files:** Modify `admin/_ws_bill.php`, `admin/booking.php` (`bill_add` handler)

- [ ] **Step 1:** In `admin/_ws_bill.php`, in the "Charges from requests" loop, add the requester under the label. Find:
```php
          <td><?= e(addon_label($l)) ?><?php if (($l['kind'] ?? '') === 'tour' && !empty($l['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$l['pax'] ?> pax</span><?php endif; ?><?php if (!$__priced): ?> <span class="badge badge--orange">set a price</span><?php endif; ?></td>
```
and append a requester line inside that `<td>` (before `</td>`):
```php
          <td><?= e(addon_label($l)) ?><?php if (($l['kind'] ?? '') === 'tour' && !empty($l['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$l['pax'] ?> pax</span><?php endif; ?><?php if (!$__priced): ?> <span class="badge badge--orange">set a price</span><?php endif; ?><?php if (!empty($l['requested_by_name'])): ?><div class="text-muted" style="font-size:12px"><?= e(guest_display_name(['passport_name'=>$l['requested_by_name']])) ?><?= !empty($l['requested_by_is_lead']) ? ' (lead)' : '' ?></div><?php endif; ?></td>
```
- [ ] **Step 2:** In `admin/_ws_bill.php`, in the "Other charges" item loop, show the guest name. Find:
```php
      <span style="flex:1"><?= e($it['label']) ?></span>
```
and replace with:
```php
      <span style="flex:1"><?= e($it['label']) ?><?php if (!empty($it['guest_name'])): ?> <span class="text-muted" style="font-size:12px">· <?= e(guest_display_name(['passport_name'=>$it['guest_name']])) ?></span><?php endif; ?></span>
```
- [ ] **Step 3:** In `admin/_ws_bill.php`, add a guest picker to the `bill_add` form. First compute the adult roster near the top (after `$__cur = ...`):
```php
$__adults = array_values(array_filter(fetch_checkin_guests($holdId), fn($g) => empty($g['is_child'])));
```
Then in the add form, before the "Add charge" button, add (only when there are guests to attribute to):
```php
      <?php if ($__adults): ?>
      <label class="wsf"><span>For</span>
        <select name="guest_id" class="inp inp--sm" style="width:130px">
          <option value="">Whole booking</option>
          <?php foreach ($__adults as $g): ?>
          <option value="<?= (int)$g['id'] ?>"><?= e(guest_display_name(['passport_name'=>$g['passport_name']])) ?><?= !empty($g['is_lead']) ? ' (lead)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
```
- [ ] **Step 4:** In `admin/booking.php`, the `bill_add` action (around line 107-115): stamp `guest_id` when the column exists. Replace:
```php
    if ($act === 'bill_add') {
        $label  = trim((string)($_POST['label'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        if ($label !== '' && $amount >= 0 && $amount < 100000000) { // NUMERIC(10,2) ceiling
            db_query("INSERT INTO bill_items (hold_id, label, amount) VALUES (:h,:l,:a)", [':h'=>$holdId, ':l'=>mb_substr($label,0,200), ':a'=>$amount]);
            audit_log('bill.add', 'hold', $holdId, $label);
        }
        header("Location: /admin/booking.php?hold=$holdId&tab=bill"); exit;
    }
```
with:
```php
    if ($act === 'bill_add') {
        $label  = trim((string)($_POST['label'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        if ($label !== '' && $amount >= 0 && $amount < 100000000) { // NUMERIC(10,2) ceiling
            $gid = (int)($_POST['guest_id'] ?? 0);
            $gid = ($gid > 0 && bill_item_guest_supported()
                    && db_query('SELECT 1 FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetchColumn())
                   ? $gid : null;
            if (bill_item_guest_supported()) {
                db_query("INSERT INTO bill_items (hold_id, label, amount, guest_id) VALUES (:h,:l,:a,:g)", [':h'=>$holdId, ':l'=>mb_substr($label,0,200), ':a'=>$amount, ':g'=>$gid]);
            } else {
                db_query("INSERT INTO bill_items (hold_id, label, amount) VALUES (:h,:l,:a)", [':h'=>$holdId, ':l'=>mb_substr($label,0,200), ':a'=>$amount]);
            }
            audit_log('bill.add', 'hold', $holdId, $label);
        }
        header("Location: /admin/booking.php?hold=$holdId&tab=bill"); exit;
    }
```
- [ ] **Step 5:** `php -l admin/_ws_bill.php && php -l admin/booking.php` → no errors.
- [ ] **Step 6:** Commit:
```bash
git add admin/_ws_bill.php admin/booking.php
git commit -m "feat(portal): admin bill shows guest names + attribute a manual charge"
```

---

## Task 5: Stamp `sender_guest_id` on guest messages

**Files:** Modify `includes/booking.php` (`seed_request_message`), `api/booking-addon.php` (pass actor), `api/booking-message.php` (send)

- [ ] **Step 1:** In `includes/booking.php`, replace `seed_request_message()`:
```php
function seed_request_message(int $holdId, int $addonId, string $body): void {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
        [':h' => $holdId, ':a' => $addonId, ':b' => $body]
    );
}
```
with:
```php
function seed_request_message(int $holdId, int $addonId, string $body, ?int $senderGuestId = null): void {
    if (message_sender_guest_supported()) {
        db_query(
            "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin, sender_guest_id)
             VALUES (:h, :a, 'guest', :b, TRUE, FALSE, :sg)",
            [':h' => $holdId, ':a' => $addonId, ':b' => $body, ':sg' => $senderGuestId]
        );
    } else {
        db_query(
            "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
             VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
            [':h' => $holdId, ':a' => $addonId, ':b' => $body]
        );
    }
}
```
- [ ] **Step 2:** In `api/booking-addon.php`, the `seed_request_message(...)` call (around line 112) passes the actor:
```php
        seed_request_message((int)$hold['id'], $addonId, $threadBody ?? $details);
```
becomes:
```php
        seed_request_message((int)$hold['id'], $addonId, $threadBody ?? $details, (int)$actor['guest_id']);
```
- [ ] **Step 3:** In `api/booking-message.php`, the guest send INSERT (around line 56-60):
```php
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
        [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body]
    );
```
becomes:
```php
    if (message_sender_guest_supported()) {
        db_query(
            "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin, sender_guest_id)
             VALUES (:h, :a, 'guest', :b, TRUE, FALSE, :sg)",
            [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body, ':sg'=>(int)$actor['guest_id']]
        );
    } else {
        db_query(
            "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
             VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
            [':h'=>$hold['id'], ':a'=>$addonId, ':b'=>$body]
        );
    }
```
(`$actor` is in scope from the C-1 `resolve_portal_actor` auth at the top of the send path.)
- [ ] **Step 4:** `php -l includes/booking.php && php -l api/booking-addon.php && php -l api/booking-message.php` → no errors; `php tests/checkin_logic.php` → `ALL PASS`.
- [ ] **Step 5:** Commit:
```bash
git add includes/booking.php api/booking-addon.php api/booking-message.php
git commit -m "feat(portal): stamp the sender guest on guest messages"
```

---

## Task 6: Show sender names on messages (4 label sites)

**Files:** Modify `includes/booking.php` (`message_payload`, `fetch_thread_messages`, `fetch_thread_messages_since`), `includes/app/messages.php`, `admin/_ws_messages.php`, `js/booking-manage.js`, `admin/assets/admin-chat.js`, `api/booking-message.php` + `admin/messages-poll.php` (payload sender_name)

- [ ] **Step 1:** In `includes/booking.php`, teach the two thread fetchers to return the sender's name. Replace `fetch_thread_messages()`:
```php
function fetch_thread_messages(int $holdId, ?int $addonId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    try {
        return db_query("SELECT * FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}
```
with:
```php
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
And replace `fetch_thread_messages_since()`:
```php
function fetch_thread_messages_since(int $holdId, ?int $addonId, int $afterId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId, ':after'=>$afterId]; if ($addonId !== null) $p[':aid'] = $addonId;
    try {
        return db_query("SELECT * FROM booking_messages WHERE hold_id=:h AND $cond AND id > :after ORDER BY id ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}
```
with:
```php
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
- [ ] **Step 2:** In `includes/booking.php`, add `sender_name` to `message_payload()`. Replace:
```php
function message_payload(array $m): array {
    return [
        'id'         => (int)$m['id'],
        'sender'     => (string)$m['sender'],
        'body'       => (string)$m['body'],
        'time_label' => message_time_label($m['created_at'] ?? 'now'),
    ];
}
```
with:
```php
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
- [ ] **Step 3:** Guest initial render — `includes/app/messages.php`, the bubble label (line 70). Replace:
```php
    <div style="font-size:11px;margin-top:4px;<?= $__me ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)' ?>"><?= $__me ? 'You' : 'Concierge' ?> · <?= e(message_time_label($__m['created_at'])) ?></div>
```
with:
```php
    <div style="font-size:11px;margin-top:4px;<?= $__me ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)' ?>"><?= $__me ? e(trim((string)($__m['sender_name'] ?? '')) !== '' ? guest_display_name(['passport_name'=>$__m['sender_name']]) : 'You') : 'Concierge' ?> · <?= e(message_time_label($__m['created_at'])) ?></div>
```
- [ ] **Step 4:** Admin initial render — `admin/_ws_messages.php`, the bubble label (line 44). Replace:
```php
      <div class="am-msg__meta"><?= $am ? 'Staff' : 'Guest' ?> · <?= e(message_time_label($m['created_at'])) ?></div>
```
with:
```php
      <div class="am-msg__meta"><?= $am ? 'Staff' : e(trim((string)($m['sender_name'] ?? '')) !== '' ? guest_display_name(['passport_name'=>$m['sender_name']]) : 'Guest') ?> · <?= e(message_time_label($m['created_at'])) ?></div>
```
- [ ] **Step 5:** Guest JS append — `js/booking-manage.js`, in `appendMsg`, the meta line (~line 121). Replace:
```js
      meta.textContent = (mine ? 'You' : 'Concierge') + ' · ' + m.time_label;
```
with:
```js
      meta.textContent = (mine ? (m.sender_name || 'You') : 'Concierge') + ' · ' + m.time_label;
```
- [ ] **Step 6:** Admin JS append — `admin/assets/admin-chat.js`, in `appendMsg`, the meta line (~line 43). Replace:
```js
    meta.textContent = (mine ? 'Staff' : 'Guest') + ' · ' + m.time_label;
```
with:
```js
    meta.textContent = (mine ? 'Staff' : (m.sender_name || 'Guest')) + ' · ' + m.time_label;
```
- [ ] **Step 7:** The two JSON send endpoints build a payload from a synthetic array — include `sender_name` so the just-sent bubble labels correctly. In `api/booking-message.php`, the send response (around line 62-64): replace:
```php
    echo json_encode(['ok'=>true, 'message'=>message_payload([
        'id'=>$id, 'sender'=>'guest', 'body'=>$body, 'created_at'=>'now',
    ])]);
```
with:
```php
    $__sn = db_query('SELECT passport_name FROM checkin_guests WHERE id=:g', [':g'=>(int)$actor['guest_id']])->fetchColumn();
    echo json_encode(['ok'=>true, 'message'=>message_payload([
        'id'=>$id, 'sender'=>'guest', 'body'=>$body, 'created_at'=>'now', 'sender_name'=>$__sn ?: '',
    ])]);
```
(`admin/messages-poll.php`'s send payload is an admin message — `sender_name` stays empty, no change needed; its GET poll uses `fetch_thread_messages_since` which now carries `sender_name`.)
- [ ] **Step 8:** `php -l` the three PHP files; `node --check js/booking-manage.js && node --check admin/assets/admin-chat.js`; `php tests/checkin_logic.php` → `ALL PASS`.
- [ ] **Step 9:** Commit:
```bash
git add includes/booking.php includes/app/messages.php admin/_ws_messages.php js/booking-manage.js admin/assets/admin-chat.js api/booking-message.php
git commit -m "feat(portal): label messages by sender name across all four render paths"
```

---

## Task 7: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1:** `php tests/checkin_logic.php` → `ALL PASS`; `for f in tests/*_logic.php; do printf "%s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done` (only the known flaky `team_logic.php`).
- [ ] **Step 2:** Reuse the shared-booking setup (a `share_reservation=TRUE` hold + lead + checked-in co-guest "Jess"). Post one request as the co-guest (g-token) and one as the lead (TS ref); confirm each as owner so they become bill lines; add a manual charge attributed to Jess. Then:
```bash
php -r 'require "includes/db.php"; require "includes/booking.php"; $h=(int)$argv[1];
foreach (fetch_bill_lines($h) as $l) echo "req: ".$l["details"]." -> ".($l["requested_by_name"]??"-")."\n";
foreach (fetch_bill_items($h) as $i) echo "item: ".$i["label"]." -> ".($i["guest_name"]??"-")."\n";' <HOLD>
```
Expected: request lines carry the requester's name; the manual item carries "Jess".
- [ ] **Step 3:** Dev server: open the co-guest `?g=` link → a **Bill** tab appears in the nav; it lists every charge with the guest name and the total. Open Messages → send a message as the co-guest; open the admin Messages tab for the booking → the guest bubble is labeled **"Jess"** (not "Guest").
- [ ] **Step 4:** With `share_reservation=FALSE`, confirm the Bill tab is gone and the guest bill view 404s/falls back to home.
- [ ] **Step 5:** Clean up the throwaway hold.

---

## Self-Review

**Spec coverage (C-2):** bill_items.guest_id + sender_guest_id → Task 1; bill fetch joins → Task 2; guest bill view shown when shared → Task 3; admin bill names + attribute a charge → Task 4; sender stamping → Task 5; name labels on all 4 sites → Task 6; E2E → Task 7.

**Placeholder scan:** none — full code in every step; commands have expected output.

**Type/name consistency:** `bill_item_guest_supported()` / `message_sender_guest_supported()` defined in Task 2, used in Tasks 2/4/5/6. `sender_name` added to `message_payload` (Task 6) and consumed at all four label sites + both send endpoints. `guest_display_name()` (from C-1) is the single name formatter throughout. Column aliases `requested_by_name`/`guest_name`/`sender_name` are produced by the fetch joins and read by the renders.

**Note:** all four message-label sites must change together (two PHP renders read raw rows; two JS appends read `message_payload`), or names will show inconsistently between the first paint and live updates.
