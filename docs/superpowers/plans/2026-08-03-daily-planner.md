# Daily Planner / Itinerary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A day-by-day itinerary on the guest **Stay** tab (arrival → check-out, auto-including confirmed scheduled requests) + a dedicated admin page to add/edit/delete plan items.

**Architecture:** New `itinerary_items` table for admin entries. A `fetch_itinerary($hold)` helper merges auto anchors (check-in/out from the hold), confirmed scheduled `booking_addons`, and admin items into per-day buckets. Guest render is read-only in `stay.php`; admin CRUD in `admin/itinerary.php`.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via `db_query()`, vanilla CSS.

---

## Task 1: Migration + schema

**Files:** Create `db/migrations/add_itinerary.sql`; Modify `db/schema.sql`.

- [ ] **Step 1: Write `db/migrations/add_itinerary.sql`**
```sql
-- Migration: per-booking daily itinerary items (admin-authored)
-- Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS itinerary_items (
    id         SERIAL PRIMARY KEY,
    hold_id    INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    day        DATE NOT NULL,
    at_time    TIME,
    category   TEXT NOT NULL DEFAULT 'note'
               CHECK (category IN ('flight','transfer','tour','dining','activity','checkin','checkout','note')),
    title      TEXT NOT NULL,
    detail     TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_itin_hold_day ON itinerary_items (hold_id, day, at_time);
```

- [ ] **Step 2: Apply locally** — `php -r "require 'includes/db.php'; db()->exec(file_get_contents('db/migrations/add_itinerary.sql')); echo 'ok';"` → `ok`; re-run → `ok`.

- [ ] **Step 3: Verify** — `php -r "require 'includes/db.php'; var_dump(db_query('SELECT COUNT(*) FROM itinerary_items')->fetchColumn());"` → `int(0)`.

- [ ] **Step 4: Append the same CREATE TABLE + INDEX to the end of `db/schema.sql`** (matching how `booking_messages`/`guest_board_posts` were appended).

- [ ] **Step 5: Commit**
```bash
git add db/migrations/add_itinerary.sql db/schema.sql
git commit -m "feat(planner): itinerary_items table + migration"
```

---

## Task 2: Helpers + tests

**Files:** Modify `includes/booking.php`, `tests/portal_logic.php`.

