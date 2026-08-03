# Portal v4 — Simplify Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Guest = 3 tabs (Home merges Concierge+Stay, trip on Home). Admin = one booking workspace (Requests·Messages·Plan·Details). No migration.

**Architecture:** Refactor guest concierge/stay markup into partials composed by a new `home.php`; retire the concierge/stay views. Add `admin/booking.php` reusing existing helpers (`fetch_booking_addons`, `fetch_booking_change_requests`, `fetch_message_threads`, `fetch_thread_messages`, `fetch_itinerary`, `fetch_itinerary_items`) and the existing `booking-request-action.php` (extended with a `return=workspace`).

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via `db_query()`, vanilla CSS/JS.

---

## Task G1: Extract guest partials

**Files:** Create `includes/app/_greeting_board.php`, `_services.php`, `_trip.php`, `_stay_essentials.php`.

Read `includes/app/concierge.php` and `includes/app/stay.php` first. Split their content into partials (verbatim markup, just relocated). Each partial expects the vars noted.

- [ ] **Step 1: `includes/app/_greeting_board.php`** — expects `$hold`, `$ref`.
  Move from `concierge.php`: the greeting `<div>…Karibu, <?= e($__first) ?>…</div>` plus the guest-board block, and the PHP that computes `$__venue`, `$__board`, `$__tagClass`, `$__first`. (Everything from the top board/greeting computation through the closing `<?php endif; ?>` of the board grid.)

- [ ] **Step 2: `includes/app/_services.php`** — expects `$hold`, `$ref`, `$status`.
  Move from `concierge.php`: the `$__kinds`/`$__tiles`/`$__icons`/`$__sched` PHP, the `<style>` block, the `<h2 class="pa-h2">Concierge</h2>` heading (retitle to **"Need something?"**) + sub, the `.cx-grid` tiles, the `$__kinds` forms, the laundry form, the transfer form, and the toggle `<script>`.

- [ ] **Step 3: `includes/app/_trip.php`** — expects `$hold`, `$ref`, `$status`.
  Move from `stay.php`: the `$__itin`/`$__icat`/`$__isvg`/`$__days`/`$__gcats` PHP, the `<h2 class="pa-h2">Your plan</h2>` heading (retitle to **"My trip"**) + sub, the day/timeline loop (incl. the guest delete `×` forms), and the "＋ Add to plan" button + form + toggle `<script>`.

- [ ] **Step 4: `includes/app/_stay_essentials.php`** — reads `setting()`.
  Move from `stay.php`: the `$__info`/`$__vals`/`$__any` PHP and the "Stay info" cards. Wrap the whole thing in a collapsible:
  ```php
  <details class="pa-details">
    <summary class="pa-details__s">Your stay — Wi-Fi, check-out, house rules</summary>
    <div style="padding-top:6px">
      … existing stay-info cards / empty-state …
    </div>
  </details>
  ```

- [ ] **Step 5: Add collapsible CSS to `css/portal-app.css`** (append):
  ```css
  .pa-details{background:var(--pa-card);border:1px solid var(--pa-line);border-radius:14px;margin-bottom:12px;}
  .pa-details__s{list-style:none;cursor:pointer;padding:14px 16px;font-size:15px;font-weight:500;color:var(--pa-ink);}
  .pa-details__s::-webkit-details-marker{display:none;}
  .pa-details__s::after{content:'▾';float:right;color:var(--pa-muted);}
  .pa-details[open] .pa-details__s::after{content:'▴';}
  .pa-details > div{padding:0 16px 14px;}
  ```

