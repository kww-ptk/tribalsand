<?php
/**
 * Two-way iCal sync with the OTAs — Airbnb, Booking.com, VRBO, Expedia…
 *
 *   IMPORT  ical_sync_all() / ical_sync_feed(): pull each OTA feed (ical_feeds)
 *           into availability_blocks. Run by docker/scheduler.sh every 15 min
 *           through api/sync-ical.php, and on demand from Admin → iCal feeds.
 *   EXPORT  ical_export_blocks(): what api/ical.php publishes per unit for the
 *           OTAs to read — our bookings, 24h holds, closures, other channels'
 *           bookings, and the whole-property buyout rule.
 *
 * With add_ical_sync_tracking.sql applied (ical_tracking_supported()) each
 * imported block remembers its feed + event UID, so a booking cancelled or moved
 * on the OTA is removed / moved here too, and "echoes" — an OTA re-exporting the
 * dates WE sent it — are recognised instead of imported as new bookings. Before
 * the migration the import behaves exactly as it always did (add-only).
 *
 * iCal carries dates only: no prices, no guest details. Rates and reservations
 * with guest data need a channel-manager API — see docs/channel-manager.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff-hold-guard.php';   // staff_hold_block_reason()

/** How long a leftover echo is ignored after the booking it echoed went away.
 *  Airbnb and Booking.com re-read our feed every few hours; inside this window
 *  the echo disappears on its own. Still there after it = a real booking. */
const ICAL_ECHO_GRACE_HOURS = 6;
const ICAL_MAX_BYTES        = 5000000;

function ical_tracking_supported(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        // Catalog lookup, never a failing SELECT: this can run inside a transaction.
        $ok = (bool) db_query("SELECT to_regclass('public.ical_echoes') IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

// ── Pure helpers ────────────────────────────────────────────────────────────

/** Minimal RFC 5545 VEVENT reader: list of [lower-case key => raw value]. */
function parse_ical_events(string $ics): array
{
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

function ical_to_ymd(string $val): string|false
{
    $val = preg_replace('/T.*$/', '', trim($val)); // strip time part
    if (strlen($val) === 8 && ctype_digit($val)) {
        return substr($val, 0, 4) . '-' . substr($val, 4, 2) . '-' . substr($val, 6, 2);
    }
    return false;
}

/** A real calendar, as opposed to an HTML error / login page served with 200. */
function ical_is_calendar(string $body): bool
{
    return stripos($body, 'BEGIN:VCALENDAR') !== false;
}

/**
 * The feed's current and future events, keyed by UID:
 *   [key => ['from'=>Y-m-d, 'to'=>Y-m-d (exclusive), 'summary'=>string]]
 * An event with no UID is keyed on its dates. Past and malformed events are
 * dropped; $skipped counts them (the old endpoint reported them as skipped).
 */
function ical_feed_events(string $ics, string $today, string $fallbackSummary = 'OTA block', int &$skipped = 0): array
{
    $out = [];
    foreach (parse_ical_events($ics) as $ev) {
        $from = ical_to_ymd($ev['dtstart'] ?? '');
        $to   = ical_to_ymd($ev['dtend']   ?? '');
        if (!$from || !$to || $from >= $to) { $skipped++; continue; }
        if ($to < $today)                    { $skipped++; continue; }  // past event
        $uid = trim((string) ($ev['uid'] ?? ''));
        $key = $uid !== '' ? mb_substr($uid, 0, 500) : "nouid:{$from}|{$to}";
        $summary = trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], (string) ($ev['summary'] ?? '')));
        $out[$key] = ['from' => $from, 'to' => $to, 'summary' => $summary !== '' ? $summary : $fallbackSummary];
    }
    return $out;
}

/**
 * Compare a feed's events with the blocks this feed imported earlier.
 * $tracked = list of ['id','ical_uid','date_from','date_to'] — current/future only.
 * Returns ['keep'=>[key], 'move'=>[key => block id], 'remove'=>[block id], 'new'=>[key]].
 * A block whose UID left the feed was cancelled on the OTA; a UID whose dates
 * changed was moved.
 */
