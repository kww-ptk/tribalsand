# Signed Waivers Archive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The owner can find every signed guest / visitor waiver by month and property, see it with the full document details and the scan it was signed with, and download a month as a ZIP.

**Architecture:** A migration freezes the scan's file key at signing (`waiver_scan_snapshot` / `waiver_photo_snapshot`) and every delete path leaves a frozen file alone. One renderer (`includes/waiver-record.php`) builds both record pages from a common model; `record.php` / `includes/visitor-record.php` become thin auth wrappers around it. `includes/waiver-archive.php` lists records (pure helpers + one query per kind) and builds the ZIP with `ZipArchive`; `admin/waivers.php` is the owner-only page and download.

**Tech Stack:** PHP 8.2 vanilla, PostgreSQL via `db_query()`, private storage (`storage_put_private` / new `storage_read_private`), `ZipArchive` (ext-zip, added to the Dockerfile).

**Spec:** `docs/superpowers/specs/2026-10-15-signed-waivers-archive-design.md`

## Global Constraints
- Page, scans-by-archive and download: **owner only** (`require_owner()`); add `waivers.php` to `ACCESS_OWNER_ONLY`.
- Records render **only frozen snapshots** for identity; never live identity columns (legacy fallback rules unchanged).
- Guest links (`?ref=`, `?g=`, `?vp=`) show the full details but **never the scan image**.
- Month = `waiver_signed_at` in **Africa/Nairobi** (the DB session is already Nairobi; compare `waiver_signed_at >= :from AND < :to` with local dates).
- Every new column read is pre-migration-safe (information_schema lookup guards).
- New migration line appended LAST to `db/migrations/ORDER.txt` as `add_waiver_scan_snapshot.sql  2026-10-15`.
- Money/PII: scans are private files; never a public URL; ZIP only streamed to the owner; each download audited.

## Review Focus
1. A guest who retakes the photo AFTER signing — the record and ZIP must still show the signing photo (Task 1 test "retake after signing keeps the frozen file").
2. A record signed before the migration (no snapshot) — must still show the current scan, labelled "Scan on file now", never "No scan" (Task 2 test "legacy record falls back").
3. A scan file missing from storage (lost on a deploy) — the ZIP must still contain the record HTML and list the missing scan in index.csv, never abort the whole month (Task 4 test "missing scan file").
4. Names with `/`, accents or quotes — ZIP file names must be safe and unique (Task 4 test "file base is safe + unique").
5. Month boundaries — a waiver signed 31 Oct 23:30 Nairobi belongs to October (Task 3 test "Nairobi month edge").

---

### Task 1: Freeze the scan at signing + never delete a frozen scan

**Files:**
- Create: `db/migrations/add_waiver_scan_snapshot.sql`
- Modify: `db/migrations/ORDER.txt` (append line)
- Modify: `includes/storage.php` (add `storage_read_private()`)
- Modify: `includes/checkin.php` (guards, `checkin_guest_scan_key()`, `checkin_guest_file_keys()`, `checkin_scan_delete_unless_frozen()`, void clears snapshot)
- Modify: `api/checkin-save.php:216-241` (freeze at signing)
- Modify: `api/checkin-upload.php:87-91`, `admin/booking.php:311-313` (delete unless frozen)
- Modify: `api/checkin-guest.php:56`, `admin/booking.php:360` (delete both keys on guest removal)
- Modify: `includes/visitor-store.php` (`visitor_photo_snapshot_supported()`, `visitor_scan_key()`, freeze in `visitor_sign()`, keep frozen in `visitor_update_identity()`, clear in `visitor_remove()`, prune treats snapshot as used)
- Test: `tests/waiver_archive_logic.php` (new)

**Interfaces:**
- Produces:
  - `storage_read_private(string $key): ?string` — bytes or null.
  - `waiver_scan_snapshot_supported(): bool` (checkin_guests column).
  - `checkin_guest_scan_key(array $g): array{key:string, frozen:bool}`
  - `checkin_guest_file_keys(int $holdId, int $guestId): string[]` — passport + frozen keys of the guest and their children.
  - `checkin_scan_delete_unless_frozen(int $holdId, int $guestId, string $oldKey): void`
  - `visitor_photo_snapshot_supported(): bool`
  - `visitor_scan_key(array $v): array{key:string, frozen:bool}`

- [ ] **Step 1: Migration**

```sql
-- db/migrations/add_waiver_scan_snapshot.sql
-- Freeze WHICH scan a waiver was signed with. A photo retaken after signing used
-- to delete the old file, so the signed record pointed at a different picture.
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_scan_snapshot TEXT;
DO $$ BEGIN
  IF to_regclass('public.registered_visitors') IS NOT NULL THEN
    ALTER TABLE registered_visitors ADD COLUMN IF NOT EXISTS waiver_photo_snapshot TEXT;
  END IF;
END $$;
```

Append to `db/migrations/ORDER.txt`: `add_waiver_scan_snapshot.sql                        2026-10-15` (match the column alignment of the existing lines). Apply locally: `psql "$DATABASE_URL" -f db/migrations/add_waiver_scan_snapshot.sql` (or `php -r` with `db()->exec(file_get_contents(...))`).

- [ ] **Step 2: Write the failing tests** (`tests/waiver_archive_logic.php`)

