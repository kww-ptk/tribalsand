<?php
/**
 * Restaurant reservation helpers (DB-driven, per property, request model).
 *
 * Data model (migration: add_reservations.sql):
 *   reservations — venue-scoped table requests, optional menu_id link.
 *   status: pending → confirmed | cancelled (staff eyeball availability; v1 has
 *   no table-capacity / double-booking logic).
 *
 * All reads are pre-migration-safe via reservations_supported(): pages that call
 * these before the migration has run get empty results instead of a fatal.
 */

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';   // reservation state machine (§6): sync_reservation_states / _transition_allowed

/** True if the reservations table exists (memoised). False pre-migration. */
function reservations_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.reservations')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * True once add_sync_applier.sql has added the contract columns (preference,
 * staff_notes, cancellation_reason, confirmed_at/seated_at/cancelled_at …).
 * An information_schema lookup — set_reservation_status() can run inside a
 * transaction, where a failing SELECT would abort it.
 */
function reservations_extended_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'reservations' AND column_name = 'staff_notes'"
        )->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

/** Status → label for staff ("No-show", not "No_show"). */
function reservation_status_label(string $status): string {
    return match ($status) {
        'no_show' => 'No-show',
        default   => ucfirst($status),
    };
}

/** Service-time options for the styled select: 12:00–22:00 in 30-min steps → [value=>label]. */
function reservation_slots(): array {
    $out = [];
    for ($m = 12 * 60; $m <= 22 * 60; $m += 30) {
        $h = intdiv($m, 60); $mi = $m % 60;
        $val = sprintf('%02d:%02d', $h, $mi);
        // 12-hour label, e.g. "12:30 PM"
        $ampm = $h < 12 ? 'AM' : 'PM';
        $h12  = $h % 12; if ($h12 === 0) $h12 = 12;
        $out[$val] = sprintf('%d:%02d %s', $h12, $mi, $ampm);
    }
    return $out;
}

/** Largest party the request form (and the integration API) will accept. */
function reservation_max_party(): int { return 30; }

/**
 * Validate a reservation request. ONE validator, shared by the website form
 * (api/submit-reservation.php) and the partner integration API
 * (api/reservation-api.php) — a partner site must not be able to create a
 * booking the website itself would have rejected.
 *
 * $in keys: venue_id, reservation_date, reservation_time, party_size,
 * guest_name, guest_phone, guest_email. Returns [field => message]; empty = OK.
 * The caller is responsible for resolving/authorising the venue; pass the
 * resolved row (or false) as $venue.
 */
