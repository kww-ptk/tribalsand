# Check-in Guest Management & Fill-then-Sign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an owner/venue-manager fill any guest's passport details (and upload the scan), add/remove adults & children, all from the admin check-in tab; and make the co-guest link show a "review & sign" state when a guest's details are already filled.

**Architecture:** New server-authed actions in `admin/booking.php`'s existing check-in-tab POST dispatcher (PRG, `can_view_guest_docs`-gated), reusing the `checkin_guests` model and the same logic as `api/checkin-guest.php` / `api/checkin-upload.php`. A pure `checkin_coguest_view_state()` helper drives the co-guest page's new state. No DB columns, no booking-model change. The signature is never set by admin (A's integrity rule stands).

**Tech Stack:** Vanilla PHP 8.5, PostgreSQL via PDO, vanilla server-rendered forms (PRG, no new JS), logic tests via `php tests/checkin_logic.php`.

**Spec:** `docs/superpowers/specs/2026-08-10-checkin-guest-management-design.md`

**Branch:** `feature/checkin-guest-management` (already created; spec committed; stacked on `feature/checkin-signature-consent`).

**Conventions:** `can_view_guest_docs()` gating; `verify_csrf()` (already called once at the top of the POST block); `client_ip()`; `storage_put_private` / `storage_delete_private`; `checkin_recompute_completion()`; `audit_log()`.

---

## Task 1: Pure helper — `checkin_coguest_view_state()`

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/checkin_logic.php`, add before the final `echo $failures ...` line:

```php
// ── Co-guest view state (pure) ──────────────────────────────────────────────
$cfgPW = ['passport'=>['enabled'=>true,'required'=>true], 'waiver'=>['enabled'=>true,'required'=>true]];
$ciDone = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k','waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06','waiver_signature'=>'sig'];
$ciPass = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k']; // passport done, unsigned
check('coguest state done',         checkin_coguest_view_state($ciDone, $cfgPW) === 'done');
check('coguest state review_sign',  checkin_coguest_view_state($ciPass, $cfgPW) === 'review_sign');
check('coguest state full',         checkin_coguest_view_state(['passport_name'=>'A'], $cfgPW) === 'full');
$cfgWaiverOnly = ['passport'=>['enabled'=>false], 'waiver'=>['enabled'=>true]];
check('coguest waiver-only=review', checkin_coguest_view_state([], $cfgWaiverOnly) === 'review_sign');
$cfgPassOnly = ['passport'=>['enabled'=>true], 'waiver'=>['enabled'=>false]];
check('coguest passport-only done', checkin_coguest_view_state($ciPass, $cfgPassOnly) === 'done');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_coguest_view_state()`

- [ ] **Step 3: Implement the helper**

In `includes/checkin.php`, add in the "Multi-guest per booking" section (e.g. after `checkin_guest_complete()`):

```php
/**
 * Which state the co-guest self-service page renders for guest $me:
 *   'done'        — nothing left (passport where enabled + waiver signed),
 *   'review_sign' — details are complete; only the signature remains,
 *   'full'        — passport still needs to be provided.
 * Pure. $config is checkin_config()-shaped (per-step ['enabled'=>bool]).
 */
function checkin_coguest_view_state(?array $me, array $config): string {
    $passOk   = empty($config['passport']['enabled']) || checkin_guest_passport_complete($me);
    $waiverOk = empty($config['waiver']['enabled'])   || checkin_guest_waiver_signed($me);
    if ($passOk && $waiverOk) return 'done';
    return $passOk ? 'review_sign' : 'full';
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: the 6 new lines PASS; suite ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): checkin_coguest_view_state() helper"
```

---

## Task 2: Admin guest-management actions

**Files:**
- Modify: `admin/booking.php` (insert new actions after the `guest_count_set` block, i.e. after the block that ends at the `header(... &tab=checkin"); exit; }` around line 138, before the `}` that closes the POST handler at ~line 139)

- [ ] **Step 1: Add the action block**

In `admin/booking.php`, immediately after the closing `}` of the `guest_count_set` action and before the `}` that closes the `if ($_SERVER['REQUEST_METHOD'] === 'POST') {` block, insert:

```php
    // ── Guest management (owner / venue-manager) — sub-project B ─────────────
    if (in_array($act, ['guest_fill','guest_upload','guest_add_adult','guest_add_child','guest_remove'], true)
        && checkin_supported() && can_view_guest_docs($holdId)) {

        $gs  = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;
        $gid = (int)($_POST['guest_id'] ?? 0);

        if ($act === 'guest_fill' && $gid > 0) {
            db_query("UPDATE checkin_guests SET passport_name=:n, passport_number=:num, nationality=:nat, passport_expiry=:exp WHERE id=:g AND hold_id=:h",
                [':n'=>$gs('passport_name'), ':num'=>$gs('passport_number'), ':nat'=>$gs('nationality'), ':exp'=>$gs('passport_expiry'), ':g'=>$gid, ':h'=>$holdId]);
            audit_log('checkin.guest_fill', 'hold', $holdId, 'guest ' . $gid);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Guest details saved.'];

        } elseif ($act === 'guest_upload' && $gid > 0) {
            require_once __DIR__ . '/../includes/storage.php';
            $f = $_FILES['passport'] ?? null;
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'No file received.'];
            } elseif (($f['size'] ?? 0) > 8 * 1024 * 1024) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'File too large (max 8 MB).'];
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
                if (!isset($allowed[$mime])) {
                    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'File must be JPG, PNG or PDF.'];
                } else {
                    $key = 'checkin/' . $holdId . '/' . $gid . '/' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
                    if (storage_put_private($f['tmp_name'], $key, $mime)) {
                        $prev = db_query('SELECT passport_file_key FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetch();
                        db_query('UPDATE checkin_guests SET passport_file_key=:k WHERE id=:g AND hold_id=:h', [':k'=>$key, ':g'=>$gid, ':h'=>$holdId]);
                        if ($prev && !empty($prev['passport_file_key']) && $prev['passport_file_key'] !== $key) { try { storage_delete_private($prev['passport_file_key']); } catch (Throwable $e) {} }
                        audit_log('checkin.guest_upload', 'hold', $holdId, 'guest ' . $gid);
                        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Passport scan uploaded.'];
                    } else {
                        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Upload failed — try again.'];
                    }
                }
            }

        } elseif ($act === 'guest_add_adult') {
            db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, passport_name) VALUES (:h, FALSE, FALSE, :n)', [':h'=>$holdId, ':n'=>$gs('passport_name')]);
            $newGid = (int) db()->lastInsertId();
            $adults = (int) db_query('SELECT COUNT(*) FROM checkin_guests WHERE hold_id=:h AND is_child=FALSE', [':h'=>$holdId])->fetchColumn();
            if ($adults > (int)($hold['guest_count'] ?? 1)) {
                db_query('UPDATE holds SET guest_count=:n WHERE id=:h', [':n'=>$adults, ':h'=>$holdId]);
            }
            audit_log('checkin.guest_add', 'hold', $holdId, 'adult ' . $newGid);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Adult added.'];

        } elseif ($act === 'guest_add_child') {
            $parent = (int)($_POST['parent_guest_id'] ?? 0);
            $okParent = $parent > 0 && db_query('SELECT 1 FROM checkin_guests WHERE id=:p AND hold_id=:h AND is_child=FALSE', [':p'=>$parent, ':h'=>$holdId])->fetchColumn();
            if ($okParent) {
                $dob = ($_POST['date_of_birth'] ?? '') !== '' ? (string)$_POST['date_of_birth'] : null;
                db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, parent_guest_id, passport_name, date_of_birth) VALUES (:h, FALSE, TRUE, :p, :n, :dob)',
                    [':h'=>$holdId, ':p'=>$parent, ':n'=>$gs('passport_name'), ':dob'=>$dob]);
                audit_log('checkin.guest_add', 'hold', $holdId, 'child of ' . $parent);
                $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Child added.'];
            } else {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Pick a valid adult to add the child under.'];
            }

        } elseif ($act === 'guest_remove' && $gid > 0) {
            require_once __DIR__ . '/../includes/storage.php';
            $row = db_query('SELECT is_lead FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetch();
            if (!$row || !empty($row['is_lead'])) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'The lead guest can’t be removed.'];
            } else {
                $files = db_query('SELECT passport_file_key FROM checkin_guests WHERE (id=:g OR parent_guest_id=:g) AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetchAll(PDO::FETCH_COLUMN);
                db_query('DELETE FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId]);
                foreach ($files as $fk) { if (!empty($fk)) { try { storage_delete_private($fk); } catch (Throwable $e) {} } }
                audit_log('checkin.guest_remove', 'hold', $holdId, 'guest ' . $gid);
                $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Guest removed.'];
            }
        }

        checkin_recompute_completion($holdId);
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
    }
```

- [ ] **Step 2: Verify PHP parses**

Run: `php -l admin/booking.php`
Expected: `No syntax errors detected in admin/booking.php`

- [ ] **Step 3: Verify no test regressions**

Run: `php tests/checkin_logic.php`
Expected: `ALL PASS` (endpoint logic is verified E2E in Task 5).

- [ ] **Step 4: Commit**

```bash
git add admin/booking.php
git commit -m "feat(checkin): admin guest-management actions (fill/upload/add/remove)"
```

---

## Task 3: Admin UI — edit, upload, roster controls

**Files:**
- Modify: `admin/_ws_checkin.php`

- [ ] **Step 1: Add an admin-actions row per adult**

In `admin/_ws_checkin.php`, find the end of each adult's main `<tr>` … `</tr>` (the block that ends at the `</tr>` right before `<?php if ($__kids): ?>`, around line 107). Immediately after that `</tr>`, insert this admin-actions row:

```php
      <?php if ($__canDocs): ?>
      <tr class="ci-admin-row">
        <td colspan="7" style="background:var(--bg-alt,#f7f7f5);padding:10px 12px">
          <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
            <details>
              <summary class="btn-sm btn-outline" style="cursor:pointer;display:inline-block">Edit details</summary>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:10px 0 0;display:grid;gap:6px;max-width:320px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_fill">
                <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
                <input class="inp inp--sm" name="passport_name" value="<?= e((string)($g['passport_name'] ?? '')) ?>" placeholder="Full name (as on passport)">
                <input class="inp inp--sm" name="passport_number" value="<?= e((string)($g['passport_number'] ?? '')) ?>" placeholder="Passport number">
                <input class="inp inp--sm" name="nationality" value="<?= e((string)($g['nationality'] ?? '')) ?>" placeholder="Nationality">
                <input class="inp inp--sm" type="date" name="passport_expiry" value="<?= e((string)($g['passport_expiry'] ?? '')) ?>">
                <button type="submit" class="btn-sm btn-primary">Save details</button>
              </form>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" enctype="multipart/form-data" style="margin:10px 0 0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_upload">
                <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
                <input type="file" name="passport" accept="image/jpeg,image/png,application/pdf" required>
                <button type="submit" class="btn-sm btn-outline">Upload scan</button>
              </form>
            </details>

            <details>
              <summary class="btn-sm btn-outline" style="cursor:pointer;display:inline-block">+ Add child</summary>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:10px 0 0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_add_child">
                <input type="hidden" name="parent_guest_id" value="<?= (int)$g['id'] ?>">
                <input class="inp inp--sm" name="passport_name" placeholder="Child's full name" required>
                <input class="inp inp--sm" type="date" name="date_of_birth" aria-label="Date of birth">
                <button type="submit" class="btn-sm btn-outline">Add child</button>
              </form>
            </details>

            <?php if (empty($g['is_lead'])): ?>
            <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0" onsubmit="return confirm('Remove this guest and their children from the booking?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="guest_remove">
              <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
              <button type="submit" class="btn-sm btn-outline" style="color:#b23">Remove guest</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
```

- [ ] **Step 2: Add "+ Add adult" below the table**

In `admin/_ws_checkin.php`, find the `</table>` that closes the guests table (around line 125, inside `<div class="table-wrap">`). Immediately after the `</div>` that closes `.table-wrap` (around line 126), insert:

```php
  <?php if ($__canDocs): ?>
  <div style="padding:12px">
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="guest_add_adult">
      <input class="inp inp--sm" name="passport_name" placeholder="New adult's name (optional)">
      <button type="submit" class="btn-sm btn-outline">+ Add adult</button>
      <span class="text-muted" style="font-size:12px">Adding past the party size raises it automatically.</span>
    </form>
  </div>
  <?php endif; ?>
```

Note: the "No guest identity captured yet" branch (when `!$__adults`) has no table; the add-adult form above sits inside the `<?php else: ?>` (adults exist) branch. To allow adding the first adult when the roster is empty, also add the same `+ Add adult` form inside the `<?php if (!$__adults): ?>` branch (right after the "No guest identity captured yet." paragraph, around line 72), wrapped in the same `<?php if ($__canDocs): ?> … <?php endif; ?>`.

- [ ] **Step 3: Verify PHP parses**

Run: `php -l admin/_ws_checkin.php`
Expected: `No syntax errors detected in admin/_ws_checkin.php`

- [ ] **Step 4: Commit**

```bash
git add admin/_ws_checkin.php
git commit -m "feat(checkin): admin edit/upload/roster controls in the check-in tab"
```

---

## Task 4: Co-guest review-and-sign state

**Files:**
- Modify: `includes/app/checkin-guest.php`

- [ ] **Step 1: Compute the state**

In `includes/app/checkin-guest.php`, replace these two lines (14–15):

```php
$meComplete = (!$showPassport || checkin_guest_passport_complete($me)) && (!$showWaiver || checkin_guest_waiver_signed($me));
$done   = $meComplete || !empty($_GET['done']);
```
with:
```php
$state  = checkin_coguest_view_state($me, checkin_config());
$done   = $state === 'done' || !empty($_GET['done']);
```

- [ ] **Step 2: Make the hero state-aware**

In the same file, replace the hero sub line (around line 50):

```php
        <p class="ci-hero__sub">You've been added to a booking at <strong><?= e($stayLoc) ?></strong> (<?= e(date('j M', strtotime((string)$hold['check_in']))) ?> – <?= e(date('j M', strtotime((string)$hold['check_out']))) ?>). Please add your passport<?= $showWaiver ? ' and sign the waiver' : '' ?>.</p>
```
with:
```php
        <p class="ci-hero__sub"><?php if ($state === 'review_sign'): ?>Your details are already on file for <strong><?= e($stayLoc) ?></strong> — just sign below to finish.<?php else: ?>You've been added to a booking at <strong><?= e($stayLoc) ?></strong> (<?= e(date('j M', strtotime((string)$hold['check_in']))) ?> – <?= e(date('j M', strtotime((string)$hold['check_out']))) ?>). Please add your passport<?= $showWaiver ? ' and sign the waiver' : '' ?>.<?php endif; ?></p>
```

- [ ] **Step 3: Collapse the passport block on review_sign**

In the same file, wrap the passport block so it starts collapsed when the details are already complete. Replace the opening of the passport block (line 58):

```php
          <?php if ($showPassport): ?>
          <label class="ci-l">Full name (as on passport)</label>
```
with:
```php
          <?php if ($showPassport): ?>
          <?php if ($state === 'review_sign'): ?>
          <details class="ci-review">
            <summary>Your details are on file — tap to review or edit</summary>
          <?php endif; ?>
          <label class="ci-l">Full name (as on passport)</label>
```

Then replace the closing of the passport block (line 72):

```php
          </div>
          <?php endif; ?>
          <?php if ($showWaiver): ?>
```
with:
```php
          </div>
          <?php if ($state === 'review_sign'): ?></details><?php endif; ?>
          <?php endif; ?>
          <?php if ($showWaiver): ?>
```

(Note: line 72's `</div>` closes the `.ci-upload` div; the `<?php endif; ?>` closes `if ($showPassport)`. The added `</details>` closes the review wrapper between them.)

- [ ] **Step 4: Add the `.ci-review` style**

Append to the end of `css/portal-app.css`:

```css
/* Co-guest review-and-sign: collapsed details summary */
.ci-review { margin: 4px 0 10px; }
.ci-review > summary { cursor: pointer; font-size: 13px; color: #123c30; font-weight: 600; padding: 8px 0; }
.ci-review[open] > summary { margin-bottom: 8px; }
```

- [ ] **Step 5: Verify PHP parses**

Run: `php -l includes/app/checkin-guest.php`
Expected: `No syntax errors detected in includes/app/checkin-guest.php`

- [ ] **Step 6: Commit**

```bash
git add includes/app/checkin-guest.php css/portal-app.css
git commit -m "feat(checkin): co-guest review-and-sign state via view-state helper"
```

---

## Task 5: Full end-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole logic suite**

Run: `php tests/checkin_logic.php` then `for f in tests/*_logic.php; do echo "$f:"; php "$f" 2>&1 | tail -1; done`
Expected: `ALL PASS` for check-in; only the 2 pre-existing `team_logic.php` failures elsewhere.

- [ ] **Step 2: Create a throwaway booking + note its admin URL and lead ref**

```bash
php -r '
require "includes/db.php"; require "includes/booking.php";
$u = db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id LIMIT 1")->fetchColumn();
db_query("INSERT INTO holds (unit_id,check_in,check_out,guest_name,guest_email,status,expires_at,require_checkin,guest_count) VALUES (:u,CURRENT_DATE+30,CURRENT_DATE+33,:n,:e,\"confirmed\",now(),TRUE,1)", [":u"=>(int)$u,":n"=>"ZZ B Test",":e"=>"zz-b@example.com"]);
$h=(int)db()->lastInsertId();
echo "HOLD=$h  admin: /admin/booking.php?hold=$h&tab=checkin  ref=".make_guest_ref($h)."\n";
'
```

- [ ] **Step 3: Admin drive (logged in as owner) via the dev server**

Start `.claude/launch.json` "tribalsand". Log into `/admin`. Open `/admin/booking.php?hold=<HOLD>&tab=checkin`. Verify (there are no guest rows yet, so first use **+ Add adult**):
1. **+ Add adult** (leave name blank) → an adult row appears; the party-size auto-bump keeps guest_count ≥ adults.
2. On that adult, **Edit details** → fill name/number/nationality/expiry → Save → values show in the row.
3. **Upload scan** → pick a small JPG/PNG/PDF → the Scan column shows "View scan →".
4. **+ Add child** under the adult → a child appears in the kids row.
5. **+ Add adult** again → second adult; **Remove guest** on it → confirm → it disappears.

- [ ] **Step 4: Confirm via DB**

```bash
php -r '
require "includes/db.php"; $h=(int)$argv[1];
foreach (db_query("SELECT id,is_lead,is_child,passport_name,nationality,passport_number IS NOT NULL AS has_num,passport_file_key IS NOT NULL AS has_scan FROM checkin_guests WHERE hold_id=:h ORDER BY is_child, id",[":h"=>$h])->fetchAll() as $r) print_r($r);
echo "guest_count=".db_query("SELECT guest_count FROM holds WHERE id=:h",[":h"=>$h])->fetchColumn()."\n";
' <HOLD>
```
Expected: the filled adult shows `has_num`/`has_scan` = t; the child row present; `guest_count` bumped to the adult count.

- [ ] **Step 5: Co-guest review-and-sign**

Get the filled adult's `?g=` link (from the admin tab's "Sign on this device" link, or `php -r 'require "includes/db.php"; require "includes/booking.php"; echo make_guest_pass_url(<HOLD>, <GUEST_ID>)."\n";'`). Open it in a fresh browser window. Because the passport is already filled (by admin) but unsigned, confirm the page shows **"Your details are already on file … just sign below to finish"**, the passport fields are collapsed under **"Your details are on file — tap to review or edit"**, and the signature pad is the primary action. Draw a signature + tick + name → submit → reload the same link → shows **"You're all set"**.

- [ ] **Step 6: Clean up**

```bash
php -r 'require "includes/db.php"; db_query("DELETE FROM holds WHERE id=:h",[":h"=>(int)$argv[1]]); echo "deleted (cascade)\n";' <HOLD>
```

- [ ] **Step 7: Final commit (only if verification required tweaks)**

```bash
git add -A && git commit -m "test(checkin): verify guest-management + review-and-sign E2E" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- Admin fills passport fields → Task 2 (`guest_fill`) + Task 3 UI.
- Admin uploads scan → Task 2 (`guest_upload`) + Task 3 UI.
- Admin adds adults/kids, auto-bump → Task 2 (`guest_add_adult` bumps `guest_count`, `guest_add_child`) + Task 3 UI.
- Admin removes guests → Task 2 (`guest_remove`, lead-protected, deletes scans) + Task 3 UI.
- Gate `can_view_guest_docs` → Task 2 (server) + Task 3 (`$__canDocs` UI guard).
- Integrity (no signature by admin) → Task 2 never writes `waiver_*`.
- Co-guest review-and-sign + reload → Task 1 helper + Task 4 render.

**Placeholder scan:** none — every step has full code and exact commands/expected output.

**Type/name consistency:** action names (`guest_fill`/`guest_upload`/`guest_add_adult`/`guest_add_child`/`guest_remove`) are identical in the dispatcher (Task 2) and the forms (Task 3). `checkin_coguest_view_state` defined in Task 1, consumed in Task 4. Upload mirrors `api/checkin-upload.php` (finfo, 8 MB, `storage_put_private`, delete previous). Add/remove mirror `api/checkin-guest.php`. `checkin_recompute_completion($holdId)` runs after every mutating action.

**Note:** Task 2 requires `includes/storage.php` inside the `guest_upload` and `guest_remove` branches (not loaded by `admin/booking.php` otherwise) — the `require_once` lines are included in the code.
