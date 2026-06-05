# Per-Property Booking Model Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-seed each property's rooms and switch each page to the correct booking mode — entire-villa (My Amani, Enkare Bofa, Sandbox), by-room (Zuri, Maya Kobe, Maya Ilai), or not-bookable (Tribal Dunes).

**Architecture:** One idempotent seed (`db/seed_rooms_model.sql`) fixes the room rows + flags + units + Zuri photos. Then small per-page edits flip modes by adding/removing the `rooms-and-rates.php` include (the bar + modal stay where present). Reuses the existing booking engine/API; no URL changes.

**Tech Stack:** PostgreSQL (PDO), PHP includes, the existing Rooms & Availability section + search bar + modal.

Spec: `docs/superpowers/specs/2026-06-04-property-booking-model-fix-design.md`.

**Conventions:** No test framework — verify with `php -l`, `psql`, a throwaway `php -S` + `curl`. Postgres v18 client `/Applications/Postgres.app/Contents/Versions/18/bin/psql` (user/db `patrikgiuliana`/`tribalsand`). Branch `main`. End every commit with a trailing blank line + `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

**Known effect (intended):** deleting My Amani's 11 placeholder rooms and Maya Kobe's 2 placeholders means the individual `my-amani-*.php` and `maya-kobe-main-house.php`/`maya-kobe-cottages.php` pages' inline booking widgets render nothing (those slugs no longer resolve). The pages still load fine — booking for those properties now happens on the overview (villa bar / suite cards). This matches the model and the booking-widget partial degrades gracefully (`return;` when the slug has no room).

---

## Task 1: Re-seed rooms per the model

**Files:** Create `db/seed_rooms_model.sql`

- [ ] **Step 1: Get the Jua + Bahari Zuri image paths** (Maji/Mwezi/Ua/Anga are known below; grab the other two):
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
grep -A3 'Jua Suite\|Bahari Suite' zuri.php | grep -oE 'images/zuri/[^"]+\.(jpg|jpeg|png|webp)'
```
Note the two paths printed (e.g. `images/zuri/Jua Suite/…` and `images/zuri/Bahari Suite/…`). Use them in Step 2's image INSERT (prefix each with `https://tribalsand.com/`). If a suite has no dedicated image, reuse `https://tribalsand.com/images/zuri/Garden/zuri.watamu.morning.upstares.outdoor.webp` for it.