function ical_diff_tracked(array $events, array $tracked): array
{
    $plan = ['keep' => [], 'move' => [], 'remove' => [], 'new' => []];
    $byUid = [];
    foreach ($tracked as $b) {
        $uid = (string) ($b['ical_uid'] ?? '');
        if ($uid === '' || !isset($events[$uid]) || isset($byUid[$uid])) {
            $plan['remove'][] = (int) $b['id'];          // gone from the feed (or a duplicate)
            continue;
        }
        $byUid[$uid] = $b;
    }
    foreach ($events as $key => $ev) {
        if (!isset($byUid[$key])) { $plan['new'][] = $key; continue; }
        $b = $byUid[$key];
        if ((string) $b['date_from'] === $ev['from'] && (string) $b['date_to'] === $ev['to']) {
            $plan['keep'][] = $key;
        } else {
            $plan['move'][$key] = (int) $b['id'];
        }
    }
    return $plan;
}

/** Airbnb names a real reservation "Reserved" — never treat that as an echo. */
function ical_summary_is_reservation(string $summary): bool
{
    return (bool) preg_match('/\breserved\b/i', $summary);
}

/** A friendly channel name from a feed link ("" when unknown). */
function ical_label_from_url(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $map  = ['airbnb.' => 'Airbnb', 'booking.com' => 'Booking.com', 'vrbo.' => 'VRBO',
             'homeaway.' => 'VRBO', 'expedia.' => 'Expedia', 'hopper.' => 'Hopper',
             'tripadvisor.' => 'Tripadvisor', 'google.' => 'Google Calendar'];
    foreach ($map as $needle => $label) {
        if (str_contains($host, $needle)) return $label;
    }
    return '';
}

/** Feed links must be https (no http://169.254… metadata endpoints, no plain text). */
function ical_feed_url_ok(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('#^https://#i', $url) === 1
        && (string) parse_url($url, PHP_URL_HOST) !== '';
}

// ── Import ──────────────────────────────────────────────────────────────────

/**
 * Import ONE event onto its feed's unit.
 *
 * Returns 'imported' when a block was written, 'skipped' when it was not.
 * tests/maya_ilai_inventory.php calls this directly (via api/sync-ical.php with
 * ICAL_SYNC_LIBRARY_ONLY) rather than posting through the endpoint.
 *
 * $feed needs 'id' and 'unit_id'; $from/$to are half-open (date_to is the
 * checkout morning) and already validated by the caller. $uid (the event key)
 * is stored on the block when tracking is installed.
 */
function ical_import_event(array $feed, string $from, string $to, string $summary, ?string $uid = null): string
{
    $unitId = (int) $feed['unit_id'];

    // Skip if an identical blocked entry already exists (idempotent re-import).
    //
    // This runs BEFORE any conflict detection, and that order is load-bearing:
    // it is what stops the importer raising a conflict against its own previous
    // import of an unchanged feed.
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

    // Maya Ilai: an OTA block is the WHOLE villa (components NULL); dropping it
    // on a villa whose bedrooms are already sold sells them twice.
    // staff_hold_block_reason() returns NULL for every non-Maya-Ilai unit.
    if (staff_hold_block_reason($unitId, $from, $to) !== null) {
        ical_record_conflict($feed, $unitId, $from, $to, null, $summary);
        return 'skipped';
    }

    $track = $uid !== null && ical_tracking_supported() && !empty($feed['id']);
    db_query(
        $track
            ? "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes, ical_feed_id, ical_uid)
               VALUES (:uid, :df, :dt, 'blocked', :notes, :fid, :iuid)"
            : "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
               VALUES (:uid, :df, :dt, 'blocked', :notes)",
        [':uid'   => $unitId,
         ':df'    => $from,
         ':dt'    => $to,
         ':notes' => 'iCal: ' . mb_substr($summary, 0, 200)]
        + ($track ? [':fid' => (int) $feed['id'], ':iuid' => $uid] : [])
    );
    return 'imported';
}

