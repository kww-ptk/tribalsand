# Events — "What's on" Bookable — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an **Event** guest-board category (date + optional price) and a **Join** action in the portal "What's on" that creates a request — reusing the Concierge Desk, the lifecycle/notifications, and the room bill.

**Architecture:** Migration expands the `guest_board_posts.category` and `booking_addons.kind` CHECKs, adds `event_date`/`price_amount` to posts and a `board_post_id` link on addons. Helpers in `includes/booking.php` (+ `addon_board_supported` in `services.php`); an `event` branch in `api/booking-addon.php` (reusing `insert_booking_addon` + the sub-project-A popup); event rendering in `includes/app/_greeting_board.php`; the Event category + fields in `admin/guest-board.php`.

**Tech Stack:** Vanilla PHP 8.2, PDO `db_query()` (pgsql — build the venue clause in PHP; no reused/`IS NULL` placeholder). Portal `.pa-*` classes, `format_price()`, `setting('site_currency')`.

**Conventions:** Escape output `e()`; ids `(int)`, prices `(float)`. Prepared statements only. Owner admin mutation stays `require_owner()` + CSRF + PRG. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Branch `feature/events-bookable` — no branch switch, no push.

---

## File map

| File | Change |
|------|--------|
| `db/migrations/add_events.sql` | **Create** — category/kind CHECK + `event_date`/`price_amount`/`board_post_id` |
| `includes/services.php` | **Modify** — `addon_board_supported()` |
| `includes/booking.php` | **Modify** — `fetch_guest_board` cols, `fetch_board_event`, `guest_joined_event`, `insert_booking_addon` board link, `_itin_map_kind` |
| `tests/portal_logic.php` | **Modify** — event helper tests |
| `api/booking-addon.php` | **Modify** — allow `event` kind + the event branch |
| `includes/app/_greeting_board.php` | **Modify** — event date/price + Join/Requested |
| `css/portal-app.css` | **Modify** — `.pa-tag--event` |
| `admin/guest-board.php` | **Modify** — Event category + date/price save + form |

---

## Task 1: Migration

**Files:** Create `db/migrations/add_events.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Tribal Sand: bookable events on the guest board ("What's on"). Idempotent.
ALTER TABLE guest_board_posts DROP CONSTRAINT IF EXISTS guest_board_posts_category_check;
ALTER TABLE guest_board_posts ADD CONSTRAINT guest_board_posts_category_check
    CHECK (category IN ('update','excursion','promotion','event'));
ALTER TABLE guest_board_posts
    ADD COLUMN IF NOT EXISTS event_date   TIMESTAMP,
    ADD COLUMN IF NOT EXISTS price_amount NUMERIC(10,2);

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other','housekeeping','amenities','maintenance','restaurant','laundry','event'));
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS board_post_id INT REFERENCES guest_board_posts(id) ON DELETE SET NULL;
```

- [ ] **Step 2: Apply locally**

Run: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_events.sql")); echo "applied\n";'`
Expected: `applied`

- [ ] **Step 3: Verify**

Run: `php -r 'require "includes/db.php"; db()->query("SELECT event_date,price_amount FROM guest_board_posts LIMIT 1"); db()->query("SELECT board_post_id FROM booking_addons LIMIT 1"); db()->exec("INSERT INTO guest_board_posts (category,title) VALUES (\x27event\x27,\x27zz probe\x27)"); db()->exec("DELETE FROM guest_board_posts WHERE title=\x27zz probe\x27"); echo "ok\n";'`
Expected: `ok` (the `event` category insert is accepted; columns exist).

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_events.sql
git commit -m "feat(db): event guest-board category + join link on addons

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Helpers (TDD)

**Files:**
- Modify: `includes/services.php`, `includes/booking.php`
- Test: `tests/portal_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/portal_logic.php`, add before the final summary line:

```php
// ── events (What's on bookable) ──
$evVid = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
db_query("INSERT INTO guest_board_posts (venue_id, category, title, body, is_published, price_amount) VALUES (NULL,'event','ZZ Beach BBQ','Friday night',TRUE,2000)");
$evId = (int)db()->lastInsertId();
db_query("INSERT INTO guest_board_posts (venue_id, category, title, is_published) VALUES (NULL,'promotion','ZZ Promo',TRUE)");
$promoId = (int)db()->lastInsertId();

$ev = fetch_board_event($evId, $evVid ?: null);
check('fetch_board_event returns the event', $ev && (int)$ev['id'] === $evId && (float)$ev['price_amount'] === 2000.0);
check('fetch_board_event rejects a non-event post', fetch_board_event($promoId, $evVid ?: null) === false);
db_query("UPDATE guest_board_posts SET is_published=FALSE WHERE id=:id", [':id'=>$evId]);
check('fetch_board_event rejects an unpublished event', fetch_board_event($evId, $evVid ?: null) === false);
db_query("UPDATE guest_board_posts SET is_published=TRUE WHERE id=:id", [':id'=>$evId]);

$evHold = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($evHold) {
    check('guest_joined_event false before joining', guest_joined_event($evHold, $evId) === false);
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status,board_post_id) VALUES (:h,'event','Join: ZZ Beach BBQ','requested',:p)", [':h'=>$evHold, ':p'=>$evId]);
    $evAddon = (int)db()->lastInsertId();
    check('guest_joined_event true after joining', guest_joined_event($evHold, $evId) === true);
    db_query("UPDATE booking_addons SET status='declined' WHERE id=:a", [':a'=>$evAddon]);
    check('guest_joined_event false when the join was declined', guest_joined_event($evHold, $evId) === false);
    db_query("DELETE FROM booking_addons WHERE id=:a", [':a'=>$evAddon]);
}

db_query("DELETE FROM guest_board_posts WHERE id IN (:a,:b)", [':a'=>$evId, ':b'=>$promoId]);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/portal_logic.php`
Expected: fatal `Call to undefined function fetch_board_event()`.

- [ ] **Step 3: Add `addon_board_supported()` to `includes/services.php`**

After `addon_pax_supported()`:

```php
/** True if booking_addons has the board_post_id column (memoised). False pre-migration. */
function addon_board_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'booking_addons' AND column_name = 'board_post_id' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}
```

- [ ] **Step 4: Update the helpers in `includes/booking.php`**

4a. `fetch_guest_board()` — add the event columns to its SELECT:

```php
        "SELECT id, category, title, body, image_filename, event_date, price_amount
         FROM guest_board_posts
         WHERE is_published = TRUE
           AND (venue_id IS NULL OR venue_id = :venue)
         ORDER BY sort_order DESC, created_at DESC
         LIMIT 6",
```

4b. Add the two event helpers (near `fetch_guest_board`):

```php
/** A published 'event' board post available at the venue, by id — else false. */
function fetch_board_event(int $postId, ?int $venueId): array|false {
    $sql = "SELECT * FROM guest_board_posts WHERE id = :id AND category = 'event' AND is_published = TRUE";
    $params = [':id' => $postId];
    if ($venueId !== null) { $sql .= " AND (venue_id IS NULL OR venue_id = :v)"; $params[':v'] = $venueId; }
    else                   { $sql .= " AND venue_id IS NULL"; }
    try { $r = db_query($sql, $params)->fetch(); } catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** Whether the guest already has an active (non-declined/cancelled) join for an event. */
function guest_joined_event(int $holdId, int $postId): bool {
    try {
        return (bool) db_query(
            "SELECT 1 FROM booking_addons
             WHERE hold_id = :h AND board_post_id = :p AND status NOT IN ('declined','cancelled') LIMIT 1",
            [':h' => $holdId, ':p' => $postId]
        )->fetchColumn();
    } catch (Throwable $e) { return false; }
}
```

4c. `insert_booking_addon()` — add the guarded board link after the `pax` block:

```php
    if (addon_board_supported())  { $cols[] = 'board_post_id'; $vals[] = ':bp'; $p[':bp'] = $d['board_post_id'] ?? null; }
```

4d. `_itin_map_kind()` — include `event` so a confirmed dated join shows on My Calendar. Change:

```php
    return in_array($kind, ['laundry','housekeeping','amenities','maintenance','restaurant'], true) ? 'activity' : 'note';
```

to:

```php
    return in_array($kind, ['laundry','housekeeping','amenities','maintenance','restaurant','event'], true) ? 'activity' : 'note';
```

- [ ] **Step 5: Run to verify it passes**

Run: `php tests/portal_logic.php`
Expected: all new checks PASS, `ALL PASS`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes/services.php includes/booking.php tests/portal_logic.php
git commit -m "feat(portal): event board helpers + addon board link, with tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Request API — the event branch

**Files:** Modify `api/booking-addon.php`

- [ ] **Step 1: Allow the `event` kind**

Change the allowed-kinds check:

```php
if (!in_array($kind, ['tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant','laundry'], true)) {
```

to:

```php
if (!in_array($kind, ['tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant','laundry','event'], true)) {
```

- [ ] **Step 2: Initialise the board-post var**

After the line `$threadBody    = null; // richer opening message for the request thread (tours)`, add:

```php
$boardPostId   = null; // set for event joins → links the addon to its board post
```

- [ ] **Step 3: Add the event branch**

Immediately before the `} else { // itinerary / other` block (i.e. as a new `elseif` after the `transfer`/`laundry` branch), add:

```php
} elseif ($kind === 'event') {
    $postId = (int)($data['board_post_id'] ?? 0);
    $ev = $postId ? fetch_board_event($postId, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$ev) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That event isn’t available.'])); }
    if (guest_joined_event((int)$hold['id'], $postId)) { http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'You’ve already requested to join this.'])); }
    $boardPostId   = $postId;
    $details       = 'Join: ' . $ev['title'];
    if (!empty($ev['event_date'])) { $schedOverride = date('Y-m-d H:i:s', strtotime((string)$ev['event_date'])); }
    $priceSnapshot = ($ev['price_amount'] === null || $ev['price_amount'] === '') ? null : (float)$ev['price_amount'];
}
```

- [ ] **Step 4: Pass the board link to the insert**

In the `insert_booking_addon([...])` call, add the `board_post_id` key:

```php
    $addonId = insert_booking_addon([
        'hold_id'      => $hold['id'],
        'kind'         => $kind,
        'tour_id'      => $tour_id,
        'details'      => $details,
        'scheduled_for'=> $schedSql,
        'price_amount' => $priceSnapshot,
        'pax'          => $paxValue,
        'board_post_id'=> $boardPostId,
    ]);
```

- [ ] **Step 5: Lint + tests**

Run: `php -l api/booking-addon.php` → clean.
Run: `php tests/portal_logic.php` → `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add api/booking-addon.php
git commit -m "feat(portal): join an event → a request (validated, priced, linked)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Guest "What's on" — event date/price + Join

**Files:**
- Modify: `includes/app/_greeting_board.php`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Head of `_greeting_board.php` — active flag, currency, event tag**

Replace:

```php
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion'];
```

with:

```php
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion','event'=>'pa-tag--event'];
$__active = in_array($hold['status'] ?? '', ['pending','confirmed'], true);
$__cur    = setting('site_currency', 'USD');
```

- [ ] **Step 2: Event details + Join, inside the card body**

In `_greeting_board.php`, inside the `<div class="pa-card__body">`, after the body `<p>…</p>` line, add:

```php
      <?php if ($p['category'] === 'event'): ?>
      <?php
        $__evMeta = [];
        if (!empty($p['event_date'])) $__evMeta[] = date('D j M, H:i', strtotime((string)$p['event_date']));
        $__evPriced = isset($p['price_amount']) && $p['price_amount'] !== null && (float)$p['price_amount'] > 0;
        if ($__evPriced) $__evMeta[] = format_price((float)$p['price_amount'], $__cur);
      ?>
      <?php if ($__evMeta): ?><p class="pa-card__meta" style="display:block;margin-top:6px;font-weight:600;color:var(--pa-ink)"><?= e(implode(' · ', $__evMeta)) ?></p><?php endif; ?>
      <?php if ($__active): ?>
        <?php if (guest_joined_event((int)$hold['id'], (int)$p['id'])): ?>
        <span class="pa-pill pa-pill--confirmed" style="margin-top:10px;display:inline-block">Requested</span>
        <?php else: ?>
        <form data-bm data-bm-success="Requested — opening your chat…" action="/api/booking-addon.php" style="margin-top:10px">
          <input type="hidden" name="ref" value="<?= e($ref) ?>">
          <input type="hidden" name="kind" value="event">
          <input type="hidden" name="board_post_id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="pa-btn pa-btn--primary"><?= $__evPriced ? 'Request · ' . e(format_price((float)$p['price_amount'], $__cur)) : 'Join event' ?></button>
          <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
        </form>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
