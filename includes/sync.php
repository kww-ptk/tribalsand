<?php
declare(strict_types=1);
/**
 * Tribalsand ↔ Zuri two-way restaurant sync — shared transport primitives.
 *
 * This is the Tribalsand (PostgreSQL) side of the spec in
 * docs/restaurant-sync.md. It owns the *plumbing* both sides build the same way:
 *   • the HMAC-SHA256 auth scheme (§5)
 *   • the loop-prevention applying flag (§2)
 *   • the §3 event envelope + id/uuid minting
 *   • the outbox writer (push local changes) and inbox de-dupe (receive)
 *   • the ownership rules (§1) and the conflict resolver + reservation state
 *     machine (§6) — both of which the spec assigns to Aly to write
 *   • a /health snapshot (§9)
 *
 * It does NOT contain the per-entity field mappers (menu_item ↔ our columns,
 * etc.) — those translate between our schema and the shared envelope and, per
 * §11, the field maps are JOINTLY owned in the shared contract repo. The applier
 * (bin/sync-apply.php) will call into mappers once that contract is signed off.
 *
 * Everything here is pre-migration-safe: sync_supported() gates the DB paths so
 * the app never fatals on a database that hasn't run add_restaurant_sync.sql.
 */
require_once __DIR__ . '/db.php';

/* ─────────────────────────────────────────────────────────────────────────
 * Support guard + feature switches
 * ───────────────────────────────────────────────────────────────────────── */

/** True once add_restaurant_sync.sql has run (memoised). False pre-migration. */
function sync_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.sync_outbox')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Our identity on the wire. The peer is 'zuri'. */
function sync_self_source(): string { return 'tribalsand'; }
function sync_peer_source(): string { return 'zuri'; }

/**
 * The master kill switch and per-direction flags (§10). Default OFF — sync only
 * runs once explicitly enabled in the environment, so a deploy never starts
 * pushing on its own. While off, outbox rows still accumulate and drain in order
 * when it comes back on.
 */
function sync_enabled(): bool {
    return sync_env_bool('SYNC_ENABLED', false);
}
function sync_ts_to_zuri_enabled(): bool {
    return sync_enabled() && sync_env_bool('SYNC_TS_TO_ZURI', false);
}
function sync_zuri_to_ts_enabled(): bool {
    return sync_enabled() && sync_env_bool('SYNC_ZURI_TO_TS', false);
}
/**
 * Reservations stage (S§10 "reservations one-way"): Tribalsand bookings for the
 * synced venue go to Zuri's POST /reserve first. Its own switch, so turning on
 * the menu push (SYNC_TS_TO_ZURI) never starts routing guest bookings.
 */