- [ ] **Step 6: Lint** each partial: `php -l includes/app/_greeting_board.php` etc. (They may reference undefined vars when linted standalone — that's fine; `php -l` only checks syntax.)

- [ ] **Step 7: Commit**
  ```bash
  git add includes/app/_greeting_board.php includes/app/_services.php includes/app/_trip.php includes/app/_stay_essentials.php css/portal-app.css
  git commit -m "refactor(portal): extract greeting/board, services, trip, stay-essentials partials"
  ```

---

## Task G2: Home view + 3-tab routing

**Files:** Create `includes/app/home.php`; Modify `booking.php`, `includes/app/nav.php`; Delete `includes/app/concierge.php`, `includes/app/stay.php`.

- [ ] **Step 1: `includes/app/home.php`**
  ```php
  <?php /** Home — merged concierge + stay. Expects $hold, $ref, $status. */ ?>
  <?php include __DIR__ . '/_greeting_board.php'; ?>
  <?php include __DIR__ . '/_trip.php'; ?>
  <?php include __DIR__ . '/_services.php'; ?>
  <?php include __DIR__ . '/_stay_essentials.php'; ?>
  ```

- [ ] **Step 2: `booking.php` routing.** Change the `$view` whitelist line to:
  ```php
  $view = in_array($_GET['view'] ?? '', ['home','activities','messages'], true) ? $_GET['view'] : 'home';
  ```
  Update `$__titles`:
  ```php
  $__titles = ['home'=>'Your stay','activities'=>'Activities','messages'=>'Messages'];
  ```
  Gate the status-header to `home` only:
  ```php
  <?php if ($view === 'home'): ?>
  <?php include __DIR__ . '/includes/app/status-header.php'; ?>
  <?php endif; ?>
  ```
  Replace the view switch (currently home/activities/messages/stay branches — note `home.php` was previously deleted; it is re-created here) with:
  ```php
      <?php if ($view === 'home'): ?>
        <?php include __DIR__ . '/includes/app/home.php'; ?>
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
          <p style="margin:0;font-size:14px;color:var(--pa-muted)"><?= e($cancel_blocked_reason) ?></p>
        </div>
        <?php endif; ?>
      <?php elseif ($view === 'activities'): ?>
        <?php include __DIR__ . '/includes/app/activities.php'; ?>
      <?php elseif ($view === 'messages'): ?>
        <?php include __DIR__ . '/includes/app/messages.php'; ?>
      <?php endif; ?>
  ```
  (This restores the guest cancel card under Home. Confirm the existing `$success` alert + help footer around this block stay intact.)

- [ ] **Step 3: `includes/app/nav.php` — 3 tabs.** Replace the `$__tabs` array with:
  ```php
  $__tabs = [
    'home'       => ['Home',       $__svg('<path d="M3 10.5 12 4l9 6.5"/><path d="M5 9.5V20h14V9.5"/>')],
    'activities' => ['Activities', $__svg('<circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>')],
    'messages'   => ['Messages',   $__svg('<path d="M4 5h16v11H8l-4 4z"/>')],
  ];
  ```
  Keep the Messages unread badge logic (`count_unread_guest`).

- [ ] **Step 4: Delete the retired views**
  ```bash
  git rm includes/app/concierge.php includes/app/stay.php
  ```
  Then `grep -rn "app/concierge.php\|app/stay.php" .` → only docs; no live PHP includes them.

- [ ] **Step 5: Lint** — `php -l booking.php && php -l includes/app/home.php && php -l includes/app/nav.php`.

- [ ] **Step 6: Commit**
  ```bash
  git add booking.php includes/app/home.php includes/app/nav.php
  git rm includes/app/concierge.php includes/app/stay.php
  git commit -m "feat(portal): merge Concierge+Stay into Home; 3-tab nav; trip on Home"
  ```

---

## Task A1: Admin workspace shell + Details tab + return=workspace

**Files:** Create `admin/booking.php`; Modify `admin/booking-request-action.php`.

- [ ] **Step 1: Extend `admin/booking-request-action.php` return whitelist.**
  Where `$returnTo` is computed (currently `=== 'concierge-desk' ? '/admin/concierge-desk.php' : '/admin/holds.php'`), change to also handle the workspace:
  ```php
  $rk = $_POST['return'] ?? '';
  if ($rk === 'concierge-desk') { $returnTo = '/admin/concierge-desk.php'; }
  elseif ($rk === 'workspace')  { $returnTo = '/admin/booking.php?hold=' . (int)($_POST['hold_id'] ?? 0) . '&tab=requests'; }
  else { $returnTo = '/admin/holds.php'; }
  ```
  (`hold_id` is already a hidden field on the desk forms; the workspace forms will include it too.) No transition-logic change.

- [ ] **Step 2: Create `admin/booking.php`** — shell, tab nav, and the Details tab with confirm/cancel (replicating the holds.php handler). Requests/Messages/Plan tab bodies are added in A2–A4; scaffold them as empty includes-of-nothing for now (or place the A2–A4 code directly — the implementer may complete all four here if straightforward). Full file:
  ```php
  <?php
  /** Admin: single-booking workspace — Requests · Messages · Plan · Details. */
  declare(strict_types=1);
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/booking.php';
  require_once __DIR__ . '/../includes/mail.php';
  require_login();

  $holdId = (int)($_GET['hold'] ?? $_POST['hold_id'] ?? 0);
  $hold = $holdId ? db_query(
      "SELECT h.*, u.name AS unit_name, r.name AS room_name, v.name AS venue_name
       FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id
       LEFT JOIN venues v ON v.id=r.venue_id WHERE h.id=:id", [':id'=>$holdId]
  )->fetch() : null;

  $flash = null;
  if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }
  if (!$hold) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Booking not found.']; header('Location: /admin/holds.php'); exit; }

  $tab = $_GET['tab'] ?? 'requests';
  if (!in_array($tab, ['requests','messages','plan','details'], true)) $tab = 'requests';

  // ── POST handlers (per tab) ──
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      verify_csrf();
      $act = $_POST['action'] ?? '';
      // Details: confirm / cancel (replicates admin/holds.php)
      if ($act === 'confirm' && $hold['status'] === 'pending') {
          db_query("UPDATE holds SET status='confirmed', confirmed_at=NOW() WHERE id=:id", [':id'=>$holdId]);
          db_query("UPDATE availability_blocks SET block_type='booked' WHERE hold_id=:hid", [':hid'=>$holdId]);
          if ($hold['guest_email']) send_hold_confirmed($hold);
          audit_log('hold.confirm', 'hold', $holdId, "{$hold['guest_name']}");
          $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Confirmed — guest notified.'];
          header("Location: /admin/booking.php?hold=$holdId&tab=details"); exit;
      }
      if ($act === 'cancel' && in_array($hold['status'], ['pending','confirmed'], true)) {
          db_query("UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id", [':id'=>$holdId]);
          db_query("DELETE FROM availability_blocks WHERE hold_id=:hid", [':hid'=>$holdId]);
          if ($hold['guest_email']) send_hold_cancelled($hold, 'cancelled');
          audit_log('hold.cancel', 'hold', $holdId, "{$hold['guest_name']}");
          $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Cancelled — dates freed, guest notified.'];
          header("Location: /admin/booking.php?hold=$holdId&tab=details"); exit;
      }
      // Messages: admin reply
      if ($act === 'reply') {
          $pa = ($_POST['addon_id'] ?? '') === '' ? null : (int)$_POST['addon_id'];
          $body = trim((string)($_POST['body'] ?? ''));
          if ($body !== '') {
              if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
              // validate addon (if any) belongs to this hold
              $okAddon = $pa === null || db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$pa,':h'=>$holdId])->fetchColumn();
              if ($okAddon) {
                  db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,:a,'admin',:b,FALSE,TRUE)", [':h'=>$holdId,':a'=>$pa,':b'=>$body]);
                  audit_log('booking_message.admin_reply','hold',$holdId,'');
                  $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Reply sent.'];
              }
          }
          $t = $pa === null ? 'general' : $pa;
          header("Location: /admin/booking.php?hold=$holdId&tab=messages&thread=$t"); exit;
      }
      // Plan: itinerary add / delete (mirrors admin/itinerary.php)
      if ($act === 'itin_add') {
          $CATS = ['flight','transfer','tour','dining','activity','note'];
          $day = (string)($_POST['day'] ?? ''); $cat = (string)($_POST['category'] ?? '');
          $title = trim((string)($_POST['title'] ?? '')); $detail = trim((string)($_POST['detail'] ?? ''));
          $atRaw = trim((string)($_POST['at_time'] ?? '')); $at = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$atRaw) ? $atRaw : null;
          $inRange = $day !== '' && $day >= (string)$hold['check_in'] && $day <= (string)$hold['check_out'] && preg_match('/^\d{4}-\d{2}-\d{2}$/',$day);
          if ($inRange && in_array($cat,$CATS,true) && $title !== '') {
              db_query("INSERT INTO itinerary_items (hold_id,day,at_time,category,title,detail,created_by) VALUES (:h,:d,:t,:c,:ti,:de,'admin')",
                  [':h'=>$holdId,':d'=>$day,':t'=>$at,':c'=>$cat,':ti'=>mb_substr($title,0,200),':de'=>($detail!==''?mb_substr($detail,0,2000):null)]);
              audit_log('itinerary.add','hold',$holdId,$title);
              $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Plan item added.'];
          } else { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Check the day, category and title.']; }
          header("Location: /admin/booking.php?hold=$holdId&tab=plan"); exit;
      }
      if ($act === 'itin_del') {
          db_query("DELETE FROM itinerary_items WHERE id=:i AND hold_id=:h", [':i'=>(int)($_POST['item_id']??0),':h'=>$holdId]);
          audit_log('itinerary.delete','hold',$holdId,'');
          $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Plan item removed.'];
          header("Location: /admin/booking.php?hold=$holdId&tab=plan"); exit;
      }
  }

  $pageTitle  = 'Booking';
  $activeMenu = 'holds';
  $portalUrl  = make_manage_url($holdId);

  // Counts for the tab chips
  $__addons  = fetch_booking_addons($holdId);
  $__changes = fetch_booking_change_requests($holdId);
  $openReq   = 0; foreach ($__addons as $a) { if (in_array($a['status'],['requested','confirmed'],true)) $openReq++; }
                 foreach ($__changes as $c){ if ($c['status']==='requested') $openReq++; }
  $unreadMsg = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND sender='guest' AND read_by_admin=FALSE",[':h'=>$holdId])->fetchColumn();

  include __DIR__ . '/_layout.php';
  ?>
  <div class="page-header">
    <h1><?= e($hold['guest_name'] ?: 'Guest') ?> — <?= e($hold['room_name']) ?></h1>
    <a href="/admin/holds.php" class="btn-outline btn-sm">← Bookings</a>
  </div>
  <p class="text-muted" style="margin:-8px 0 14px;font-size:13px"><?= e(date('j M Y',strtotime($hold['check_in']))) ?> → <?= e(date('j M Y',strtotime($hold['check_out']))) ?> · <span class="badge badge--<?= ['pending'=>'orange','confirmed'=>'green','cancelled'=>'red','expired'=>'grey'][$hold['status']] ?? 'grey' ?>"><?= e($hold['status']) ?></span> · <code><?= e($hold['access_code']) ?></code></p>
  <?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:16px"><div class="card__body" style="display:flex;gap:8px;flex-wrap:wrap">
    <?php
    $__tabs = ['requests'=>'Requests','messages'=>'Messages','plan'=>'Plan','details'=>'Details'];
    foreach ($__tabs as $tk=>$tl):
      $badge = $tk==='requests' && $openReq ? " ($openReq)" : ($tk==='messages' && $unreadMsg ? " ($unreadMsg)" : '');
    ?>
    <a href="?hold=<?= $holdId ?>&tab=<?= $tk ?>" class="btn-sm <?= $tab===$tk?'btn-primary':'btn-outline' ?>"><?= e($tl.$badge) ?></a>
    <?php endforeach; ?>
  </div></div>

  <?php if ($tab === 'requests'): ?>
    <?php include __DIR__ . '/_ws_requests.php'; ?>
  <?php elseif ($tab === 'messages'): ?>
    <?php include __DIR__ . '/_ws_messages.php'; ?>
  <?php elseif ($tab === 'plan'): ?>
    <?php include __DIR__ . '/_ws_plan.php'; ?>
  <?php else: ?>
    <div class="card"><div class="card__body">
      <table class="data-table" style="max-width:520px">
        <tr><td class="text-muted">Guest</td><td><?= e($hold['guest_name']) ?></td></tr>
        <tr><td class="text-muted">Email</td><td><?= e($hold['guest_email']) ?></td></tr>
        <tr><td class="text-muted">Property</td><td><?= e(trim(($hold['venue_name']??'').' · '.$hold['room_name'],' ·')) ?></td></tr>
        <tr><td class="text-muted">Dates</td><td><?= e(date('j M Y',strtotime($hold['check_in']))) ?> → <?= e(date('j M Y',strtotime($hold['check_out']))) ?></td></tr>
        <tr><td class="text-muted">Code</td><td><code><?= e($hold['access_code']) ?></code></td></tr>
        <tr><td class="text-muted">Portal link</td><td><input type="text" readonly value="<?= e($portalUrl) ?>" onclick="this.select()" style="width:100%;font-size:12px"></td></tr>
      </table>
      <div style="margin-top:14px">
        <?php if ($hold['status']==='pending'): ?>
        <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="action" value="confirm"><button class="btn-primary btn-sm" onclick="return confirm('Confirm and notify the guest?')">Confirm</button></form>
        <?php endif; ?>
        <?php if (in_array($hold['status'],['pending','confirmed'],true)): ?>
        <form method="POST" style="display:inline;margin-left:6px"><?= csrf_field() ?><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="action" value="cancel"><button class="btn-danger btn-sm" onclick="return confirm('Cancel this booking? Dates freed, guest notified.')">Cancel</button></form>
        <?php endif; ?>
      </div>
    </div></div>
  <?php endif; ?>
  <?php include __DIR__ . '/_layout_end.php'; ?>
  ```

- [ ] **Step 3: Lint + smoke** — `php -l admin/booking.php && php -l admin/booking-request-action.php`; `php -r "\$_SERVER['REQUEST_METHOD']='GET'; require 'admin/booking.php';" 2>&1 | head -3` (login redirect fine; the tab includes `_ws_*.php` don't exist yet → will warn, acceptable until A2–A4; if it fatals on missing include, create empty placeholder files first).

- [ ] **Step 4: Commit**
  ```bash
  git add admin/booking.php admin/booking-request-action.php
  git commit -m "feat(admin): booking workspace shell + Details tab + return=workspace"
  ```

---

## Task A2: Workspace Requests tab

**Files:** Create `admin/_ws_requests.php`.

- [ ] **Step 1:** Create `admin/_ws_requests.php` (expects `$hold`, `$holdId`, `$__addons`, `$__changes` from the parent). Render each addon + change with inline actions posting to `booking-request-action.php` with `return=workspace` + `hold_id`. Model it on the actions in `admin/concierge-desk.php` (Accept/Done/Decline for addons) and `admin/holds.php` (Mark-handled/Decline for changes). Use `addon_label`, `addon_status_label`, the `.badge` classes. Include an empty-state.

- [ ] **Step 2: Lint** — `php -l admin/_ws_requests.php`. Commit:
  ```bash
  git add admin/_ws_requests.php
  git commit -m "feat(admin): workspace Requests tab"
  ```

---

## Task A3: Workspace Messages tab

**Files:** Create `admin/_ws_messages.php`.

- [ ] **Step 1:** Create `admin/_ws_messages.php` (expects `$hold`, `$holdId`). Read `$_GET['thread']` (`general` or an addon id). No `thread` → a thread list from `fetch_message_threads($holdId)` linking to `?hold&tab=messages&thread=<id>`. With `thread` → `mark_thread_read_by_admin($holdId,$addonId)` then render `fetch_thread_messages` as bubbles + a reply form (`action=reply`, hidden `hold_id` + `addon_id`, posts to `admin/booking.php`). Mirror the bubble styling in `admin/messages.php`.

- [ ] **Step 2: Lint + commit**
  ```bash
  php -l admin/_ws_messages.php
  git add admin/_ws_messages.php
  git commit -m "feat(admin): workspace Messages tab"
  ```

---

## Task A4: Workspace Plan tab

**Files:** Create `admin/_ws_plan.php`.

- [ ] **Step 1:** Create `admin/_ws_plan.php` (expects `$hold`, `$holdId`). Render the "Add item" form (day `<select>` of the stay dates, time, category `flight/transfer/tour/dining/activity/note`, title, detail; `action=itin_add`) + the merged `fetch_itinerary($hold)` view + a delete list from `fetch_itinerary_items($holdId)` (`action=itin_del`). Model on `admin/itinerary.php`'s body. Badge guest-added items.

- [ ] **Step 2: Lint + commit**
  ```bash
  php -l admin/_ws_plan.php
  git add admin/_ws_plan.php
  git commit -m "feat(admin): workspace Plan tab"
  ```

---

## Task A5: Manage links into the workspace

**Files:** Modify `admin/holds.php`, `admin/concierge-desk.php`, `admin/messages.php`.

- [ ] **Step 1: `admin/holds.php`** — change the per-hold **Plan** link to **Manage** → `/admin/booking.php?hold=<id>` (keep quick Cancel). 
- [ ] **Step 2: `admin/concierge-desk.php`** — add a **Manage** link per row → `/admin/booking.php?hold=<hold_id>&tab=requests` (keep the inline actions).
- [ ] **Step 3: `admin/messages.php`** — on each thread row add a **Manage** link → `/admin/booking.php?hold=<hold_id>&tab=messages`.
- [ ] **Step 4: Lint** all three; commit:
  ```bash
  git add admin/holds.php admin/concierge-desk.php admin/messages.php
  git commit -m "feat(admin): Manage links into the booking workspace"
  ```

---

## Task V: E2E + regression + cleanup

- [ ] **Step 1: Tests + lint** — `php tests/portal_logic.php && php tests/manage_logic.php && php tests/convert_logic.php` → ALL PASS; `php -l` on every changed/new file.
- [ ] **Step 2: Guest E2E** (mobile 375 + desktop): land on **Home** → status → greeting/board → My trip (add + remove an item) → "Need something?" tiles (submit a request) → collapsible "Your stay" opens. Nav = 3 tabs. `view=concierge`/`stay`/`manage` → Home. Cancel card present for active holds. Activities + Messages still work.
- [ ] **Step 3: Admin E2E** (if login available): open `admin/booking.php?hold=<id>` → all four tabs render; a Request action returns to the workspace; a Message reply posts; a Plan add/delete works; Details confirm/cancel works. Manage links from Holds/Desk/Messages open the workspace. If no login, at least confirm each admin file lints + requires login without fatal.
- [ ] **Step 4: Clean up** any seeded data. 
- [ ] **Step 5: Done** — ready for final review.

---

## Self-Review Notes
- Coverage: guest partials (G1), Home + routing + nav (G2); admin shell+Details+return (A1), Requests (A2), Messages (A3), Plan (A4), links (A5); verification (V).
- No migration. Reuses helpers + `booking-request-action.php`. Guest cancel card relocated under Home. Retired concierge.php/stay.php. Status-header gated to Home.
- Consistency: workspace tab bodies mirror the existing standalone pages' logic; POSTs are CSRF+PRG+audit; itinerary/message inserts scoped to the loaded hold.