```

- [ ] **Step 3: `.pa-tag--event` style**

Append to `css/portal-app.css`:

```css
.pa-tag--event{background:#ede9fe;color:#5b21b6;}
```

- [ ] **Step 4: Verify (local, in-app browser)**

With the dev server + a seeded active hold: create a published **event** post (with a date + a price) via admin (Task 5) or DB. On the portal Home "What's on", the event card shows its **date · price** and a **Request · <price>** (or **Join event**) button. Tapping it shows the **"Request sent" popup** and creates a `booking_addons` row (kind `event`, `board_post_id` set, price snapshotted). Reload → the card now shows a **Requested** pill (dedup). Clean up the test post + join.

- [ ] **Step 5: Commit**

```bash
git add includes/app/_greeting_board.php css/portal-app.css
git commit -m "feat(portal): What's on events show date/price + Join (with Requested state)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Admin — Event category + date/price

**Files:** Modify `admin/guest-board.php`

- [ ] **Step 1: Add the Event category**

Find the `$CATS = [ … ]` map and add the event entry, e.g.:

```php
$CATS = ['update'=>'Update','excursion'=>'Excursion','promotion'=>'Promotion','event'=>'Event'];
```

(Match the existing keys/labels; just append `'event'=>'Event'`.)

- [ ] **Step 2: Save `event_date` + `price_amount`**

In the `save` handler, after the existing `$sort`/`$pub` reads, add:

```php
        $evRaw    = trim((string)($_POST['event_date'] ?? ''));
        $eventDate = $evRaw !== '' && strtotime($evRaw) !== false ? date('Y-m-d H:i:s', strtotime($evRaw)) : null;
        $priceRaw = $_POST['price_amount'] ?? '';
        $priceAmt = ($priceRaw === '' ) ? null : (float)$priceRaw;
```

Then add these to BOTH the UPDATE and INSERT.

UPDATE — extend the SET list and params:

```php
                db_query(
                    'UPDATE guest_board_posts SET venue_id=:v, category=:c, title=:t, body=:b,
                            image_filename=:img, is_published=:p, sort_order=:s,
                            event_date=:ed, price_amount=:pa, updated_at=now() WHERE id=:id',
                    [':v'=>$venueId, ':c'=>$category, ':t'=>$title, ':b'=>$body, ':img'=>$img,
                     ':p'=>$pub?'TRUE':'FALSE', ':s'=>$sort, ':ed'=>$eventDate, ':pa'=>$priceAmt, ':id'=>$id]
                );
```

INSERT — extend the column list and params:

```php
                db_query(
                    'INSERT INTO guest_board_posts (venue_id, category, title, body, image_filename, is_published, sort_order, event_date, price_amount)
                     VALUES (:v,:c,:t,:b,:img,:p,:s,:ed,:pa)',
                    [':v'=>$venueId, ':c'=>$category, ':t'=>$title, ':b'=>$body, ':img'=>$newImage,
                     ':p'=>$pub?'TRUE':'FALSE', ':s'=>$sort, ':ed'=>$eventDate, ':pa'=>$priceAmt]
                );
```

- [ ] **Step 3: Add the form fields**

In the edit form, after the **Body** `<label>…</label>` block, add:

```php
      <label style="display:block;margin-bottom:12px">Event date &amp; time <span style="color:var(--muted);font-weight:400">(events only)</span>
        <input type="datetime-local" name="event_date" value="<?= e(!empty($edit['event_date']) ? date('Y-m-d\TH:i', strtotime((string)$edit['event_date'])) : '') ?>" style="display:block;margin-top:4px">
      </label>
      <label style="display:block;margin-bottom:12px">Price <span style="color:var(--muted);font-weight:400">(events only — blank = free)</span>
        <input type="number" name="price_amount" step="0.01" min="0" value="<?= e(isset($edit['price_amount']) && $edit['price_amount'] !== null ? rtrim(rtrim(number_format((float)$edit['price_amount'],2,'.',''),'0'),'.') : '') ?>" style="display:block;width:160px;margin-top:4px">
      </label>
```

- [ ] **Step 4: Verify (local)**

`php -l admin/guest-board.php` clean. As owner on `/admin/guest-board.php`, add a post with category **Event**, a date, and a price → saved; edit it → the date/price persist. It appears in the guest portal "What's on" with a Join button (Task 4).

- [ ] **Step 5: Commit**

```bash
git add admin/guest-board.php
git commit -m "feat(admin): guest board Event category with date + price

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Full regression + verification

- [ ] **Step 1: Test suites** — `php tests/portal_logic.php`, `php tests/activities_logic.php`, `php tests/services_logic.php`, `php tests/frontdesk_logic.php`, `php tests/bill_logic.php` → all `ALL PASS`.
- [ ] **Step 2: Lint** — `for f in includes/services.php includes/booking.php api/booking-addon.php includes/app/_greeting_board.php admin/guest-board.php; do php -l "$f"; done` → clean.
- [ ] **Step 3: End-to-end (in-app browser)** — as owner, create an **Event** on the guest board (date + price). As a guest on an active booking at that property, "What's on" shows the event with date/price + **Request · <price>** → tap → **"Request sent" popup** → the request appears on the **Concierge Desk** (kind event) with its price; the guest's card now shows **Requested**; a second tap is blocked (409). Accept it on the desk → the guest gets the **auto-message + notification** (D). If priced, it shows on the booking's **Bill** (E). Confirm a free event (no price) also joins. Clean up seeded data.
- [ ] **Step 4: Final review** — use superpowers:requesting-code-review against this plan + spec; fix findings before finishing.

---

## Rollout

Run `db/migrations/add_events.sql` via **/admin/migrate.php** after deploy. Then create **Event** posts on the guest board; guests Join from "What's on" and the request flows through the desk, messaging, and the bill.