function sync_reservations_enabled(): bool {
    return sync_enabled() && sync_env_bool('SYNC_RESERVATIONS', false);
}
function sync_env_bool(string $key, bool $default): bool {
    $env = parse_env();
    if (!isset($env[$key])) return $default;
    $v = strtolower(trim((string) $env[$key]));
    if ($v === '') return $default;
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/** The peer's base URL for the dispatcher, e.g. https://zuriwatamu.com/sync/v1 */
function sync_peer_base_url(): string {
    $env = parse_env();
    return rtrim((string) ($env['SYNC_PEER_URL'] ?? ''), '/');
}

/* ─────────────────────────────────────────────────────────────────────────
 * Loop prevention (§2)
 *
 * When the applier writes an inbound change it wraps the write in
 * SyncContext::applying(), and the outbox writer skips while that flag is set —
 * otherwise one edit ping-pongs forever between the two systems.
 * ───────────────────────────────────────────────────────────────────────── */

final class SyncContext {
    private static bool $applying = false;

    public static function isApplying(): bool { return self::$applying; }

    /** Run $fn with the applying flag set, restoring it afterwards (even on throw). */
    public static function applying(callable $fn): mixed {
        $prev = self::$applying;
        self::$applying = true;
        try { return $fn(); }
        finally { self::$applying = $prev; }
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * HMAC auth (§5)
 *
 *   $sig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $sharedSecret);
 *
 * Compared with hash_equals(), never ===. Timestamps more than 300s off are
 * rejected (replay window). The secret lives in the SYNC_SHARED_SECRET env var.
 * ───────────────────────────────────────────────────────────────────────── */

function sync_shared_secret(): string {
    $env = parse_env();
    return trim((string) ($env['SYNC_SHARED_SECRET'] ?? ''));
}

/** The allowed clock skew, in seconds, between the two peers. */
function sync_timestamp_window(): int { return 300; }

/** Sign a raw body for an outbound request. Returns the hex signature. */
function sync_sign(string $timestamp, string $rawBody, ?string $secret = null): string {
    $secret = $secret ?? sync_shared_secret();
    return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
}

/**
 * Verify an inbound request. Returns one of:
 *   ['ok' => true]
 *   ['ok' => false, 'code' => 401, 'error' => 'bad_signature']
 *   ['ok' => false, 'code' => 401, 'error' => 'bad_timestamp']  (outside the 300s window)
 *   ['ok' => false, 'code' => 503, 'error' => 'not_configured'] (no secret set)
 *
 * The 300s window check comes FIRST so a replayed body with a valid old signature
 * is still rejected. hash_equals guards against timing attacks.
 */
function sync_verify_signature(string $timestamp, string $rawBody, string $presentedSig, ?int $now = null, ?string $secret = null): array {
    $secret = $secret ?? sync_shared_secret();
    if ($secret === '') {
        return ['ok' => false, 'code' => 503, 'error' => 'not_configured'];
    }

    $ts = (int) $timestamp;
    $now = $now ?? time();
    if ($ts <= 0 || abs($now - $ts) > sync_timestamp_window()) {
        return ['ok' => false, 'code' => 401, 'error' => 'bad_timestamp'];   // stale / skewed clock
    }

    $expected = sync_sign($timestamp, $rawBody, $secret);
    if ($presentedSig === '' || !hash_equals($expected, $presentedSig)) {
        return ['ok' => false, 'code' => 401, 'error' => 'bad_signature'];
    }
    return ['ok' => true];
}

/** True if the caller's IP is on the peer allowlist (SYNC_PEER_IPS, comma-sep). Empty list = allow. */
function sync_ip_allowed(string $ip): bool {
    $env = parse_env();
    $list = trim((string) ($env['SYNC_PEER_IPS'] ?? ''));
    if ($list === '') return true;                       // not configured = don't block (belt is HMAC)
    $allowed = array_filter(array_map('trim', explode(',', $list)));
    return in_array($ip, $allowed, true);
}

/* ─────────────────────────────────────────────────────────────────────────
 * Ownership rules (§1)
 *
 * Every entity has at most one owner. A write to an entity you don't own is
 * refused with 403 not_owner. Reservations are two-way (owner = null): the state
 * machine in §6 governs who may make a given status change, not a static owner.
 * ───────────────────────────────────────────────────────────────────────── */

/** entity => 'tribalsand' | 'zuri' | null (two-way). Unknown entity => null. */
function sync_entity_owner(string $entity): ?string {
    static $map = [
        'menu_category'    => 'tribalsand',
        'menu_item'        => 'tribalsand',
        'restaurant_table' => 'tribalsand',
        'opening_hours'    => 'tribalsand',
        'item_availability' => 'zuri',
        'seat_inventory'   => 'zuri',
        'customer'         => 'zuri',
        'reservation'      => null,          // two-way; see the state machine
    ];
    return $map[$entity] ?? null;
}

/**
 * May the peer (Zuri) write this entity to us? It may write anything Tribalsand
 * does not own — so it is refused ONLY for our own entities (menu*, tables,
 * hours). This is what turns Zuri trying to change a price into a 403 not_owner
 * (spec test #15).
 */
function sync_peer_may_write(string $entity): bool {
    return sync_entity_owner($entity) !== sync_self_source();
}

/* ─────────────────────────────────────────────────────────────────────────
 * Reservation state machine (§6)
 *
 * cancelled / no_show / completed are terminal. A transition OUT of a terminal
 * state is never allowed, however new the incoming timestamp — this is the one
 * rule that stops a cancelled table going live again and being double-booked.
 * ───────────────────────────────────────────────────────────────────────── */

function sync_reservation_states(): array {
    return ['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'];
}

function sync_status_is_terminal(string $status): bool {
    return in_array($status, ['cancelled', 'no_show', 'completed'], true);
}

/** Allowed transitions. Staying in the same state is always allowed (idempotent re-apply). */
function sync_reservation_transition_allowed(string $from, string $to): bool {
    if ($from === $to) return true;
    if (sync_status_is_terminal($from)) return false;    // terminal never revives
    static $edges = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['seated', 'cancelled', 'no_show'],
        'seated'    => ['completed'],
    ];
    return in_array($to, $edges[$from] ?? [], true);
}

/* ─────────────────────────────────────────────────────────────────────────
 * Conflict resolver (§6)
 *
 * Pure decision function the applier runs. It does NOT touch the DB — the applier
 * handles the unknown-uuid (insert / pull) cases via sync_id_map before calling
 * this, then acts on the decision and always logs a sync_conflicts row for a
 * genuine conflict.
 *
 * $local  = ['version'=>int, 'data'=>array, 'occurred_at'=>string|int, 'status'=>?string]
 * $remote = same shape, plus the event is from the peer.
 *
 * Returns ['decision' => …, 'reason' => …] where decision is one of:
 *   'apply'   — write the remote change
 *   'stale'   — remote version is behind ours; sender must pull (409)
 *   'noop'    — identical, acknowledge and do nothing
 *   'conflict'— equal version, different data; also carries 'winner' => 'remote'|'local'
 */
function sync_resolve(string $entity, array $local, array $remote): array {
    // Reservation status transitions ignore versions/timestamps and obey the
    // machine. This is checked first because the terminal-state guard must win
    // over any "higher version" argument.
    if ($entity === 'reservation') {
        $from = (string) ($local['status'] ?? '');
        $to   = (string) ($remote['status'] ?? '');
        if ($from !== '' && $to !== '' && $from !== $to) {
            if (!sync_reservation_transition_allowed($from, $to)) {
                return ['decision' => 'noop', 'reason' => 'illegal_transition'];   // e.g. cancelled ← confirmed
            }
        }
        // legal transition (or a non-status field change) falls through to the
        // version comparison below
    }

    $lv = (int) ($local['version'] ?? 0);
    $rv = (int) ($remote['version'] ?? 0);

    if ($rv > $lv) return ['decision' => 'apply',  'reason' => 'higher_version'];
    if ($rv < $lv) return ['decision' => 'stale',  'reason' => 'lower_version'];

    // Equal versions.
    if (sync_data_equal($local['data'] ?? [], $remote['data'] ?? [])) {
        return ['decision' => 'noop', 'reason' => 'identical'];
    }

    // Equal version, different data → conflict. Owner wins; if neither owns it,
    // the later occurred_at wins; on an exact tie, Zuri wins.
    $owner = sync_entity_owner($entity);
    if ($owner === sync_self_source()) {
        return ['decision' => 'conflict', 'winner' => 'local',  'reason' => 'owner_wins'];
    }
    if ($owner === sync_peer_source()) {
        return ['decision' => 'conflict', 'winner' => 'remote', 'reason' => 'owner_wins'];
    }

    $lt = sync_epoch($local['occurred_at']  ?? null);
    $rt = sync_epoch($remote['occurred_at'] ?? null);
    if ($rt > $lt) return ['decision' => 'conflict', 'winner' => 'remote', 'reason' => 'later_occurred_at'];
    if ($rt < $lt) return ['decision' => 'conflict', 'winner' => 'local',  'reason' => 'later_occurred_at'];
    return ['decision' => 'conflict', 'winner' => 'remote', 'reason' => 'zuri_wins'];   // exact tie
}

/** Normalise an occurred_at (epoch int or ISO-8601 string) to a Unix timestamp; 0 if unparseable. */
function sync_epoch(mixed $v): int {
    if (is_numeric($v)) return (int) $v;
    $t = strtotime((string) ($v ?? ''));
    return $t === false ? 0 : $t;
}

/** Deep, order-insensitive value equality for two envelope data maps. */
function sync_data_equal(array $a, array $b): bool {
    $norm = static function (array $x) use (&$norm): array {
        ksort($x);
        foreach ($x as $k => $v) if (is_array($v)) $x[$k] = $norm($v);
        return $x;
    };
    return $norm($a) === $norm($b);
}

/* ─────────────────────────────────────────────────────────────────────────
 * Envelope + id minting (§3)
 * ───────────────────────────────────────────────────────────────────────── */

/** A sortable, unique event id: evt_ + 26-char Crockford base32 of time+random. */
function sync_new_event_id(): string {
    $bytes = pack('J', (int) (microtime(true) * 1000)) . random_bytes(8);
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';   // Crockford base32
    $out = '';
    $val = 0; $bits = 0;
    foreach (str_split($bytes) as $ch) {
        $val = ($val << 8) | ord($ch);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($val >> $bits) & 31];
        }
    }
    return 'evt_' . substr($out, 0, 26);
}

