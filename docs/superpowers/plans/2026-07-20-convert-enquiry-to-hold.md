# Convert Enquiry → Hold (Admin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Convert to hold" action on `admin/submission-view.php` that force-creates a hold from an enquiry (generating the access code, blocking the dates, linking hold↔submission), and shows a "Converted → Hold #N" banner with the guest code + portal link once converted.

**Architecture:** Two small DB helpers in `includes/db.php` (fetch the hold linked to a submission; list Room—Unit options). `admin/submission-view.php` gains: a session-flash display, a Convert card that renders EITHER the converted banner (if a hold already links to this submission) OR a prefilled convert form, and a `POST action=convert` handler that force-creates via the existing `create_hold_with_block()`.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via PDO (`db_query()`), admin auth (`require_login`, `verify_csrf`, `csrf_field`), `audit_log()`. No framework, no build step. No PHP test framework — pure-DB logic is verified with a `php`-CLI script and `php -r` checks; the admin UI click-through needs an admin login (final step).

**Reuse note:** `create_hold_with_block(int $unit_id, int $submission_id, string $check_in, string $check_out, string $guest_name, string $guest_email): int` already generates `access_code`, inserts the `availability_blocks` row, and stores `submission_id`. Force-create = call it directly with NO availability check. `make_manage_url(int $holdId)` builds the portal link. The `.copy-link` button + clipboard `<script>` pattern already exists in `admin/holds.php` — mirror it.

**Local dev:** `php -S localhost:8765 router.php` (Neon DB via `.env`). No `DATABASE_URL` on this machine — use `php bin/migrate.php` / `php -r` with `db()`, never `psql`.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `includes/db.php` | Add `fetch_hold_by_submission()` + `fetch_room_unit_options()` |
| `admin/submission-view.php` | Flash display; Convert card (banner OR form); `action=convert` POST handler |
| `tests/convert_logic.php` | CLI checks for the two helpers (DB-backed) |

No new files beyond the test; no DB migration.

---

## Task 1: DB helpers

**Files:**
- Modify: `includes/db.php` (add two functions near the other fetch helpers)
- Create: `tests/convert_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/convert_logic.php`:

```php
<?php
declare(strict_types=1);
// DB-backed checks for convert-to-hold helpers. Run: php tests/convert_logic.php
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// fetch_room_unit_options: returns rows with unit_id/unit_name/room_name
$opts = fetch_room_unit_options();
check('room-unit options is a list', is_array($opts));
check('options have unit_id + room_name + unit_name keys',
      $opts === [] || (isset($opts[0]['unit_id'], $opts[0]['room_name'], $opts[0]['unit_name'])));
check('unit_id values are numeric', $opts === [] || ctype_digit((string)$opts[0]['unit_id']));

// fetch_hold_by_submission: false for a submission id that cannot exist
check('no hold for submission id 0', fetch_hold_by_submission(0) === false);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `php tests/convert_logic.php`
Expected: fatal — `Call to undefined function fetch_room_unit_options()`.

- [ ] **Step 3: Add the helpers to `includes/db.php`**

Add near the other `fetch_*` helpers (e.g. after `fetch_units_by_room()`):

```php
/**
 * Every active Room—Unit pair, for the "convert to hold" dropdown.
 * Returns rows: unit_id, unit_name, room_id, room_name (ordered by room then unit).
 */
function fetch_room_unit_options(): array {
    return db_query(
        "SELECT u.id AS unit_id, u.name AS unit_name, r.id AS room_id, r.name AS room_name
         FROM units u
         JOIN rooms r ON r.id = u.room_id
         WHERE u.is_active = TRUE
         ORDER BY r.sort_order ASC, r.name ASC, u.sort_order ASC"
    )->fetchAll();
}

/** The most recent hold linked to a submission, or false. */
function fetch_hold_by_submission(int $submission_id): array|false {
    if ($submission_id <= 0) return false;
    return db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name
         FROM holds h
         JOIN units u ON u.id = h.unit_id
         JOIN rooms r ON r.id = u.room_id
         WHERE h.submission_id = :sid
         ORDER BY h.id DESC LIMIT 1",
        [':sid' => $submission_id]
    )->fetch();
}
```

- [ ] **Step 4: Run the test, confirm PASS**

Run: `php tests/convert_logic.php`
Expected: all `PASS`, `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/db.php tests/convert_logic.php
git commit -m "feat: db helpers for convert-enquiry-to-hold"
```

---

## Task 2: Flash + Convert card rendering (no POST yet)

**Files:**
- Modify: `admin/submission-view.php`

Read the file first. It: `require_login()`s, fetches `$sub` (with `room_name`, `room_slug`, `tour_name`), handles a `delete` POST, renders header + Guest Details card + Tracking card + Danger Zone, includes `_layout.php` / `_layout_end.php`, uses `e()`, `csrf_field()`.

- [ ] **Step 1: Load the linked hold + a session flash near the top**

In `admin/submission-view.php`, immediately AFTER the `$sub` existence check (the `if (!$sub) { … exit; }` block) and BEFORE the delete handler, add:

```php
require_once __DIR__ . '/../includes/booking.php'; // make_manage_url()

