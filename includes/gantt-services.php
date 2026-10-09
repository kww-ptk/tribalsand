<?php
declare(strict_types=1);
/**
 * Services & excursions on the Calendar (admin/gantt.php) + its zoom.
 *
 * READ-ONLY. A guest's requests (booking_addons — activities, transfers, dining,
 * housekeeping…) and the staff-built plan (itinerary_items) are drawn as a
 * "Services & excursions" row under each property, on the day they happen, so
 * reception sees on one screen who arrives AND what has to be arranged for them.
 * Requests with no date yet go to a "Not scheduled yet" tray for the stays on
 * screen. Nothing here changes a request — staff confirm / schedule it on the
 * booking's Requests tab, where every link points.
 *
 * Only live requests (requested / confirmed) of live stays (pending / confirmed)
 * are shown; scope = the unit's property, like every other calendar row.
 * Pre-migration-safe: a missing column or table gives an empty lane, never a 500.
 *
 * Test: php tests/gantt_services_logic.php
 */
require_once __DIR__ . '/db.php';

/** Service groups — one filter chip and one colour each. Order = chip order. */
const GANTT_SERVICE_GROUPS = [
    'excursion' => 'Excursions',
    'transfer'  => 'Transfers',
    'dining'    => 'Dining',
    'house'     => 'Housekeeping & laundry',
    'repair'    => 'Maintenance',
    'other'     => 'Other',
];

/** Zoom levels: window length, step for Prev/Next, day width in px. */
const GANTT_ZOOMS = [
    'week'    => ['label' => 'Week',    'day_w' => 64, 'unit' => 'week'],
    'month'   => ['label' => 'Month',   'day_w' => 40, 'unit' => 'month'],
    'quarter' => ['label' => 'Quarter', 'day_w' => 28, 'unit' => 'month'],
];

/** A request kind (booking_addons.kind) or plan category → its service group. Pure. */
function gantt_service_group(string $kindOrCategory): string
{
    return match ($kindOrCategory) {
        'tour', 'activity'                     => 'excursion',
        'transfer', 'flight'                   => 'transfer',
        'restaurant', 'dining'                 => 'dining',
        'housekeeping', 'laundry', 'amenities' => 'house',
        'maintenance'                          => 'repair',
        default                                => 'other',
    };
}

/** A zoom name from the URL, defaulting to the old 3-month view. Pure. */
function gantt_zoom(?string $z): string
{
    return isset(GANTT_ZOOMS[(string)$z]) ? (string)$z : 'quarter';
}

/**
 * The visible window for a zoom + offset, as [start Y-m-d, end Y-m-d (exclusive)].
 * Week = 14 days from this week's Monday, stepping a week; Month = one calendar
 * month; Quarter = three months from the 1st, stepping a month (as before). Pure.
 */
function gantt_window(string $zoom, int $offset, string $today): array
{
    $t = new DateTimeImmutable($today);
    if ($zoom === 'week') {
        $s = $t->modify('monday this week')->modify(($offset * 7) . ' days');
        return [$s->format('Y-m-d'), $s->modify('+14 days')->format('Y-m-d')];
    }
    $s = $t->modify('first day of this month')->modify("{$offset} months");
    return [$s->format('Y-m-d'), $s->modify($zoom === 'month' ? '+1 month' : '+3 months')->format('Y-m-d')];
}

/**
 * Lay services out in a window: each one sits on its day; several on one day
 * stack into lanes. Items outside the window are dropped. Pure.
 * @param list<array{key:string,day:string}> $items
 * @param array<string,int> $dayIndex  Y-m-d → column
 * @return array{lanes:array<string,int>,count:int}
 */
function gantt_service_lanes(array $items, array $dayIndex): array
{
    $byDay = []; $lanes = []; $count = 0;
    foreach ($items as $it) {
        if (!isset($dayIndex[$it['day']])) continue;
        $n = $byDay[$it['day']] = ($byDay[$it['day']] ?? -1) + 1;
        $lanes[$it['key']] = $n;
        $count = max($count, $n + 1);
    }
    return ['lanes' => $lanes, 'count' => $count];
}

/** "09:30", "Morning" … or '' — never "00:00" for a date-only request. Pure. */
function gantt_service_time(?string $ts): string
{
    if (!$ts) return '';
    $hm = date('H:i', strtotime($ts));
    return $hm === '00:00' ? '' : $hm;
}

/** Column probe — information_schema, never a failing SELECT. */
function gantt_services_col(string $table, string $col): bool
{
    static $c = [];
    $k = "$table.$col";
    if (!isset($c[$k])) {
        try {
            $c[$k] = (bool)db_query(
                'SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c',
                [':t' => $table, ':c' => $col]
            )->fetchColumn();
        } catch (Throwable $e) { $c[$k] = false; }
    }
    return $c[$k];
}

/**
 * Every live service for stays on screen.
 * @param string $venueSql  venue_scope_sql('r.venue_id') ('' = owner, all)
 * @return array{scheduled: list<array>, unscheduled: list<array>}
 *   item: key, venue, group, kind_label, title, day, time, status, hold_id, guest, unit, url, check_in, check_out
 */
