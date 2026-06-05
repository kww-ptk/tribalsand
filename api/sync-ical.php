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

header('Content-Type: application/json');

$env    = parse_env();
$secret = trim($_GET['secret'] ?? '');
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

        // Skip if an identical blocked entry already exists (idempotent re-import)
        $exists = db_query(
            "SELECT id FROM availability_blocks
             WHERE unit_id=:uid AND date_from=:df AND date_to=:dt AND block_type='blocked'",
            [':uid' => $feed['unit_id'], ':df' => $from, ':dt' => $to]
        )->fetchColumn();

        if ($exists) { $skipped++; continue; }

        // Detect conflict with existing hold or booked block
        $conflicting_hold = db_query(
            "SELECT h.id FROM holds h
             WHERE h.unit_id = :uid
               AND h.status IN ('pending','confirmed')
               AND h.check_in  < :dto
               AND h.check_out > :dfrom",
            [':uid' => $feed['unit_id'], ':dto' => $to, ':dfrom' => $from]
        )->fetch();

        if ($conflicting_hold) {
            // Record conflict — do NOT insert the OTA block yet
            $conflict_already = db_query(
                "SELECT id FROM channel_conflicts
                 WHERE unit_id=:uid AND date_from=:df AND date_to=:dt AND hold_id=:hid AND status='pending'",
                [':uid' => $feed['unit_id'], ':df' => $from, ':dt' => $to, ':hid' => $conflicting_hold['id']]
            )->fetchColumn();

            if (!$conflict_already) {
                db_query(
                    "INSERT INTO channel_conflicts (ical_feed_id, unit_id, date_from, date_to, hold_id, ota_summary)
                     VALUES (:fid, :uid, :df, :dt, :hid, :summary)",
                    [':fid'     => $feed['id'],
                     ':uid'     => $feed['unit_id'],
                     ':df'      => $from,
                     ':dt'      => $to,
                     ':hid'     => $conflicting_hold['id'],
                     ':summary' => mb_substr($summary, 0, 500)]
                );
            }
            $skipped++;
            continue;
        }

        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
             VALUES (:uid, :df, :dt, 'blocked', :notes)",
            [':uid'   => $feed['unit_id'],
             ':df'    => $from,
             ':dt'    => $to,
             ':notes' => 'iCal: ' . mb_substr($summary, 0, 200)]
        );
        $imported++;
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