// Flash (set by the convert handler on redirect)
$flash = $_SESSION['sub_flash'] ?? null;
unset($_SESSION['sub_flash']);

// Is this submission already converted to a hold?
$linked_hold = fetch_hold_by_submission($id);
```

> `session_init()` is already called inside `require_login()` (via `includes/auth.php`), so `$_SESSION` is available. If a lint/runtime check shows the session isn't started here, add `session_init();` before reading `$_SESSION`.

- [ ] **Step 2: Render the flash banner**

Right after `include __DIR__ . '/_layout.php';` (which opens the page body) and before the `<div class="page-header">`, add:

```php
<?php if ($flash): ?>
<div class="card" style="border-left:4px solid <?= $flash['type']==='error' ? 'var(--red,#dc2626)' : 'var(--green,#16a34a)' ?>;margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($flash['msg']) ?></div>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Add the Convert card (banner OR form) before the Danger Zone**

Immediately BEFORE the `<!-- Delete -->` card, insert:

```php
<!-- Convert to hold -->
<div class="card">
  <div class="card__head"><span class="card__title">Convert to Hold</span></div>
  <div class="card__body" style="padding:20px">
  <?php if ($linked_hold):
      $lh_code = $linked_hold['access_code'] ?? '';
      $lh_link = make_manage_url((int)$linked_hold['id']);
      $lh_badge = match($linked_hold['status']) {
          'pending' => 'badge--blue', 'confirmed' => 'badge--green',
          'cancelled' => 'badge--red', 'expired' => 'badge--grey', default => 'badge--grey',
      };
  ?>
    <p style="margin:0 0 12px;font-size:14px">
      Converted to
      <a href="/admin/holds.php"><strong>Hold #<?= (int)$linked_hold['id'] ?></strong></a>
      <span class="badge <?= $lh_badge ?>" style="vertical-align:middle"><?= e($linked_hold['status']) ?></span>
      — <?= e($linked_hold['room_name']) ?>,
      <?= e(date('d M Y', strtotime($linked_hold['check_in']))) ?> → <?= e(date('d M Y', strtotime($linked_hold['check_out']))) ?>
    </p>
    <div style="font-size:13px;color:var(--muted)">
      Booking code: <strong style="font-family:monospace;letter-spacing:1px;color:var(--text,#111)"><?= e($lh_code ?: '—') ?></strong>
      <?php if ($lh_link): ?>
      <button type="button" class="copy-link" data-link="<?= e($lh_link) ?>"
              style="margin-left:6px;font-size:11px;padding:1px 7px;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#fff">Copy portal link</button>
      <?php endif; ?>
    </div>
  <?php else:
      $ru_options = fetch_room_unit_options();
      // Preselect the first active unit of the submission's room, if any
      $prefill_unit = 0;
      foreach ($ru_options as $o) { if ((int)$o['room_id'] === (int)$sub['room_id']) { $prefill_unit = (int)$o['unit_id']; break; } }
  ?>
    <?php if (!$ru_options): ?>
      <p style="margin:0;font-size:14px;color:var(--muted)">No availability units are set up yet, so a hold can't be created. Add units to a room first (Rooms admin).</p>
    <?php else: ?>
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Creates a 24h hold from this enquiry, generates a booking code, and blocks the dates. Availability is not checked — you control overlaps.</p>
    <form method="POST" action="/admin/submission-view?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="convert">
      <div class="detail-grid">
        <div>
          <div class="detail-item__label">Room / Unit</div>
          <select name="unit_id" required style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
            <option value="">— select —</option>
            <?php foreach ($ru_options as $o): ?>
            <option value="<?= (int)$o['unit_id'] ?>" <?= (int)$o['unit_id'] === $prefill_unit ? 'selected' : '' ?>>
              <?= e($o['room_name']) ?> — <?= e($o['unit_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="detail-item__label">Check-in</div>
          <input type="date" name="check_in" required value="<?= e($sub['check_in'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Check-out</div>
          <input type="date" name="check_out" required value="<?= e($sub['check_out'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest name</div>
          <input type="text" name="guest_name" required value="<?= e($sub['guest_name'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest email</div>
          <input type="email" name="guest_email" required value="<?= e($sub['guest_email'] ?? '') ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
      </div>
      <?php if (!empty($sub['guest_phone']) || !empty($sub['guests_adults']) || !empty($sub['guests_children'])): ?>
      <p style="margin:12px 0 0;font-size:12px;color:var(--muted)">
        For reference (not stored on the hold):
        <?= !empty($sub['guest_phone']) ? 'phone ' . e($sub['guest_phone']) . '; ' : '' ?>
        <?= (int)($sub['guests_adults'] ?? 0) ?> adult(s)<?= (int)($sub['guests_children'] ?? 0) ? ', ' . (int)$sub['guests_children'] . ' child(ren)' : '' ?>
      </p>
      <?php endif; ?>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:16px"
              onclick="return confirm('Create a hold from this enquiry?')">Create Hold</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
```