function gantt_services(string $start, string $end, string $venueSql): array
{
    $out = ['scheduled' => [], 'unscheduled' => []];
    $scope = $venueSql !== '' ? " AND {$venueSql}" : '';
    $base = "FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = u.room_id
             LEFT JOIN venues v ON v.id = r.venue_id
             WHERE h.status IN ('pending','confirmed')
               AND h.check_in < :end AND h.check_out >= :start{$scope}";

    // Guest requests
    if (gantt_services_col('booking_addons', 'id')) {
        $sched = gantt_services_col('booking_addons', 'scheduled_for') ? 'a.scheduled_for' : 'NULL';
        try {
            $rows = db_query(
                "SELECT a.id, a.kind, a.status, a.details, {$sched} AS scheduled_for, t.name AS tour_title,
                        h.id AS hold_id, h.guest_name, h.check_in, h.check_out,
                        u.name AS unit_name, r.name AS room_name, COALESCE(v.name, 'Unassigned') AS venue_name
                 FROM booking_addons a
                 LEFT JOIN tours t ON t.id = a.tour_id
                 JOIN (SELECT h.id {$base}) hs ON hs.id = a.hold_id
                 JOIN holds h ON h.id = a.hold_id
                 JOIN units u ON u.id = h.unit_id
                 JOIN rooms r ON r.id = u.room_id
                 LEFT JOIN venues v ON v.id = r.venue_id
                 WHERE a.status IN ('requested','confirmed')
                 ORDER BY scheduled_for NULLS LAST, a.id",
                [':start' => $start, ':end' => $end]
            )->fetchAll();
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            $title = trim((string)($r['tour_title'] ?? '')) ?: (trim(strtok((string)$r['details'], "\n") ?: '') ?: ucfirst((string)$r['kind']));
            $item = [
                'key'        => 'a' . (int)$r['id'],
                'venue'      => (string)$r['venue_name'],
                'group'      => gantt_service_group((string)$r['kind']),
                'kind_label' => ucfirst((string)$r['kind']),
                'title'      => mb_substr($title, 0, 80),
                'day'        => $r['scheduled_for'] ? date('Y-m-d', strtotime((string)$r['scheduled_for'])) : '',
                'time'       => gantt_service_time($r['scheduled_for'] ?? null),
                'status'     => (string)$r['status'],
                'hold_id'    => (int)$r['hold_id'],
                'guest'      => (string)$r['guest_name'],
                'unit'       => trim($r['room_name'] . ' · ' . $r['unit_name'], ' ·'),
                'url'        => '/admin/booking.php?hold=' . (int)$r['hold_id'] . '&tab=requests',
                'check_in'   => (string)$r['check_in'],
                'check_out'  => (string)$r['check_out'],
            ];
            $out[$item['day'] !== '' ? 'scheduled' : 'unscheduled'][] = $item;
        }
    }

    // Staff-built plan (itinerary) — only the items that need arranging
    if (gantt_services_col('itinerary_items', 'id')) {
        try {
            $rows = db_query(
                "SELECT i.id, i.day, i.at_time, i.category, i.title,
                        h.id AS hold_id, h.guest_name, h.check_in, h.check_out,
                        u.name AS unit_name, r.name AS room_name, COALESCE(v.name, 'Unassigned') AS venue_name
                 FROM itinerary_items i
                 JOIN (SELECT h.id {$base}) hs ON hs.id = i.hold_id
                 JOIN holds h ON h.id = i.hold_id
                 JOIN units u ON u.id = h.unit_id
                 JOIN rooms r ON r.id = u.room_id
                 LEFT JOIN venues v ON v.id = r.venue_id
                 WHERE i.category IN ('flight','transfer','tour','dining','activity')
                   AND i.day >= :start2 AND i.day < :end2
                 ORDER BY i.day, i.at_time NULLS LAST, i.id",
                [':start' => $start, ':end' => $end, ':start2' => $start, ':end2' => $end]
            )->fetchAll();
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            $out['scheduled'][] = [
                'key'        => 'i' . (int)$r['id'],
                'venue'      => (string)$r['venue_name'],
                'group'      => gantt_service_group((string)$r['category']),
                'kind_label' => 'Plan · ' . ucfirst((string)$r['category']),
                'title'      => mb_substr((string)$r['title'], 0, 80),
                'day'        => (string)$r['day'],
                'time'       => $r['at_time'] ? substr((string)$r['at_time'], 0, 5) : '',
                'status'     => 'planned',
                'hold_id'    => (int)$r['hold_id'],
                'guest'      => (string)$r['guest_name'],
                'unit'       => trim($r['room_name'] . ' · ' . $r['unit_name'], ' ·'),
                'url'        => '/admin/booking.php?hold=' . (int)$r['hold_id'] . '&tab=plan',
                'check_in'   => (string)$r['check_in'],
                'check_out'  => (string)$r['check_out'],
            ];
        }
    }
    return $out;
}
