<?php
declare(strict_types=1);
/**
 * Sync dispatcher (§5) — pushes pending sync_outbox rows to the peer's
 * /sync/v1/events, one entity type per batch, with the §5 retry schedule.
 *
 * Run as a supervised worker for low latency, or on a short scheduler interval
 * as the fallback (which caps latency at the interval). Single drain pass by
 * default; pass --loop to keep draining with a short sleep between passes.
 *
 *   php bin/sync-dispatch.php            # one pass, then exit (scheduler-driven)
 *   php bin/sync-dispatch.php --loop     # long-running worker
 *   php bin/sync-dispatch.php --quiet    # no "idle" lines (the scheduler runs it every 10s)
 *
 * Only one dispatcher runs at a time across all ECS tasks (sync_worker_lock) —
 * delivery order matters, so a second instance skips its pass.
 *
 * Kill switch (§10): does nothing unless SYNC_ENABLED and SYNC_TS_TO_ZURI are on.
 * While off, rows accumulate and drain in order once switched back on.
 *
 * Local Windows dev: PHP cURL needs a CA bundle for the HTTPS call — run with
 *   php -d curl.cainfo="C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt" bin/sync-dispatch.php
 * Prod Linux has a system CA store, so no flag is needed.
 */
require_once __DIR__ . '/../includes/sync.php';

const SYNC_BATCH   = 100;
const SYNC_RETRIES = [10, 30, 120, 600, 3600, 21600];   // 10s,30s,2m,10m,1h,6h → then failed

function dispatch_log(string $msg): void {
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . "\n");
}

/** next_retry_at delay for the given attempt count, with 20% jitter. */
function dispatch_backoff(int $attempts): ?int {
    if ($attempts >= count(SYNC_RETRIES)) return null;   // exhausted → mark failed
    $base = SYNC_RETRIES[$attempts];
    return (int) round($base * (1 + (mt_rand(0, 200) / 1000)));   // +0–20%
}

/**
 * POST one batch to the peer. Returns [httpCode, decodedBody|null].
 */
function dispatch_send(string $rawBody): array {
    $base = sync_peer_base_url();
    if ($base === '') { dispatch_log('SYNC_PEER_URL not set'); return [0, null]; }

    $ts  = (string) time();
    $sig = sync_sign($ts, $rawBody);

    $ch = curl_init($base . '/events');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rawBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Sync-Source: ' . sync_self_source(),
            'X-Sync-Timestamp: ' . $ts,
            'X-Sync-Signature: ' . $sig,
            'Idempotency-Key: ' . sync_new_event_id(),
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) dispatch_log('curl error: ' . curl_error($ch));
    curl_close($ch);

    $decoded = is_string($resp) ? json_decode($resp, true) : null;
    return [$code, is_array($decoded) ? $decoded : null];
}

/** Claim up to SYNC_BATCH eligible pending rows of ONE entity type. Returns rows. */
function dispatch_claim(): array {
    // Pick the entity type of the oldest eligible row so a bad menu event can't
    // block reservations (§5: one entity type per batch).
    $ent = db_query(
        "SELECT entity FROM sync_outbox
          WHERE status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= now())
          ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if (!$ent) return [];

    // SKIP LOCKED lets multiple dispatcher instances run without stealing each
    // other's rows. We only read here; the send + status update happen after.
    return db_query(
        "SELECT id, event_id, entity, payload, attempts FROM sync_outbox
          WHERE status = 'pending' AND entity = :ent
            AND (next_retry_at IS NULL OR next_retry_at <= now())
          ORDER BY id ASC LIMIT " . SYNC_BATCH . "
          FOR UPDATE SKIP LOCKED",
        [':ent' => $ent]
    )->fetchAll();
}

function dispatch_mark_sent(array $ids): void {
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    db_query("UPDATE sync_outbox SET status = 'sent', sent_at = now() WHERE id IN ($in)");
}

function dispatch_mark_failed(array $ids, string $err): void {
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    db_query(
        "UPDATE sync_outbox SET status = 'failed', last_error = :e, attempts = attempts + 1 WHERE id IN ($in)",
        [':e' => $err]
    );
}

function dispatch_mark_retry(array $rows, string $err): void {
    foreach ($rows as $r) {
        $delay = dispatch_backoff((int) $r['attempts']);
        if ($delay === null) {
            dispatch_mark_failed([(int) $r['id']], 'retries exhausted: ' . $err);
            dispatch_log('event ' . $r['event_id'] . ' FAILED after retries: ' . $err);
            continue;
        }
        db_query(
            "UPDATE sync_outbox
                SET attempts = attempts + 1, last_error = :e,
                    next_retry_at = now() + (:d || ' seconds')::interval
              WHERE id = :id",
            [':e' => $err, ':d' => (string) $delay, ':id' => (int) $r['id']]
        );
    }
}

