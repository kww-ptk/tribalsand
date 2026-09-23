<?php
declare(strict_types=1);
/**
 * Booking a Zuri table from Tribalsand — Zuri's POST /sync/v1/reserve (agreed:
 * a Tribalsand booking is NEVER sent as a direct create event; Zuri owns the
 * seat inventory and answers synchronously).
 *
 *   reservation_book($data)  the ONE entry point for a new booking. For the
 *                            synced venue with SYNC_RESERVATIONS on, it asks
 *                            Zuri first; every other venue (and sync off) takes
 *                            the old local create_reservation() path unchanged.
 *
 * Outcomes:
 *   201 → we store the booking locally with Zuri's reference/status/table, our
 *         minted sync_uuid and sync_source='tribalsand' (we are its creator), and
 *         sync_last_at set (it exists on Zuri, so later status changes emit).
 *   409 slot_unavailable → nothing is stored; the caller shows `alternatives`.
 *   anything else (Zuri down, 5xx, 400) → saved as a LOCAL pending request so a
 *         guest's booking is never lost, flagged in staff_notes for staff to
 *         enter on Zuri, and an ALERT is logged.
 *
 * Contract (docs/sync-contract.json, api.endpoints."POST /sync/v1/reserve"):
 * body {date, time, guests, customer{name,phone,email}, preference, requests,
 * notes, sync_uuid, external_id}; Idempotency-Key replays the first answer 24h.
 * The HTTP call goes through sync_peer_request() (function_exists-guarded).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/sync-mappers.php';
require_once __DIR__ . '/sync-apply.php';
require_once __DIR__ . '/reservations.php';
require_once __DIR__ . '/restaurant-tables.php';

/** Should a booking for $venueId go through Zuri's /reserve? */
function sync_reserve_route_enabled(int $venueId): bool {
    if (!sync_reservations_enabled() || !sync_applier_supported()) return false;
    return $venueId > 0 && $venueId === sync_apply_venue_id();
}

/**
 * The /reserve request body. Pure. $b uses our create_reservation() keys.
 * Optional fields are left out rather than sent empty.
 */
function sync_reserve_body(array $b, string $syncUuid, string $externalId): array {
    $customer = ['name' => trim((string) ($b['guest_name'] ?? '')), 'phone' => trim((string) ($b['guest_phone'] ?? ''))];
    $email = trim((string) ($b['guest_email'] ?? ''));
    if ($email !== '') $customer['email'] = $email;
    $body = [
        'date'        => (string) ($b['reservation_date'] ?? ''),
        'time'        => substr((string) ($b['reservation_time'] ?? ''), 0, 5),
        'guests'      => (int) ($b['party_size'] ?? 0),
        'customer'    => $customer,
        'sync_uuid'   => $syncUuid,
        'external_id' => $externalId,
    ];
    foreach (['preference' => 'preference', 'requests' => 'notes', 'notes' => 'staff_notes'] as $out => $in) {
        $v = trim((string) ($b[$in] ?? ''));
        if ($v !== '') $body[$out] = $v;
    }
    return $body;
}

/**
 * Zuri's `alternatives` → a list of ['date' => ?string, 'time' => 'HH:MM'].
 * Pure. Accepts plain "HH:MM" strings or objects carrying time (+ date);
 * anything unrecognisable is dropped.
 */
function sync_reserve_alternatives(mixed $alts): array {
    $out = [];
    foreach (is_array($alts) ? $alts : [] as $a) {
        $date = null; $time = null;
        if (is_string($a)) {
            $time = $a;
        } elseif (is_array($a)) {
            $time = $a['time'] ?? $a['slot'] ?? null;
            $date = isset($a['date']) && is_string($a['date']) ? $a['date'] : null;
        }
        if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $time)) continue;
        $out[] = ['date' => $date, 'time' => substr($time, 0, 5)];
    }
    return $out;
}

/** "19:00, 20:30" — or "Sat 4 Oct 19:00, …" when an alternative is on another day. */
function sync_reserve_alternatives_label(array $alts, string $forDate = ''): string {
    $parts = [];
    foreach ($alts as $a) {
        $t = reservation_time_label($a['time']);
        $parts[] = ($a['date'] && $a['date'] !== $forDate) ? date('D j M', strtotime($a['date'])) . ' ' . $t : $t;
    }
    return implode(', ', $parts);
}

/**
 * Call /reserve. Returns
 *   ['ok' => true,  'reservation' => array]
 *   ['ok' => false, 'code' => 'slot_unavailable', 'alternatives' => [...]]
 *   ['ok' => false, 'code' => 'invalid_payload'|'unreachable'|'http_<n>', 'message' => string]
 */
function sync_reserve_call(array $body, string $idempotencyKey): array {
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    [$code, $resp] = sync_peer_request('POST', '/reserve', (string) $raw, ['Idempotency-Key: ' . $idempotencyKey]);
    if ($code === 201 && is_array($resp['reservation'] ?? null)) {
        return ['ok' => true, 'reservation' => $resp['reservation']];
    }
    if ($code === 409) {
        return ['ok' => false, 'code' => 'slot_unavailable', 'alternatives' => sync_reserve_alternatives($resp['alternatives'] ?? [])];
    }
    if ($code === 400) {
        return ['ok' => false, 'code' => 'invalid_payload', 'message' => (string) ($resp['message'] ?? $resp['error'] ?? '')];
    }
    return ['ok' => false, 'code' => $code === 0 ? 'unreachable' : 'http_' . $code, 'message' => (string) ($resp['error'] ?? '')];
}