- [ ] **Step 2: Write `db/seed_rooms_model.sql`** (fill the `<JUA_URL>` / `<BAHARI_URL>` with the Step-1 paths, full `https://tribalsand.com/...` URLs):
```sql
-- Per-property booking model fix. Idempotent: deletes each venue's rooms then re-inserts the correct set.
-- Pre-launch DB only; test holds/units/blocks cascade via FK. Room slugs are booking identifiers
-- (resolved by /api/check-availability.php?room=slug) and need NOT match a page file for sub-rooms.

-- ── ENTIRE VILLA: My Amani — one whole-villa room ──
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='my-amani');
INSERT INTO rooms (slug, name, venue_id, capacity, price_amount, price_currency, form_mode, is_entire_place, is_published, sort_order)
SELECT 'my-amani', 'My Amani — Whole Villa', id, 10, 0, 'USD', 'availability', TRUE, TRUE, 0 FROM venues WHERE slug='my-amani';

-- ── ENTIRE VILLA: Enkare Bofa, Sandbox — flag existing single room ──
UPDATE rooms SET is_entire_place = TRUE
WHERE venue_id IN (SELECT id FROM venues WHERE slug IN ('enkare-bofa','sandbox'));

-- ── BY-ROOM: Zuri — 6 named suites ──
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='zuri');
INSERT INTO rooms (slug, name, venue_id, capacity, bed_count, short_desc, price_amount, price_currency, form_mode, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.beds, x.descr, 0, 'USD', 'availability', TRUE, x.so
FROM venues v CROSS JOIN (VALUES
  ('zuri-maji','Maji Suite',2,1,'An intimate oceanfront suite with king bed, en-suite bathroom and direct Indian Ocean views.',1),
  ('zuri-mwezi','Mwezi Suite',4,3,'Zuri''s most spacious suite — a king bed plus two twins, perfect for families or a group of four.',2),
  ('zuri-ua','Ua Suite',2,1,'A serene king suite with en-suite bathroom and air conditioning, made for couples.',3),
  ('zuri-anga','Anga Suite',2,1,'A tranquil king suite with full suite comforts and ocean-side calm.',4),
  ('zuri-jua','Jua Suite',2,1,'A light-filled king suite with en-suite bathroom and all suite amenities.',5),
  ('zuri-bahari','Bahari Suite',2,2,'A twin-bed suite with ocean-facing comforts, ideal for friends travelling together.',6)
) AS x(slug,name,cap,beds,descr,so)
WHERE v.slug='zuri';

-- ── BY-ROOM: Maya Kobe — 5 suites, KES prices (from klickenya) ──
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='maya-kobe');
INSERT INTO rooms (slug, name, venue_id, capacity, price_amount, price_currency, form_mode, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, x.price, 'KES', 'availability', TRUE, x.so
FROM venues v CROSS JOIN (VALUES
  ('maya-kobe-prestige','Prestige Suite 2',4,72800,1),
  ('maya-kobe-haze','Haze Suite',2,30000,2),
  ('maya-kobe-glow','Glow Suite',2,31009,3),
  ('maya-kobe-tide','Tide Suite',2,33800,4),
  ('maya-kobe-drift','Drift Suite',2,33800,5)
) AS x(slug,name,cap,price,so)
WHERE v.slug='maya-kobe';

-- ── BY-ROOM: Maya Ilai — placeholder room types (owner curates the 16-unit detail in admin) ──
DELETE FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug='maya_ilai');
INSERT INTO rooms (slug, name, venue_id, capacity, price_amount, price_currency, form_mode, is_published, sort_order)
SELECT x.slug, x.name, v.id, x.cap, 0, 'USD', 'availability', TRUE, x.so
FROM venues v CROSS JOIN (VALUES
  ('maya-ilai-studio','Studio',2,1),
  ('maya-ilai-garden-room','Garden Room',2,2)
) AS x(slug,name,cap,so)
WHERE v.slug='maya_ilai';

-- ── Units: ensure one per room ──
INSERT INTO units (room_id, name, sort_order)
SELECT id, 'Unit A', 0 FROM rooms r WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id=r.id);

-- ── Zuri suite hero photos (full URLs so storage_url() passes them through; proxied in dev) ──
INSERT INTO room_images (room_id, filename, is_hero, sort_order)
SELECT r.id, x.url, TRUE, 0
FROM rooms r CROSS JOIN (VALUES
  ('zuri-maji','https://tribalsand.com/images/zuri/Maji Suite/Maji Suite 1.jpg'),
  ('zuri-mwezi','https://tribalsand.com/images/zuri/Mwezi Suite/IMG-20251121-WA0030.jpg'),
  ('zuri-ua','https://tribalsand.com/images/zuri/Ua Suite/Ua Suite 1.jpg'),
  ('zuri-anga','https://tribalsand.com/images/zuri/Anga Suite/Anga Suite 1.jpg'),
  ('zuri-jua','<JUA_URL>'),
  ('zuri-bahari','<BAHARI_URL>')
) AS x(slug,url)
WHERE r.slug = x.slug;
```
NOTE: `CROSS JOIN (VALUES …)` — verify your PG accepts it; if not, use the `FROM venues v, (VALUES …) AS x(...) WHERE v.slug=...` comma-join form instead (equivalent).

- [ ] **Step 3: Run + verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand"
$PSQL -f db/seed_rooms_model.sql
$PSQL -tA -c "SELECT v.slug, count(r.id) rooms, count(*) FILTER (WHERE r.is_entire_place) entire FROM venues v LEFT JOIN rooms r ON r.venue_id=v.id GROUP BY v.slug ORDER BY v.slug;"
echo "--- every room has a unit (expect 0) ---"; $PSQL -tA -c "SELECT count(*) FROM rooms r WHERE NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id=r.id);"
echo "--- zuri suite photos (expect 6) ---"; $PSQL -tA -c "SELECT count(*) FROM room_images ri JOIN rooms r ON r.id=ri.room_id JOIN venues v ON v.id=r.venue_id WHERE v.slug='zuri';"
echo "--- maya-kobe KES (expect 5) ---"; $PSQL -tA -c "SELECT count(*) FROM rooms r JOIN venues v ON v.id=r.venue_id WHERE v.slug='maya-kobe' AND r.price_currency='KES';"
```
Expected counts: `my-amani 1 (entire 1)`, `enkare-bofa 1 (1)`, `sandbox 1 (1)`, `zuri 6 (0)`, `maya-kobe 5 (0)`, `maya_ilai 2 (0)`, `tribal-dunes 1 (0)`; rooms-without-unit `0`; zuri photos `6`; maya-kobe KES `5`.

- [ ] **Step 4: Commit**
```bash
git add db/seed_rooms_model.sql
git commit -m "feat: re-seed rooms per real model (villa vs by-room) + Zuri suite photos"
```

---

## Task 2: My Amani → villa mode

**Files:** Modify `my-amani.php`

- [ ] **Step 1: Remove the card-grid include.** Delete the line (≈ 324):
```php
<?php $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
```
Keep the next two lines (`availability-bar.php` + `booking-modal.php`) and `$page_rooms_rates = true; $rr_venue_slug = 'my-amani';` at the top — the bar now books the single whole-villa room.

- [ ] **Step 2: Verify (no card grid, bar present, books the villa)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l my-amani.php
php -S localhost:8065 router.php >/tmp/mf2.log 2>&1 & SRV=$!; sleep 1
out=$(curl -s "http://localhost:8065/my-amani")
echo "cards=$(echo "$out" | grep -c 'suite-card rr-card') bar=$(echo "$out" | grep -c 'id=\"rrBar\"') fallback=$(echo "$out" | grep -oE 'data-fallback-slug=\"[^\"]+\"' | head -1)"
kill $SRV
```
Expected: `cards=0 bar=1 fallback=data-fallback-slug="my-amani"`.

