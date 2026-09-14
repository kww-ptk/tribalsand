<?php
/**
 * iCal pull sync — fetches external OTA feeds and imports blocks.
 * Protected by ICAL_SYNC_SECRET env var.
 *
 * Trigger via external cron (e.g. cron-job.org) every 1–6 hours:
 *   GET https://yoursite/api/sync-ical.php?secret=YOUR_SECRET
 *
 * Or from the admin Gantt page "Sync Now" button.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-hold-guard.php';   // staff_hold_block_reason()

/**
 * Import ONE parsed OTA event onto its feed's unit.
 *
 * Returns 'imported' when a block was written, 'skipped' when it was not — the
 * two counters the feed result reports. Extracted from the loop below so the
 * decision can be exercised directly: tests/maya_ilai_inventory.php requires
 * this file with ICAL_SYNC_LIBRARY_ONLY defined and calls this, rather than
 * posting through the endpoint (which would fetch real feeds over the network).
 *
 * $feed needs 'id' and 'unit_id'; $from/$to are half-open (date_to is the
 * checkout morning) and already validated by the caller.
 */
function ical_import_event(array $feed, string $from, string $to, string $summary): string
{
    $unitId = (int) $feed['unit_id'];

    // Skip if an identical blocked entry already exists (idempotent re-import).
    //
    // This runs BEFORE any conflict detection, and that order is load-bearing:
    // it is the only thing standing between the hourly in-container scheduler
    // and the importer raising a conflict against its own previous import. An
    // unchanged feed re-imported an hour later never reaches the checks below.
    $exists = db_query(
        "SELECT id FROM availability_blocks
         WHERE unit_id=:uid AND date_from=:df AND date_to=:dt AND block_type='blocked'",
        [':uid' => $unitId, ':df' => $from, ':dt' => $to]
    )->fetchColumn();

    if ($exists) return 'skipped';

    // Detect conflict with an existing hold.
    $conflicting_hold = db_query(
        "SELECT h.id FROM holds h
         WHERE h.unit_id = :uid
           AND h.status IN ('pending','confirmed')
           AND h.check_in  < :dto
           AND h.check_out > :dfrom",
        [':uid' => $unitId, ':dto' => $to, ':dfrom' => $from]
    )->fetch();

    $holdId = $conflicting_hold ? (int) $conflicting_hold['id'] : null;

    if ($holdId !== null) {
        // Record conflict — do NOT insert the OTA block yet
        ical_record_conflict($feed, $unitId, $from, $to, $holdId, $summary);
        return 'skipped';
    }

    // The hold check above is all this importer has ever done, and it is enough
    // for every unit whose blocks mean "the whole unit is gone" — an OTA block
    // that overlaps one at Zuri is an ordinary, deliberate double-book and must
    // keep importing exactly as it does today.
    //
    // Maya Ilai breaks that. Its eight products slice the same eight villas, and
    // an OTA-imported block is written components NULL = the WHOLE villa. A live
    // component booking IS caught above, because a composite hold is recorded
    // against the villa unit. What is not caught is a BLOCK with no live hold
    // behind it — a converted booking, a block imported from another source —
    // and dropping a whole-villa block on top of one sells that bedroom twice.
    //
    // staff_hold_block_reason() is the same frozen guard the booking forms, the
    // Gantt and the conflict resolver use, and it returns NULL for every unit
    // that is not a Maya Ilai villa, so nothing below this line can change what
    // any other property does.
    //
    // Self-exclusion needs no trick here: the identical-block skip at the top of
    // this function runs FIRST, so a feed re-imported by the hourly scheduler
    // never reaches the guard and can never raise a conflict against the block
    // the importer itself wrote last hour.
    if (staff_hold_block_reason($unitId, $from, $to) !== null) {
        ical_record_conflict($feed, $unitId, $from, $to, null, $summary);
        return 'skipped';
    }

    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
         VALUES (:uid, :df, :dt, 'blocked', :notes)",
        [':uid'   => $unitId,
         ':df'    => $from,
         ':dt'    => $to,
         ':notes' => 'iCal: ' . mb_substr($summary, 0, 200)]
    );
    return 'imported';
}

/**
 * Record a pending channel conflict, unless an identical one is already open.
 *
 * The de-dupe has to match on hold_id the way SQL means it: `hold_id = NULL` is
 * never true, so a conflict with no hold behind it must be looked up with
 * `hold_id IS NULL` or the hourly scheduler would insert a fresh row on every
 * pass.
 */
