<?php
/**
 * Internal team / HR directory helpers.
 *
 * The directory (`hr_staff`) is the workforce "who works where" — a superset of
 * the login accounts in `admin_users`. Every read here is pre-migration-safe via
 * hr_staff_supported(), so a deploy without the add_hr_staff migration simply
 * shows an empty directory instead of 500-ing.
 *
 * Scope mirrors the rest of admin: pass admin_venue_ids() (null = owner/all). A
 * scoped account sees only staff at its own properties; unassigned staff
 * (venue_id IS NULL — office, kite school, restaurant, plots) are owner-only.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Table present? Cached per request. */
function hr_staff_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.hr_staff')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** True once add_hr_staff_venues.sql has been applied (memoised). */
function hr_staff_venues_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.hr_staff_venues')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** A person's ADDITIONAL venue ids (the home venue lives on hr_staff.venue_id). */
function hr_staff_venue_ids(int $staffId): array {
    if ($staffId <= 0 || !hr_staff_venues_supported()) return [];
    try {
        return array_map('intval', array_column(
            db_query('SELECT venue_id FROM hr_staff_venues WHERE hr_staff_id = :s ORDER BY venue_id', [':s' => $staffId])->fetchAll(),
            'venue_id'
        ));
    } catch (Throwable $e) { return []; }
}

/**
 * Replace a person's ADDITIONAL venues (home venue is written separately on the
 * hr_staff row). The home venue is filtered out — it never doubles as an extra.
 * Only valid venue ids are kept. Caller wraps in a transaction where appropriate.
 */
function hr_set_staff_venues(int $staffId, array $venueIds, ?int $homeVenueId, array $validIds): void {
    if ($staffId <= 0 || !hr_staff_venues_supported()) return;
    $ids = [];
    foreach ($venueIds as $v) {
        $vid = (int)$v;
        if ($vid > 0 && $vid !== (int)$homeVenueId && in_array($vid, $validIds, true) && !in_array($vid, $ids, true)) $ids[] = $vid;
    }
    db_query('DELETE FROM hr_staff_venues WHERE hr_staff_id = :s', [':s' => $staffId]);
    foreach ($ids as $vid) {
        db_query('INSERT INTO hr_staff_venues (hr_staff_id, venue_id) VALUES (:s, :v) ON CONFLICT DO NOTHING',
            [':s' => $staffId, ':v' => $vid]);
    }
}

/** The department buckets, in display order. */
function hr_departments(): array {
    return ['Housekeeping', 'Kitchen', 'Gardening', 'Maintenance', 'Security', 'Service', 'Stores', 'Admin', 'Other'];
}

/** Weekly off-day options (empty = Sunday-off by convention). */
function hr_off_days(): array {
    return ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
}

/**
 * Map a raw job title to a department bucket. Keyword-based and case-insensitive
 * so the roster's many spelling variants (GARDNER/GARDERNER, ACCOUNTATNT…) all
 * land correctly. Order matters: "HOUSE SUPERVISOR" is Housekeeping, but
 * "OPERATIONS SUPERV" is Admin — so HOUSE is tested before the SUPERV rule.
 */
function hr_department(?string $position): string {
    $p = strtoupper(trim((string)$position));
    if ($p === '') return 'Other';
    $has = fn(string ...$needles) => (bool) array_filter($needles, fn($n) => strpos($p, $n) !== false);
    if ($has('WATCH', 'SECUR', 'GUARD'))                      return 'Security';
    if ($has('GARD'))                                         return 'Gardening'; // GARDENER/GARDNER/GARDERNER
    if ($has('COOK', 'CHEF', 'KITCHEN'))                      return 'Kitchen';
    if ($has('HOUSE', 'CLEAN', 'POOL', 'LAUNDR'))            return 'Housekeeping';
    if ($has('CARPENT', 'MAINT', 'ELECTRIC', 'PLUMB'))       return 'Maintenance';
    if ($has('STORE'))                                        return 'Stores';
    if ($has('WAIT', 'KITE'))                                 return 'Service';
    if ($has('MANAG', 'ACCOUNT', 'RESERV', 'OPERATION', 'SUPERV', 'HR', 'ADMIN')) return 'Admin';
    return 'Other';
}

/** CSS badge class for a department. */
function hr_department_badge(string $dept): string {
    return match ($dept) {
        'Security'     => 'badge--red',
        'Gardening'    => 'badge--green',
        'Kitchen'      => 'badge--orange',
        'Housekeeping' => 'badge--blue',
        'Maintenance'  => 'badge--purple',
        'Admin'        => 'badge--teal',
        default        => 'badge--grey',
    };
}

/**
 * Build the scope WHERE clause (appended to a WHERE). Returns via &$sqlOut; empty
 * when unscoped (owner). $alias qualifies the hr_staff table: '' for an unaliased
 * `FROM hr_staff`, or e.g. 's' for `FROM hr_staff s`. A person is in scope when
 * their HOME venue (hr_staff.venue_id) OR any ADDITIONAL venue (hr_staff_venues)
 * is in the account's set — the many-to-many widens visibility only (Item 5).
 * Pre-migration (no join table) it is exactly the old home-venue-only clause.
 */
