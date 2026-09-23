<?php
declare(strict_types=1);
/**
 * The applier (spec S§6, handover §6–§8) — writes what Zuri sent us.
 *
 * /sync/v1/events only STORES inbound events in sync_inbox (de-duped on
 * event_id). bin/sync-apply.php drains that inbox, oldest first, through
 * sync_apply_pending() below. Every write runs inside SyncContext::applying(), so
 * nothing it touches is echoed back to Zuri (loop prevention).
 *
 * Entities Zuri may write to us (sync_peer_may_write):
 *   item_availability  → menu_items.is_sold_out (sync_uuid = the menu item's)
 *   customer           → customers
 *   reservation        → reservations (+ table/customer links via sync_uuid)
 *
 * Per-event resolution (S§6 order):
 *   unknown uuid + create          → insert (+ sync_id_map)
 *   unknown uuid + update          → insert when the event carries the required
 *                                    fields, else pull GET /changes?entity=&sync_uuid=
 *                                    from Zuri first
 *   known, higher version          → apply
 *   known, lower version           → rejected stale_version
 *   known, equal version, same     → no-op
 *   known, equal version, differs  → sync_conflicts row; owner (or later
 *                                    occurred_at for reservations) wins
 * Reservations: a terminal status (cancelled/no_show/completed) never revives —
 * such an event is ignored and logged as a `terminal_state` conflict.
 *
 * Ordering race (handover §8): a reservation can arrive before its customer.
 * The event is left pending with an attempt counter and back-off, and fails as
 * missing_reference after SYNC_APPLY_MAX_ATTEMPTS tries. Later events for the
 * same record wait behind it, so per-record order is kept.
 *
 * Pre-migration-safe: sync_applier_supported() is an information_schema lookup
 * (never a failing SELECT — a failed statement aborts a Postgres transaction).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/sync-mappers.php';
require_once __DIR__ . '/customers.php';

const SYNC_APPLY_MAX_ATTEMPTS = 5;

/** True once add_sync_applier.sql has run (the sold-out + inbox retry columns exist). */
function sync_applier_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    if (!sync_supported()) return $c = false;
    try {
        $n = (int) db_query(
            "SELECT count(*) FROM information_schema.columns
              WHERE table_schema = 'public'
                AND ((table_name = 'menu_items'   AND column_name = 'is_sold_out')
                  OR (table_name = 'sync_inbox'   AND column_name = 'attempts')
                  OR (table_name = 'reservations' AND column_name = 'staff_notes'))"
        )->fetchColumn();
        return $c = ($n === 3);
    } catch (Throwable $e) {
        return $c = false;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Pure mappers: contract `data` → our columns. Only keys PRESENT in the event
 * are mapped — partial objects are allowed, and treating a missing key as null
 * would wipe a value we hold.
 * ───────────────────────────────────────────────────────────────────────── */

/** ISO-8601 → the same string when parseable, else null. */
function sync_apply_ts(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    return strtotime((string) $v) === false ? null : (string) $v;
}

/**
 * customer data → customers columns. Returns ['cols' => [...], 'errors' => [...]].
 * (Contract `source` has no column here and is not stored.)
 */
function sync_apply_map_customer(array $d): array {
    $cols = []; $err = [];
    $txt = static fn($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
    if (array_key_exists('name', $d)) {
        $cols['name'] = $txt($d['name']);
        if ($cols['name'] === null) $err[] = 'name is required';
    }
    if (array_key_exists('email', $d)) $cols['email'] = $txt($d['email']);
    if (array_key_exists('phone', $d)) $cols['phone'] = $txt($d['phone']);
    if (array_key_exists('notes', $d)) $cols['notes'] = $txt($d['notes']);
    foreach (['name' => 160, 'email' => 200, 'phone' => 40] as $k => $max) {
        if (isset($cols[$k]) && mb_strlen($cols[$k]) > $max) $err[] = "$k too long";
    }
    return ['cols' => $cols, 'errors' => $err];
}

/** Contract reservation keys → our column. customer_uuid/table_uuid are resolved separately. */
function sync_apply_reservation_keymap(): array {
    return [
        'reference'           => 'reference',
        'external_id'         => 'external_id',
        'date'                => 'reservation_date',
        'time'                => 'reservation_time',
        'duration_minutes'    => 'duration_minutes',
        'guests'              => 'party_size',
        'preference'          => 'preference',
        'special_requests'    => 'notes',          // the guest's own request
        'notes'               => 'staff_notes',
        'status'              => 'status',
        'source'              => 'source',
        'cancellation_reason' => 'cancellation_reason',
        'confirmed_at'        => 'confirmed_at',
        'seated_at'           => 'seated_at',
        'cancelled_at'        => 'cancelled_at',
        'created_at'          => 'created_at',
    ];
}

/**
 * reservation data → reservations columns. Returns ['cols' => [...], 'errors' => [...]].
 * Invalid values are errors (the event is rejected invalid_payload), never coerced
 * into something the guest didn't book.
 */
function sync_apply_map_reservation(array $d): array {
    $cols = []; $err = [];
    $txt = static fn($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
    foreach (sync_apply_reservation_keymap() as $k => $col) {
        if (!array_key_exists($k, $d)) continue;
        $v = $d[$k];
        switch ($k) {
            case 'date':
                $ok = is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
                if (!$ok) { $err[] = 'bad date'; break; }
                $cols[$col] = $v; break;
            case 'time':
                if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) { $err[] = 'bad time'; break; }
                $cols[$col] = substr($v, 0, 5); break;
            case 'guests':
                $n = filter_var($v, FILTER_VALIDATE_INT);
                if ($n === false || $n < 1 || $n > 500) { $err[] = 'bad guests'; break; }
                $cols[$col] = $n; break;
            case 'duration_minutes':
                if ($v === null) { $cols[$col] = null; break; }
                $n = filter_var($v, FILTER_VALIDATE_INT);
                if ($n === false || $n < 15) { $err[] = 'bad duration_minutes'; break; }
                $cols[$col] = $n; break;
            case 'preference':
                $p = $txt($v);
                if ($p !== null && !in_array($p, ['Lunch', 'Dinner', 'Private Dining'], true)) { $err[] = 'bad preference'; break; }
                $cols[$col] = $p; break;
            case 'status':
                if (!in_array($v, sync_reservation_states(), true)) { $err[] = 'bad status'; break; }
                $cols[$col] = $v; break;
            case 'source':
                $cols[$col] = mb_substr($txt($v) ?? 'zuri', 0, 40); break;
            case 'reference':
                $r = $txt($v);
                if ($r !== null && mb_strlen($r) > 32) { $err[] = 'reference too long'; break; }
                $cols[$col] = $r; break;
            case 'external_id':
                $r = $txt($v);
                if ($r !== null && mb_strlen($r) > 64) { $err[] = 'external_id too long'; break; }
                $cols[$col] = $r; break;
            case 'confirmed_at': case 'seated_at': case 'cancelled_at': case 'created_at':
                $cols[$col] = sync_apply_ts($v); break;
            default:
                $cols[$col] = $txt($v);
        }
    }
    if (array_key_exists('created_at', $cols) && $cols['created_at'] === null) unset($cols['created_at']);   // NOT NULL column
    return ['cols' => $cols, 'errors' => $err];
}

/**
 * On a booking WE created (sync_source = tribalsand), Zuri may change only the
 * shared fields — status and cancellation_reason (S§6) — plus what it assigns
 * when it seats the booking: its reference, the status timestamps and the table.
 * Everything else is ours; a Zuri change to it is ignored.
 */
function sync_apply_reservation_peer_keys_on_ours(): array {
    return ['status', 'cancellation_reason', 'confirmed_at', 'seated_at', 'cancelled_at', 'reference', 'table_uuid'];
}

/** Our current row → contract keys (for the resolver's equality check), limited to $keys. */
function sync_apply_reservation_local_data(array $row, array $keys, ?string $customerUuid, ?string $tableUuid): array {
    $map = sync_apply_reservation_keymap();
    $out = [];
    foreach ($keys as $k) {
        if ($k === 'customer_uuid') { $out[$k] = $customerUuid; continue; }
        if ($k === 'table_uuid')    { $out[$k] = $tableUuid;    continue; }
        if (!isset($map[$k])) continue;
        $v = $row[$map[$k]] ?? null;
        if ($k === 'time' && $v !== null) $v = substr((string) $v, 0, 5);
        if (in_array($k, ['guests', 'duration_minutes'], true) && $v !== null) $v = (int) $v;
        $out[$k] = $v;
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────────────────
 * Talking back to Zuri: a signed GET of one record's current state (S§6 — an
 * update for a uuid we don't know makes us pull the full record first).
 * sync_peer_request() is function_exists-guarded so tests can stub it.
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

/** Zuri's current state of one record as an envelope, or null (not found / unreachable). */
function sync_pull_record(string $entity, string $uuid): ?array {
    [$code, $body] = sync_peer_request('GET', '/changes?entity=' . rawurlencode($entity) . '&sync_uuid=' . rawurlencode($uuid));
    if ($code !== 200 || !is_array($body)) return null;
    $ev = $body['events'][0] ?? null;
    return is_array($ev) && ($ev['sync_uuid'] ?? '') === $uuid ? $ev : null;
}

/* ─────────────────────────────────────────────────────────────────────────
 * DB helpers
 * ───────────────────────────────────────────────────────────────────────── */

function sync_id_map_put(string $entity, string $uuid, int $localId): void {
    db_query(
        'INSERT INTO sync_id_map (entity, sync_uuid, local_id) VALUES (:e, :u, :l) ON CONFLICT (entity, sync_uuid) DO NOTHING',
        [':e' => $entity, ':u' => $uuid, ':l' => $localId]
    );
}

/**
 * Record a conflict. Never discard the losing side silently (S§6): both sides
 * are stored, `resolution` says what the applier did, and resolved_at stays
 * NULL until someone reviews it on the sync dashboard.
 */
function sync_log_conflict(string $entity, string $uuid, int $lv, int $rv, array $local, array $remote, string $resolution): void {
    db_query(
        'INSERT INTO sync_conflicts (entity, sync_uuid, local_version, remote_version, local_data, remote_data, resolution)
         VALUES (:e, :u, :lv, :rv, :ld, :rd, :res)',
        [
            ':e' => $entity, ':u' => $uuid, ':lv' => $lv, ':rv' => $rv,
            ':ld' => json_encode($local, JSON_UNESCAPED_UNICODE), ':rd' => json_encode($remote, JSON_UNESCAPED_UNICODE),
            ':res' => mb_substr($resolution, 0, 24),
        ]
    );
}

/** The synced venue's id (reservations Zuri creates belong to it), or 0. */
function sync_apply_venue_id(): int {
    static $id = null;
    if ($id === null) {
        $id = (int) (db_query('SELECT id FROM venues WHERE slug = :s', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
    }
    return $id;
}

/** Build "SET a = :c0, b = :c1" + params from a column map. Column names are ours (never from the wire). */
function sync_apply_set_sql(array $cols): array {
    $set = []; $p = []; $i = 0;
    foreach ($cols as $col => $v) {
        $set[] = "{$col} = :c{$i}";
        $p[":c{$i}"] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
        $i++;
    }
    return [implode(', ', $set), $p];
}

/** An outcome. status: applied | rejected | wait. */
function sync_apply_result(string $status, ?string $error = null): array {
    return ['status' => $status, 'error' => $error];
}

/**
 * Run the resolver for a known record and turn it into an action:
 * returns ['apply' => bool, 'result' => outcome|null]. result is set when the
 * event is finished without writing (stale / identical / local won).
 */
function sync_apply_decide(string $entity, string $uuid, array $local, array $remote): array {
    $d = sync_resolve($entity, $local, $remote);
    switch ($d['decision']) {
        case 'apply':
            return ['apply' => true, 'result' => null];
        case 'stale':
            return ['apply' => false, 'result' => sync_apply_result('rejected', 'stale_version')];
        case 'noop':
            return ['apply' => false, 'result' => sync_apply_result('applied', $d['reason'] === 'identical' ? null : 'ignored: ' . $d['reason'])];
        default:   // conflict
            sync_log_conflict($entity, $uuid, (int) $local['version'], (int) $remote['version'],
                              $local['data'], $remote['data'], $d['reason']);
            return $d['winner'] === 'remote'
                ? ['apply' => true, 'result' => null]
                : ['apply' => false, 'result' => sync_apply_result('applied', 'conflict: local kept (' . $d['reason'] . ')')];
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Per-entity appliers. Each takes a validated envelope and returns an outcome.
 * They run inside the caller's transaction and SyncContext::applying().
 * ───────────────────────────────────────────────────────────────────────── */

function sync_apply_item_availability(array $ev): array {
    $uuid = (string) $ev['sync_uuid'];
    $row = db_query(
        'SELECT id, is_sold_out, sold_out_version, sync_updated_at FROM menu_items WHERE sync_uuid = :u',
        [':u' => $uuid]
    )->fetch();
    if (!$row) return sync_apply_result('rejected', 'unknown_record: no menu item with that sync_uuid');

    $data = (array) ($ev['data'] ?? []);
    if (($ev['operation'] ?? '') === 'delete') $data = ['is_available' => true];   // availability record gone = not sold out
    if (!array_key_exists('is_available', $data) || !is_bool($data['is_available'])) {
        return sync_apply_result('rejected', 'invalid_payload: is_available (boolean) is required');
    }
    $local  = ['version' => (int) $row['sold_out_version'], 'data' => ['is_available' => !$row['is_sold_out']],
               'occurred_at' => (string) $row['sync_updated_at']];
    $remote = ['version' => (int) $ev['version'], 'data' => ['is_available' => $data['is_available']],
               'occurred_at' => $ev['occurred_at'] ?? null];
    $act = sync_apply_decide('item_availability', $uuid, $local, $remote);
    if (!$act['apply']) return $act['result'];

    $soldOut = !$data['is_available'];
    db_query(
        "UPDATE menu_items
            SET is_sold_out = :so,
                sold_out_at = CASE WHEN :so2 THEN COALESCE(sold_out_at, now()) ELSE NULL END,
                sold_out_version = :v
          WHERE id = :id",
        [':so' => $soldOut ? 'true' : 'false', ':so2' => $soldOut ? 'true' : 'false', ':v' => (int) $ev['version'], ':id' => (int) $row['id']]
    );
    return sync_apply_result('applied');
}

function sync_apply_customer(array $ev): array {
    $uuid = (string) $ev['sync_uuid'];
    $op   = (string) $ev['operation'];
    $row  = db_query('SELECT * FROM customers WHERE sync_uuid = :u', [':u' => $uuid])->fetch() ?: null;

    if ($op === 'delete') {
        if ($row) {
            db_query('UPDATE customers SET is_deleted = TRUE, sync_version = GREATEST(sync_version, :v), sync_last_at = now(), updated_at = now() WHERE id = :id',
                     [':v' => (int) $ev['version'], ':id' => (int) $row['id']]);
        }
        return sync_apply_result('applied', $row ? null : 'ignored: unknown record');
    }

    $data = (array) ($ev['data'] ?? []);
    if (!$row) {
        $version = (int) $ev['version'];
        if (!array_key_exists('name', $data)) {                    // partial update for a record we lack → pull it
            $pulled = sync_pull_record('customer', $uuid);
            if (!$pulled) return sync_apply_result('wait', 'waiting: pull of unknown customer failed');
            $data = (array) ($pulled['data'] ?? []) + $data;
            $version = max($version, (int) ($pulled['version'] ?? 0));
        }
        $m = sync_apply_map_customer($data);
        if ($m['errors'] || !isset($m['cols']['name'])) {
            return sync_apply_result('rejected', 'invalid_payload: ' . implode(', ', $m['errors'] ?: ['name is required']));
        }
        $id = (int) db_query(
            "INSERT INTO customers (sync_uuid, name, phone, email, notes, sync_version, sync_source, sync_last_at)
             VALUES (:u, :n, :p, :e, :notes, :v, 'zuri', now()) RETURNING id",
            [':u' => $uuid, ':n' => $m['cols']['name'], ':p' => $m['cols']['phone'] ?? null, ':e' => $m['cols']['email'] ?? null,
             ':notes' => $m['cols']['notes'] ?? null, ':v' => max(1, $version)]
        )->fetchColumn();
        sync_id_map_put('customer', $uuid, $id);
        return sync_apply_result('applied');
    }

    $m = sync_apply_map_customer($data);
    if ($m['errors']) return sync_apply_result('rejected', 'invalid_payload: ' . implode(', ', $m['errors']));
    $localData = [];
    foreach (array_keys($m['cols']) as $col) $localData[$col] = $row[$col] ?? null;
    $act = sync_apply_decide('customer', $uuid,
        ['version' => (int) $row['sync_version'], 'data' => $localData, 'occurred_at' => (string) $row['sync_updated_at']],
        ['version' => (int) $ev['version'], 'data' => $m['cols'], 'occurred_at' => $ev['occurred_at'] ?? null]);
    if (!$act['apply']) return $act['result'];

    [$set, $p] = sync_apply_set_sql($m['cols'] + ['sync_version' => (int) $ev['version'], 'is_deleted' => false]);
    db_query("UPDATE customers SET {$set}, sync_last_at = now(), updated_at = now() WHERE id = :id", $p + [':id' => (int) $row['id']]);
    return sync_apply_result('applied');
}

function sync_apply_reservation(array $ev): array {
    $uuid = (string) $ev['sync_uuid'];
    $op   = (string) $ev['operation'];
    $row  = db_query('SELECT * FROM reservations WHERE sync_uuid = :u', [':u' => $uuid])->fetch() ?: null;

    if ($op === 'delete') {
        if ($row) {
            db_query('UPDATE reservations SET is_deleted = TRUE, sync_version = GREATEST(sync_version, :v), sync_last_at = now(), updated_at = now() WHERE id = :id',
                     [':v' => (int) $ev['version'], ':id' => (int) $row['id']]);
        }
        return sync_apply_result('applied', $row ? null : 'ignored: unknown record');
    }

    $data    = (array) ($ev['data'] ?? []);
    $version = (int) $ev['version'];

    if (!$row && !(isset($data['date'], $data['time'], $data['guests']))) {
        // An update for a booking we have never seen: pull the full record first.
        $pulled = sync_pull_record('reservation', $uuid);
        if (!$pulled) return sync_apply_result('wait', 'waiting: pull of unknown reservation failed');
        $data = $data + (array) ($pulled['data'] ?? []);
        $version = max($version, (int) ($pulled['version'] ?? 0));
    }

    // A booking we created: Zuri may only change the shared/assigned fields.
    $ignored = [];
    if ($row && ($row['sync_source'] ?? '') === sync_self_source()) {
        $allowed = array_flip(sync_apply_reservation_peer_keys_on_ours());
        foreach (array_keys($data) as $k) if (!isset($allowed[$k])) { $ignored[] = $k; unset($data[$k]); }
    }

    // References by sync_uuid. The customer is Zuri's and may not have arrived
    // yet (ordering race) — wait for it. Tables are ours; an unknown table is
    // dropped rather than blocking the booking.
    $customerId = null; $tableId = null; $refCols = [];
    $custUuid  = array_key_exists('customer_uuid', $data) ? (string) ($data['customer_uuid'] ?? '') : null;
    $tableUuid = array_key_exists('table_uuid', $data)    ? (string) ($data['table_uuid'] ?? '')    : null;
    if ($custUuid !== null && $custUuid !== '') {
        $customer = db_query('SELECT id, name, phone, email FROM customers WHERE sync_uuid = :u', [':u' => $custUuid])->fetch();
        if (!$customer) return sync_apply_result('wait', 'waiting: customer ' . $custUuid);
        $customerId = (int) $customer['id'];
        $refCols['customer_id'] = $customerId;
        $refCols['guest_name']  = (string) ($customer['name'] ?: 'Guest');
        $refCols['guest_phone'] = (string) ($customer['phone'] ?? '');
        $refCols['guest_email'] = $customer['email'] ?: null;
        if ($row && ($row['sync_source'] ?? '') === sync_self_source()) {
            unset($refCols['guest_name'], $refCols['guest_phone'], $refCols['guest_email']);   // our guest details stand
        }
    } elseif ($custUuid === '') {
        $refCols['customer_id'] = null;
    }
    if ($tableUuid !== null) {
        $tableId = $tableUuid === '' ? null
            : ((int) (db_query('SELECT id FROM restaurant_tables WHERE sync_uuid = :u', [':u' => $tableUuid])->fetchColumn() ?: 0) ?: null);
        $refCols['table_id'] = $tableId;
    }

    $m = sync_apply_map_reservation($data);
    if ($m['errors']) return sync_apply_result('rejected', 'invalid_payload: ' . implode(', ', $m['errors']));
    $cols = $m['cols'];

    if (!$row) {
        $venueId = sync_apply_venue_id();
        if (!$venueId) return sync_apply_result('rejected', 'no local venue "' . sync_venue_slug() . '"');
        foreach (['reservation_date', 'reservation_time', 'party_size'] as $req) {
            if (!isset($cols[$req])) return sync_apply_result('rejected', 'invalid_payload: date, time and guests are required');
        }
        $ins = $cols + $refCols + [
            'venue_id' => $venueId, 'status' => 'pending', 'source' => 'zuri',
            'guest_name' => 'Guest', 'guest_phone' => '',
            'sync_uuid' => $uuid, 'sync_version' => max(1, $version), 'sync_source' => sync_peer_source(),
        ];
        $names = array_keys($ins);
        $ph    = array_map(fn($i) => ':c' . $i, array_keys($names));
        $p     = [];
        foreach (array_values($ins) as $i => $v) $p[':c' . $i] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
        $id = (int) db_query(
            'INSERT INTO reservations (' . implode(', ', $names) . ', sync_last_at) VALUES (' . implode(', ', $ph) . ', now()) RETURNING id',
            $p
        )->fetchColumn();
        sync_id_map_put('reservation', $uuid, $id);
        return sync_apply_result('applied');
    }

    // Terminal never revives (S§6) — checked before any version argument.
    $from = (string) $row['status'];
    $to   = (string) ($cols['status'] ?? $from);
    if ($to !== $from && sync_status_is_terminal($from)) {
        sync_log_conflict('reservation', $uuid, (int) $row['sync_version'], $version,
                          ['status' => $from], $data, 'terminal_state');
        return sync_apply_result('applied', "ignored: terminal_state ($from → $to)");
    }

    // Version / equality over the keys this event carries. Status moves out of
    // a non-terminal state are accepted as Zuri made them (it runs the booking
    // flow; a walk-in may skip `confirmed`), so the strict edge check is
    // bypassed here — only the terminal rule above is load-bearing.
    $keys = array_keys($data);
    $localData  = sync_apply_reservation_local_data($row, $keys,
        $row['customer_id'] ? (string) (db_query('SELECT sync_uuid FROM customers WHERE id = :i', [':i' => (int) $row['customer_id']])->fetchColumn() ?: '') : null,
        $row['table_id'] ? (string) (db_query('SELECT sync_uuid FROM restaurant_tables WHERE id = :i', [':i' => (int) $row['table_id']])->fetchColumn() ?: '') : null);
    $remoteData = array_intersect_key($data, array_flip(array_merge(array_keys(sync_apply_reservation_keymap()), ['customer_uuid', 'table_uuid'])));
    $act = sync_apply_decide('reservation', $uuid,
        ['version' => (int) $row['sync_version'], 'data' => $localData, 'occurred_at' => (string) $row['sync_updated_at']],
        ['version' => $version, 'data' => $remoteData, 'occurred_at' => $ev['occurred_at'] ?? null]);
    if (!$act['apply']) return $act['result'];

    unset($cols['created_at']);   // creation time never moves
    [$set, $p] = sync_apply_set_sql($cols + $refCols + ['sync_version' => $version, 'is_deleted' => false]);
    db_query("UPDATE reservations SET {$set}, sync_last_at = now(), updated_at = now() WHERE id = :id", $p + [':id' => (int) $row['id']]);
    return sync_apply_result('applied', $ignored ? 'ignored not_owner fields: ' . implode(', ', $ignored) : null);
}

/** Route one validated inbound envelope to its applier. */
function sync_apply_event(array $ev): array {
    $why = sync_validate_event($ev);
    if ($why !== '') return sync_apply_result('rejected', 'invalid_payload: ' . $why);
    if (($ev['source'] ?? '') !== sync_peer_source()) return sync_apply_result('rejected', 'bad source');
    return match ((string) $ev['entity']) {
        'item_availability' => sync_apply_item_availability($ev),
        'customer'          => sync_apply_customer($ev),
        'reservation'       => sync_apply_reservation($ev),
        default             => sync_apply_result('rejected', 'unsupported_entity'),
    };
}

/** Back-off before re-trying a waiting event: 10s, 20s, 40s, 80s … */
function sync_apply_backoff(int $attempts): int {
    return 10 * (2 ** max(0, $attempts - 1));
}

/**
 * Per-event transaction. Joins a caller's transaction through a SAVEPOINT (the
 * tests wrap everything in one they roll back, and PDO/pgsql cannot nest), else
 * a real transaction — the same rule as rates_apply_ranges().
 */
function sync_apply_tx(string $step, bool $outer): void {
    match ($step) {
        'begin'    => $outer ? db()->exec('SAVEPOINT sync_apply_ev') : db()->beginTransaction(),
        'commit'   => $outer ? db()->exec('RELEASE SAVEPOINT sync_apply_ev') : db()->commit(),
        'rollback' => $outer ? db()->exec('ROLLBACK TO SAVEPOINT sync_apply_ev') : (db()->inTransaction() ? db()->rollBack() : null),
    };
}

/**
 * Drain eligible pending inbox rows, oldest first. Each event is applied in its
 * own transaction, together with its inbox status. Returns counts.
 */
function sync_apply_pending(int $limit = 500): array {
    $stats = ['applied' => 0, 'rejected' => 0, 'waiting' => 0, 'held' => 0];
    if (!sync_applier_supported()) return $stats;
    $outer = db()->inTransaction();

    // Every pending row, ready or backing off, in arrival order: a row still
    // backing off must hold back the LATER rows for the same record, or an
    // update could apply before the create it follows.
    $rows = db_query(
        "SELECT id, entity, sync_uuid, payload, attempts,
                (next_retry_at IS NULL OR next_retry_at <= now()) AS ready
           FROM sync_inbox
          WHERE status = 'pending'
          ORDER BY id ASC LIMIT " . max(1, $limit)
    )->fetchAll();

    $blocked = [];   // sync_uuid => true: a record whose earlier event is waiting
    foreach ($rows as $r) {
        $uuid = (string) $r['sync_uuid'];
        if (isset($blocked[$uuid])) { $stats['held']++; continue; }   // keep per-record order
        if (!$r['ready'] || $r['ready'] === 'f') { $blocked[$uuid] = true; continue; }   // still backing off
        $ev = json_decode((string) $r['payload'], true);
        $attempts = (int) $r['attempts'] + 1;

        sync_apply_tx('begin', $outer);
        try {
            $res = is_array($ev)
                ? SyncContext::applying(fn() => sync_apply_event($ev))
                : sync_apply_result('rejected', 'invalid_payload: not JSON');
        } catch (Throwable $e) {
            sync_apply_tx('rollback', $outer);   // drop the half-applied write …
            if (!$outer) sync_apply_tx('begin', $outer);   // … and record the failure on its own
            $res = sync_apply_result('wait', 'error: ' . mb_substr($e->getMessage(), 0, 300));
        }

        if ($res['status'] === 'wait') {
            $blocked[$uuid] = true;
            if ($attempts >= SYNC_APPLY_MAX_ATTEMPTS) {
                $res = sync_apply_result('rejected', 'missing_reference after ' . $attempts . ' tries — ' . $res['error']);
            } else {
                db_query(
                    "UPDATE sync_inbox SET attempts = :a, error = :e, next_retry_at = now() + (:d || ' seconds')::interval WHERE id = :id",
                    [':a' => $attempts, ':e' => $res['error'], ':d' => (string) sync_apply_backoff($attempts), ':id' => (int) $r['id']]
                );
                sync_apply_tx('commit', $outer);
                $stats['waiting']++;
                continue;
            }
        }
        db_query(
            'UPDATE sync_inbox SET status = :s, error = :e, attempts = :a, applied_at = now() WHERE id = :id',
            [':s' => $res['status'], ':e' => $res['error'], ':a' => $attempts, ':id' => (int) $r['id']]
        );
        sync_apply_tx('commit', $outer);
        $stats[$res['status'] === 'applied' ? 'applied' : 'rejected']++;
        if ($res['status'] === 'rejected') {
            error_log('[sync-apply] rejected ' . $r['entity'] . ' ' . $uuid . ': ' . $res['error']);
        }
    }
    return $stats;
}