/** A v4 UUID for a brand-new local row that has no sync_uuid yet. */
function sync_new_uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** Build the §3 envelope. Money must already be a decimal string, timestamps UTC ISO-8601. */
function sync_make_event(string $entity, string $operation, string $syncUuid, int $version, array $data, bool $deleted = false): array {
    return [
        'event_id'    => sync_new_event_id(),
        'entity'      => $entity,
        'operation'   => $operation,
        'sync_uuid'   => $syncUuid,
        'version'     => $version,
        'source'      => sync_self_source(),
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'deleted'     => $deleted,
        'data'        => $data,
    ];
}

/* ─────────────────────────────────────────────────────────────────────────
 * Outbox writer (§2/§4)
 *
 * Call this in the SAME transaction as the local data change, from the repo
 * layer. It is a no-op while the applier is running (loop prevention) and while
 * the migration hasn't run. It only queues the event — the dispatcher sends it.
 * ───────────────────────────────────────────────────────────────────────── */

function sync_outbox_push(string $entity, string $syncUuid, string $operation, array $data, int $version, bool $deleted = false): void {
    if (SyncContext::isApplying()) return;   // loop prevention — the change came FROM the peer
    if (!sync_supported())        return;    // pre-migration: nothing to write to

    $event = sync_make_event($entity, $operation, $syncUuid, $version, $data, $deleted);
    db_query(
        "INSERT INTO sync_outbox (event_id, entity, sync_uuid, operation, payload, version, status, next_retry_at)
         VALUES (:eid, :ent, :uuid, :op, :payload, :ver, 'pending', now())
         ON CONFLICT (event_id) DO NOTHING",
        [
            ':eid'     => $event['event_id'],
            ':ent'     => $entity,
            ':uuid'    => $syncUuid,
            ':op'      => $operation,
            ':payload' => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':ver'     => $version,
        ]
    );
}