/**
 * Record a pending channel conflict, unless an identical one is already open.
 * `hold_id = NULL` is never true in SQL, so a conflict with no hold behind it is
 * looked up with `hold_id IS NULL` or every pass would insert a fresh row.
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

/** The units whose blocks also close $unitId (the whole-property buyout rule). */
function ical_linked_unit_ids(int $unitId): array
{
    $room = db_query(
        "SELECT r.* FROM units u JOIN rooms r ON r.id = u.room_id WHERE u.id = :id",
        [':id' => $unitId]
    )->fetch();
    if (!$room) return [];
    return array_values(array_diff(room_conflict_unit_ids($room), [$unitId]));
}

/**
 * Is this event the OTA echoing dates we already hold? True when a block with
 * exactly these dates sits on the unit (or a buyout-linked unit) and did not come
 * from this feed — our export showed it to the OTA, and the OTA sent it back.
 */
function ical_echo_of_existing(array $feed, string $from, string $to): bool
{
    $units = array_merge([(int) $feed['unit_id']], ical_linked_unit_ids((int) $feed['unit_id']));
    $in    = implode(',', array_map('intval', $units));
    return (bool) db_query(
        "SELECT 1 FROM availability_blocks
          WHERE unit_id IN ({$in}) AND date_from = :df AND date_to = :dt
            AND ical_feed_id IS DISTINCT FROM :fid
          LIMIT 1",
        [':df' => $from, ':dt' => $to, ':fid' => (int) $feed['id']]
    )->fetchColumn();
}

/**
 * Bring one NEW (or moved) event in, with tracking: adopt, echo, or import.
 * Returns 'imported' | 'skipped' | 'echo'.
 */
function ical_sync_new_event(array $feed, string $key, array $ev): string
{
    $fid = (int) $feed['id'];
    $uid = (int) $feed['unit_id'];

    // A block "Keep OTA" wrote from this feed's conflict carries the feed but no
    // UID yet — claim it, so a later cancellation removes it.
    $adopt = db_query(
        "SELECT id FROM availability_blocks
          WHERE unit_id = :u AND date_from = :df AND date_to = :dt
            AND ical_feed_id = :fid AND ical_uid IS NULL
          LIMIT 1",
        [':u' => $uid, ':df' => $ev['from'], ':dt' => $ev['to'], ':fid' => $fid]
    )->fetchColumn();
    if ($adopt) {
        db_query("UPDATE availability_blocks SET ical_uid = :k WHERE id = :id", [':k' => $key, ':id' => (int) $adopt]);
        return 'skipped';
    }

    if (!ical_summary_is_reservation($ev['summary']) && ical_echo_of_existing($feed, $ev['from'], $ev['to'])) {
        db_query(
            "INSERT INTO ical_echoes (feed_id, uid, date_from, date_to)
             VALUES (:f, :k, :df, :dt)
             ON CONFLICT (feed_id, uid) DO UPDATE
               SET date_from = EXCLUDED.date_from, date_to = EXCLUDED.date_to,
                   origin_gone_at = NULL, seen_at = NOW()",
            [':f' => $fid, ':k' => $key, ':df' => $ev['from'], ':dt' => $ev['to']]
        );
        return 'echo';
    }

    $echo = db_query(
        "SELECT date_from, date_to, origin_gone_at,
                (origin_gone_at IS NOT NULL AND origin_gone_at < NOW() - make_interval(hours => :h)) AS expired
           FROM ical_echoes WHERE feed_id = :f AND uid = :k",
        [':f' => $fid, ':k' => $key, ':h' => ICAL_ECHO_GRACE_HOURS]
    )->fetch();
    if ($echo) {
        $sameDates = (string) $echo['date_from'] === $ev['from'] && (string) $echo['date_to'] === $ev['to'];
        if ($sameDates && !$echo['expired']) {
            // The booking it echoed is gone; wait for the OTA to re-read our feed.
            if ($echo['origin_gone_at'] === null) {
                db_query("UPDATE ical_echoes SET origin_gone_at = NOW() WHERE feed_id = :f AND uid = :k",
                    [':f' => $fid, ':k' => $key]);
            }
            return 'echo';
        }
        db_query("DELETE FROM ical_echoes WHERE feed_id = :f AND uid = :k", [':f' => $fid, ':k' => $key]);
    }

    return ical_import_event($feed, $ev['from'], $ev['to'], $ev['summary'], $key);
}