- [ ] **Step 3: Commit**
```bash
git add my-amani.php
git commit -m "feat: My Amani booked as entire villa (remove room-card grid)"
```

---

## Task 3: Zuri → by-room (DB suite cards)

**Files:** Modify `zuri.php`

- [ ] **Step 1: Replace the hand-coded suites block with the DB section.** READ `zuri.php` around lines 392–435: there's a block beginning `<div class="sec-label">Accommodations</div>` / `<h2 class="sec-h">Six <em>Named Suites</em></h2>` / `<div class="suites-grid"> … </div>` holding the 6 hand-coded `.suite-card`s, inside a containing `<section>`/`<div class="section">` wrapper. Replace the ENTIRE accommodations block (from its `.sec-label`/heading through the closing of the `.suites-grid` and its section wrapper) with:
```php
<?php $rr_venue_slug = 'zuri'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
```
Be careful to remove only the accommodations/suites block (keep the gallery, about, dining, FAQ, etc. intact). The `availability-bar.php` + `booking-modal.php` includes already present after the gallery stay.

- [ ] **Step 2: Verify (6 DB suite cards with photos, bar present)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l zuri.php
php -S localhost:8065 router.php >/tmp/mf3.log 2>&1 & SRV=$!; sleep 1
out=$(curl -s "http://localhost:8065/zuri")
echo "dbCards=$(echo "$out" | grep -c 'suite-card rr-card') heading=$(echo "$out" | grep -c 'Rooms &amp; <em>Availability') hardcodedSixNamed=$(echo "$out" | grep -c 'Six <em>Named Suites') bar=$(echo "$out" | grep -c 'id=\"rrBar\"') zuriPhotos=$(echo "$out" | grep -c 'images/zuri/Maji')"
kill $SRV
```
Expected: `dbCards=6 heading=1 hardcodedSixNamed=0 bar=1 zuriPhotos>=1` (the hand-coded block gone, DB cards present, Maji photo URL rendered).

- [ ] **Step 3: Commit**
```bash
git add zuri.php
git commit -m "feat: Zuri by-room — DB-driven suite cards replace hand-coded block"
```

---

## Task 4: Maya Ilai → by-room

**Files:** Modify `maya_ilai.php`

- [ ] **Step 1: Add the card-grid include before the bar.** The page currently has (≈ 411–412):
```php
<?php $rr_venue_slug = 'maya_ilai'; include __DIR__ . '/includes/availability-bar.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```
Insert immediately BEFORE that bar line:
```php
<?php $rr_venue_slug = 'maya_ilai'; include __DIR__ . '/includes/rooms-and-rates.php'; ?>
```

- [ ] **Step 2: Verify**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l maya_ilai.php
php -S localhost:8065 router.php >/tmp/mf4.log 2>&1 & SRV=$!; sleep 1
out=$(curl -s "http://localhost:8065/maya_ilai")
echo "cards=$(echo "$out" | grep -c 'suite-card rr-card') bar=$(echo "$out" | grep -c 'id=\"rrBar\"')"
kill $SRV
```
Expected: `cards=2 bar=1` (Studio + Garden Room placeholders).

- [ ] **Step 3: Commit**
```bash
git add maya_ilai.php
git commit -m "feat: Maya Ilai by-room — add room-card grid"
```

---

## Task 5: Tribal Dunes → not bookable

**Files:** Modify `tribal-dunes.php`

- [ ] **Step 1: Remove the booking includes + flag.** Delete the line `$page_rooms_rates = true;` (≈ 17) and the two include lines (≈ 325–326):
```php
<?php $rr_venue_slug = 'tribal-dunes'; include __DIR__ . '/includes/availability-bar.php'; ?>
<?php include __DIR__ . '/includes/booking-modal.php'; ?>
```
Leave the rest of the page (it becomes a plain content page).

- [ ] **Step 2: Verify (no bar, no modal, page still 200)**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"; php -l tribal-dunes.php
php -S localhost:8065 router.php >/tmp/mf5.log 2>&1 & SRV=$!; sleep 1
echo "status=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8065/tribal-dunes)"
out=$(curl -s "http://localhost:8065/tribal-dunes")
echo "bar=$(echo "$out" | grep -c 'id=\"rrBar\"') modal=$(echo "$out" | grep -c 'id=\"bkModal\"')"
kill $SRV
```
Expected: `status=200`, `bar=0 modal=0`.