```php
<?php
declare(strict_types=1);
// Signed waivers archive: frozen scans, full-detail records, month list, ZIP.
// Pure checks always; DB checks in ONE rolled-back transaction.
// Run: php tests/waiver_archive_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/visitor-store.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; } else { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Task 1: which scan a record shows ──
check('scan: frozen snapshot wins', checkin_guest_scan_key(['waiver_scan_snapshot' => 'a.jpg', 'passport_file_key' => 'b.jpg']) === ['key' => 'a.jpg', 'frozen' => true]);
check('scan: legacy falls back to the current file', checkin_guest_scan_key(['passport_file_key' => 'b.jpg']) === ['key' => 'b.jpg', 'frozen' => false]);
check('scan: nothing on file', checkin_guest_scan_key([]) === ['key' => '', 'frozen' => false]);
check('visitor scan: frozen wins', visitor_scan_key(['waiver_photo_snapshot' => 'v1.jpg', 'id_photo_key' => 'v2.jpg']) === ['key' => 'v1.jpg', 'frozen' => true]);

// storage_read_private round-trip (local private dir)
$tmp = tempnam(sys_get_temp_dir(), 'wa'); file_put_contents($tmp, 'SCAN');
$k = 'tests/waiver-' . bin2hex(random_bytes(4)) . '.jpg';
storage_put_private($tmp, $k, 'image/jpeg');
check('storage_read_private returns the bytes', storage_read_private($k) === 'SCAN');
storage_delete_private($k);
check('storage_read_private: missing file = null', storage_read_private($k) === null);

try {
    $db = db();
    if (!waiver_scan_snapshot_supported()) { echo "SKIP  DB checks (add_waiver_scan_snapshot.sql not applied)\n"; }
    else {
        $db->beginTransaction();
        $hid = (int)db_query('SELECT id FROM holds ORDER BY id LIMIT 1')->fetchColumn();
        $tmp = tempnam(sys_get_temp_dir(), 'wa'); file_put_contents($tmp, 'OLD');
        $old = 'tests/frozen-' . bin2hex(random_bytes(4)) . '.jpg';
        storage_put_private($tmp, $old, 'image/jpeg');
        $gid = (int)db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, passport_number, passport_file_key, waiver_signed_at, waiver_signed_name, waiver_signature, waiver_scan_snapshot)
                              VALUES (:h, FALSE, 'ZZ Frozen', 'X1', :k, now(), 'ZZ Frozen', 'data:image/png;base64,x', :k) RETURNING id",
                             [':h' => $hid, ':k' => $old])->fetchColumn();
        checkin_scan_delete_unless_frozen($hid, $gid, $old);
        check('db: retake after signing keeps the frozen file', storage_read_private($old) === 'OLD');
        $keys = checkin_guest_file_keys($hid, $gid);
        check('db: guest file keys include the frozen scan once', $keys === [$old]);
        checkin_void_signature_if_identity_changed($hid, $gid, ['passport_number' => 'X2'], 'admin');
        $snap = db_query('SELECT waiver_scan_snapshot FROM checkin_guests WHERE id = :g', [':g' => $gid])->fetchColumn();
        check('db: voiding the signature clears the frozen scan', $snap === null);
        $db->rollBack();
        storage_delete_private($old);
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 3: Run — expect FAIL** (`php tests/waiver_archive_logic.php` → "Call to undefined function checkin_guest_scan_key")

- [ ] **Step 4: Implement**

`includes/storage.php` (after `storage_delete_private()`):
```php
/** Bytes of a private check-in file (private bucket via a signed GET, else the local dir), or null. */
function storage_read_private(string $key): ?string {
    if ($key === '') return null;
    $signed = storage_signed_get_url($key);
    if ($signed !== '') { $d = @file_get_contents($signed); return $d === false ? null : $d; }
    $path = storage_local_path($key);
    if (!is_file($path)) return null;
    $d = @file_get_contents($path);
    return $d === false ? null : $d;
}
```

`includes/checkin.php` (beside `checkin_identity_snapshot_supported()`):
```php
/** add_waiver_scan_snapshot.sql applied? Catalog lookup — safe inside a transaction. */
function waiver_scan_snapshot_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { return $ok = (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'checkin_guests' AND column_name = 'waiver_scan_snapshot'")->fetchColumn(); }
    catch (Throwable $e) { return $ok = false; }
}

/** The scan a signed record shows: the one frozen at signing, else (legacy) the current file. */
function checkin_guest_scan_key(array $g): array {
    $frozen = trim((string)($g['waiver_scan_snapshot'] ?? ''));
    if ($frozen !== '') return ['key' => $frozen, 'frozen' => true];
    return ['key' => trim((string)($g['passport_file_key'] ?? '')), 'frozen' => false];
}

/** Every private scan file of a guest and their children (current + frozen), de-duplicated. */
function checkin_guest_file_keys(int $holdId, int $guestId): array {
    $cols = 'passport_file_key' . (waiver_scan_snapshot_supported() ? ', waiver_scan_snapshot' : '');
    $rows = db_query("SELECT $cols FROM checkin_guests WHERE (id = :g OR parent_guest_id = :g) AND hold_id = :h",
                     [':g' => $guestId, ':h' => $holdId])->fetchAll();
    $keys = [];
    foreach ($rows as $r) foreach ($r as $v) { $v = trim((string)$v); if ($v !== '') $keys[$v] = true; }
    return array_keys($keys);
}

/** After a new photo replaced $oldKey: delete the old file — unless a signed record froze it. */
function checkin_scan_delete_unless_frozen(int $holdId, int $guestId, string $oldKey): void {
    if ($oldKey === '') return;
    if (waiver_scan_snapshot_supported()) {
        $frozen = (string) db_query('SELECT COALESCE(waiver_scan_snapshot, \'\') FROM checkin_guests WHERE id = :g AND hold_id = :h',
                                   [':g' => $guestId, ':h' => $holdId])->fetchColumn();
        if ($frozen === $oldKey) return;
    }
    try { storage_delete_private($oldKey); } catch (Throwable $e) {}
}
```

In `checkin_void_signature_if_identity_changed()`: before the UPDATE, read `$frozen = waiver_scan_snapshot_supported() ? trim((string)($g['waiver_scan_snapshot'] ?? '')) : '';`; add `if (waiver_scan_snapshot_supported()) $clear .= ', waiver_scan_snapshot=NULL';`; after the UPDATE: `if ($frozen !== '' && $frozen !== trim((string)($g['passport_file_key'] ?? ''))) { try { storage_delete_private($frozen); } catch (Throwable $e) {} }` (the frozen file belongs to no record any more).

`api/checkin-save.php` — after the `checkin_id_type_supported()` block (line ~238):
```php
        if (waiver_scan_snapshot_supported()) {
            $sigSet .= ', waiver_scan_snapshot=:snscan';
            $sigP[':snscan'] = trim((string)($targetRow['passport_file_key'] ?? '')) ?: null;
        }
```
If this re-sign replaces an earlier frozen file that differs from the current one, delete the earlier one after the UPDATE: read `$prevFrozen = trim((string)($targetRow['waiver_scan_snapshot'] ?? ''))` before it, and after `db_query("UPDATE checkin_guests SET $sigSet …")`: `if ($prevFrozen !== '' && $prevFrozen !== ($sigP[':snscan'] ?? '')) { try { storage_delete_private($prevFrozen); } catch (Throwable $e) {} }`.

`api/checkin-upload.php` lines 89-91 → replace the delete with:
```php
if ($prev && !empty($prev['passport_file_key']) && $prev['passport_file_key'] !== $key) {
    checkin_scan_delete_unless_frozen($holdId, $guestId, (string)$prev['passport_file_key']);
}
```
`admin/booking.php:313` → same call with `$gid`.

`api/checkin-guest.php:56` and `admin/booking.php:360` → replace the `SELECT passport_file_key …` with `$files = checkin_guest_file_keys($holdId, $gid);` (the existing foreach deletes them after the DELETE).

`includes/visitor-store.php`:
```php
function visitor_photo_snapshot_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { return $ok = (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'registered_visitors' AND column_name = 'waiver_photo_snapshot'")->fetchColumn(); }
    catch (Throwable $e) { return $ok = false; }
}
/** The ID photo a visitor's record shows: frozen at signing, else (legacy) the current one. */
function visitor_scan_key(array $v): array {
    $frozen = trim((string)($v['waiver_photo_snapshot'] ?? ''));
    if ($frozen !== '') return ['key' => $frozen, 'frozen' => true];
    return ['key' => trim((string)($v['id_photo_key'] ?? '')), 'frozen' => false];
}
```
- `visitor_sign()`: add `. (visitor_photo_snapshot_supported() ? ', waiver_photo_snapshot = id_photo_key' : '')` to the SET list.
- `visitor_update_identity()`: in the void UPDATE add `, waiver_photo_snapshot = NULL` when supported; in the photo-delete block, skip the delete when `$v['id_photo_key'] === ($v['waiver_photo_snapshot'] ?? '')` **and** the signature was not voided (`!$changed`); when voided and the frozen key differs from both the old and new photo, queue/delete the frozen key too.
- `visitor_remove()`: also `waiver_photo_snapshot = NULL` in the UPDATE (when supported) and delete that file if it differs from `id_photo_key`.
- `visitor_prune_drafts()`: the `used` EXISTS also matches `rv.waiver_photo_snapshot = a.subject` when supported.

- [ ] **Step 5: Run — expect PASS**, plus `php tests/visitor_registration_logic.php`, `php tests/checkin_consent.php`, `php tests/checkin_logic.php`, `php tests/migration_order_logic.php` (all ALL PASS).

- [ ] **Step 6: Commit** — `git add` the files above; `git commit -m "feat(waivers): freeze the scan a waiver was signed with"`

---

### Task 2: One record renderer — full document details + the scan

**Files:**
- Create: `includes/waiver-record.php`
- Modify: `record.php` (auth stays; body → renderer)
- Modify: `includes/visitor-record.php` (auth stays; body → renderer)
- Modify: `admin/checkin-file.php` (`&signed=1` serves the frozen scan), `admin/visitor-file.php` (`&signed=1` same)
- Test: `tests/waiver_archive_logic.php` (append)

**Interfaces:**
- Consumes: `checkin_guest_scan_key()`, `visitor_scan_key()` (Task 1).
- Produces:
  - `waiver_record_model_guest(array $hold, array $guest, string $reference): array`
  - `waiver_record_model_visitor(array $v): array`
  - Model keys: `kind` ('guest'|'visitor'), `title`, `reference`, `booking` (string, guest only), `stay`, `dates`, `name`, `role`, `nationality`, `doc_label`, `doc_number`, `expiry` (Y-m-d or ''), `terms`, `version`, `signature`, `signed_name`, `signed_at`, `ip`, `device`, `method`, `scan_key`, `scan_frozen` (bool), `scan_removed_at` (string|''), `hold_id`, `guest_id`, `visitor_id`.
  - `waiver_record_html(array $m, array $opt = []): string` — `$opt`: `scan` = `'none'` (guest links) | `'url'` (owner/staff screen) | `'embed'` (ZIP); `scan_url` (string, for `'url'`); `scan_bytes` (?string, for `'embed'`); `scan_file` (file name beside the record, for `'embed'`); `print_button` (bool, default true).

- [ ] **Step 1: Failing tests** (append before the final echo)

```php
// ── Task 2: the record ──
require_once __DIR__ . '/../includes/waiver-record.php';
$hold = ['id' => 175, 'access_code' => 'AB12', 'venue_name' => 'Maya Kobe', 'room_name' => 'Ocean Suite', 'check_in' => '2026-12-20', 'check_out' => '2026-12-27'];
$g = ['id' => 9, 'is_lead' => true, 'waiver_name_snapshot' => 'Anne Smith', 'waiver_passport_snapshot' => 'X1234567',
      'waiver_nationality_snapshot' => 'United Kingdom', 'waiver_passport_expiry_snapshot' => '2031-05-01', 'waiver_id_type_snapshot' => 'passport',
      'waiver_terms_snapshot' => 'TERMS', 'waiver_version' => 'v1', 'waiver_signature' => 'data:image/png;base64,AAAA',
      'waiver_signed_name' => 'Anne Smith', 'waiver_signed_at' => '2026-10-15 10:00:00+03', 'waiver_scan_snapshot' => 'k.jpg'];
$m = waiver_record_model_guest($hold, $g, 'TSR-175-9-ab');
check('record: full number, no dots', $m['doc_number'] === 'X1234567');
check('record: expiry kept', $m['expiry'] === '2031-05-01');
$html = waiver_record_html($m, ['scan' => 'none']);
check('guest link: full number on the page', str_contains($html, 'X1234567') && !str_contains($html, '•'));
check('guest link: expiry on the page', str_contains($html, '1 May 2031'));
check('guest link: no scan image', !str_contains($html, 'Document scan'));
$html = waiver_record_html($m, ['scan' => 'url', 'scan_url' => '/admin/checkin-file.php?hold=175&guest=9&signed=1']);
check('owner view: scan image shown', str_contains($html, 'Document scan') && str_contains($html, 'checkin-file.php?hold=175&amp;guest=9&amp;signed=1'));
$html = waiver_record_html($m, ['scan' => 'embed', 'scan_bytes' => "\xFF\xD8\xFFJPEG", 'scan_file' => 'x.jpg']);
check('zip: image embedded as data URI', str_contains($html, 'src="data:image/jpeg;base64,'));
$mPdf = waiver_record_model_guest($hold, ['waiver_scan_snapshot' => 'k.pdf'] + $g, 'R');
$html = waiver_record_html($mPdf, ['scan' => 'embed', 'scan_bytes' => '%PDF-1.4', 'scan_file' => 'x.pdf']);
check('zip: a PDF scan points at the file beside the record', str_contains($html, 'PDF scan') && str_contains($html, 'x.pdf'));
$legacy = waiver_record_model_guest($hold, ['waiver_scan_snapshot' => null, 'passport_file_key' => 'now.jpg'] + $g, 'R');
check('legacy record falls back to the current scan, labelled', $legacy['scan_key'] === 'now.jpg'
    && str_contains(waiver_record_html($legacy, ['scan' => 'url', 'scan_url' => '/x']), 'Scan on file now'));
$noScan = waiver_record_model_guest($hold, ['waiver_scan_snapshot' => null, 'passport_file_key' => null] + $g, 'R');
check('no scan at all says so', str_contains(waiver_record_html($noScan, ['scan' => 'url', 'scan_url' => '']), 'No scan on file'));
$v = ['id' => 4, 'waiver_reference' => 'VR-4', 'waiver_name_snapshot' => 'Wanjiru Kamau', 'waiver_id_type_snapshot' => 'kenya_id',
      'waiver_id_number_snapshot' => '12345678', 'waiver_nationality_snapshot' => 'Kenya', 'waiver_id_expiry_snapshot' => null,
      'waiver_terms_snapshot' => 'T', 'waiver_version' => 'v', 'waiver_signature' => 'data:image/png;base64,A', 'waiver_signed_name' => 'W K',
      'waiver_signed_at' => '2026-10-15 09:00:00+03', 'removed_at' => '2026-10-20 12:00:00+03', 'waiver_photo_snapshot' => null, 'id_photo_key' => null];
$vm = waiver_record_model_visitor($v);
check('visitor: Kenyan ID full number, expiry dash', $vm['doc_label'] === 'Kenyan ID' && $vm['doc_number'] === '12345678' && $vm['expiry'] === '');
check('visitor removed: photo removed line', str_contains(waiver_record_html($vm, ['scan' => 'url', 'scan_url' => '']), 'ID photo removed on 20 Oct 2026'));
check('record escapes names', !str_contains(waiver_record_html(waiver_record_model_guest($hold, ['waiver_name_snapshot' => '<b>x</b>'] + $g, 'R')), '<b>x</b>'));
```

- [ ] **Step 2: Run — expect FAIL** (missing `includes/waiver-record.php`).

- [ ] **Step 3: Implement `includes/waiver-record.php`**

```php
<?php
/**
 * The signed waiver record — ONE renderer for guests (record.php), visitors
 * (includes/visitor-record.php) and the monthly ZIP (includes/waiver-archive.php).
 * Identity comes only from the frozen snapshots (legacy guest rows fall back to
 * the live columns, as before). The scan is shown only when the caller says so.
 */
declare(strict_types=1);
require_once __DIR__ . '/checkin.php';

function waiver_record_model_guest(array $hold, array $guest, string $reference): array {
    $snap = fn(string $s, string $live) => trim((string)($guest[$s] ?? '')) !== '' ? trim((string)$guest[$s]) : trim((string)($guest[$live] ?? ''));
    $scan = checkin_guest_scan_key($guest);
    $stay = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
    return [
        'kind' => 'guest', 'title' => 'Guest Indemnity & Waiver', 'reference' => $reference,
        'booking' => 'TS-' . (int)($hold['id'] ?? 0) . (!empty($hold['access_code']) ? ' · code ' . $hold['access_code'] : ''),
        'stay' => $stay,
        'dates' => !empty($hold['check_in']) ? date('D j M Y', strtotime((string)$hold['check_in'])) . ' → ' . date('D j M Y', strtotime((string)$hold['check_out'])) : '',
        'name' => $snap('waiver_name_snapshot', 'passport_name'),
        'role' => !empty($guest['is_lead']) ? 'Lead guest' : 'Adult guest',
        'nationality' => $snap('waiver_nationality_snapshot', 'nationality'),
        'doc_label' => checkin_id_type_label(trim((string)($guest['waiver_id_type_snapshot'] ?? '')) ?: 'passport'),
        'doc_number' => $snap('waiver_passport_snapshot', 'passport_number'),
        'expiry' => substr($snap('waiver_passport_expiry_snapshot', 'passport_expiry'), 0, 10),
        'terms' => (string)($guest['waiver_terms_snapshot'] ?? ''), 'version' => (string)($guest['waiver_version'] ?? ''),
        'signature' => (string)($guest['waiver_signature'] ?? ''), 'signed_name' => (string)($guest['waiver_signed_name'] ?? ''),
        'signed_at' => (string)($guest['waiver_signed_at'] ?? ''),
        'ip' => (string)($guest['waiver_signed_ip'] ?? ''), 'device' => (string)($guest['waiver_signed_user_agent'] ?? ''),
        'method' => ((string)($guest['waiver_signed_method'] ?? '') === 'reception') ? 'On a Tribal Sand device, in person' : 'Own device, via personal check-in link',
        'scan_key' => $scan['key'], 'scan_frozen' => $scan['frozen'], 'scan_removed_at' => '',
        'hold_id' => (int)($hold['id'] ?? 0), 'guest_id' => (int)($guest['id'] ?? 0), 'visitor_id' => 0,
    ];
}

function waiver_record_model_visitor(array $v): array {
    $scan = visitor_scan_key($v);
    return [
        'kind' => 'visitor', 'title' => 'Visitor Indemnity & Waiver', 'reference' => (string)($v['waiver_reference'] ?? ''),
        'booking' => '', 'stay' => '', 'dates' => '',
        'name' => (string)($v['waiver_name_snapshot'] ?? ''), 'role' => 'Visitor',
        'nationality' => (string)($v['waiver_nationality_snapshot'] ?? ''),
        'doc_label' => checkin_id_type_label((string)($v['waiver_id_type_snapshot'] ?? 'passport')),
        'doc_number' => trim((string)($v['waiver_id_number_snapshot'] ?? '')),
        'expiry' => substr(trim((string)($v['waiver_id_expiry_snapshot'] ?? '')), 0, 10),
        'terms' => (string)($v['waiver_terms_snapshot'] ?? ''), 'version' => (string)($v['waiver_version'] ?? ''),
        'signature' => (string)($v['waiver_signature'] ?? ''), 'signed_name' => (string)($v['waiver_signed_name'] ?? ''),
        'signed_at' => (string)($v['waiver_signed_at'] ?? ''),
        'ip' => (string)($v['signed_ip'] ?? ''), 'device' => (string)($v['signed_ua'] ?? ''),
        'method' => ($v['signed_via'] ?? '') === 'gate' ? 'On a Tribal Sand device at the gate' : 'Own device, via the registration link',
        'scan_key' => $scan['key'], 'scan_frozen' => $scan['frozen'],
        'scan_removed_at' => !empty($v['removed_at']) ? (string)$v['removed_at'] : '',
        'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => (int)($v['id'] ?? 0),
    ];
}

/** MIME of scan bytes (sniffed — the key's extension is a hint only). */
function waiver_scan_mime(string $bytes): string {
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($bytes, "\x89PNG")) return 'image/png';
    if (str_starts_with($bytes, '%PDF-')) return 'application/pdf';
    return 'application/octet-stream';
}

function waiver_record_html(array $m, array $opt = []): string {
    $mode = $opt['scan'] ?? 'none';
    $d = fn(string $ymd) => $ymd !== '' ? date('j M Y', strtotime($ymd)) : '—';
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Signed consent · <?= e($m['name'] !== '' ? $m['name'] : 'Guest') ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .subh{font-size:10px;letter-spacing:2px;opacity:.75;text-transform:uppercase;margin-top:3px}
  .bd{padding:26px 30px 30px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:22px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:12.5px} td{padding:4px 0;vertical-align:top} td.k{color:#666;width:150px}
  .terms{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;white-space:pre-wrap}
  .sigbox{border:1px solid #d8dbd8;border-radius:4px;padding:8px;max-width:280px;background:#fff} .sigbox img{display:block;max-width:100%;height:auto}
  .scan img{display:block;max-width:100%;max-height:520px;border:1px solid #d8dbd8;border-radius:4px}
  .note{font-size:11px;color:#888;margin-top:6px}
  .stamp{border:1.5px solid #123c30;color:#123c30;font-size:9px;letter-spacing:1.5px;font-weight:700;padding:6px 10px;border-radius:3px;transform:rotate(-3deg)}
  code{background:#eee;padding:1px 5px;border-radius:3px}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}.scan{break-inside:avoid}}
</style></head><body>
<?php if ($opt['print_button'] ?? true): ?><button class="printbtn" onclick="window.print()">Print / Save PDF</button><?php endif; ?>
<div class="head">
  <div><div class="brand">TRIBAL SAND</div><div class="subh">Kilifi · Kenya Coast</div></div>
  <div style="text-align:right;font-size:11px;opacity:.85;line-height:1.5"><?= e($m['title']) ?><br><strong style="opacity:1">Signed Consent Record</strong></div>
</div>
<div class="bd">
  <table>
    <?php if ($m['reference'] !== ''): ?><tr><td class="k">Record reference</td><td><strong><?= e($m['reference']) ?></strong></td></tr><?php endif; ?>
    <?php if ($m['booking'] !== ''): ?><tr><td class="k">Booking reference</td><td><strong><?= e($m['booking']) ?></strong></td></tr><?php endif; ?>
    <?php if ($m['stay'] !== ''): ?><tr><td class="k">Stay</td><td><strong><?= e($m['stay']) ?></strong></td></tr><?php endif; ?>
    <?php if ($m['dates'] !== ''): ?><tr><td class="k">Dates</td><td><strong><?= e($m['dates']) ?></strong></td></tr><?php endif; ?>
  </table>
  <div class="sec">1 · Signatory</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e($m['name'] !== '' ? $m['name'] : '—') ?></strong></td></tr>
    <tr><td class="k">Role</td><td><?= e($m['role']) ?></td></tr>
    <tr><td class="k">Nationality</td><td><?= e($m['nationality'] !== '' ? $m['nationality'] : '—') ?></td></tr>
    <tr><td class="k">Document</td><td><?= e($m['doc_label']) ?></td></tr>
    <tr><td class="k">Document number</td><td><strong><?= e($m['doc_number'] !== '' ? $m['doc_number'] : '—') ?></strong></td></tr>
    <tr><td class="k">Expiry</td><td><?= e($d($m['expiry'])) ?></td></tr>
  </table>
  <div class="sec">2 · Terms agreed</div>
  <div class="terms"><?= e($m['terms']) ?></div>
  <div style="font-size:10.5px;color:#888;margin-top:8px">Waiver version <code><?= e($m['version']) ?></code> — the exact terms shown at signing.</div>
  <div class="sec">3 · Signature</div>
  <div style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap">
    <div class="sigbox"><img src="<?= e($m['signature']) ?>" alt="Signature"></div>
    <div style="font-size:12px;line-height:1.7"><div style="color:#666">Signed by</div><div style="font-weight:600"><?= e($m['signed_name']) ?></div>
      <div style="color:#666;margin-top:6px">&#9745; Confirmed &ldquo;I have read and agree&rdquo;</div></div>
  </div>
  <div class="sec">4 · Evidence of signing</div>
  <table>
    <?php if ($m['reference'] !== ''): ?><tr><td class="k">Reference ID</td><td><strong><?= e($m['reference']) ?></strong></td></tr><?php endif; ?>
    <tr><td class="k">Date &amp; time</td><td><strong><?= $m['signed_at'] !== '' ? e(date('j M Y, H:i:s', strtotime($m['signed_at']))) : '—' ?></strong></td></tr>
    <tr><td class="k">IP address</td><td><?= e($m['ip'] !== '' ? $m['ip'] : '—') ?></td></tr>
    <tr><td class="k">Device</td><td><?= e($m['device'] !== '' ? $m['device'] : '—') ?></td></tr>
    <tr><td class="k">Method</td><td><?= e($m['method']) ?></td></tr>
  </table>
  <?php if ($mode !== 'none'): ?>
  <div class="sec">5 · Document scan</div>
  <div class="scan">
    <?php if ($m['scan_removed_at'] !== '' && $m['scan_key'] === ''): ?>
      <p style="font-size:12px">ID photo removed on <?= e(date('j M Y', strtotime($m['scan_removed_at']))) ?>.</p>
    <?php elseif ($m['scan_key'] === ''): ?>
      <p style="font-size:12px">No scan on file.</p>
    <?php elseif ($mode === 'embed'): ?>
      <?php $b = (string)($opt['scan_bytes'] ?? ''); $mime = waiver_scan_mime($b); ?>
      <?php if ($b === ''): ?><p style="font-size:12px">The scan file could not be read from storage.</p>
      <?php elseif (str_starts_with($mime, 'image/')): ?><img src="data:<?= e($mime) ?>;base64,<?= base64_encode($b) ?>" alt="Document scan">
      <?php else: ?><p style="font-size:12px">PDF scan — see the file beside this record: <strong><?= e((string)($opt['scan_file'] ?? '')) ?></strong></p><?php endif; ?>
    <?php else: ?>
      <?php if (preg_match('/\.pdf$/i', $m['scan_key'])): ?><p style="font-size:12px"><a href="<?= e((string)($opt['scan_url'] ?? '')) ?>" target="_blank" rel="noopener">Open PDF scan</a></p>
      <?php else: ?><img src="<?= e((string)($opt['scan_url'] ?? '')) ?>" alt="Document scan"><?php endif; ?>
    <?php endif; ?>
    <?php if ($m['scan_key'] !== '' && !$m['scan_frozen']): ?><div class="note">Scan on file now — this record was signed before scans were frozen at signing.</div><?php endif; ?>
  </div>
  <?php endif; ?>
  <div style="margin-top:26px;padding-top:12px;border-top:1px solid #e0e2e0;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:10px;color:#999;line-height:1.5">Electronic record generated by the Tribal Sand guest system<?= $m['reference'] !== '' ? ' · ' . e($m['reference']) : '' ?></div>
    <div class="stamp">ELECTRONICALLY&nbsp;SIGNED</div>
  </div>
</div>
</body></html>
<?php return (string) ob_get_clean();
}
```

(`visitor_scan_key()` lives in `includes/visitor-store.php`; `waiver-record.php` requires it with `require_once __DIR__ . '/visitor-store.php';` at the top so the model works standalone.)

`record.php`: keep everything up to and including the `checkin_log_signing_step('record_viewed', …)` call; replace the snapshot/mask variables and the whole HTML with:
```php
require_once __DIR__ . '/includes/waiver-record.php';
$m = waiver_record_model_guest($hold, $guest, $reference);
$opt = ['scan' => 'none'];
if ($viewer === 'admin') $opt = ['scan' => 'url', 'scan_url' => '/admin/checkin-file.php?hold=' . $holdId . '&guest=' . $guestId . '&signed=1'];
echo waiver_record_html($m, $opt);
```
`includes/visitor-record.php`: keep auth + `visitor_log_step`; replace the rest with:
```php
require_once __DIR__ . '/waiver-record.php';
$m = waiver_record_model_visitor($v);
echo waiver_record_html($m, $viewer === 'admin'
    ? ['scan' => 'url', 'scan_url' => '/admin/visitor-file.php?id=' . (int)$v['id'] . '&signed=1']
    : ['scan' => 'none']);
```
`admin/checkin-file.php` passport branch: when `($_GET['signed'] ?? '') === '1'`, select `*` and use `$key = checkin_guest_scan_key($row)['key'];` instead of `passport_file_key`. `admin/visitor-file.php`: when `signed=1`, `$key = visitor_scan_key($v)['key'];`.

- [ ] **Step 4: Run — expect PASS**; also `php tests/checkin_consent.php`, `php tests/visitor_registration_logic.php`.
- [ ] **Step 5: Browser check** — open a signed local record as the guest link (no scan, full number) and as owner (scan shown).
- [ ] **Step 6: Commit** — `git commit -m "feat(waivers): one record renderer — full document details and the signing scan"`

---

### Task 3: The archive list + Team → Signed waivers page

**Files:**
- Create: `includes/waiver-archive.php`, `admin/waivers.php`
- Modify: `includes/admin-nav.php` (Team item + `ADMIN_NAV_SEARCH_WORDS`), `includes/access.php` (`ACCESS_OWNER_ONLY` += `'waivers.php'`)
- Test: `tests/waiver_archive_logic.php` (append)

**Interfaces:**
- Consumes: Task 1 guards, Task 2 nothing.
- Produces:
  - `waiver_month_bounds(string $ym): ?array` → `['2026-10-01', '2026-11-01']` or null.
  - `waiver_archive_rows(string $ym, ?int $venueId, string $kind, string $q = ''): array` — `$kind` ∈ `guests|visitors|both`. Row keys: `kind`, `signed_at`, `name`, `venue_id` (?int), `venue_name` (string, '' = none), `where` (guest: `TS-175 · Ocean Suite`; visitor: place name or `No visit yet`), `doc_label`, `doc_number`, `nationality`, `expiry`, `reference`, `version`, `scan_key`, `scan_frozen`, `hold_id`, `guest_id`, `visitor_id`, `removed_at`. Sorted `signed_at DESC`.
  - `waiver_archive_filter_rows(array $rows, ?int $venueId, string $q): array` (pure; used by the query wrapper and tests).

- [ ] **Step 1: Failing tests** (append)

```php
// ── Task 3: the list ──
require_once __DIR__ . '/../includes/waiver-archive.php';
check('month bounds', waiver_month_bounds('2026-10') === ['2026-10-01', '2026-11-01'] && waiver_month_bounds('2026-12') === ['2026-12-01', '2027-01-01']);
check('month bounds: junk refused', waiver_month_bounds('2026-13') === null && waiver_month_bounds('x') === null);
$rows = [['name' => 'Anne Smith', 'venue_id' => 3, 'reference' => 'R1', 'doc_number' => 'X1'], ['name' => 'Bob Jones', 'venue_id' => 4, 'reference' => 'R2', 'doc_number' => 'Y2']];
check('filter: property', count(waiver_archive_filter_rows($rows, 3, '')) === 1);
check('filter: name search, any case', array_column(waiver_archive_filter_rows($rows, null, 'jones'), 'name') === ['Bob Jones']);
check('filter: search by reference or number', count(waiver_archive_filter_rows($rows, null, 'y2')) === 1 && count(waiver_archive_filter_rows($rows, null, 'r1')) === 1);
try {
    $db = db();
    if (!checkin_signature_supported()) { echo "SKIP  list DB checks\n"; }
    else {
        $db->beginTransaction();
        $hid = (int)db_query('SELECT id FROM holds ORDER BY id LIMIT 1')->fetchColumn();
        // 31 Oct 23:30 Nairobi = October; 1 Nov 00:10 Nairobi = November
        db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, waiver_signed_at, waiver_signed_name, waiver_signature, waiver_name_snapshot)
                  VALUES (:h, FALSE, 'ZZ Oct', '2099-10-31 23:30:00+03', 'ZZ Oct', 'data:image/png;base64,x', 'ZZ Oct'),
                         (:h, FALSE, 'ZZ Nov', '2099-11-01 00:10:00+03', 'ZZ Nov', 'data:image/png;base64,x', 'ZZ Nov')", [':h' => $hid]);
        $oct = array_column(waiver_archive_rows('2099-10', null, 'guests', 'ZZ'), 'name');
        check('Nairobi month edge: 23:30 on the 31st is October', $oct === ['ZZ Oct']);
        check('Nairobi month edge: 00:10 on the 1st is November', array_column(waiver_archive_rows('2099-11', null, 'guests', 'ZZ'), 'name') === ['ZZ Nov']);
        $r = waiver_archive_rows('2099-10', null, 'guests', 'ZZ Oct')[0] ?? [];
        check('row: guest carries its booking and property', str_starts_with((string)($r['where'] ?? ''), 'TS-' . $hid) && array_key_exists('venue_name', $r));
        $db->rollBack();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  list DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement `includes/waiver-archive.php` (list part)**

```php
<?php
/**
 * Signed waivers archive (owner): guest check-in + visitor waivers by month,
 * property and kind, and the monthly ZIP. Reads only — the records themselves
 * come from includes/waiver-record.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/visitor-store.php';
require_once __DIR__ . '/waiver-record.php';

function waiver_month_bounds(string $ym): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12) return null;
    $from = sprintf('%04d-%02d-01', (int)$mm[1], (int)$mm[2]);
    return [$from, date('Y-m-d', strtotime($from . ' +1 month'))];
}

function waiver_archive_filter_rows(array $rows, ?int $venueId, string $q): array {
    $q = mb_strtolower(trim($q));
    return array_values(array_filter($rows, function ($r) use ($venueId, $q) {
        if ($venueId !== null && (int)($r['venue_id'] ?? 0) !== $venueId) return false;
        if ($q === '') return true;
        $hay = mb_strtolower(($r['name'] ?? '') . ' ' . ($r['reference'] ?? '') . ' ' . ($r['doc_number'] ?? ''));
        return str_contains($hay, $q);
    }));
}

function waiver_archive_rows(string $ym, ?int $venueId, string $kind, string $q = ''): array {
    $b = waiver_month_bounds($ym);
    if (!$b || !checkin_signature_supported()) return [];
    $out = [];
    if ($kind !== 'visitors') {
        $snap = checkin_identity_snapshot_supported();
        $scan = waiver_scan_snapshot_supported() ? 'g.waiver_scan_snapshot' : 'NULL';
        $idt  = checkin_id_type_supported() ? 'g.waiver_id_type_snapshot' : 'NULL';
        $rows = db_query("SELECT g.id AS guest_id, g.hold_id, g.waiver_signed_at, g.waiver_reference, g.waiver_version, g.passport_file_key,
                                 $scan AS waiver_scan_snapshot, $idt AS waiver_id_type_snapshot,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_name_snapshot,''), g.passport_name)" : 'g.passport_name') . " AS name,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_passport_snapshot,''), g.passport_number)" : 'g.passport_number') . " AS doc_number,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_nationality_snapshot,''), g.nationality)" : 'g.nationality') . " AS nationality,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_passport_expiry_snapshot::text,''), g.passport_expiry::text)" : 'g.passport_expiry::text') . " AS expiry,
                                 r.venue_id, v.name AS venue_name, r.name AS room_name
                            FROM checkin_guests g
                            JOIN holds h  ON h.id = g.hold_id
                            JOIN units u  ON u.id = h.unit_id
                            JOIN rooms r  ON r.id = " . hold_room_id_sql('h', 'u') . "
                            LEFT JOIN venues v ON v.id = r.venue_id
                           WHERE g.waiver_signed_at >= :f AND g.waiver_signed_at < :t", [':f' => $b[0], ':t' => $b[1]])->fetchAll();
        foreach ($rows as $r) {
            $s = checkin_guest_scan_key($r);
            $out[] = ['kind' => 'guest', 'signed_at' => (string)$r['waiver_signed_at'], 'name' => (string)$r['name'],
                'venue_id' => $r['venue_id'] !== null ? (int)$r['venue_id'] : null, 'venue_name' => (string)($r['venue_name'] ?? ''),
                'where' => 'TS-' . (int)$r['hold_id'] . ($r['room_name'] ? ' · ' . $r['room_name'] : ''),
                'doc_label' => checkin_id_type_label(trim((string)$r['waiver_id_type_snapshot']) ?: 'passport'),
                'doc_number' => (string)$r['doc_number'], 'nationality' => (string)$r['nationality'], 'expiry' => substr((string)$r['expiry'], 0, 10),
                'reference' => (string)$r['waiver_reference'], 'version' => (string)$r['waiver_version'],
                'scan_key' => $s['key'], 'scan_frozen' => $s['frozen'],
                'hold_id' => (int)$r['hold_id'], 'guest_id' => (int)$r['guest_id'], 'visitor_id' => 0, 'removed_at' => ''];
        }
    }
    if ($kind !== 'guests' && visitor_reg_supported()) {
        $ps = visitor_photo_snapshot_supported() ? 'rv.waiver_photo_snapshot' : 'NULL';
        $rows = db_query("SELECT rv.*, $ps AS waiver_photo_snapshot, fv.venue_id, fv.place_name, ve.name AS venue_name
                            FROM registered_visitors rv
                            LEFT JOIN LATERAL (SELECT p.venue_id, p.name AS place_name FROM visitor_visits vv JOIN visitor_places p ON p.id = vv.place_id
                                                WHERE vv.visitor_id = rv.id AND vv.visit_date >= rv.waiver_signed_at::date
                                                ORDER BY vv.visit_date, vv.id LIMIT 1) fv ON TRUE
                            LEFT JOIN venues ve ON ve.id = fv.venue_id
                           WHERE rv.waiver_signed_at >= :f AND rv.waiver_signed_at < :t AND rv.parent_id IS NULL",
                         [':f' => $b[0], ':t' => $b[1]])->fetchAll();
        foreach ($rows as $r) {
            $s = visitor_scan_key($r);
            $out[] = ['kind' => 'visitor', 'signed_at' => (string)$r['waiver_signed_at'], 'name' => (string)$r['waiver_name_snapshot'],
                'venue_id' => $r['venue_id'] !== null ? (int)$r['venue_id'] : null, 'venue_name' => (string)($r['venue_name'] ?? ''),
                'where' => $r['place_name'] ? (string)$r['place_name'] : 'No visit yet',
                'doc_label' => checkin_id_type_label((string)($r['waiver_id_type_snapshot'] ?? 'passport')),
                'doc_number' => (string)$r['waiver_id_number_snapshot'], 'nationality' => (string)$r['waiver_nationality_snapshot'],
                'expiry' => substr((string)$r['waiver_id_expiry_snapshot'], 0, 10),
                'reference' => (string)$r['waiver_reference'], 'version' => (string)$r['waiver_version'],
                'scan_key' => $s['key'], 'scan_frozen' => $s['frozen'],
                'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => (int)$r['id'], 'removed_at' => (string)($r['removed_at'] ?? '')];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['signed_at'], $a['signed_at']));
    return waiver_archive_filter_rows($out, $venueId, $q);
}
```

(The DB session is Africa/Nairobi, so `'2026-10-01'` compared with a timestamptz is Nairobi midnight and `::date` is the Nairobi date — matching the Global Constraint.)

- [ ] **Step 4: Page `admin/waivers.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/waiver-archive.php';
require_owner();

$ym    = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : date('Y-m');
$venue = (int)($_GET['venue'] ?? 0) ?: null;
$kind  = in_array($_GET['kind'] ?? '', ['guests', 'visitors', 'both'], true) ? (string)$_GET['kind'] : 'both';
$q     = trim((string)($_GET['q'] ?? ''));
$rows  = waiver_archive_rows($ym, $venue, $kind, $q);

if (($_GET['download'] ?? '') === '1') { waiver_archive_download($rows, $ym, $venue, $kind); exit; }   // Task 4

$venues = db_query("SELECT id, name FROM venues ORDER BY name")->fetchAll();
$pageTitle = 'Signed waivers'; $activeMenu = 'waivers';
require __DIR__ . '/_layout.php';
?>
<div class="page-head"><h1>Signed waivers</h1></div>
<form method="get" class="dt-toolbar" data-help="waivers-filters">
  <input type="month" name="month" value="<?= e($ym) ?>" class="inp">
  <select name="venue" class="inp"><option value="">All properties</option>
    <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $venue === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="kind" class="inp">
    <?php foreach (['both' => 'Guests and visitors', 'guests' => 'Guests', 'visitors' => 'Visitors'] as $k => $l): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, reference or number" class="inp">
  <button class="btn">Show</button>
  <?php if (class_exists('ZipArchive')): ?>
    <a class="btn btn--primary" href="?<?= e(http_build_query(['month' => $ym, 'venue' => $venue, 'kind' => $kind, 'q' => $q, 'download' => 1])) ?>" data-no-shell>Download month (ZIP)</a>
  <?php else: ?><span class="muted">ZIP download needs the PHP zip extension on the server.</span><?php endif; ?>
</form>
<div class="card"><table class="table" id="waiversTbl" data-paginate="50">
  <thead><tr><th>Signed</th><th>Name</th><th>Type</th><th>Property</th><th>Booking / place</th><th>Document</th><th>Reference</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $view = $r['kind'] === 'guest' ? '/record.php?hold=' . $r['hold_id'] . '&guest=' . $r['guest_id'] : '/record.php?vid=' . $r['visitor_id'];
      $scan = $r['kind'] === 'guest' ? '/admin/checkin-file.php?hold=' . $r['hold_id'] . '&guest=' . $r['guest_id'] . '&signed=1' : '/admin/visitor-file.php?id=' . $r['visitor_id'] . '&signed=1'; ?>
    <tr>
      <td><?= e(date('j M Y, H:i', strtotime($r['signed_at']))) ?></td>
      <td><?= e($r['name']) ?></td>
      <td><?= $r['kind'] === 'guest' ? 'Guest' : 'Visitor' ?></td>
      <td><?= e($r['venue_name'] !== '' ? $r['venue_name'] : '—') ?></td>
      <td><?= e($r['where']) ?></td>
      <td><?= e($r['doc_label'] . ' ' . $r['doc_number']) ?></td>
      <td><code><?= e($r['reference']) ?></code></td>
      <td style="white-space:nowrap;text-align:right">
        <a href="<?= e($view) ?>" target="_blank" rel="noopener">View</a>
        <?php if ($r['scan_key'] !== ''): ?> · <a href="<?= e($scan) ?>" target="_blank" rel="noopener">Scan</a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted">No signed waivers for this month.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php require __DIR__ . '/_layout_end.php';
```

(Use the page's existing house controls if `admin/attendance.php` uses `.eselect` instead of `<select>` — copy its filter-toolbar markup exactly; match `pageTitle`/`activeMenu` variable names from a neighbour page such as `admin/staff-rooms.php`.)

`includes/admin-nav.php` — Team items, after Staff rooms:
```php
            $item('Signed waivers', 'waivers', [$tab('Signed waivers', 'waivers.php', $on('owner'))]),
```
`ADMIN_NAV_SEARCH_WORDS`: `'waivers.php' => 'waiver consent signed records passport scan id accounting archive download zip legal',`
`includes/access.php`: append `'waivers.php'` to `ACCESS_OWNER_ONLY`.

- [ ] **Step 5: Run** `php tests/waiver_archive_logic.php`, `php tests/admin_nav_logic.php`, `php tests/access_logic.php`, `php tests/help_logic.php` — all PASS (if `help_logic` demands a guide for new pages, add a `signed-waivers` guide to `help_guides()` with a step targeting `[data-help="waivers-filters"]`).
- [ ] **Step 6: Commit** — `git commit -m "feat(waivers): Team → Signed waivers — by month, property and kind (owner)"`

---

### Task 4: Download month (ZIP)

**Files:**
- Modify: `includes/waiver-archive.php` (ZIP part), `Dockerfile` (ext-zip)
- Test: `tests/waiver_archive_logic.php` (append)

**Interfaces:**
- Consumes: `waiver_archive_rows()`, `waiver_record_model_guest()`, `waiver_record_model_visitor()`, `waiver_record_html()`, `storage_read_private()`.
- Produces:
  - `waiver_safe_name(string $s): string` — letters/digits/space/dash/dot only, accents folded, max 80 chars.
  - `waiver_file_base(array $row): string` — `YYYY-MM-DD <Name> <reference>` (safe).
  - `waiver_zip_folder(array $row): string` — `<Property>` / `No property` for guests; `Visitors/<Property>` / `Visitors/No visit yet`.
  - `waiver_zip_build(array $rows, string $path, ?callable $read = null): array{records:int, missing:string[]}` — `$read` defaults to `storage_read_private` (tests inject a stub).
  - `waiver_archive_download(array $rows, string $ym, ?int $venueId, string $kind): void` — streams the ZIP, audit-logs.

- [ ] **Step 1: Failing tests** (append)

```php
// ── Task 4: the ZIP ──
check('file base is safe', waiver_file_base(['signed_at' => '2026-10-15 10:00:00+03', 'name' => 'José / "Q" Ñandú', 'reference' => 'TSR-1-2-ab']) === '2026-10-15 Jose Q Nandu TSR-1-2-ab');
check('folders', waiver_zip_folder(['kind' => 'guest', 'venue_name' => 'Maya Kobe']) === 'Maya Kobe'
    && waiver_zip_folder(['kind' => 'visitor', 'venue_name' => '']) === 'Visitors/No visit yet');
if (class_exists('ZipArchive')) {
    $rowsZ = [
        ['kind' => 'guest', 'signed_at' => '2026-10-15 10:00:00+03', 'name' => 'Anne Smith', 'venue_name' => 'Maya Kobe', 'reference' => 'R1',
         'where' => 'TS-175', 'doc_label' => 'Passport', 'doc_number' => 'X1', 'nationality' => 'UK', 'expiry' => '2031-05-01', 'version' => 'v',
         'scan_key' => 'a.jpg', 'scan_frozen' => true, 'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => 0, 'removed_at' => '',
         '_model' => ['scan_key' => 'a.jpg'] + $m],
        ['kind' => 'guest', 'signed_at' => '2026-10-15 10:00:00+03', 'name' => 'Anne Smith', 'venue_name' => 'Maya Kobe', 'reference' => 'R1',
         'where' => 'TS-176', 'doc_label' => 'Passport', 'doc_number' => 'X2', 'nationality' => 'UK', 'expiry' => '', 'version' => 'v',
         'scan_key' => 'gone.jpg', 'scan_frozen' => true, 'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => 0, 'removed_at' => '',
         '_model' => ['scan_key' => 'gone.jpg'] + $m],
    ];
    $zipPath = tempnam(sys_get_temp_dir(), 'wz');
    $res = waiver_zip_build($rowsZ, $zipPath, fn($k) => $k === 'a.jpg' ? "\xFF\xD8\xFFJPEG" : null);
    $z = new ZipArchive(); $z->open($zipPath);
    $names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    check('zip: record + scan in the property folder', in_array('Maya Kobe/2026-10-15 Anne Smith R1.html', $names, true) && in_array('Maya Kobe/2026-10-15 Anne Smith R1.jpg', $names, true));
    check('file base is safe + unique (same name twice)', in_array('Maya Kobe/2026-10-15 Anne Smith R1 (2).html', $names, true));
    check('missing scan file: record still there, listed as missing', in_array('Maya Kobe/2026-10-15 Anne Smith R1 (2).html', $names, true) && $res['missing'] === ['gone.jpg']);
    $csv = (string)$z->getFromName('index.csv');
    check('index.csv: header + 2 rows', substr_count(trim($csv), "\n") === 2 && str_contains($csv, 'Document number') && str_contains($csv, 'missing'));
    check('zip: embedded image in the record', str_contains((string)$z->getFromName('Maya Kobe/2026-10-15 Anne Smith R1.html'), 'data:image/jpeg;base64,'));
    $z->close(); @unlink($zipPath);
} else { echo "SKIP  ZIP checks (no ZipArchive)\n"; }
```
(`_model` lets tests skip the DB; in production `waiver_zip_build()` builds the model from the ids when `_model` is absent.)

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement (append to `includes/waiver-archive.php`)**

```php
function waiver_safe_name(string $s): string {
    $s = (string)(function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s);
    $s = preg_replace('/[^A-Za-z0-9 .\-]+/', ' ', $s);
    return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$s)), 0, 80);
}

function waiver_file_base(array $row): string {
    return waiver_safe_name(date('Y-m-d', strtotime((string)$row['signed_at'])) . ' ' . $row['name'] . ' ' . $row['reference']);
}

function waiver_zip_folder(array $row): string {
    $p = waiver_safe_name((string)($row['venue_name'] ?? ''));
    if (($row['kind'] ?? '') === 'visitor') return 'Visitors/' . ($p !== '' ? $p : 'No visit yet');
    return $p !== '' ? $p : 'No property';
}

/** The record model for an archive row (from the DB by id; tests pass `_model`). */
function waiver_archive_model(array $row): ?array {
    if (isset($row['_model'])) return $row['_model'];
    if ($row['kind'] === 'guest') {
        $hold = fetch_hold_for_guest((int)$row['hold_id']);
        $g = db_query('SELECT * FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $row['guest_id'], ':h' => $row['hold_id']])->fetch();
        return ($hold && $g) ? waiver_record_model_guest($hold, $g, (string)$row['reference']) : null;
    }
    $v = visitor_find((int)$row['visitor_id'], true);
    return $v ? waiver_record_model_visitor($v) : null;
}

function waiver_zip_build(array $rows, string $path, ?callable $read = null): array {
    $read = $read ?? 'storage_read_private';
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the ZIP file.');
    $used = []; $missing = []; $count = 0;
    $csv = fopen('php://temp', 'w+');
    fputcsv($csv, ['Signed', 'Name', 'Type', 'Property', 'Booking / place', 'Document', 'Document number', 'Nationality', 'Expiry', 'Reference', 'Waiver version', 'Scan file']);
    foreach ($rows as $row) {
        $m = waiver_archive_model($row);
        if (!$m) continue;
        $dir = waiver_zip_folder($row); $base = waiver_file_base($row);
        $n = 1; $try = $base;
        while (isset($used[$dir . '/' . $try])) $try = $base . ' (' . (++$n) . ')';
        $used[$dir . '/' . $try] = true; $base = $try;
        $key = (string)$m['scan_key'];
        $bytes = $key !== '' ? $read($key) : null;
        $scanFile = '';
        if ($key !== '' && $bytes === null) { $missing[] = $key; $scanFile = 'missing'; }
        if ($bytes !== null) {
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][waiver_scan_mime($bytes)] ?? (pathinfo($key, PATHINFO_EXTENSION) ?: 'bin');
            $scanFile = $base . '.' . $ext;
            $z->addFromString($dir . '/' . $scanFile, $bytes);
        }
        $z->addFromString($dir . '/' . $base . '.html', waiver_record_html($m, ['scan' => 'embed', 'scan_bytes' => $bytes ?? '', 'scan_file' => $scanFile, 'print_button' => true]));
        fputcsv($csv, [date('Y-m-d H:i', strtotime((string)$row['signed_at'])), $row['name'], $row['kind'] === 'guest' ? 'Guest' : 'Visitor',
            $row['venue_name'], $row['where'], $row['doc_label'], $row['doc_number'], $row['nationality'], $row['expiry'],
            $row['reference'], $row['version'], $scanFile]);
        $count++;
    }
    rewind($csv);
    $z->addFromString('index.csv', "\xEF\xBB\xBF" . stream_get_contents($csv));   // BOM: Excel reads UTF-8
    $z->close();
    return ['records' => $count, 'missing' => $missing];
}

function waiver_archive_download(array $rows, string $ym, ?int $venueId, string $kind): void {
    if (!class_exists('ZipArchive')) { http_response_code(501); exit('ZIP support is not installed on the server.'); }
    @set_time_limit(300);
    $path = tempnam(sys_get_temp_dir(), 'waivers');
    $res = waiver_zip_build($rows, $path);
    $venueName = $venueId ? (string)db_query('SELECT name FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn() : '';
    $file = 'waivers-' . $ym . ($venueName !== '' ? '-' . waiver_safe_name($venueName) : '') . '.zip';
    audit_log('waivers.download', 'waivers', 0, "month $ym · " . ($venueName ?: 'all properties') . " · $kind · {$res['records']} records" . ($res['missing'] ? ' · ' . count($res['missing']) . ' scans missing' : ''));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    @unlink($path);
}
```

`Dockerfile` line 5-7 → add `libzip-dev` to `apt-get install` and `zip` to `docker-php-ext-install pdo pdo_pgsql gd zip`.

- [ ] **Step 4: Run — expect PASS** (`php tests/waiver_archive_logic.php`).
- [ ] **Step 5: Browser check** — owner page lists the local test records; Download month returns a ZIP that opens (`unzip -l`) with folders, HTML, scans and `index.csv`; a non-owner session gets bounced from `admin/waivers.php` and `?download=1`.
- [ ] **Step 6: Commit** — `git commit -m "feat(waivers): download a month as a ZIP — records, original scans, index.csv"`

---

### Task 5: Docs + full test run

**Files:**
- Modify: `CLAUDE.md` (new section "Signed waivers archive", File Map rows, update the "Signed-consent record" bullet: full number + scan section + frozen scan)

- [ ] **Step 1:** Add the CLAUDE.md section:

```markdown
### Signed waivers archive — owner, by month, ZIP download (Oct 2026)
**Team → Signed waivers** (`admin/waivers.php`, `require_owner()`, in `ACCESS_OWNER_ONLY`): every signed guest check-in waiver and visitor waiver by month (Nairobi), property, Guests | Visitors, search; **Download month (ZIP)** = `<Property>/` and `Visitors/<Property>/` with `<date> <name> <reference>.html` (the record, scan embedded) + the original scan + `index.csv`; each download audited (`waivers.download`). Logic `includes/waiver-archive.php`; ONE record renderer `includes/waiver-record.php` (`record.php` and `includes/visitor-record.php` are auth wrappers). Spec `docs/superpowers/specs/2026-10-15-signed-waivers-archive-design.md`. Test: `php tests/waiver_archive_logic.php`.
- **Records show the FULL document details** (type, number, nationality, expiry — from the snapshots). The scan shows on the owner/staff view and in the ZIP, **never on guest links**.
- **The scan is frozen at signing** (`checkin_guests.waiver_scan_snapshot`, `registered_visitors.waiver_photo_snapshot`, migration `add_waiver_scan_snapshot.sql`): a re-upload never deletes a frozen file (`checkin_scan_delete_unless_frozen()`), voiding a signature clears it, removing a guest deletes both keys (`checkin_guest_file_keys()`), removing a visitor deletes it ("ID photo removed on …"). Older records show the current scan labelled "Scan on file now". `admin/checkin-file.php` / `admin/visitor-file.php` take `&signed=1` for the frozen scan.
- ZIP needs ext-zip (Dockerfile); without it the button is hidden.
```

- [ ] **Step 2:** Run every related test: `for t in waiver_archive_logic checkin_logic checkin_consent checkin_id_type_logic coguest_portal_logic eta_letter_logic visitor_registration_logic admin_nav_logic access_logic help_logic migration_order_logic; do php tests/$t.php | tail -1; done` — all ALL PASS.
- [ ] **Step 3: Commit** — `git commit -m "docs: signed waivers archive"`
- [ ] **Step 4:** Remind the owner: after deploy run `add_waiver_scan_snapshot.sql` via `/admin/migrate.php`.