/** Fetch a feed: ['ok'=>bool, 'body'=>string, 'error'=>string]. */
function ical_fetch(string $url): array
{
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
        'max_redirects' => 5,
        'user_agent'    => 'TribalSand/1.0 iCalSync (+https://tribalsand.com)',
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, ICAL_MAX_BYTES);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];   // last one wins (after redirects)
    }
    if ($body === false)              return ['ok' => false, 'body' => '', 'error' => 'Could not reach the link.'];
    if ($status >= 400)               return ['ok' => false, 'body' => '', 'error' => "The site answered HTTP {$status} — the link may have been reset."];
    if (!ical_is_calendar($body))     return ['ok' => false, 'body' => '', 'error' => 'The link did not return a calendar.'];
    return ['ok' => true, 'body' => $body, 'error' => ''];
}

/**
 * Sync one feed. $ics is the feed body (tests pass it in); null = fetch it.
 * Returns the per-feed result the endpoint reports.
 */
function ical_sync_feed(array $feed, ?string $ics = null): array
{
    $fid    = (int) $feed['id'];
    $track  = ical_tracking_supported();
    $result = ['id' => $fid, 'label' => $feed['label'] ?? '', 'unit_id' => (int) $feed['unit_id']];

    if ($ics === null) {
        $got = ical_fetch((string) $feed['feed_url']);
        if (!$got['ok']) {
            if ($track) {
                db_query("UPDATE ical_feeds SET last_status = 'error', last_error = :e WHERE id = :id",
                    [':e' => $got['error'], ':id' => $fid]);
            }
            return $result + ['status' => 'error', 'message' => $got['error']];
        }
        $ics = $got['body'];
    } elseif (!ical_is_calendar($ics)) {
        return $result + ['status' => 'error', 'message' => 'The link did not return a calendar.'];
    }

    $today   = date('Y-m-d');
    $skipped = 0;
    $events  = ical_feed_events($ics, $today, trim((string) ($feed['label'] ?? '')) ?: 'OTA block', $skipped);
    $counts  = ['imported' => 0, 'skipped' => $skipped, 'removed' => 0, 'moved' => 0, 'echoes' => 0];

    $inTx = db()->inTransaction();
    $sp   = 'ical_sync_feed';
    if ($inTx) db()->exec("SAVEPOINT {$sp}"); else db()->beginTransaction();
    try {
        if (!$track) {
            // Pre-migration: add-only, exactly as before.
            foreach ($events as $ev) {
                if (ical_import_event($feed, $ev['from'], $ev['to'], $ev['summary']) === 'imported') $counts['imported']++;
                else $counts['skipped']++;
            }
            db_query("UPDATE ical_feeds SET last_synced_at = NOW() WHERE id = :id", [':id' => $fid]);
        } else {
            $tracked = db_query(
                "SELECT id, ical_uid, date_from, date_to FROM availability_blocks
                  WHERE ical_feed_id = :f AND hold_id IS NULL AND date_to >= :today
                  ORDER BY id",
                [':f' => $fid, ':today' => $today]
            )->fetchAll();
            $plan = ical_diff_tracked($events, $tracked);

            // Cancelled on the OTA → free the dates here (and, via our export, everywhere).
            // Untracked "Keep OTA" blocks (uid NULL) are never in $tracked's removal
            // path unless their uid is empty AND they are this feed's — see adopt below.
            foreach ($plan['remove'] as $blockId) {
                $b = null;
                foreach ($tracked as $t) if ((int) $t['id'] === $blockId) { $b = $t; break; }
                if ($b !== null && ($b['ical_uid'] ?? null) === null) continue;   // awaiting adoption
                db_query("DELETE FROM availability_blocks WHERE id = :id AND ical_feed_id = :f",
                    [':id' => $blockId, ':f' => $fid]);
                $counts['removed']++;
            }
            foreach ($plan['move'] as $key => $blockId) {
                db_query("DELETE FROM availability_blocks WHERE id = :id AND ical_feed_id = :f",
                    [':id' => $blockId, ':f' => $fid]);
                $r = ical_sync_new_event($feed, $key, $events[$key]);
                $counts['moved']++;
                if ($r === 'echo') $counts['echoes']++;
            }
            foreach ($plan['new'] as $key) {
                $r = ical_sync_new_event($feed, $key, $events[$key]);
                if ($r === 'imported')  $counts['imported']++;
                elseif ($r === 'echo')  $counts['echoes']++;
                else                    $counts['skipped']++;
            }
            $counts['skipped'] += count($plan['keep']);

            // Forget echoes the OTA no longer sends.
            $keys = array_keys($events);
            if ($keys) {
                $ph = []; $p = [':f' => $fid];
                foreach ($keys as $i => $k) { $ph[] = ":k{$i}"; $p[":k{$i}"] = $k; }
                db_query("DELETE FROM ical_echoes WHERE feed_id = :f AND uid NOT IN (" . implode(',', $ph) . ")", $p);
            } else {
                db_query("DELETE FROM ical_echoes WHERE feed_id = :f", [':f' => $fid]);
            }

            db_query(
                "UPDATE ical_feeds SET last_synced_at = NOW(), last_ok_at = NOW(), last_status = 'ok',
                        last_error = NULL, last_event_count = :n
                  WHERE id = :id",
                [':n' => count($events), ':id' => $fid]
            );
        }
        if ($inTx) db()->exec("RELEASE SAVEPOINT {$sp}"); else db()->commit();
    } catch (Throwable $e) {
        if ($inTx) db()->exec("ROLLBACK TO SAVEPOINT {$sp}"); else db()->rollBack();
        error_log('iCal sync feed ' . $fid . ': ' . $e->getMessage());
        if ($track) {
            db_query("UPDATE ical_feeds SET last_status = 'error', last_error = :e WHERE id = :id",
                [':e' => 'Sync failed — the calendar was left unchanged.', ':id' => $fid]);
        }
        return $result + ['status' => 'error', 'message' => 'Sync failed — the calendar was left unchanged.'];
    }

    return $result + ['status' => 'ok', 'total' => count($events)] + $counts;
}

