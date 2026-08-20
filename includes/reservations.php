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

/** True if the reservations table exists (memoised). False pre-migration. */
function reservations_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.reservations')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
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

/** Badge CSS class for a reservation status (matches admin badge--* palette). */
function reservation_status_badge(string $status): string {
    return match ($status) {
        'confirmed' => 'badge--green',
        'cancelled' => 'badge--red',
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

    // Insert first (reference NULL), then mint + set the reference so we can key
    // it off the real id. Retry the reference on the rare unique collision.
    $stmt = db()->prepare(
        "INSERT INTO reservations
            (venue_id, menu_id, reservation_date, reservation_time, party_size,
             guest_name, guest_phone, guest_email, notes, source, client_ip)
         VALUES
            (:venue, :menu, :date, :time, :party,
             :name, :phone, :email, :notes, :source, :ip)
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

    if ($venueIds !== null) {
        if (!$venueIds) return ['WHERE FALSE', []];       // scoped, no venues → nothing
        $in = implode(',', array_map('intval', $venueIds));
        $clauses[] = "r.venue_id IN ($in)";
    }
    if (!empty($filters['venue_id'])) {
        $clauses[] = 'r.venue_id = :fvenue';
        $params[':fvenue'] = (int)$filters['venue_id'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], ['pending','confirmed','cancelled'], true)) {
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

/** Transition a reservation's status. Returns true on a real change. */
function set_reservation_status(int $id, string $status): bool {
    if (!reservations_supported() || $id <= 0) return false;
    if (!in_array($status, ['pending','confirmed','cancelled'], true)) return false;
    $n = db_query(
        "UPDATE reservations SET status = :s, updated_at = now()
          WHERE id = :id AND status <> :s",
        [':s' => $status, ':id' => $id]
    )->rowCount();
    return $n > 0;
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