function reservation_validate(array $in, array|false $venue): array {
    $errors = [];

    if (!$venue || empty($venue['is_published'])) $errors['venue_id'] = 'Please choose a property.';

    $time = trim((string)($in['reservation_time'] ?? ''));
    if ($time === '' || !isset(reservation_slots()[$time])) {
        $errors['reservation_time'] = 'Please choose a time.';
    }

    $date   = trim((string)($in['reservation_date'] ?? ''));
    $dateTs = $date !== '' ? strtotime($date) : false;
    if ($date === '' || $dateTs === false) {
        $errors['reservation_date'] = 'Please choose a date.';
    } elseif (date('Y-m-d', $dateTs) < date('Y-m-d')) {
        $errors['reservation_date'] = 'Please choose today or a future date.';
    }

    $party = (int)($in['party_size'] ?? 0);
    if ($party < 1 || $party > reservation_max_party()) {
        $errors['party_size'] = 'Party size must be between 1 and ' . reservation_max_party() . '.';
    }

    if (trim((string)($in['guest_name']  ?? '')) === '') $errors['guest_name']  = 'A name is required.';
    if (trim((string)($in['guest_phone'] ?? '')) === '') $errors['guest_phone'] = 'A phone number is required.';

    $email = trim((string)($in['guest_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['guest_email'] = 'Please enter a valid email, or leave it blank.';
    }

    return $errors;
}

/**
 * The venue's first published menu id, or null. Soft link only — used to tie a
 * reservation to the menu the guest was looking at.
 */
function reservation_menu_id_for_venue(int $venueId): ?int {
    if ($venueId <= 0) return null;
    try {
        if (!db_query("SELECT to_regclass('public.menus')")->fetchColumn()) return null;
        $id = db_query(
            'SELECT id FROM menus WHERE venue_id = :v AND is_published = TRUE ORDER BY sort_order, id LIMIT 1',
            [':v' => $venueId]
        )->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

/** True when this IP has hit the reservation cap (5 / 10 min). Fails OPEN on a read error. */
function reservation_rate_limited(string $ip, int $max = 5): bool {
    if (!reservations_supported()) return false;
    try {
        $n = (int) db_query(
            "SELECT COUNT(*) FROM reservations WHERE client_ip = :ip AND created_at > now() - interval '10 minutes'",
            [':ip' => $ip]
        )->fetchColumn();
        return $n >= $max;
    } catch (Throwable $e) { return false; }
}

/** One reservation by its public reference (TSR-…), or null. */
function fetch_reservation_by_reference(string $reference): ?array {
    if (!reservations_supported() || trim($reference) === '') return null;
    try {
        $row = db_query(
            "SELECT r.*, v.name AS venue_name, v.slug AS venue_slug
               FROM reservations r
               LEFT JOIN venues v ON v.id = r.venue_id
              WHERE r.reference = :r",
            [':r' => trim($reference)]
        )->fetch();
    } catch (Throwable $e) { return null; }
    return $row ?: null;
}

/** Badge CSS class for a reservation status (matches admin badge--* palette). */
function reservation_status_badge(string $status): string {
    return match ($status) {
        'confirmed' => 'badge--green',
        'seated'    => 'badge--blue',
        'completed' => 'badge--grey',
        'cancelled' => 'badge--red',
        'no_show'   => 'badge--purple',
        default     => 'badge--orange',   // pending
    };
}

/** Published venues offered on the public reservation form: [id,slug,name,location,menu_id]. */
function fetch_reservable_venues(): array {
    if (!reservations_supported()) return [];
    // menu_id is the venue's first published menu (if any) — a soft link only.
    $menusExist = false;
    try { $menusExist = (bool) db_query("SELECT to_regclass('public.menus')")->fetchColumn(); }
    catch (Throwable $e) { $menusExist = false; }

    $menuSel = $menusExist
        ? "(SELECT m.id FROM menus m WHERE m.venue_id = v.id AND m.is_published = TRUE ORDER BY m.sort_order, m.id LIMIT 1)"
        : "NULL";

    return db_query(
        "SELECT v.id, v.slug, v.name, v.location, {$menuSel} AS menu_id
           FROM venues v
          WHERE v.is_published = TRUE
          ORDER BY v.sort_order, v.name"
    )->fetchAll();
}

/**
 * Create a pending reservation. $data keys: venue_id, menu_id (nullable),
 * reservation_date (Y-m-d), reservation_time (H:i), party_size, guest_name,
 * guest_phone, guest_email (nullable), notes (nullable), source (default 'web').
 * Mints a unique reference (TSR-<venue>-<rand>). Returns the inserted row.
 */
function create_reservation(array $data): array {
    $venueId = (int)($data['venue_id'] ?? 0);
    $menuId  = !empty($data['menu_id']) ? (int)$data['menu_id'] : null;

    $params = [
        ':venue' => $venueId,
        ':menu'  => $menuId,
        ':date'  => (string)($data['reservation_date'] ?? ''),
        ':time'  => (string)($data['reservation_time'] ?? ''),
        ':party' => max(1, (int)($data['party_size'] ?? 1)),
        ':name'  => trim((string)($data['guest_name'] ?? '')),
        ':phone' => trim((string)($data['guest_phone'] ?? '')),
        ':email' => trim((string)($data['guest_email'] ?? '')) ?: null,
        ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
        ':source'=> (string)($data['source'] ?? 'web'),
        ':ip'    => client_ip(),
    ];

    // Once synced, a row we create is OURS: the column defaults to 'zuri' (most
    // bookings originate there), so stamp it — the applier uses sync_source to
    // decide which fields Zuri may change. Preference / staff notes only exist
    // after add_sync_applier.sql.
    $extraCols = ''; $extraVals = '';
    if (sync_supported()) { $extraCols .= ', sync_source'; $extraVals .= ", 'tribalsand'"; }
    if (reservations_extended_supported()) {
        $extraCols .= ', preference, staff_notes';
        $extraVals .= ', :pref, :snotes';
        $params[':pref']   = trim((string)($data['preference'] ?? '')) ?: null;
        $params[':snotes'] = trim((string)($data['staff_notes'] ?? '')) ?: null;
    }

    // Insert first (reference NULL), then mint + set the reference so we can key
    // it off the real id. Retry the reference on the rare unique collision.
    $stmt = db()->prepare(
        "INSERT INTO reservations
            (venue_id, menu_id, reservation_date, reservation_time, party_size,
             guest_name, guest_phone, guest_email, notes, source, client_ip{$extraCols})
         VALUES
            (:venue, :menu, :date, :time, :party,
             :name, :phone, :email, :notes, :source, :ip{$extraVals})
         RETURNING id"
    );
    $stmt->execute($params);
    $id = (int)$stmt->fetchColumn();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $ref = 'TSR-' . $venueId . '-' . generate_access_code(5);
        try {
            db_query('UPDATE reservations SET reference = :r WHERE id = :id', [':r' => $ref, ':id' => $id]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && $attempt < 4) continue;
            throw $e;
        }
    }

    return fetch_reservation($id) ?? [];
}

/** One reservation row (joined with venue name), or null. */
function fetch_reservation(int $id): ?array {
    if (!reservations_supported() || $id <= 0) return null;
    $row = db_query(
        "SELECT r.*, v.name AS venue_name, v.slug AS venue_slug
           FROM reservations r
           LEFT JOIN venues v ON v.id = r.venue_id
          WHERE r.id = :id",
        [':id' => $id]
    )->fetch();
    return $row ?: null;
}

/**
 * Build the shared WHERE for a scoped + filtered reservation list.
 * $venueIds: null = owner/all; [] = scoped user with no venues (returns FALSE).
 * $filters: status, date (Y-m-d), venue_id, from (Y-m-d), to (Y-m-d).
 * Returns [sql, params] where sql includes the leading "WHERE ...".
 */
function _reservations_where(?array $venueIds, array $filters): array {
    $clauses = [];
    $params  = [];

    // A reservation Zuri deleted is soft-deleted (is_deleted exists once
    // add_restaurant_sync.sql has run); lists never show it.
    if (sync_supported()) $clauses[] = 'r.is_deleted = FALSE';

    if ($venueIds !== null) {
        if (!$venueIds) return ['WHERE FALSE', []];       // scoped, no venues → nothing
        $in = implode(',', array_map('intval', $venueIds));
        $clauses[] = "r.venue_id IN ($in)";
    }
    if (!empty($filters['venue_id'])) {
        $clauses[] = 'r.venue_id = :fvenue';
        $params[':fvenue'] = (int)$filters['venue_id'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], sync_reservation_states(), true)) {
        $clauses[] = 'r.status = :fstatus';
        $params[':fstatus'] = $filters['status'];
    }
    if (!empty($filters['date'])) {
        $clauses[] = 'r.reservation_date = :fdate';
        $params[':fdate'] = $filters['date'];
    }
    if (!empty($filters['from'])) {
        $clauses[] = 'r.reservation_date >= :ffrom';
        $params[':ffrom'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $clauses[] = 'r.reservation_date <= :fto';
        $params[':fto'] = $filters['to'];
    }

    $sql = $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';
    return [$sql, $params];
}

/**
 * Scoped + filtered reservation list, ordered by date then time.
 * $venueIds: null = owner/all. $limit/$offset for pagination (0 limit = all).
 */
function fetch_reservations(?array $venueIds, array $filters = [], int $limit = 0, int $offset = 0): array {
    if (!reservations_supported()) return [];
    [$where, $params] = _reservations_where($venueIds, $filters);
    $lim = $limit > 0 ? (' LIMIT ' . (int)$limit . ' OFFSET ' . max(0, $offset)) : '';
    return db_query(
        "SELECT r.*, v.name AS venue_name, v.slug AS venue_slug
           FROM reservations r
           LEFT JOIN venues v ON v.id = r.venue_id
           $where
          ORDER BY r.reservation_date DESC, r.reservation_time DESC, r.id DESC
           $lim",
        $params
    )->fetchAll();
}

/** Count of reservations matching a scope + filter (for the pager). */
function count_reservations(?array $venueIds, array $filters = []): int {
    if (!reservations_supported()) return 0;
    [$where, $params] = _reservations_where($venueIds, $filters);
    return (int) db_query("SELECT COUNT(*) FROM reservations r $where", $params)->fetchColumn();
}

/**
 * Dashboard counts for the admin surface, honouring scope:
 * today (upcoming today, not cancelled), upcoming (future, not cancelled), pending.
 */
function reservation_dashboard_counts(?array $venueIds): array {
    if (!reservations_supported()) return ['today' => 0, 'upcoming' => 0, 'pending' => 0];
    [$scope, $params] = _reservations_where($venueIds, []);
    $and = $scope !== '' ? (substr($scope, 5) . ' AND ') : '';   // strip leading "WHERE"
    $q = fn(string $extra) => (int) db_query(
        "SELECT COUNT(*) FROM reservations r WHERE {$and}{$extra}", $params
    )->fetchColumn();
    return [
        'today'    => $q("r.reservation_date = CURRENT_DATE AND r.status <> 'cancelled'"),
        'upcoming' => $q("r.reservation_date > CURRENT_DATE AND r.status <> 'cancelled'"),
        'pending'  => $q("r.status = 'pending'"),
    ];
}

/**
 * Transition a reservation's status, enforcing the §6 state machine (via
 * sync_reservation_transition_allowed). An illegal move — most importantly a
 * terminal status trying to revive (cancelled/no_show/completed → anything) —
 * returns false and changes nothing. Returns true on a real change.
 *
 * Once synced it also bumps sync_version, stamps the contract timestamp for the
 * new state (confirmed_at / seated_at / cancelled_at) and the cancellation
 * reason, and — when the booking exists on Zuri (reservation_on_zuri) — queues
 * a `reservation` update carrying ONLY status (+ cancellation_reason), in the
 * same transaction. Those two are the shared fields (S§6); anything else on a
 * Zuri-created booking would be rejected not_owner. Skipped while applying an
 * inbound change (sync_outbox_push's loop guard), so Zuri's own status moves are
 * never echoed back.
 */
function set_reservation_status(int $id, string $status, ?string $reason = null): bool {
    if (!reservations_supported() || $id <= 0) return false;
    if (!in_array($status, sync_reservation_states(), true)) return false;

    $current = db_query('SELECT status FROM reservations WHERE id = :id', [':id' => $id])->fetchColumn();
    if ($current === false) return false;                       // no such row
    if (!sync_reservation_transition_allowed((string) $current, $status)) return false;

    // Bump the sync columns only when they exist (post add_restaurant_sync);
    // pre-migration the reservations table has no sync_version, so keep the
    // original plain update and never reference the missing columns.
    $set    = 'status = :s, updated_at = now()';
    $params = [':s' => $status, ':id' => $id];
    if (sync_supported()) $set .= ', sync_version = sync_version + 1, sync_updated_at = now()';
    if (reservations_extended_supported()) {
        $stamp = ['confirmed' => 'confirmed_at', 'seated' => 'seated_at', 'cancelled' => 'cancelled_at'][$status] ?? null;
        if ($stamp) $set .= ", {$stamp} = now()";
        if ($status === 'cancelled') {
            $set .= ', cancellation_reason = :why';
            $params[':why'] = ($reason !== null && trim($reason) !== '') ? mb_substr(trim($reason), 0, 255) : null;
        }
    }

    $write = function () use ($set, $params, $id): bool {
        $n = db_query("UPDATE reservations SET $set WHERE id = :id AND status <> :s", $params)->rowCount();
        if ($n > 0) reservation_sync_emit_status($id);
        return $n > 0;
    };
    if (!sync_supported() || db()->inTransaction()) return $write();
    db()->beginTransaction();
    try { $ok = $write(); db()->commit(); return $ok; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}

/**
 * Does this booking exist on Zuri? True once it came FROM Zuri (the applier
 * sets sync_last_at) or was accepted by Zuri's /reserve (also sets it) — and it
 * belongs to the synced venue. A local-only request (other venues, sync off, or
 * a /reserve fallback) is never announced: Zuri has nothing to update.
 */
function reservation_on_zuri(array $row): bool {
    if (empty($row['sync_last_at']) || empty($row['sync_uuid'])) return false;
    $synced = trim((string) (parse_env()['SYNC_VENUE_SLUG'] ?? '')) ?: 'zuri';
    return (string) ($row['venue_slug'] ?? '') === $synced;
}

/** Queue the status (+ cancellation_reason) of a booking that exists on Zuri. */
function reservation_sync_emit_status(int $id): void {
    if (!sync_supported() || SyncContext::isApplying()) return;
    $row = fetch_reservation($id);
    if (!$row || !reservation_on_zuri($row)) return;
    $data = ['status' => (string) $row['status']];
    if ($row['status'] === 'cancelled' && reservations_extended_supported()) {
        $data['cancellation_reason'] = $row['cancellation_reason'] ?? null;
    }
    sync_outbox_push('reservation', (string) $row['sync_uuid'], 'update', $data, (int) $row['sync_version']);
}

/** True if the current admin ($venueIds scope) may act on this reservation. */
function reservation_editable(?array $venueIds, ?array $res): bool {
    if (!$res) return false;
    if ($venueIds === null) return true;                       // owner
    return in_array((int)$res['venue_id'], $venueIds, true);
}

/** Human label for a reservation time (H:i:s or H:i) → "7:30 PM". */
function reservation_time_label(string $time): string {
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : $time;
}