- [ ] **Step 1: Add helpers to `includes/booking.php`** (after `fetch_booking_addons`)
```php
/** Map a booking_addons kind to an itinerary category. */
function _itin_map_kind(string $kind): string {
    if ($kind === 'tour' || $kind === 'transfer') return $kind;
    return in_array($kind, ['laundry','housekeeping','amenities','maintenance','restaurant'], true) ? 'activity' : 'note';
}

/**
 * Day-by-day itinerary for a hold: check-in → check-out, merging auto anchors,
 * confirmed scheduled requests, and admin itinerary_items. Guarded so the Stay
 * tab still renders (anchors only) if the table is absent pre-migration.
 */
function fetch_itinerary(array $hold): array {
    $start = new DateTime((string)$hold['check_in']);
    $end   = new DateTime((string)$hold['check_out']);
    $today = date('Y-m-d');
    $buckets = []; $order = [];
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $k = $d->format('Y-m-d'); $buckets[$k] = []; $order[] = $k;
    }
    $ciKey = $start->format('Y-m-d'); $coKey = $end->format('Y-m-d');
    $buckets[$ciKey][] = ['sort'=>0,   'time'=>null,'category'=>'checkin', 'title'=>'Check-in', 'detail'=>(string)($hold['room_name'] ?? ''),'source'=>'auto'];
    $buckets[$coKey][] = ['sort'=>2000,'time'=>null,'category'=>'checkout','title'=>'Check-out','detail'=>'','source'=>'auto'];

    try {
        $reqs = db_query(
            "SELECT ba.*, t.name AS tour_name FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
             WHERE ba.hold_id = :h AND ba.scheduled_for IS NOT NULL AND ba.status IN ('confirmed','completed')",
            [':h'=>(int)$hold['id']]
        )->fetchAll();
        foreach ($reqs as $r) {
            $ts = strtotime((string)$r['scheduled_for']); if ($ts === false) continue;
            $k = date('Y-m-d', $ts); if (!isset($buckets[$k])) continue;
            $min = (int)date('G',$ts)*60 + (int)date('i',$ts);
            $buckets[$k][] = ['sort'=>100+$min,'time'=>date('H:i',$ts),'category'=>_itin_map_kind((string)$r['kind']),'title'=>addon_label($r),'detail'=>'from your request','source'=>'request'];
        }
        $items = db_query("SELECT * FROM itinerary_items WHERE hold_id = :h", [':h'=>(int)$hold['id']])->fetchAll();
        foreach ($items as $it) {
            $k = (string)$it['day']; if (!isset($buckets[$k])) continue;
            if (!empty($it['at_time'])) {
                $ts = strtotime((string)$it['at_time']); $min = (int)date('G',$ts)*60 + (int)date('i',$ts);
                $sort = 100 + $min; $time = date('H:i', $ts);
            } else { $sort = 1500 + (int)$it['sort_order']; $time = null; }
            $buckets[$k][] = ['sort'=>$sort,'time'=>$time,'category'=>(string)$it['category'],'title'=>(string)$it['title'],'detail'=>(string)($it['detail'] ?? ''),'source'=>'admin'];
        }
    } catch (Throwable $e) { /* tables absent pre-migration — anchors still render */ }

    $out = []; $n = 0;
    foreach ($order as $k) {
        $n++;
        usort($buckets[$k], fn($a,$b) => $a['sort'] <=> $b['sort']);
        $out[] = ['date'=>$k,'label'=>'Day '.$n.' · '.(new DateTime($k))->format('D j M'),'is_today'=>($k===$today),'items'=>$buckets[$k]];
    }
    return $out;
}

/** Admin: raw itinerary rows for a hold. */
function fetch_itinerary_items(int $holdId): array {
    return db_query("SELECT * FROM itinerary_items WHERE hold_id = :h ORDER BY day, at_time NULLS LAST, sort_order", [':h'=>$holdId])->fetchAll();
}
```

- [ ] **Step 2: Tests in `tests/portal_logic.php`** (before the final summary)
```php
// ── itinerary ────────────────────────────────────────────────
$ihold = db()->query("SELECT id, check_in, check_out, guest_name FROM holds ORDER BY id DESC LIMIT 1")->fetch();
if ($ihold) {
    $hid = (int)$ihold['id'];
    $d2  = (new DateTime((string)$ihold['check_in']))->modify('+1 day')->format('Y-m-d');
    // an admin item on day 2 (10:00) + a confirmed scheduled tour on day 2 (06:00)
    db_query("INSERT INTO itinerary_items (hold_id, day, at_time, category, title, detail) VALUES (:h, :d, '10:00', 'dining', 'ZZ Dinner', 'Table for 2')", [':h'=>$hid, ':d'=>$d2]);
    db_query("INSERT INTO booking_addons (hold_id, kind, details, status, scheduled_for) VALUES (:h, 'tour', 'ZZ Safari', 'confirmed', :sf)", [':h'=>$hid, ':sf'=>$d2.' 06:00']);
    // an out-of-range admin item (100 days out) — must be excluded
    $far = (new DateTime((string)$ihold['check_out']))->modify('+100 day')->format('Y-m-d');
    db_query("INSERT INTO itinerary_items (hold_id, day, category, title) VALUES (:h, :d, 'note', 'ZZ FarAway')", [':h'=>$hid, ':d'=>$far]);

    $itin = fetch_itinerary(['id'=>$hid,'check_in'=>$ihold['check_in'],'check_out'=>$ihold['check_out'],'room_name'=>'Test Room']);
    $days = (new DateTime((string)$ihold['check_in']))->diff(new DateTime((string)$ihold['check_out']))->days + 1;
    check('itinerary spans the stay (inclusive)', count($itin) === $days);
    check('day 1 has the check-in anchor', $itin[0]['items'][0]['category'] === 'checkin');
    check('last day has the check-out anchor', in_array('checkout', array_column($itin[count($itin)-1]['items'],'category'), true));
    $day2 = null; foreach ($itin as $D) { if ($D['date'] === $d2) { $day2 = $D; break; } }
    check('day 2 found', $day2 !== null);
    $titles2 = array_column($day2['items'], 'title');
    check('day 2 has the confirmed tour + admin dinner', in_array('ZZ Safari', $titles2, true) && in_array('ZZ Dinner', $titles2, true));
    check('day 2 items in time order (06:00 tour before 10:00 dinner)',
          array_search('ZZ Safari',$titles2) < array_search('ZZ Dinner',$titles2));
    $allTitles = [];
    foreach ($itin as $D) foreach ($D['items'] as $I) $allTitles[] = $I['title'];
    check('out-of-range admin item excluded', !in_array('ZZ FarAway', $allTitles, true));

    db_query("DELETE FROM itinerary_items WHERE hold_id=:h AND title LIKE 'ZZ %'", [':h'=>$hid]);
    db_query("DELETE FROM booking_addons WHERE hold_id=:h AND details='ZZ Safari'", [':h'=>$hid]);
}
```