- [ ] **Step 4: Add the clipboard script (mirror admin/holds.php) before `_layout_end.php`**

Immediately BEFORE the final `<?php include __DIR__ . '/_layout_end.php'; ?>`, add:

```php
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.copy-link');
  if (!b) return;
  var link = b.getAttribute('data-link');
  var done = function () { var t = b.textContent; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = t; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(link).then(done).catch(function () { window.prompt('Copy this portal link:', link); });
  } else {
    window.prompt('Copy this portal link:', link);
  }
});
</script>
```

- [ ] **Step 5: Verify syntax + helper wiring**

Run: `php -l admin/submission-view.php`
Expected: no syntax errors.

Confirm the page still auth-gates (renders nothing without login) and the helpers resolve:
Run: `php -r 'require "includes/db.php"; require "includes/booking.php"; $o=fetch_room_unit_options(); echo "unit options: ".count($o)."\n"; echo $o? "first: ".$o[0]["room_name"]." — ".$o[0]["unit_name"]."\n" : "";'`
Expected: prints a non-zero count and a sample "Room — Unit".

- [ ] **Step 6: Commit**

```bash
git add admin/submission-view.php
git commit -m "feat(admin): convert-to-hold card (banner + form) on submission view"
```

---

## Task 3: The `action=convert` POST handler

**Files:**
- Modify: `admin/submission-view.php` (add the handler next to the existing delete handler)

- [ ] **Step 1: Add the convert handler**

In `admin/submission-view.php`, immediately AFTER the existing delete handler block (`if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') { … exit; }`), add:

```php
// Convert enquiry → hold (force-create; no availability check by design)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert') {
    verify_csrf();
    require_once __DIR__ . '/../includes/booking.php';

    $redirect = '/admin/submission-view?id=' . $id;

    // Guard: don't create a second hold for the same submission
    if (fetch_hold_by_submission($id)) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'This enquiry is already converted to a hold.'];
        header('Location: ' . $redirect); exit;
    }

    $str      = fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $unit_id  = (int)($_POST['unit_id'] ?? 0);
    $check_in = $str($_POST['check_in']  ?? '');
    $check_out= $str($_POST['check_out'] ?? '');
    $g_name   = $str($_POST['guest_name']  ?? '');
    $g_email  = $str($_POST['guest_email'] ?? '');

    $is_date  = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    $unit_ok  = $unit_id > 0 && db_query("SELECT 1 FROM units WHERE id = :id AND is_active = TRUE", [':id' => $unit_id])->fetchColumn();

    $err = '';
    if (!$unit_ok)                                       $err = 'Please choose a valid room / unit.';
    elseif (!$is_date($check_in) || !$is_date($check_out)) $err = 'Please enter valid check-in and check-out dates.';
    elseif ($check_in >= $check_out)                    $err = 'Check-out must be after check-in.';
    elseif ($g_name === '')                             $err = 'Guest name is required.';
    elseif (!filter_var($g_email, FILTER_VALIDATE_EMAIL)) $err = 'A valid guest email is required.';

    if ($err) {
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => $err];
        header('Location: ' . $redirect); exit;
    }

    try {
        $hold_id = create_hold_with_block($unit_id, $id, $check_in, $check_out, $g_name, $g_email);
        audit_log('hold.create_from_submission', 'hold', $hold_id,
                  "from submission #{$id} — {$g_name} {$check_in}→{$check_out}");
        $_SESSION['sub_flash'] = ['type' => 'success', 'msg' => "Hold #{$hold_id} created from this enquiry."];
    } catch (Throwable $e) {
        error_log('[convert-to-hold] failed: ' . $e->getMessage());
        $_SESSION['sub_flash'] = ['type' => 'error', 'msg' => 'Could not create the hold. Please try again.'];
    }
    header('Location: ' . $redirect); exit;
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l admin/submission-view.php`
Expected: no syntax errors.

- [ ] **Step 3: Simulate the full convert path against a real submission (proves create + link + code + block + guard)**

This machine has NO `DATABASE_URL` — use `php -r`. This creates a hold from an existing submission exactly as the handler does, verifies the links, then cleans up:

```bash
php -r '
require "includes/db.php";
$sid = (int)db()->query("SELECT id FROM submissions ORDER BY id LIMIT 1")->fetchColumn();
$uid = (int)db()->query("SELECT id FROM units WHERE is_active=TRUE LIMIT 1")->fetchColumn();
echo "submission=$sid unit=$uid\n";
$hid = create_hold_with_block($uid, $sid, "2027-05-01", "2027-05-04", "Convert Test", "convert@example.com");
$h = db_query("SELECT id, access_code, submission_id, status FROM holds WHERE id=:id", [":id"=>$hid])->fetch();
echo "hold {$h["id"]} code {$h["access_code"]} submission_id {$h["submission_id"]} status {$h["status"]}\n";
require "includes/db.php";
$linked = fetch_hold_by_submission($sid);
echo "fetch_hold_by_submission finds it: ".($linked && (int)$linked["id"]===$hid ? "YES" : "NO")."\n";
$blk = (int)db_query("SELECT COUNT(*) FROM availability_blocks WHERE hold_id=:h", [":h"=>$hid])->fetchColumn();
echo "availability block created: ".($blk? "YES":"NO")."\n";
// cleanup
db_query("DELETE FROM availability_blocks WHERE hold_id=:h", [":h"=>$hid]);
db_query("DELETE FROM holds WHERE id=:h", [":h"=>$hid]);
echo "cleaned up\n";
'
```
Expected: prints the hold id + a 6-char code, `submission_id` equal to the submission, status `pending`; `fetch_hold_by_submission finds it: YES`; `availability block created: YES`; `cleaned up`. No errors.

- [ ] **Step 4: Commit**

```bash
git add admin/submission-view.php
git commit -m "feat(admin): action=convert handler force-creates a hold from an enquiry"
```

---

## Task 4: End-to-end (admin login) + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Start the dev server**

Run (background): `php -S localhost:8765 router.php`

- [ ] **Step 2: Confirm the page auth-gates**

Run: `curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8765/admin/submission-view?id=1"`
Expected: `302` (redirect to login) — the convert action is admin-only.

- [ ] **Step 3: Full click-through (requires an admin login in the browser)**

Log into `/admin`, open a submission that has a room + dates (Submissions inbox → a booking enquiry). In the **Convert to Hold** card:
1. Confirm Room/Unit is preselected to the enquiry's room (if it has units) and dates are prefilled.
2. Click **Create Hold** → confirm → you're returned to the submission with a green "Hold #N created" flash and the card now shows the **Converted → Hold #N** banner with the booking code + Copy portal link.
3. Reload / resubmit → the form is gone; attempting convert again is blocked ("already converted").
4. Open `/admin/holds.php` → the new hold appears (with its code + link, from the earlier feature).
5. Open the portal link → the guest booking page loads that hold.

- [ ] **Step 4: Validation checks (via browser or by removing the prefill and submitting)**

- Submit with check-out before check-in → red flash "Check-out must be after check-in.", no hold created.
- Submit with an empty email (edit the field) → red flash about email.

- [ ] **Step 5: Clean up any test holds created during E2E**

For each test hold you created by hand, remove it and its block (replace `<HID>`):
```bash
php -r '$h=(int)($argv[1]??0); if(!$h){echo "pass hold id\n";exit;} require "includes/db.php"; db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]); echo "removed hold $h\n";' <HID>
```
(The linked submission is preserved by design — only remove holds you created for testing.)

- [ ] **Step 6: Stop the server**

Run: `pkill -f "php -S localhost:8765"`

- [ ] **Step 7: Run the logic test once more**

Run: `php tests/convert_logic.php`
Expected: `ALL PASS`.

---

## Self-Review Notes (author checklist)

- **Spec coverage:** convert card on submission-view (Task 2), force-create no-availability-check (Task 3 `create_hold_with_block` direct call), keep+link submission (banner via `fetch_hold_by_submission`, Task 1/2), double-convert guard (Task 3 Step 1), code+portal link on banner (Task 2), read-only phone/adults/children (Task 2 form), helpers (Task 1), no migration. ✔
- **Type/signature consistency:** `fetch_hold_by_submission(int): array|false`, `fetch_room_unit_options(): array`, `create_hold_with_block(int,int,string,string,string,string): int`, `make_manage_url(int): string`, `audit_log(string,string,int,string)` — used consistently across tasks.
- **Flash:** `$_SESSION['sub_flash']` is written by the handler (Task 3) and read+displayed (Task 2 Steps 1–2). Order in the file: handlers run before render, so the flash set during a prior request (redirect) is what shows.
- **No placeholders / all code shown.** Escaping via `e()` throughout; POST is `verify_csrf()`-gated; inputs `is_scalar`-hardened; unit existence validated against the DB.
