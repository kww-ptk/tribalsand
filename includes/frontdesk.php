<?php
declare(strict_types=1);
/**
 * Front Desk data helpers — confirmed reservations grouped by day, venue-scoped.
 * $venueIds: null = all venues (owner); array = restrict to those ids; an empty
 * array is coerced to [-1] so the SQL never emits an empty IN () (staff with no
 * venues therefore see nothing).
 */

/** 'today' in the property timezone (Kenya), not the server's UTC. */
function frontdesk_today_ymd(): string {
    return (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
}
/** 'tomorrow' in the property timezone. */
function frontdesk_tomorrow_ymd(): string {
    return (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->modify('+1 day')->format('Y-m-d');
}

/**
 * Confirmed reservations matching a date predicate (referencing h.check_in /
 * h.check_out via the named params in $params), scoped to $venueIds. Each row
 * carries badge counts (open requests, unread guest messages). Returns [] and
 * logs if the query fails (e.g. a table is missing pre-migration).
 */
function frontdesk_rows(?array $venueIds, string $datePredicate, array $params): array {
    $where = ["h.status = 'confirmed'", $datePredicate];
    if ($venueIds !== null) {
        $ids = $venueIds ?: [-1];
        $ph = [];
        foreach ($ids as $i => $v) { $n = ":fv{$i}"; $ph[] = $n; $params[$n] = (int)$v; }
        $where[] = 'r.venue_id IN (' . implode(',', $ph) . ')';
    }
    $whereSql = implode(' AND ', $where);
    try {
        return db_query(
            "SELECT h.id, h.guest_name, h.check_in, h.check_out, h.access_code,
                    r.name AS room_name, u.name AS unit_name,
                    v.id AS venue_id, v.name AS venue_name,
                    s.guest_phone,
                    (SELECT COUNT(*) FROM booking_addons ba
                       WHERE ba.hold_id = h.id AND ba.status = 'requested') AS open_requests,
                    (SELECT COUNT(*) FROM booking_messages bm
                       WHERE bm.hold_id = h.id AND bm.sender = 'guest' AND bm.read_by_admin = FALSE) AS unread_msgs
             FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = u.room_id
             LEFT JOIN venues v      ON v.id = r.venue_id
             LEFT JOIN submissions s ON s.id = h.submission_id
             WHERE {$whereSql}
             ORDER BY h.check_in ASC, h.guest_name ASC",
            $params
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[frontdesk] query failed: ' . $e->getMessage());
        return [];
    }
}

/** Arrivals / in-house / departures for a single day (Y-m-d), plus the tonight KPI. */
function frontdesk_day(?array $venueIds, string $ymd): array {
    $arriving  = frontdesk_rows($venueIds, 'h.check_in = :d',                     [':d' => $ymd]);
    $inhouse   = frontdesk_rows($venueIds, 'h.check_in < :d AND h.check_out > :d', [':d' => $ymd]);
    $departing = frontdesk_rows($venueIds, 'h.check_out = :d',                    [':d' => $ymd]);
    // Sleeping that night = arrivals that day + continuing stays (departures excluded).
    return [
        'arriving'    => $arriving,
        'inhouse'     => $inhouse,
        'departing'   => $departing,
        'kpi_inhouse' => count($arriving) + count($inhouse),
    ];
}

/** Arrivals in the window [$fromYmd, $fromYmd + $days). Flat list; view groups by date. */
function frontdesk_week(?array $venueIds, string $fromYmd, int $days = 7): array {
    $to = (new DateTime($fromYmd))->modify('+' . max(1, $days) . ' days')->format('Y-m-d'); // exclusive
    return frontdesk_rows($venueIds, 'h.check_in >= :from AND h.check_in < :to', [':from' => $fromYmd, ':to' => $to]);
}