- [ ] **Step 3: Run + lint** — `php tests/portal_logic.php` → `ALL PASS`; `php -l includes/booking.php`.

- [ ] **Step 4: Commit**
```bash
git add includes/booking.php tests/portal_logic.php
git commit -m "feat(planner): fetch_itinerary merge helper + tests"
```

---

## Task 3: Guest "Your plan" on the Stay tab

**Files:** Modify `includes/app/stay.php`, `css/portal-app.css`.

- [ ] **Step 1: Add planner CSS to `css/portal-app.css`** (append)
```css
/* ── Daily planner ── */
.pa-planday{margin:0 0 14px}
.pa-planday__h{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--pa-muted);margin:0 0 8px}
.pa-planday--today .pa-planday__h{color:var(--pa-teal-d)}
.pa-planday__today{background:#e6eefb;color:#1a4a9c;font-size:10px;padding:1px 8px;border-radius:999px;text-transform:none;letter-spacing:0}
.pa-plantl{border-left:2px solid var(--pa-line);padding-left:14px;margin-left:6px;display:flex;flex-direction:column;gap:11px}
.pa-planit{display:flex;gap:9px;align-items:flex-start}
.pa-planit__ico{flex:0 0 auto;color:var(--pa-teal);margin-top:1px}
.pa-planit__t{font-size:14px;font-weight:500;color:var(--pa-ink);line-height:1.3}
.pa-planit__d{font-size:12px;color:var(--pa-muted);margin-top:2px}
.pa-planit__tag{display:inline-block;font-size:10px;background:#e6eefb;color:#1a4a9c;padding:0 7px;border-radius:999px;margin-left:6px;vertical-align:1px}
.pa-planempty{font-size:12px;color:var(--pa-muted);font-style:italic}
```