/**
 * Sync every feed (or only those on $venueScope's properties: null = all,
 * [] = none). Returns the per-feed results.
 */
function ical_sync_all(?array $venueScope = null): array
{
    if ($venueScope === []) return [];
    $where = '';
    if ($venueScope !== null) {
        $where = ' WHERE r.venue_id IN (' . implode(',', array_map('intval', $venueScope)) . ')';
    }
    $feeds = db_query(
        "SELECT f.* FROM ical_feeds f
           JOIN units u ON u.id = f.unit_id
           JOIN rooms r ON r.id = u.room_id{$where}
          ORDER BY f.id"
    )->fetchAll();

    $results = [];
    foreach ($feeds as $feed) $results[] = ical_sync_feed($feed);
    return $results;
}

// ── Export ──────────────────────────────────────────────────────────────────

/**
 * The date ranges a unit's export feed publishes as "not available": every
 * current/future booking, 24h hold and closure on the unit, plus those on the
 * units it shares with (a whole-property buyout closes its rooms, and a booked
 * room closes the buyout). Imported OTA bookings are included — that is how a
 * Booking.com booking reaches Airbnb. Identical ranges are published once.
 * Returns [['id','date_from','date_to']] ordered by date.
 */
function ical_export_blocks(int $unitId): array
{
    $units = array_merge([$unitId], ical_linked_unit_ids($unitId));
    $in    = implode(',', array_map('intval', $units));
    $rows  = db_query(
        "SELECT MIN(ab.id) AS id, ab.date_from, ab.date_to
           FROM availability_blocks ab
          WHERE ab.unit_id IN ({$in})
            AND ab.date_to > CURRENT_DATE
            AND ab.block_type IN ('hold','booked','blocked')
          GROUP BY ab.date_from, ab.date_to
          ORDER BY ab.date_from, ab.date_to"
    )->fetchAll();
    return $rows;
}