- [ ] **Step 3: Commit**
```bash
git add tribal-dunes.php
git commit -m "feat: Tribal Dunes is not bookable (remove booking bar/modal)"
```

---

## Task 6: Cross-property verification

**Files:** none (verification + end-to-end sanity)

- [ ] **Step 1: Render-mode check across all property pages**
```bash
cd "/Users/patrikgiuliana/Desktop/CLAUDE CODE/Tribal Sand"
php -S localhost:8064 router.php >/tmp/mf6.log 2>&1 & SRV=$!; sleep 1
for p in my-amani enkare-bofa sandbox zuri maya-kobe maya_ilai tribal-dunes; do
  out=$(curl -s "http://localhost:8064/$p")
  echo "$p: cards=$(echo "$out" | grep -c 'suite-card rr-card') bar=$(echo "$out" | grep -c 'id=\"rrBar\"')"
done
kill $SRV
```
Expected: `my-amani cards=0 bar=1`; `enkare-bofa cards=0 bar=1`; `sandbox cards=0 bar=1`; `zuri cards=6 bar=1`; `maya-kobe cards=5 bar=1`; `maya_ilai cards=2 bar=1`; `tribal-dunes cards=0 bar=0`.

- [ ] **Step 2: End-to-end hold still works (villa + by-room)**
```bash
BASE=http://localhost:8064; PSQL="/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA"
php -S localhost:8064 router.php >/tmp/mf6b.log 2>&1 & SRV=$!; sleep 1
echo "villa (my-amani):"; curl -s -X POST "$BASE/api/submit-enquiry.php" -H 'Content-Type: application/json' -d '{"room_slug":"my-amani","checkin":"2028-09-01","checkout":"2028-09-04","name":"Model Villa","email":"mv@example.com","adults":2}'
echo; echo "by-room (zuri-maji):"; curl -s -X POST "$BASE/api/submit-enquiry.php" -H 'Content-Type: application/json' -d '{"room_slug":"zuri-maji","checkin":"2028-09-01","checkout":"2028-09-03","name":"Model Room","email":"mr@example.com","adults":2}'
kill $SRV
$PSQL -c "DELETE FROM holds WHERE guest_email IN ('mv@example.com','mr@example.com'); DELETE FROM submissions WHERE guest_email IN ('mv@example.com','mr@example.com');" >/dev/null
```
Expected: both return `{"ok":true,...,"mode":"hold"}` (villa room + a Zuri suite both create holds). Test rows cleaned up after.

- [ ] **Step 3: Confirm no leftover availability blocks from the test**
```bash
/Applications/Postgres.app/Contents/Versions/18/bin/psql -h localhost -p 5432 -U patrikgiuliana -d tribalsand -tA -c "DELETE FROM availability_blocks WHERE date_from='2028-09-01';"
```
(Clears the two test holds' date blocks.)

---

## Self-review

**Spec coverage:**
- My Amani/Enkare/Sandbox = entire villa (1 room, is_entire_place, villa mode) → Task 1 + Task 2 ✓
- Zuri = by-room with 6 named suites + photos; hand-coded block replaced → Task 1 + Task 3 ✓
- Maya Kobe = 5 suites, KES prices → Task 1 (already by-room page) ✓
- Maya Ilai = by-room placeholders → Task 1 + Task 4 ✓
- Tribal Dunes = not bookable → Task 5 ✓
- Units per room; idempotent re-seed → Task 1 (units INSERT; delete+insert) ✓
- Reuse engine/API, no URL changes → only includes toggled + data; no page renames ✓

**Placeholder scan:** the seed's `<JUA_URL>`/`<BAHARI_URL>` are filled from a concrete grep in Task 1 Step 1 (not left as placeholders). All else is literal.

**Type/name consistency:** room slugs (`my-amani`, `zuri-maji…zuri-bahari`, `maya-kobe-prestige…drift`, `maya-ilai-studio`/`-garden-room`) used consistently in the seed; `is_entire_place` (from the earlier migration) drives villa fallback + (no) toggle; `data-fallback-slug` produced by `availability-bar.php` and asserted in Tasks 2/6; `rooms-and-rates.php` include added/removed to flip modes (Tasks 2–5).

**Known follow-ups (intended, per spec):** real photos/prices for Maya Kobe & Maya Ilai and exact Maya Ilai unit breakdown via admin; `my-amani-*` and `maya-kobe-main-house`/`cottages` individual pages' inline widgets go inert (booking moved to the overview).