- [ ] **Step 2: Render "Your plan" at the TOP of `includes/app/stay.php`** (before the existing `<h2 class="pa-h2">Stay info</h2>` block). Insert an icon map + the loop:
```php
<?php
$__itin = fetch_itinerary($hold);
$__icat = [
  'checkin'  => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
  'checkout' => '<path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M14 17l5-5-5-5"/><path d="M19 12H7"/>',
  'flight'   => '<path d="M10 5l-7 7 2 1 5-2 3 6 2-1-1-7 4-3a1.5 1.5 0 0 0-2-2l-3 4-7-1-1 2z"/>',
  'transfer' => '<path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/><circle cx="8" cy="16" r="1"/><circle cx="16" cy="16" r="1"/>',
  'tour'     => '<circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>',
  'dining'   => '<path d="M6 3v7a2 2 0 0 0 4 0V3M8 10v11"/><path d="M17 3c-1.5 0-3 1.8-3 4.5S15.5 12 17 12v9"/>',
  'activity' => '<path d="M12 3l2.5 5.5L20 9l-4 4 1 6-5-3-5 3 1-6-4-4 5.5-.5z"/>',
  'note'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/>',
];
$__isvg = fn(string $cat) => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($__icat[$cat] ?? $__icat['note']) . '</svg>';
?>
<h2 class="pa-h2">Your plan</h2>
<p class="pa-sub">Your day-by-day itinerary. Tours and transfers you’ve booked appear automatically.</p>
<?php foreach ($__itin as $__day): ?>
<div class="pa-planday <?= $__day['is_today'] ? 'pa-planday--today' : '' ?>">
  <div class="pa-planday__h"><?= e($__day['label']) ?><?php if ($__day['is_today']): ?><span class="pa-planday__today">Today</span><?php endif; ?></div>
  <?php if (!$__day['items']): ?>
    <div class="pa-plantl"><span class="pa-planempty">Nothing planned — browse Activities or ask the concierge.</span></div>
  <?php else: ?>
    <div class="pa-plantl">
      <?php foreach ($__day['items'] as $__it): ?>
      <div class="pa-planit">
        <span class="pa-planit__ico"><?= $__isvg($__it['category']) ?></span>
        <div>
          <div class="pa-planit__t"><?php if ($__it['time']): ?><?= e($__it['time']) ?> · <?php endif; ?><?= e($__it['title']) ?><?php if ($__it['source'] === 'request'): ?><span class="pa-planit__tag">booked</span><?php endif; ?></div>
          <?php if (($__it['detail'] ?? '') !== '' && $__it['detail'] !== 'from your request'): ?><div class="pa-planit__d"><?= e($__it['detail']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div style="height:8px"></div>
```
(Keep the existing "Stay info" heading + cards + change form below this block — do not remove them.)

- [ ] **Step 3: Lint** — `php -l includes/app/stay.php`.

- [ ] **Step 4: Commit**
```bash
git add includes/app/stay.php css/portal-app.css
git commit -m "feat(planner): render Your plan timeline at top of the Stay tab"
```

---

## Task 4: Admin planner page

**Files:** Create `admin/itinerary.php`.