/** A table reference in Zuri's answer (uuid string or {sync_uuid}) → our table id, or null. */
function sync_reserve_table_id(mixed $table): ?int {
    $uuid = is_array($table) ? ($table['sync_uuid'] ?? $table['uuid'] ?? null) : $table;
    if (!is_string($uuid) || !preg_match('/^[0-9a-f-]{36}$/i', $uuid) || !rtables_supported()) return null;
    $id = (int) (db_query('SELECT id FROM restaurant_tables WHERE sync_uuid = :u', [':u' => $uuid])->fetchColumn() ?: 0);
    return $id ?: null;
}

/** Store a booking Zuri accepted (201). Returns the local row. */
function sync_reserve_store(array $b, string $syncUuid, string $externalId, array $z): array {
    $m = sync_apply_map_reservation(array_intersect_key($z, array_flip(['status', 'duration_minutes', 'reference'])));
    $cols = $m['errors'] ? [] : $m['cols'];
    $customerId = null;
    if (!empty($z['customer_uuid']) && customers_supported()) {
        $customerId = (int) (db_query('SELECT id FROM customers WHERE sync_uuid = :u', [':u' => (string) $z['customer_uuid']])->fetchColumn() ?: 0) ?: null;
    }
    $id = (int) db_query(
        "INSERT INTO reservations
            (venue_id, menu_id, reference, external_id, reservation_date, reservation_time, party_size,
             guest_name, guest_phone, guest_email, notes, staff_notes, preference, status, source, client_ip,
             duration_minutes, table_id, customer_id,
             sync_uuid, sync_version, sync_source, sync_last_at)
         VALUES
            (:venue, :menu, :ref, :ext, :date, :time, :party,
             :name, :phone, :email, :notes, :snotes, :pref, :status, :source, :ip,
             :dur, :tbl, :cust,
             :uuid, 1, 'tribalsand', now())
         RETURNING id",
        [
            ':venue' => (int) $b['venue_id'], ':menu' => !empty($b['menu_id']) ? (int) $b['menu_id'] : null,
            ':ref' => $cols['reference'] ?? $externalId, ':ext' => $externalId,
            ':date' => (string) $b['reservation_date'], ':time' => substr((string) $b['reservation_time'], 0, 5),
            ':party' => max(1, (int) $b['party_size']),
            ':name' => trim((string) $b['guest_name']), ':phone' => trim((string) $b['guest_phone']),
            ':email' => trim((string) ($b['guest_email'] ?? '')) ?: null,
            ':notes' => trim((string) ($b['notes'] ?? '')) ?: null,
            ':snotes' => trim((string) ($b['staff_notes'] ?? '')) ?: null,
            ':pref' => trim((string) ($b['preference'] ?? '')) ?: null,
            ':status' => $cols['status'] ?? 'pending', ':source' => (string) ($b['source'] ?? 'web'), ':ip' => client_ip(),
            ':dur' => $cols['duration_minutes'] ?? null, ':tbl' => sync_reserve_table_id($z['table'] ?? $z['table_uuid'] ?? null),
            ':cust' => $customerId, ':uuid' => $syncUuid,
        ]
    )->fetchColumn();
    sync_id_map_put('reservation', $syncUuid, $id);
    return fetch_reservation($id) ?? [];
}

/**
 * Book a table. The ONE entry point for new bookings. Returns
 *   ['ok' => true,  'reservation' => row, 'via' => 'local'|'zuri'|'local_fallback', 'warning' => ?string]
 *   ['ok' => false, 'code' => 'slot_unavailable', 'alternatives' => [...]]
 */
function reservation_book(array $b): array {
    $venueId = (int) ($b['venue_id'] ?? 0);
    if (!sync_reserve_route_enabled($venueId)) {
        return ['ok' => true, 'reservation' => create_reservation($b), 'via' => 'local', 'warning' => null];
    }

    $uuid       = sync_new_uuid();
    $externalId = 'TSR-' . $venueId . '-' . generate_access_code(6);   // ours, echoed back by Zuri
    $r = sync_reserve_call(sync_reserve_body($b, $uuid, $externalId), $uuid);

    if ($r['ok']) {
        try {
            return ['ok' => true, 'reservation' => sync_reserve_store($b, $uuid, $externalId, $r['reservation']), 'via' => 'zuri', 'warning' => null];
        } catch (Throwable $e) {
            // Zuri holds the table; its reservation event will re-create our copy
            // (the applier inserts an unknown uuid). Log loudly, don't lose the guest.
            error_log('[sync] ALERT /reserve 201 but local store failed for ' . $uuid . ': ' . $e->getMessage());
            return ['ok' => true, 'reservation' => ['reference' => (string) ($r['reservation']['reference'] ?? $externalId)] + $b,
                    'via' => 'zuri', 'warning' => 'Booked on Zuri; the local copy will arrive with the next sync.'];
        }
    }
    if ($r['code'] === 'slot_unavailable') return $r;

    error_log('[sync] ALERT /reserve failed (' . $r['code'] . ' ' . ($r['message'] ?? '') . ') — saved as a local request');
    $note = 'NOT ON ZURI YET — /reserve failed (' . $r['code'] . '). Enter this booking on Zuri.';
    $row  = create_reservation($b);
    if ($row && sync_applier_supported()) {
        db_query('UPDATE reservations SET staff_notes = :n WHERE id = :id', [':n' => $note, ':id' => (int) $row['id']]);
        $row['staff_notes'] = $note;
    }
    return ['ok' => true, 'reservation' => $row, 'via' => 'local_fallback', 'warning' => $note];
}