/** Stage 1 (§10): log what we WOULD send, send nothing. Independent of the direction flags. */
function dispatch_shadow_mode(): bool {
    return sync_env_bool('SYNC_SHADOW', false);
}

/** One drain pass. Returns the number of rows attempted (0 = nothing to do). */
function dispatch_pass(): int {
    $rows = dispatch_claim();
    if (!$rows) return 0;

    $ids     = array_map(fn($r) => (int) $r['id'], $rows);
    $events  = array_map(fn($r) => json_decode((string) $r['payload'], true), $rows);

    // Stage 1 Shadow: log the batch, apply nothing, and mark the rows sent so the
    // outbox drains. Going live happens off fresh edits (Stage 2) and the full
    // go-live dataset comes from bin/sync-export.php, so nothing is lost.
    if (dispatch_shadow_mode()) {
        foreach ($rows as $r) {
            dispatch_log('SHADOW would send ' . $r['entity'] . ' event ' . $r['event_id']);
        }
        dispatch_mark_sent($ids);
        return count($rows);
    }

    $rawBody = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    [$code, $body] = dispatch_send($rawBody);

    if ($code === 202) {
        // Accepted + duplicates both count as delivered. A per-event rejection
        // inside a 202 is a bug for that event — mark it failed, don't retry.
        $ok = array_merge($body['accepted'] ?? [], $body['duplicates'] ?? []);
        $okIds = [];
        foreach ($rows as $r) {
            if (in_array($r['event_id'], $ok, true)) $okIds[] = (int) $r['id'];
        }
        // Each rejection carries {event_id, code, message} (Zuri handover §7):
        // invalid_payload = our mapper bug, not_owner = we sent a field Zuri
        // owns, stale_version = Zuri holds newer — pull it with
        // GET /changes?entity=&sync_uuid= and reconcile. None is retried.
        $why = [];
        foreach ($body['rejected'] ?? [] as $rej) {
            $code = (string) ($rej['code'] ?? $rej['error'] ?? 'rejected');
            $why[(string) ($rej['event_id'] ?? '')] = trim($code . ' ' . (string) ($rej['message'] ?? ''));
        }
        dispatch_mark_sent($okIds);
        foreach ($rows as $r) {
            if (!isset($why[$r['event_id']])) continue;
            dispatch_mark_failed([(int) $r['id']], 'rejected by peer: ' . $why[$r['event_id']]);
            dispatch_log("ALERT peer rejected {$r['entity']} {$r['event_id']}: " . $why[$r['event_id']]);
        }
        return count($rows);
    }

    // Non-202: the §5 code table decides retry vs alert.
    switch ($code) {
        case 400:  // invalid_payload — a bug in our mapper
        case 401:  // bad_signature — misconfigured secret / attack, alert now
        case 403:  // not_owner — we tried to write a field we don't own
        case 409:  // stale_version — pull current state (handled by the applier)
            dispatch_mark_failed($ids, "peer $code");
            dispatch_log("ALERT peer returned $code for entity {$rows[0]['entity']} — no retry, needs attention");
            break;
        default:   // 429 / 5xx / transport error — retry with backoff
            dispatch_mark_retry($rows, $code ? "peer $code" : 'transport error');
    }
    return count($rows);
}

// ── main ────────────────────────────────────────────────────────────────────
$quiet = in_array('--quiet', $argv, true);
$shadow = dispatch_shadow_mode();
// Env switches first: an idle pass (the scheduler's every-10s default) never touches the DB.
if (!$shadow && !sync_ts_to_zuri_enabled()) { if (!$quiet) dispatch_log('TS→Zuri disabled (kill switch) — idle'); exit(0); }
if (!sync_supported()) { if (!$quiet) dispatch_log('sync not migrated — nothing to do'); exit(0); }
if (!sync_worker_lock('dispatch')) { if (!$quiet) dispatch_log('another dispatcher is running — skipping'); exit(0); }
if ($shadow && !$quiet) dispatch_log('SHADOW mode — logging only, nothing sent');

$loop = in_array('--loop', $argv, true);
do {
    $n = 0;
    // Drain everything currently eligible before sleeping/exiting.
    while (dispatch_pass() > 0) { $n++; if ($n > 1000) break; }   // guard against a runaway pass
    if ($loop) { usleep(1_000_000); }   // 1s between drains for near-real-time reservations
} while ($loop && ($shadow || sync_ts_to_zuri_enabled()));

exit(0);