function ical_record_conflict(
    array $feed, int $unitId, string $from, string $to, ?int $holdId, string $summary
): void {
    $params = [':uid' => $unitId, ':df' => $from, ':dt' => $to];
    if ($holdId !== null) $params[':hid'] = $holdId;

    $already = db_query(
        "SELECT id FROM channel_conflicts
         WHERE unit_id=:uid AND date_from=:df AND date_to=:dt AND status='pending'
           AND " . ($holdId === null ? 'hold_id IS NULL' : 'hold_id = :hid'),
        $params
    )->fetchColumn();

    if ($already) return;

    db_query(
        "INSERT INTO channel_conflicts (ical_feed_id, unit_id, date_from, date_to, hold_id, ota_summary)
         VALUES (:fid, :uid, :df, :dt, :hid, :summary)",
        [':fid'     => $feed['id'],
         ':uid'     => $unitId,
         ':df'      => $from,
         ':dt'      => $to,
         ':hid'     => $holdId,
         ':summary' => mb_substr($summary, 0, 500)]
    );
}

// tests/maya_ilai_inventory.php requires this file for ical_import_event() only.
if (defined('ICAL_SYNC_LIBRARY_ONLY')) return;

header('Content-Type: application/json');

$env    = parse_env();
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $secret = trim(substr($authHeader, 7));
} else {
    $secret = trim($_GET['secret'] ?? '');
}
$expected = trim($env['ICAL_SYNC_SECRET'] ?? '');

if (!$expected || !hash_equals($expected, $secret)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden — set ICAL_SYNC_SECRET in environment.']));
}

$feeds = db_query(
    "SELECT f.*, u.room_id FROM ical_feeds f JOIN units u ON u.id = f.unit_id ORDER BY f.id ASC"
)->fetchAll();

if (empty($feeds)) {
    exit(json_encode(['ok' => true, 'message' => 'No feeds configured.', 'feeds' => []]));
}

$results = [];

foreach ($feeds as $feed) {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
        'user_agent'    => 'SevenIslandsResort/1.0 iCalSync',
    ]]);

    $ics = @file_get_contents($feed['feed_url'], false, $ctx);

    if ($ics === false || trim($ics) === '') {
        $results[] = [
            'id'      => $feed['id'],
            'label'   => $feed['label'],
            'status'  => 'error',
            'message' => 'Could not fetch feed URL.',
        ];
        continue;
    }

    $events   = parse_ical_events($ics);
    $imported = 0;
    $skipped  = 0;

    foreach ($events as $event) {
        $from = ical_to_ymd($event['dtstart'] ?? '');
        $to   = ical_to_ymd($event['dtend']   ?? '');

        if (!$from || !$to || $from >= $to) { $skipped++; continue; }
        if ($to < date('Y-m-d'))             { $skipped++; continue; } // past event

        $summary = trim($event['summary'] ?? $feed['label'] ?? 'OTA block');

        if (ical_import_event($feed, $from, $to, $summary) === 'imported') $imported++;
        else                                                               $skipped++;
    }

    db_query(
        "UPDATE ical_feeds SET last_synced_at = NOW() WHERE id = :id",
        [':id' => $feed['id']]
    );

    $results[] = [
        'id'       => $feed['id'],
        'label'    => $feed['label'],
        'unit_id'  => $feed['unit_id'],
        'status'   => 'ok',
        'imported' => $imported,
        'skipped'  => $skipped,
        'total'    => count($events),
    ];
}

echo json_encode(['ok' => true, 'synced_at' => date('c'), 'feeds' => $results], JSON_PRETTY_PRINT);

// ── Minimal iCal parser ──────────────────────────────────────────

function parse_ical_events(string $ics): array {
    // Unfold long lines (RFC 5545 §3.1)
    $ics = preg_replace("/\r\n[ \t]/", '', $ics);
    $ics = preg_replace("/\n[ \t]/",   '', $ics);

    $events  = [];
    $current = null;

    foreach (preg_split('/\r?\n/', $ics) as $line) {
        $line = rtrim($line);
        if ($line === 'BEGIN:VEVENT') {
            $current = [];
        } elseif ($line === 'END:VEVENT') {
            if ($current !== null) $events[] = $current;
            $current = null;
        } elseif ($current !== null && str_contains($line, ':')) {
            $colon = strpos($line, ':');
            $key   = strtolower(substr($line, 0, $colon));
            $val   = substr($line, $colon + 1);
            // Strip parameters: DTSTART;TZID=Africa/Nairobi → dtstart
            $key = preg_replace('/;.*$/', '', $key);
            $current[$key] = $val;
        }
    }

    return $events;
}

function ical_to_ymd(string $val): string|false {
    $val = preg_replace('/T.*$/', '', trim($val)); // strip time part
    if (strlen($val) === 8 && ctype_digit($val)) {
        return substr($val, 0, 4) . '-' . substr($val, 4, 2) . '-' . substr($val, 6, 2);
    }
    return false;
}
