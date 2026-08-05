# Staff Front Desk Dashboard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give on-site staff a property-scoped "Front Desk" dashboard (`admin/frontdesk.php`) showing confirmed reservations grouped Today / Tomorrow / This week, as their landing page.

**Architecture:** Query logic isolated in a new `includes/frontdesk.php` (unit-tested via `tests/frontdesk_logic.php`); a thin `admin/frontdesk.php` page renders it inside the existing `admin/_layout.php` chrome; a small `admin_home_url()` in `includes/auth.php` routes staff here on login. Reads existing tables (`holds`, `units`, `rooms`, `venues`, `submissions`, `booking_addons`, `booking_messages`) — no migration.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via PDO `db_query()`. Admin UI uses existing classes: `.card`, `.card__body`, `.badge badge--{orange|blue|green|red|grey}`, `.btn-sm btn-primary|btn-outline`, `.page-header`, `.text-muted`, `var(--muted)`. Pages set `$pageTitle`/`$activeMenu`, `include _layout.php` … `include _layout_end.php`.

**Conventions:** Escape all output with `e()`. Prepared statements only. Staff scoping via `is_staff()` + `admin_venue_ids()`. Commit trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Branch `feature/staff-frontdesk-dashboard` — do not switch branches or push.

---

## File map

| File | Change |
|------|--------|
| `includes/frontdesk.php` | **Create** — date helpers + `frontdesk_day()` / `frontdesk_week()` query helpers |
| `tests/frontdesk_logic.php` | **Create** — DB-backed `check()` tests for the helpers |
| `admin/frontdesk.php` | **Create** — the dashboard page (tabs, KPI, cards, filter, scoping) |
| `admin/_layout.php` | **Modify** — add a "Front desk" nav link visible to all roles |
| `includes/auth.php` | **Modify** — add `admin_home_url()`; point `require_owner()`'s staff bounce at frontdesk |
| `admin/login.php` | **Modify** — staff login + already-logged-in redirects use the new home |
| `admin/index.php` | **Modify** — already-logged-in redirect uses the new home |

---

## Task 1: `includes/frontdesk.php` helpers (TDD)

**Files:**
- Create: `includes/frontdesk.php`
- Test: `tests/frontdesk_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/frontdesk_logic.php`:

```php
<?php
declare(strict_types=1);
// Front Desk helpers. Run: php tests/frontdesk_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/frontdesk.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function ids(array $rows): array { return array_map(fn($r) => (int)$r['id'], $rows); }

// A unit whose room belongs to a venue (needed for venue scoping).
$u = db()->query("SELECT u.id AS unit_id, r.venue_id
                  FROM units u JOIN rooms r ON r.id = u.room_id
                  WHERE r.venue_id IS NOT NULL LIMIT 1")->fetch();
if (!$u) { echo "SKIP  no unit with a venue — cannot test frontdesk\n"; echo "\nALL PASS\n"; exit(0); }
$unit = (int)$u['unit_id']; $venue = (int)$u['venue_id'];

$A   = '2031-06-15';                                   // anchor day (far future, no real data)
$d   = fn(string $base, int $n) => (new DateTime($base))->modify(($n>=0?'+':'').$n.' days')->format('Y-m-d');
$mk  = function(string $ci, string $co) use ($unit): int {
    db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
              VALUES (:u,:ci,:co,'ZZ FD Guest','zz@example.com','confirmed', NOW())",
             [':u'=>$unit, ':ci'=>$ci, ':co'=>$co]);
    return (int)db()->lastInsertId();
};

$hArrive  = $mk($A,            $d($A, 3));   // arrives on anchor
$hInhouse = $mk($d($A,-2),     $d($A, 2));   // staying across anchor
$hDepart  = $mk($d($A,-3),     $A);          // departs on anchor
$hWeekOut = $mk($d($A,10),     $d($A, 12));  // arrival outside the 7-day window

$day = frontdesk_day([$venue], $A);
check('arrival is in "arriving"',      in_array($hArrive,  ids($day['arriving']),  true));
check('continuing stay is "in house"', in_array($hInhouse, ids($day['inhouse']),   true));
check('departure is in "departing"',   in_array($hDepart,  ids($day['departing']), true));
check('arrival is NOT counted as departing', !in_array($hArrive, ids($day['departing']), true));
check('departing guest is NOT in house', !in_array($hDepart, ids($day['inhouse']), true));
check('kpi_inhouse = arrivals + continuing', $day['kpi_inhouse'] === count($day['arriving']) + count($day['inhouse']));

// Venue scoping: a non-matching venue id yields none of our holds.
$other = frontdesk_day([$venue + 999999], $A);
check('venue scoping excludes other venues', !in_array($hArrive, ids($other['arriving']), true));

// Week: arrivals within [A, A+7) include the anchor arrival, exclude the +10 one.
$week = frontdesk_week([$venue], $A, 7);
check('week includes the in-window arrival', in_array($hArrive,  ids($week), true));
check('week excludes the out-of-window arrival', !in_array($hWeekOut, ids($week), true));

// Badges: a requested addon + an unread guest message on the arrival hold.
db_query("INSERT INTO booking_addons (hold_id, kind, details, status) VALUES (:h,'other','ZZ FD req','requested')", [':h'=>$hArrive]);
db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,NULL,'guest','ZZ FD msg',TRUE,FALSE)", [':h'=>$hArrive]);
$day2 = frontdesk_day([$venue], $A);
$arr  = null; foreach ($day2['arriving'] as $r) { if ((int)$r['id'] === $hArrive) { $arr = $r; break; } }
check('badge: open_requests counted', $arr && (int)$arr['open_requests'] >= 1);
check('badge: unread_msgs counted',   $arr && (int)$arr['unread_msgs']   >= 1);

// Cleanup
db_query("DELETE FROM booking_messages WHERE hold_id = :h", [':h'=>$hArrive]);
db_query("DELETE FROM booking_addons  WHERE hold_id = :h", [':h'=>$hArrive]);
db_query("DELETE FROM holds WHERE id IN (:a,:b,:c,:e)", [':a'=>$hArrive, ':b'=>$hInhouse, ':c'=>$hDepart, ':e'=>$hWeekOut]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/frontdesk_logic.php`
Expected: fatal `Failed opening require '.../includes/frontdesk.php'` (file doesn't exist yet).

- [ ] **Step 3: Create the helpers**

Create `includes/frontdesk.php`:

```php
<?php
declare(strict_types=1);
/**
 * Front Desk data helpers — confirmed reservations grouped by day, venue-scoped.
 * $venueIds: null = all venues (owner); array = restrict to those ids; an empty
 * array is coerced to [-1] so the SQL never emits an empty IN () (staff with no
 * venues therefore see nothing).
 */

/** 'today' in the property timezone (Kenya), not the server's UTC. */
function frontdesk_today_ymd(): string {
    return (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
}
/** 'tomorrow' in the property timezone. */
function frontdesk_tomorrow_ymd(): string {
    return (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->modify('+1 day')->format('Y-m-d');
}

/**
 * Confirmed reservations matching a date predicate (referencing h.check_in /
 * h.check_out via the named params in $params), scoped to $venueIds. Each row
 * carries badge counts (open requests, unread guest messages). Returns [] and
 * logs if the query fails (e.g. a table is missing pre-migration).
 */
function frontdesk_rows(?array $venueIds, string $datePredicate, array $params): array {
    $where = ["h.status = 'confirmed'", $datePredicate];
    if ($venueIds !== null) {
        $ids = $venueIds ?: [-1];
        $ph = [];
        foreach ($ids as $i => $v) { $n = ":fv{$i}"; $ph[] = $n; $params[$n] = (int)$v; }
        $where[] = 'r.venue_id IN (' . implode(',', $ph) . ')';
    }
    $whereSql = implode(' AND ', $where);
    try {
        return db_query(
            "SELECT h.id, h.guest_name, h.check_in, h.check_out, h.access_code,
                    r.name AS room_name, u.name AS unit_name,
                    v.id AS venue_id, v.name AS venue_name,
                    s.guest_phone,
                    (SELECT COUNT(*) FROM booking_addons ba
                       WHERE ba.hold_id = h.id AND ba.status = 'requested') AS open_requests,
                    (SELECT COUNT(*) FROM booking_messages bm
                       WHERE bm.hold_id = h.id AND bm.sender = 'guest' AND bm.read_by_admin = FALSE) AS unread_msgs
             FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = u.room_id
             LEFT JOIN venues v      ON v.id = r.venue_id
             LEFT JOIN submissions s ON s.id = h.submission_id
             WHERE {$whereSql}
             ORDER BY h.check_in ASC, h.guest_name ASC",
            $params
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[frontdesk] query failed: ' . $e->getMessage());
        return [];
    }
}

/** Arrivals / in-house / departures for a single day (Y-m-d), plus the tonight KPI. */
function frontdesk_day(?array $venueIds, string $ymd): array {
    $arriving  = frontdesk_rows($venueIds, 'h.check_in = :d',                     [':d' => $ymd]);
    $inhouse   = frontdesk_rows($venueIds, 'h.check_in < :d AND h.check_out > :d', [':d' => $ymd]);
    $departing = frontdesk_rows($venueIds, 'h.check_out = :d',                    [':d' => $ymd]);
    // Sleeping that night = arrivals that day + continuing stays (departures excluded).
    return [
        'arriving'    => $arriving,
        'inhouse'     => $inhouse,
        'departing'   => $departing,
        'kpi_inhouse' => count($arriving) + count($inhouse),
    ];
}

/** Arrivals in the window [$fromYmd, $fromYmd + $days). Flat list; view groups by date. */
function frontdesk_week(?array $venueIds, string $fromYmd, int $days = 7): array {
    $to = (new DateTime($fromYmd))->modify('+' . max(1, $days) . ' days')->format('Y-m-d'); // exclusive
    return frontdesk_rows($venueIds, 'h.check_in >= :from AND h.check_in < :to', [':from' => $fromYmd, ':to' => $to]);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/frontdesk_logic.php`
Expected: all checks PASS, final line `ALL PASS`, exit 0. (A `SKIP` line then `ALL PASS` is also acceptable if the local DB has no venue-linked unit — but the seeded DB should have one.)

- [ ] **Step 5: Commit**

```bash
git add includes/frontdesk.php tests/frontdesk_logic.php
git commit -m "feat(admin): front-desk reservation query helpers, with tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `admin/frontdesk.php` page + nav link

**Files:**
- Create: `admin/frontdesk.php`
- Modify: `admin/_layout.php` (add the nav link after `<nav class="sidebar__nav">`)

- [ ] **Step 1: Create the page**

Create `admin/frontdesk.php`:

```php
<?php
/**
 * Admin: Front Desk — confirmed reservations grouped Today / Tomorrow / This week,
 * scoped to the viewer's property(ies). Staff landing page; owner sees all + filter.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/frontdesk.php';
require_login();

$pageTitle  = 'Front desk';
$activeMenu = 'frontdesk';

// ── Scope: owner => null (all); staff => their venue ids (empty => none) ──
$isStaff = is_staff();
$allowed = $isStaff ? (admin_venue_ids() ?: []) : null;   // null = all venues

// Venue list for the filter (owner: all; staff: their venues).
if ($allowed === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($allowed) {
    $ph = []; $p = [];
    foreach ($allowed as $i => $v) { $n = ":lv{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}

// Selected venue filter (validated against scope).
$venueFilter = isset($_GET['venue']) ? (int)$_GET['venue'] : 0;
$validIds = array_map(fn($v) => (int)$v['id'], $venues);
if ($venueFilter > 0 && in_array($venueFilter, $validIds, true)) {
    $venueIds = [$venueFilter];
} else {
    $venueFilter = 0;
    $venueIds = $allowed;   // null (owner all) or the staff's full set
}

// Tab.
$when = in_array($_GET['when'] ?? '', ['today','tomorrow','week'], true) ? $_GET['when'] : 'today';
$todayYmd = frontdesk_today_ymd();

// Data for the active tab.
$dayData = null; $weekRows = null;
if ($when === 'week') {
    $weekRows = frontdesk_week($venueIds, $todayYmd, 7);
} else {
    $dayYmd  = $when === 'tomorrow' ? frontdesk_tomorrow_ymd() : $todayYmd;
    $dayData = frontdesk_day($venueIds, $dayYmd);
    $dayLabel = (new DateTime($dayYmd))->format('D j M Y');
}

// Preserve the venue filter across tab links.
$q = fn(string $w) => '?when=' . $w . ($venueFilter ? '&venue=' . $venueFilter : '');

// Human label for the current scope (shown in the top line).
$venueNameById = [];
foreach ($venues as $v) { $venueNameById[(int)$v['id']] = $v['name']; }
if ($venueFilter) {
    $scopeName = $venueNameById[$venueFilter] ?? 'Property';
} elseif ($allowed === null) {
    $scopeName = 'All properties';
} elseif (count($venues) === 1) {
    $scopeName = $venues[0]['name'];
} else {
    $scopeName = 'All my properties';
}

/** Render one reservation card. */
$card = function(array $r): string {
    $name  = e($r['guest_name'] !== '' ? $r['guest_name'] : 'Guest');
    $roomOrUnit = ($r['room_name'] ?? '') !== '' ? (string)$r['room_name'] : (string)($r['unit_name'] ?? '');
    $place = e(trim(((string)($r['venue_name'] ?? '')) . ' · ' . $roomOrUnit, ' ·'));
    $nights = max(0, (int) round((strtotime((string)$r['check_out']) - strtotime((string)$r['check_in'])) / 86400));
    $dates = e(date('j M', strtotime((string)$r['check_in'])) . '–' . date('j M', strtotime((string)$r['check_out']))
             . ' (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')');
    $code  = trim((string)($r['access_code'] ?? ''));
    $phone = trim((string)($r['guest_phone'] ?? ''));
    $hid   = (int)$r['id'];
    $reqs  = (int)$r['open_requests'];
    $unread = (int)$r['unread_msgs'];

    ob_start(); ?>
    <div class="fd-card">
      <div class="fd-card__main">
        <div class="fd-card__name"><?= $name ?></div>
        <div class="fd-card__meta"><b><?= $place ?></b> · <?= $dates ?><?php if ($code !== ''): ?> · <span class="fd-code"><?= e($code) ?></span><?php endif; ?></div>
        <div class="fd-card__badges">
          <?php if ($reqs > 0): ?><a class="fd-badge fd-badge--req" href="/admin/booking.php?hold=<?= $hid ?>&tab=requests"><?= $reqs ?> request<?= $reqs === 1 ? '' : 's' ?></a><?php endif; ?>
          <?php if ($unread > 0): ?><a class="fd-badge fd-badge--msg" href="/admin/messages.php?hold=<?= $hid ?>"><?= $unread ?> unread</a><?php endif; ?>
        </div>
      </div>
      <div class="fd-card__side">
        <?php if ($phone !== ''): ?><a class="fd-phone" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php else: ?><span class="fd-phone fd-phone--none">—</span><?php endif; ?>
        <a class="btn-outline btn-sm" href="/admin/booking.php?hold=<?= $hid ?>">Open →</a>
      </div>
    </div>
    <?php return ob_get_clean();
};

/** Render a titled section of cards with an empty state. */
$section = function(string $title, string $dotClass, array $rows, string $empty) use ($card): string {
    ob_start(); ?>
    <div class="fd-sec">
      <div class="fd-sec__h"><span class="fd-dot <?= $dotClass ?>"></span><?= e($title) ?> <span class="fd-sec__n">· <?= count($rows) ?></span></div>
      <?php if (!$rows): ?><p class="fd-empty"><?= e($empty) ?></p>
      <?php else: foreach ($rows as $r) echo $card($r); endif; ?>
    </div>
    <?php return ob_get_clean();
};

include __DIR__ . '/_layout.php';
?>

<style>
.fd-topline{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.fd-seg{display:flex;gap:6px;margin:4px 0 14px}
.fd-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:6px}
.fd-kpi{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:14px 16px}
.fd-kpi .n{font-size:26px;font-weight:800;color:#102F3A;line-height:1}
.fd-kpi .l{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:4px}
.fd-kpi--in .n{color:#166534}.fd-kpi--dep .n{color:#b45309}
.fd-sec{margin-top:14px}
.fd-sec__h{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 8px}
.fd-sec__n{color:#b3aa9c;font-weight:600}
.fd-dot{width:9px;height:9px;border-radius:50%}
.fd-dot--arr{background:#2563eb}.fd-dot--in{background:#16a34a}.fd-dot--dep{background:#d97706}
.fd-empty{color:var(--muted);font-size:14px;margin:0 0 8px}
.fd-card{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:12px 14px;margin-bottom:8px}
.fd-card__main{flex:1;min-width:0}
.fd-card__name{font-size:15px;font-weight:700}
.fd-card__meta{font-size:12.5px;color:var(--muted);margin-top:2px}
.fd-card__meta b{color:#3a352d;font-weight:600}
.fd-code{font-family:monospace;letter-spacing:.5px}
.fd-card__badges{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap}
.fd-badge{font-size:11px;font-weight:700;border-radius:999px;padding:3px 9px;text-decoration:none}
.fd-badge--req{background:#ffedd5;color:#9a3412}
.fd-badge--msg{background:#dbeafe;color:#1e40af}
.fd-card__side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;white-space:nowrap}
.fd-phone{font-size:12.5px;color:#1E5C6B;text-decoration:none}
.fd-phone--none{color:var(--muted)}
</style>

<div class="page-header">
  <h1>Front desk</h1>
  <?php if (!$isStaff): ?><a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a><?php endif; ?>
</div>

<div class="fd-topline">
  <div class="text-muted" style="font-size:13px">
    <strong><?= e($scopeName) ?></strong>
    <?php if ($when !== 'week'): ?> · <?= e($dayLabel) ?><?php endif; ?>
  </div>
  <?php if (count($venues) > 1): ?>
  <form method="get" style="margin:0">
    <input type="hidden" name="when" value="<?= e($when) ?>">
    <select name="venue" onchange="this.form.submit()" class="btn-outline btn-sm" style="padding:6px 10px">
      <option value="0"><?= $allowed === null ? 'All properties' : 'All my properties' ?></option>
      <?php foreach ($venues as $v): ?>
      <option value="<?= (int)$v['id'] ?>" <?= $venueFilter === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<div class="fd-seg">
  <?php foreach (['today'=>'Today','tomorrow'=>'Tomorrow','week'=>'This week'] as $wk => $wl): ?>
  <a href="<?= e($q($wk)) ?>" class="btn-sm <?= $when === $wk ? 'btn-primary' : 'btn-outline' ?>"><?= e($wl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($when === 'week'): ?>

  <?php if (!$weekRows): ?>
    <div class="card"><div class="card__body"><p class="fd-empty" style="margin:0">No arrivals in the next 7 days.</p></div></div>
  <?php else:
    $byDay = [];
    foreach ($weekRows as $r) { $byDay[(string)$r['check_in']][] = $r; }
    foreach ($byDay as $ymd => $rows): ?>
    <div class="fd-sec">
      <div class="fd-sec__h"><span class="fd-dot fd-dot--arr"></span><?= e((new DateTime($ymd))->format('D j M')) ?> <span class="fd-sec__n">· <?= count($rows) ?> arriving</span></div>
      <?php foreach ($rows as $r) echo $card($r); ?>
    </div>
  <?php endforeach; endif; ?>

<?php else: ?>

  <div class="fd-kpis">
    <div class="fd-kpi fd-kpi--in"><div class="n"><?= (int)$dayData['kpi_inhouse'] ?></div><div class="l">In house <?= $when === 'today' ? 'tonight' : 'that night' ?></div></div>
    <div class="fd-kpi"><div class="n"><?= count($dayData['arriving']) ?></div><div class="l">Arriving</div></div>
    <div class="fd-kpi fd-kpi--dep"><div class="n"><?= count($dayData['departing']) ?></div><div class="l">Departing</div></div>
  </div>

  <?= $section('Arriving',  'fd-dot--arr', $dayData['arriving'],  $when === 'today' ? 'Nobody arriving today.'  : 'Nobody arriving tomorrow.') ?>
  <?= $section('In house',  'fd-dot--in',  $dayData['inhouse'],   'Nobody staying over.') ?>
  <?= $section('Departing', 'fd-dot--dep', $dayData['departing'], $when === 'today' ? 'Nobody departing today.' : 'Nobody departing tomorrow.') ?>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Add the nav link (visible to all roles)**

In `admin/_layout.php`, find the line `<nav class="sidebar__nav">` and insert this link immediately after it (before the `<?php if (!is_staff()): ?>` that guards owner-only items):

```php
      <a href="/admin/frontdesk.php"    class="sidebar__link <?= ($activeMenu??'')==='frontdesk'    ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 3v3h6V3M8 11h8M8 15h5"/></svg>
        Front desk
      </a>
```

- [ ] **Step 3: Verify the page loads (local, in-app browser)**

Start the dev server (via preview_start with the `tribalsand` launch config) and open `/admin/frontdesk.php` after logging in as owner. Expected: page renders with Today/Tomorrow/This week tabs, a KPI strip, and reservation sections (populated if there are confirmed holds around today, otherwise empty states). Switch tabs and confirm the URL carries `?when=…`. No PHP notices in `preview_logs`.

- [ ] **Step 4: Commit**

```bash
git add admin/frontdesk.php admin/_layout.php
git commit -m "feat(admin): Front Desk dashboard page + nav link

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Staff landing page routing

**Files:**
- Modify: `includes/auth.php` (add `admin_home_url()`; retarget `require_owner()` bounce)
- Modify: `admin/login.php` (3 redirects)
- Modify: `admin/index.php` (1 redirect)

- [ ] **Step 1: Add `admin_home_url()` and retarget the staff bounce**

In `includes/auth.php`, add this function next to `is_staff()`:

```php
/** Post-login home for the current admin: staff → Front Desk, owner → dashboard. */
function admin_home_url(): string { return is_staff() ? '/admin/frontdesk.php' : '/admin/dashboard.php'; }
```

Then in the same file, in `require_owner()`, change the staff redirect target from the concierge desk to the front desk:

```php
function require_owner(): void {
    require_login();
    if (is_staff()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is not available for staff accounts.']; header('Location: /admin/frontdesk.php'); exit; }
}
```

- [ ] **Step 2: Route logins through the new home**

In `admin/login.php`:

1. The "already logged in" redirect (currently `header('Location: /admin/dashboard.php');` near the top) → `header('Location: ' . admin_home_url());`
2. The staff access-code success (currently `header('Location: /admin/concierge-desk.php');`) → `header('Location: /admin/frontdesk.php');`
3. Leave the owner email/password success (`header('Location: /admin/dashboard.php');`) as-is.

In `admin/index.php`, change the logged-in redirect (currently `header('Location: /admin/dashboard.php');`) → `header('Location: ' . admin_home_url());`. (`includes/auth.php` is already required at the top of `index.php`, so `admin_home_url()` is available.)

- [ ] **Step 3: Verify routing (local, in-app browser)**

Confirm `php -l admin/login.php`, `php -l admin/index.php`, `php -l includes/auth.php` all report "No syntax errors". Then, if a staff access code is available in the seed DB, log in as staff and confirm you land on `/admin/frontdesk.php`; log in as owner and confirm you still land on `/admin/dashboard.php`. (If no staff code is handy, this is covered by the final manual pass.)

- [ ] **Step 4: Commit**

```bash
git add includes/auth.php admin/login.php admin/index.php
git commit -m "feat(admin): staff land on Front Desk after login

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Full regression + verification

- [ ] **Step 1: Run the test suites**

Run: `php tests/frontdesk_logic.php`
Expected: `ALL PASS`.

Run: `php tests/portal_logic.php`
Expected: `ALL PASS` (unchanged — confirms no regression).

- [ ] **Step 2: Lint all touched PHP**

Run: `for f in includes/frontdesk.php admin/frontdesk.php admin/_layout.php includes/auth.php admin/login.php admin/index.php; do php -l "$f"; done`
Expected: "No syntax errors detected" for each.

- [ ] **Step 3: End-to-end walkthrough (local, in-app browser)**

With the `tribalsand` dev server running, seed a couple of confirmed holds spanning today (e.g. one arriving today, one mid-stay, one departing today) on a venue, then as owner:
- `/admin/frontdesk.php` shows them in Arriving / In house / Departing with correct KPI counts.
- A hold with a `requested` addon and an unread guest message shows the **requests** and **unread** badges; each badge opens the workspace / messages.
- The **Open →** link opens `/admin/booking.php?hold=<id>`.
- Tomorrow and This week tabs render (This week groups arrivals by date).
- If more than one venue is in scope, the property filter narrows the list and persists across tabs.
Clean up any seeded holds afterward.

- [ ] **Step 4: Request a final code review**

Use superpowers:requesting-code-review to review the branch against this plan and the spec; fix any findings before finishing the branch.

---

## Rollout

No migration. After merge + deploy, staff immediately land on the Front Desk dashboard; owner gets it as a nav item.