- [ ] **Step 1: Create `admin/itinerary.php`**
```php
<?php
/** Admin: per-booking daily itinerary editor. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$pageTitle  = 'Itinerary';
$activeMenu = 'holds';

$CATS = ['flight'=>'Flight','transfer'=>'Transfer','tour'=>'Tour','dining'=>'Dining','activity'=>'Activity','note'=>'Note'];

$holdId = (int)($_GET['hold'] ?? $_POST['hold_id'] ?? 0);
$hold = $holdId ? db_query(
    "SELECT h.*, r.name AS room_name FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE h.id=:id",
    [':id'=>$holdId]
)->fetch() : null;

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

if (!$hold) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Booking not found.']; header('Location: /admin/holds.php'); exit; }

// Valid stay dates (inclusive) for the day <select> + validation.
$__days = [];
for ($d = new DateTime((string)$hold['check_in']); $d <= new DateTime((string)$hold['check_out']); $d->modify('+1 day')) {
    $__days[$d->format('Y-m-d')] = $d->format('D j M Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $day   = (string)($_POST['day'] ?? '');
        $cat   = (string)($_POST['category'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $detail= trim((string)($_POST['detail'] ?? ''));
        $atRaw = trim((string)($_POST['at_time'] ?? ''));
        $at    = preg_match('/^\d{2}:\d{2}$/', $atRaw) ? $atRaw : null;
        if (!isset($__days[$day]))      $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Pick a day within the stay.'];
        elseif (!isset($CATS[$cat]))    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Pick a category.'];
        elseif ($title === '')          $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Title is required.'];
        else {
            db_query("INSERT INTO itinerary_items (hold_id, day, at_time, category, title, detail) VALUES (:h,:d,:t,:c,:ti,:de)",
                [':h'=>$holdId, ':d'=>$day, ':t'=>$at, ':c'=>$cat, ':ti'=>$title, ':de'=>($detail !== '' ? $detail : null)]);
            audit_log('itinerary.add', 'hold', $holdId, $title);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Item added.'];
        }
    } elseif ($action === 'delete') {
        $iid = (int)($_POST['item_id'] ?? 0);
        db_query("DELETE FROM itinerary_items WHERE id=:i AND hold_id=:h", [':i'=>$iid, ':h'=>$holdId]);
        audit_log('itinerary.delete', 'hold', $holdId, (string)$iid);
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Item removed.'];
    }
    header('Location: /admin/itinerary.php?hold=' . $holdId); exit;
}

$itin  = fetch_itinerary($hold);
$items = fetch_itinerary_items($holdId);   // admin rows (for delete buttons), keyed by id
$byId  = [];
foreach ($items as $it) { $byId[(int)$it['id']] = $it; }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Itinerary — <?= e($hold['guest_name'] ?: 'Guest') ?></h1>
  <a href="/admin/holds.php" class="btn-outline btn-sm">← Bookings</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px">
  <div class="card__head"><span class="card__title">Add item</span></div>
  <div class="card__body">
    <form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
      <label style="font-size:13px">Day<br>
        <select name="day" required>
          <?php foreach ($__days as $dv=>$dl): ?><option value="<?= e($dv) ?>"><?= e($dl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:13px">Time (optional)<br><input type="time" name="at_time"></label>
      <label style="font-size:13px">Category<br>
        <select name="category"><?php foreach ($CATS as $cv=>$cl): ?><option value="<?= e($cv) ?>"><?= e($cl) ?></option><?php endforeach; ?></select>
      </label>
      <label style="font-size:13px;flex:1;min-width:180px">Title<br><input type="text" name="title" required style="width:100%"></label>
      <label style="font-size:13px;flex:1;min-width:180px">Detail<br><input type="text" name="detail" style="width:100%"></label>
      <button type="submit" class="btn-primary">Add</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Plan (<?= e($hold['check_in']) ?> → <?= e($hold['check_out']) ?>)</span></div>
  <div class="card__body">
    <?php foreach ($itin as $day): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px"><?= e($day['label']) ?><?= $day['is_today'] ? ' · today' : '' ?></div>
      <?php if (!$day['items']): ?>
        <div style="font-size:13px;color:var(--muted);font-style:italic">—</div>
      <?php else: foreach ($day['items'] as $it): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid #f0f0f0;font-size:14px">
          <span style="min-width:44px;color:var(--muted)"><?= e($it['time'] ?? '—') ?></span>
          <span style="text-transform:capitalize;min-width:74px;color:var(--muted);font-size:12px"><?= e($it['category']) ?></span>
          <span style="flex:1"><strong><?= e($it['title']) ?></strong><?php if (($it['detail'] ?? '') !== '' && $it['detail'] !== 'from your request'): ?> <span style="color:var(--muted)">· <?= e($it['detail']) ?></span><?php endif; ?></span>
          <span style="font-size:11px;color:var(--muted)"><?= e($it['source']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($items): ?>
    <div style="margin-top:18px;border-top:1px solid #eee;padding-top:12px">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:8px">Your added items</div>
      <?php foreach ($items as $it): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:5px 0;font-size:14px">
        <span style="flex:1"><?= e((string)$it['day']) ?><?php if (!empty($it['at_time'])): ?> <?= e(substr((string)$it['at_time'],0,5)) ?><?php endif; ?> · <strong><?= e($it['title']) ?></strong> <span style="color:var(--muted);text-transform:capitalize">(<?= e($it['category']) ?>)</span></span>
        <form method="POST" onsubmit="return confirm('Remove this item?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn-danger btn-sm">Delete</button></form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Lint + smoke** — `php -l admin/itinerary.php`; `php -r "\$_SERVER['REQUEST_METHOD']='GET'; require 'admin/itinerary.php';" 2>&1 | head -3` (login redirect/exit is fine — no fatal/parse error).

- [ ] **Step 3: Commit**
```bash
git add admin/itinerary.php
git commit -m "feat(planner): admin itinerary editor page (add/delete + merged view)"
```

---

## Task 5: Links from admin Holds + Concierge Desk

**Files:** Modify `admin/holds.php`, `admin/concierge-desk.php`.

- [ ] **Step 1: Add a Plan link on each hold row in `admin/holds.php`.**
In the per-hold actions cell (the `<td>` containing the Confirm/Cancel forms, which ends with `</td>` before `</tr>`), add BEFORE the closing `</td>` a link that shows for every status:
```php
            <a href="/admin/itinerary.php?hold=<?= (int)$hold['id'] ?>" class="btn-outline btn-sm" style="margin-left:4px">Plan</a>