/* ─────────────────────────────────────────────────────────────────────────
 * Inbox receive (§5 receiver)
 *
 * De-dupes on event_id (exactly-once application). Returns one of 'accepted',
 * 'duplicate' or 'rejected' so the endpoint can build the 202 response arrays.
 * The applier processes 'pending' rows out of band.
 * ───────────────────────────────────────────────────────────────────────── */

/** Validate the minimum shape of an inbound event. Returns '' if OK, else a reason. */
function sync_validate_event(array $ev): string {
    foreach (['event_id', 'entity', 'operation', 'sync_uuid', 'version', 'source'] as $k) {
        if (!isset($ev[$k]) || $ev[$k] === '') return "missing_$k";
    }
    if (!in_array($ev['operation'], ['create', 'update', 'delete'], true)) return 'bad_operation';
    if (!is_array($ev['data'] ?? [])) return 'bad_data';
    return '';
}

/**
 * Store one received event. Returns 'duplicate' if already seen, 'rejected' with
 * a reason on a bad shape or an ownership violation (not_owner), else 'accepted'.
 */
function sync_inbox_receive(array $ev): array {
    $reason = sync_validate_event($ev);
    if ($reason !== '') return ['result' => 'rejected', 'error' => $reason];

    if (!sync_peer_may_write((string) $ev['entity'])) {
        return ['result' => 'rejected', 'error' => 'not_owner'];
    }

    $stmt = db_query(
        "INSERT INTO sync_inbox (event_id, entity, sync_uuid, operation, payload, version, source, status)
         VALUES (:eid, :ent, :uuid, :op, :payload, :ver, :src, 'pending')
         ON CONFLICT (event_id) DO NOTHING
         RETURNING id",
        [
            ':eid'     => (string) $ev['event_id'],
            ':ent'     => (string) $ev['entity'],
            ':uuid'    => (string) $ev['sync_uuid'],
            ':op'      => (string) $ev['operation'],
            ':payload' => json_encode($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':ver'     => (int) $ev['version'],
            ':src'     => (string) $ev['source'],
        ]
    );
    return $stmt->fetchColumn() ? ['result' => 'accepted'] : ['result' => 'duplicate'];
}

/* ─────────────────────────────────────────────────────────────────────────
 * Signed request to the peer (the applier's pull-on-unknown and the /reserve
 * client). function_exists-guarded so tests can stub the HTTP call, like
 * ai_claude_request(). The dispatcher keeps its own batch sender.
 * ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('sync_peer_request')) {
    /**
     * Signed request to Zuri's /sync/v1. Returns [httpCode, decodedJson|null].
     * $headers are extra "Name: value" lines (e.g. Idempotency-Key).
     */
    function sync_peer_request(string $method, string $path, string $body = '', array $headers = []): array {
        $base = sync_peer_base_url();
        if ($base === '' || sync_shared_secret() === '') return [0, null];
        $ts = (string) time();
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array_merge([
                'Content-Type: application/json',
                'X-Sync-Source: ' . sync_self_source(),
                'X-Sync-Timestamp: ' . $ts,
                'X-Sync-Signature: ' . sync_sign($ts, $body),
            ], $headers),
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) error_log('[sync] peer request failed: ' . curl_error($ch));
        curl_close($ch);
        $json = is_string($resp) ? json_decode($resp, true) : null;
        return [$code, is_array($json) ? $json : null];
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Single-runner lock for the workers (dispatcher / applier)
 *
 * The scheduler runs in EVERY ECS task, so without this two tasks would drain
 * the same queue concurrently and deliver/apply events out of order. A session
 * advisory lock (released automatically when the PHP process exits) makes each
 * worker a singleton; a second instance simply skips its pass.
 * ───────────────────────────────────────────────────────────────────────── */

/** Try to become the only running $worker. False = another process holds it. */
function sync_worker_lock(string $worker): bool {
    try {
        return (bool) db_query('SELECT pg_try_advisory_lock(hashtext(:k))', [':k' => 'sync-worker:' . $worker])->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Health snapshot (§9)
 * ───────────────────────────────────────────────────────────────────────── */

function sync_health(): array {
    if (!sync_supported()) {
        return ['ok' => false, 'enabled' => false, 'supported' => false];
    }
    $out = db_query(
        "SELECT
            count(*) FILTER (WHERE status = 'pending')                    AS outbox_pending,
            count(*) FILTER (WHERE status = 'failed')                     AS outbox_failed,
            EXTRACT(EPOCH FROM (now() - min(created_at)
                FILTER (WHERE status = 'pending')))::int                  AS oldest_pending_secs,
            max(sent_at)                                                  AS last_sent_at
         FROM sync_outbox"
    )->fetch() ?: [];
    $in = db_query(
        "SELECT
            count(*) FILTER (WHERE status = 'pending')  AS inbox_pending,
            count(*) FILTER (WHERE status = 'rejected') AS inbox_rejected,
            max(applied_at)                             AS last_applied_at
         FROM sync_inbox"
    )->fetch() ?: [];
    return [
        'ok'                 => true,
        'supported'          => true,
        'enabled'            => sync_enabled(),
        'ts_to_zuri'         => sync_ts_to_zuri_enabled(),
        'zuri_to_ts'         => sync_zuri_to_ts_enabled(),
        'outbox_pending'     => (int) ($out['outbox_pending'] ?? 0),
        'outbox_failed'      => (int) ($out['outbox_failed'] ?? 0),
        'oldest_pending_secs'=> (int) ($out['oldest_pending_secs'] ?? 0),
        'last_sent_at'       => $out['last_sent_at'] ?? null,
        'inbox_pending'      => (int) ($in['inbox_pending'] ?? 0),
        'inbox_rejected'     => (int) ($in['inbox_rejected'] ?? 0),
        'last_applied_at'    => $in['last_applied_at'] ?? null,
        'generated_at'       => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}