function hr_scope_sql(?array $venueIds, string &$sqlOut, array &$params, string $alias = ''): void {
    if ($venueIds === null) { $sqlOut = ''; return; } // owner — everyone
    $ids = array_values(array_filter(array_map('intval', $venueIds)));
    $vcol  = $alias !== '' ? "$alias.venue_id" : 'venue_id';
    $idcol = $alias !== '' ? "$alias.id"       : 'hr_staff.id';
    if (!$ids) { $sqlOut = " AND $vcol = -1"; return; } // scoped but no venues → nothing
    $ph = [];
    foreach ($ids as $i => $vid) { $k = ":sv$i"; $ph[] = $k; $params[$k] = $vid; }
    $inList = implode(',', $ph);
    if (hr_staff_venues_supported()) {
        $sqlOut = " AND ($vcol IN ($inList)"
                . " OR EXISTS (SELECT 1 FROM hr_staff_venues hv WHERE hv.hr_staff_id = $idcol AND hv.venue_id IN ($inList)))";
    } else {
        $sqlOut = " AND $vcol IN ($inList)";
    }
}

/**
 * Whether an account scoped to $venueIds (null = owner/all) may see/manage a
 * staff member — true if their HOME venue OR any ADDITIONAL venue is in scope.
 * The single-boundary version of hr_scope_sql(), for per-row write guards.
 */
function hr_staff_in_venue_scope(int $staffId, ?int $homeVenueId, ?array $venueIds): bool {
    if ($venueIds === null) return true;                                    // owner — everyone
    if ($homeVenueId !== null && in_array((int)$homeVenueId, $venueIds, true)) return true;
    foreach (hr_staff_venue_ids($staffId) as $vid) if (in_array($vid, $venueIds, true)) return true;
    return false;
}

/**
 * List directory rows, scoped, with optional filters:
 *   ['q' => search, 'department' => bucket, 'venue_id' => int, 'status' => 'active'|'inactive'|'']
 */
function fetch_hr_staff(?array $venueIds, array $filters = []): array {
    if (!hr_staff_supported()) return [];
    $where = 'WHERE 1=1'; $params = [];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params); $where .= $scope;

    if (!empty($filters['department'])) { $where .= ' AND department = :dept'; $params[':dept'] = (string)$filters['department']; }
    if (!empty($filters['venue_id']))   { $where .= ' AND venue_id = :fvid';  $params[':fvid'] = (int)$filters['venue_id']; }
    if (isset($filters['status']) && $filters['status'] !== '') { $where .= ' AND status = :st'; $params[':st'] = (string)$filters['status']; }
    if (!empty($filters['q'])) {
        $where .= ' AND (full_name ILIKE :q OR position ILIKE :q OR COALESCE(unit_label,\'\') ILIKE :q)';
        $params[':q'] = '%' . $filters['q'] . '%';
    }
    return db_query(
        "SELECT * FROM hr_staff $where ORDER BY venue_id NULLS LAST, sort_order ASC, full_name ASC",
        $params
    )->fetchAll();
}

/** One directory row (unscoped — caller enforces scope on write paths). */
function fetch_hr_staff_row(int $id): array|false {
    if (!hr_staff_supported()) return false;
    return db_query("SELECT * FROM hr_staff WHERE id = :id", [':id' => $id])->fetch();
}

/**
 * Directory grouped by property for the "who works where" view. Returns an
 * ordered list of ['venue_id','label','staff'=>[...]] groups; unassigned staff
 * fall into a trailing "Other units" group keyed by their unit_label.
 */
function hr_staff_by_property(?array $venueIds, array $filters = []): array {
    $rows = fetch_hr_staff($venueIds, $filters);
    if (!$rows) return [];
    $venueNames = [];
    foreach (db_query('SELECT id, name FROM venues')->fetchAll() as $v) { $venueNames[(int)$v['id']] = $v['name']; }

    $groups = [];
    foreach ($rows as $r) {
        $vid = $r['venue_id'] !== null ? (int)$r['venue_id'] : 0;
        $label = $vid > 0
            ? ($venueNames[$vid] ?? ('Property #' . $vid))
            : (trim((string)($r['unit_label'] ?? '')) ?: 'Unassigned');
        $key = $vid > 0 ? ('v' . $vid) : ('u:' . $label);
        if (!isset($groups[$key])) $groups[$key] = ['venue_id' => $vid ?: null, 'label' => $label, 'staff' => []];
        $groups[$key]['staff'][] = $r;
    }
    return array_values($groups);
}

/** Count of active directory rows (scoped). */
function hr_staff_count(?array $venueIds): int {
    if (!hr_staff_supported()) return 0;
    $where = "WHERE status = 'active'"; $params = [];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params); $where .= $scope;
    return (int) db_query("SELECT COUNT(*) FROM hr_staff $where", $params)->fetchColumn();
}