```
Place it so it renders regardless of the pending/confirmed/else branches (i.e. after the `<?php endif; ?>` that closes the status branches, still inside the `<td>`).

- [ ] **Step 2: Add a Plan link on each Concierge Desk row in `admin/concierge-desk.php`.**
In the Actions cell (near the Accept/Done/Decline/Message forms), add:
```php
<a href="/admin/itinerary.php?hold=<?= (int)$a['hold_id'] ?>" class="btn-outline btn-sm">Plan</a>
```

- [ ] **Step 3: Lint** — `php -l admin/holds.php && php -l admin/concierge-desk.php`.

- [ ] **Step 4: Commit**
```bash
git add admin/holds.php admin/concierge-desk.php
git commit -m "feat(planner): Plan links from Holds + Concierge Desk"
```

---

## Task 6: E2E + regression + cleanup

**Files:** none (verification only).

- [ ] **Step 1: Tests + lint** — `php tests/portal_logic.php && php tests/manage_logic.php && php tests/convert_logic.php` → three `ALL PASS`; `php -l` on all changed/new PHP.

- [ ] **Step 2: Browser E2E**
Start `php -S localhost:8765 router.php`. Seed a confirmed hold, a confirmed tour addon with a `scheduled_for` on day 2, and one `itinerary_items` row (flight) on day 1. Then:
  - Guest Stay tab (`booking.php?ref=…&view=stay`): "Your plan" shows Day 1 with Check-in + the flight item, Day 2 with the tour ("booked" tag), the last day with Check-out; empty days show the hint; today-highlight if applicable. Stay info + change form still render below.
  - Confirm the admin editor renders and (auth permitting) that adding an item makes it appear on the guest plan; deleting removes it.

- [ ] **Step 3: Clean up** — delete seeded hold, blocks, addons, itinerary rows. Confirm baseline.

- [ ] **Step 4: Done** — if green, ready for final review.

---

## Self-Review Notes
- Spec coverage: migration (T1); helper + tests (T2); guest render (T3); admin editor (T4); links (T5); verification (T6).
- Type consistency: `fetch_itinerary(array $hold)` (needs id/check_in/check_out/room_name) used in stay.php + admin/itinerary.php; `_itin_map_kind`, `fetch_itinerary_items(int)`; category CHECK set matches admin `$CATS` (admin omits checkin/checkout — those are auto-only, correct). Column `at_time` (avoids reserved `time`).
- Guarded: `fetch_itinerary` try/catch → anchors still render pre-migration.
- Reuse: admin uses `_layout`, `csrf_field`, `audit_log`, `$_SESSION['hold_flash']`; guest reuses `.pa-*`.
