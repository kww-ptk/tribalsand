<?php
declare(strict_types=1);
/**
 * Team roles helpers — request auto-routing, assignee lookup and the staff
 * "My Work" queue. Loaded via includes/booking.php so the routing helpers are
 * available to the public request endpoint as well as the admin.
 *
 * These functions assume only db_query(); callers pass venue scope in explicitly
 * (same pattern as includes/frontdesk.php), so this file has no auth dependency.
 * Every read is wrapped so a pre-migration column/table never fatals a page.
 */

/** Map a request kind to the job type that normally handles it, or null (manager routes it). */
function request_job_for_kind(string $kind): ?string {
    return [
        'housekeeping' => 'housekeeping',
        'amenities'    => 'housekeeping',
        'laundry'      => 'laundry',       // its own specialty now (add_laundry_job.sql)
        'maintenance'  => 'maintenance',
        'transfer'     => 'driver',
    ][$kind] ?? null;
}

/**
 * The staff member a new request of this kind should auto-assign to at a venue:
 * the single active staff member with the matching job whose scope covers the
 * venue. 0 or >1 candidates → null (leaves it in the manager's queue to route).
 */
function default_assignee_for(string $kind, ?int $venueId): ?int {
    if ($venueId === null) return null;
    $job = request_job_for_kind($kind);
    if ($job === null) return null;
    try {
        $ids = db_query(
            "SELECT a.id FROM admin_users a
             JOIN admin_user_venues av ON av.admin_user_id = a.id
             WHERE a.role = 'staff' AND a.job_type = :j AND a.is_active = TRUE AND av.venue_id = :v",
            [':j' => $job, ':v' => $venueId]
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return null; }
    return (count($ids) === 1) ? (int)$ids[0] : null;
}

/**
 * Active non-owner accounts whose scope covers a venue — the "Assign to…" list.
 * $venueId null → [] (can't scope). Returns rows: id, name, role, job_type.
 */
function assignable_team_for_venue(?int $venueId): array {
    if ($venueId === null) return [];
    try {
        return db_query(
            "SELECT DISTINCT a.id, a.name, a.role, a.job_type
             FROM admin_users a
             JOIN admin_user_venues av ON av.admin_user_id = a.id
             WHERE a.role IN ('manager','staff') AND a.is_active = TRUE AND av.venue_id = :v
             ORDER BY a.role DESC, a.name ASC",
            [':v' => $venueId]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** True if $adminId may be assigned a request on $holdId (active account, scope covers the hold's venue). */
function team_can_be_assigned(int $adminId, int $holdId): bool {
    if ($adminId <= 0) return false;
    try {
        return (bool) db_query(
            "SELECT 1 FROM admin_users a
             JOIN admin_user_venues av ON av.admin_user_id = a.id
             JOIN rooms r ON r.venue_id = av.venue_id
             JOIN units u ON u.room_id = r.id
             JOIN holds h ON h.unit_id = u.id
             WHERE a.id = :a AND a.role IN ('manager','staff') AND a.is_active = TRUE AND h.id = :h
             LIMIT 1",
            [':a' => $adminId, ':h' => $holdId]
        )->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// ── Lead / booking assignment (Items 2 & 3) ─────────────────────────────────

/** True if <table>.assigned_to exists (memoised per table). False pre-migration. */
function assignee_column_supported(string $table): bool {
    static $c = [];
    if (isset($c[$table])) return $c[$table];
    try {
        return $c[$table] = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = :t AND column_name = 'assigned_to'",
            [':t' => $table]
        )->fetchColumn();
    } catch (Throwable $e) { return $c[$table] = false; }
}
function submission_assignee_supported(): bool { return assignee_column_supported('submissions'); }
function hold_assignee_supported(): bool       { return assignee_column_supported('holds'); }

/**
 * The people a lead / booking can be assigned to — active non-owner accounts that
 * actually follow leads up (manager, reception, staff). When $venueId is given the
 * list is scoped to accounts covering that property; NULL/0 (a general enquiry with
 * no property) returns every such account. Returns rows: id, name, email, role, job_type.
 */
function assignable_accounts(?int $venueId = null): array {
    try {
        if ($venueId) {
            return db_query(
                "SELECT DISTINCT a.id, a.name, a.email, a.role, a.job_type
                 FROM admin_users a
                 JOIN admin_user_venues av ON av.admin_user_id = a.id
                 WHERE a.role IN ('manager','reception','staff') AND a.is_active = TRUE AND av.venue_id = :v
                 ORDER BY a.role DESC, a.name ASC",
                [':v' => $venueId]
            )->fetchAll();
        }
        return db_query(
            "SELECT a.id, a.name, a.email, a.role, a.job_type
             FROM admin_users a
             WHERE a.role IN ('manager','reception','staff') AND a.is_active = TRUE
             ORDER BY a.role DESC, a.name ASC"
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** True if $adminId is a valid target to assign a lead/booking at $venueId to. */
function is_assignable_account(int $adminId, ?int $venueId = null): bool {
    if ($adminId <= 0) return false;
    foreach (assignable_accounts($venueId) as $a) {
        if ((int)$a['id'] === $adminId) return true;
    }
    return false;
}

/** Display name for an assigned admin id (or '' if unknown). */
function team_member_name(?int $adminId): string {
    if (!$adminId) return '';
    try {
        $n = db_query("SELECT name FROM admin_users WHERE id = :a", [':a' => $adminId])->fetchColumn();
        return $n !== false ? (string)$n : '';
    } catch (Throwable $e) { return ''; }
}

/** The operational job types (key => label). Single source used by staff/tasks/gate UIs. */
function team_job_types(): array {
    return [
        'frontdesk'    => 'Front desk',
        'housekeeping' => 'Housekeeping',
        'laundry'      => 'Laundry',
        'maintenance'  => 'Maintenance',
        'gardening'    => 'Gardening',
        'security'     => 'Gate security',
        'driver'       => 'Driver',
        'shop'         => 'Shop (POS)',
        'spa'          => 'Salon & Spa (POS)',
        'kite'         => 'Kite school (POS)',
    ];
}

/** True if $adminId is an active non-owner account scoped to $venueId (assignable there). */
function team_can_take_venue(int $adminId, int $venueId): bool {
    if ($adminId <= 0 || $venueId <= 0) return false;
    try {
        return (bool) db_query(
            "SELECT 1 FROM admin_users a
             JOIN admin_user_venues av ON av.admin_user_id = a.id
             WHERE a.id = :a AND a.role IN ('manager','staff') AND a.is_active = TRUE AND av.venue_id = :v
             LIMIT 1",
            [':a' => $adminId, ':v' => $venueId]
        )->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// ── Internal tasks (Phase 3) ───────────────────────────────────────────────

/** True if the tasks table exists (memoised). False pre-migration. */
function tasks_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.tasks')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Task status → admin badge colour class. */
function task_badge_class(string $s): string {
    return ['todo'=>'badge--orange','in_progress'=>'badge--blue','done'=>'badge--green','cancelled'=>'badge--grey'][$s] ?? 'badge--grey';
}
/** Human label for a task status. */
function task_status_label(string $s): string {
    return ['todo'=>'To do','in_progress'=>'In progress','done'=>'Done','cancelled'=>'Cancelled'][$s] ?? ucfirst($s);
}

/**
 * Tasks within a venue scope ($venueIds null = all/owner), newest-actionable first.
 * $filters keys: 'status' (open|todo|in_progress|done|cancelled|all),
 *   'assignee' ('unassigned'|'me'|int), 'me' (int, for the 'me' assignee).
 */
function fetch_tasks(?array $venueIds, array $filters = []): array {
    if (!tasks_supported()) return [];
    $where = []; $params = [];
    if ($venueIds !== null) {
        $ids = $venueIds ?: [-1];
        $ph = []; foreach ($ids as $i => $v) { $n = ":tv{$i}"; $ph[] = $n; $params[$n] = (int)$v; }
        $where[] = 't.venue_id IN (' . implode(',', $ph) . ')';
    }
    $st = $filters['status'] ?? 'open';
    if ($st === 'open') { $where[] = "t.status IN ('todo','in_progress')"; }
    elseif (in_array($st, ['todo','in_progress','done','cancelled'], true)) { $where[] = 't.status = :st'; $params[':st'] = $st; }
    if (isset($filters['assignee'])) {
        if ($filters['assignee'] === 'unassigned')      $where[] = 't.assigned_to IS NULL';
        elseif ($filters['assignee'] === 'me')        { $where[] = 't.assigned_to = :fme'; $params[':fme'] = (int)($filters['me'] ?? 0); }
        elseif (is_int($filters['assignee']))         { $where[] = 't.assigned_to = :faid'; $params[':faid'] = $filters['assignee']; }
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    try {
        return db_query(
            "SELECT t.*, v.name AS venue_name, a.name AS assignee_name, h.guest_name AS hold_guest
             FROM tasks t
             LEFT JOIN venues v ON v.id = t.venue_id
             LEFT JOIN admin_users a ON a.id = t.assigned_to
             LEFT JOIN holds h ON h.id = t.hold_id
             {$whereSql}
             ORDER BY (t.status IN ('done','cancelled')) ASC, t.due_date ASC NULLS LAST, t.created_at DESC",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** One task by id (with venue for scope checks), or false. */
function fetch_task(int $id): array|false {
    if (!tasks_supported()) return false;
    try { $r = db_query("SELECT * FROM tasks WHERE id = :id", [':id' => $id])->fetch(); }
    catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** A team member's own open tasks (My Work). */
function mywork_tasks(int $adminId, array $statuses = ['todo','in_progress']): array {
    if (!tasks_supported() || $adminId <= 0 || !$statuses) return [];
    $names = []; $params = [':a' => $adminId];
    foreach (array_values($statuses) as $i => $s) { $n = ":ts{$i}"; $names[] = $n; $params[$n] = $s; }
    try {
        return db_query(
            "SELECT t.*, v.name AS venue_name, h.guest_name AS hold_guest
             FROM tasks t
             LEFT JOIN venues v ON v.id = t.venue_id
             LEFT JOIN holds h ON h.id = t.hold_id
             WHERE t.assigned_to = :a AND t.status IN (" . implode(',', $names) . ")
             ORDER BY t.due_date ASC NULLS LAST, t.created_at DESC",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * A specialty ops worklist for one day, DERIVED from the live confirmed-booking
 * calendar (frontdesk_day) — never a hand-kept list, so it cannot drift from the
 * real arrivals/departures. Each job gets tailored, labelled sections:
 *   housekeeping → check-out cleans, arrival preps, in-house refreshes
 *   laundry      → check-out linen strip, in-house mid-stay change
 *   driver       → arrival pickups, departure drop-offs
 * Jobs with no turnover-derived work (gardening/maintenance/frontdesk/security)
 * return [] and work from Tasks / their own screens.
 *
 * $venueIds is the acting staff member's scope (null = all/owner), $ymd a
 * Nairobi-local Y-m-d. Pre-migration-safe: frontdesk_day() fails soft to [].
 *
 * @return list<array{key:string,title:string,note:string,rows:array}>
 */
function staff_day_worklist(?array $venueIds, string $job, string $ymd): array {
    if (!in_array($job, ['housekeeping','laundry','driver'], true)) return [];
    require_once __DIR__ . '/frontdesk.php';
    if (!function_exists('frontdesk_day')) return [];

    $day = frontdesk_day($venueIds, $ymd);
    $arr = $day['arriving']  ?? [];
    $dep = $day['departing'] ?? [];
    $inh = $day['inhouse']   ?? [];

    $sections = [];
    if ($job === 'housekeeping') {
        $sections = [
            ['key'=>'checkout','title'=>'Check-out cleans','note'=>'Full clean once these guests leave today','rows'=>$dep],
            ['key'=>'arrival', 'title'=>'Arrival prep',    'note'=>'Ready these rooms for guests arriving today','rows'=>$arr],
            ['key'=>'stay',    'title'=>'In-house refresh','note'=>'Occupied tonight — daily servicing','rows'=>$inh],
        ];
    } elseif ($job === 'laundry') {
        $sections = [
            ['key'=>'checkout','title'=>'Check-out linen','note'=>'Strip and launder after departure','rows'=>$dep],
            ['key'=>'stay',    'title'=>'In-house linen', 'note'=>'Mid-stay linen change','rows'=>$inh],
        ];
    } else { // driver
        $sections = [
            ['key'=>'pickup', 'title'=>'Arrival pickups',    'note'=>'Guests arriving today — transfers in','rows'=>$arr],
            ['key'=>'dropoff','title'=>'Departure drop-offs','note'=>'Guests leaving today — transfers out','rows'=>$dep],
        ];
    }
    // Drop empty sections so a quiet day shows a clean "nothing today", not blanks.
    return array_values(array_filter($sections, fn($s) => !empty($s['rows'])));
}

// ── Gate visitors (Phase 4) ────────────────────────────────────────────────

/** True if the visitors table exists (memoised). False pre-migration. */
function visitors_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.visitors')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * Visitor register rows within a venue scope ($venueIds null = all/owner).
 * $openOnly true → only those still on site (time_out IS NULL); false → everyone
 * signed in since $sinceYmd (00:00 that day), open first then most-recent in.
 */
function fetch_visitors(?array $venueIds, bool $openOnly = true, ?string $sinceYmd = null): array {
    if (!visitors_supported()) return [];
    $where = []; $params = [];
    if ($venueIds !== null) {
        $ids = $venueIds ?: [-1];
        $ph = []; foreach ($ids as $i => $v) { $n = ":gv{$i}"; $ph[] = $n; $params[$n] = (int)$v; }
        $where[] = 'vs.venue_id IN (' . implode(',', $ph) . ')';
    }
    if ($openOnly) { $where[] = 'vs.time_out IS NULL'; }
    elseif ($sinceYmd !== null) { $where[] = '(vs.time_out IS NULL OR vs.time_in >= :since)'; $params[':since'] = $sinceYmd . ' 00:00:00'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    try {
        return db_query(
            "SELECT vs.*, v.name AS venue_name, a.name AS logged_by_name, h.guest_name AS hold_guest
             FROM visitors vs
             LEFT JOIN venues v ON v.id = vs.venue_id
             LEFT JOIN admin_users a ON a.id = vs.logged_by
             LEFT JOIN holds h ON h.id = vs.hold_id
             {$whereSql}
             ORDER BY (vs.time_out IS NOT NULL) ASC, vs.time_in DESC",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Paged + searchable visitor register for the Gate log. $venueIds null = all/owner.
 * $opts: scope ('onsite'|'all'), date (Y-m-d filter on time_in, '' = any),
 *        q (free-text over name/visiting/purpose/vehicle/property), per, offset.
 * Returns ['rows' => [...], 'total' => int]. Open (on-site) visitors sort first,
 * then most-recent in. Empty pre-migration — never fatals.
 */
function fetch_visitors_paged(?array $venueIds, array $opts = []): array {
    if (!visitors_supported()) return ['rows' => [], 'total' => 0];
    $where = []; $params = [];
    if ($venueIds !== null) {
        $ids = $venueIds ?: [-1];
        $ph = []; foreach ($ids as $i => $v) { $n = ":gv{$i}"; $ph[] = $n; $params[$n] = (int)$v; }
        $where[] = 'vs.venue_id IN (' . implode(',', $ph) . ')';
    }
    if (($opts['scope'] ?? 'onsite') === 'onsite') { $where[] = 'vs.time_out IS NULL'; }
    $date = trim((string)($opts['date'] ?? ''));
    if ($date !== '') { $where[] = 'vs.time_in::date = :vdate'; $params[':vdate'] = $date; }
    $q = trim((string)($opts['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(vs.visitor_name ILIKE :vq OR COALESCE(vs.visiting,'') ILIKE :vq"
                 . " OR COALESCE(vs.purpose,'') ILIKE :vq OR COALESCE(vs.vehicle,'') ILIKE :vq"
                 . " OR COALESCE(v.name,'') ILIKE :vq)";
        $params[':vq'] = '%' . $q . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $per    = max(1, (int)($opts['per'] ?? 25));
    $offset = max(0, (int)($opts['offset'] ?? 0));
    try {
        $total = (int) db_query("SELECT COUNT(*) FROM visitors vs LEFT JOIN venues v ON v.id = vs.venue_id {$whereSql}", $params)->fetchColumn();
        $rows = db_query(
            "SELECT vs.*, v.name AS venue_name, a.name AS logged_by_name, h.guest_name AS hold_guest
             FROM visitors vs
             LEFT JOIN venues v ON v.id = vs.venue_id
             LEFT JOIN admin_users a ON a.id = vs.logged_by
             LEFT JOIN holds h ON h.id = vs.hold_id
             {$whereSql}
             ORDER BY (vs.time_out IS NOT NULL) ASC, vs.time_in DESC
             LIMIT {$per} OFFSET {$offset}",
            $params
        )->fetchAll();
        return ['rows' => $rows, 'total' => $total];
    } catch (Throwable $e) { return ['rows' => [], 'total' => 0]; }
}

/** One visitor row by id (with venue for scope checks), or false. */
function fetch_visitor(int $id): array|false {
    if (!visitors_supported()) return false;
    try { $r = db_query("SELECT * FROM visitors WHERE id = :id", [':id' => $id])->fetch(); }
    catch (Throwable $e) { return false; }
    return $r ?: false;
}

/**
 * A staff member's assigned requests (their My Work queue). $statuses filters the
 * addon status set. Returns [] pre-migration (no assigned_to column) — never fatals.
 */
function mywork_requests(int $adminId, array $statuses = ['requested','confirmed']): array {
    if ($adminId <= 0 || !$statuses) return [];
    $names = []; $params = [':a' => $adminId];
    foreach (array_values($statuses) as $i => $s) { $n = ":ms{$i}"; $names[] = $n; $params[$n] = $s; }
    try {
        return db_query(
            "SELECT ba.*, t.name AS tour_name,
                    h.guest_name, h.check_in, h.check_out,
                    r.name AS room_name, v.name AS venue_name
             FROM booking_addons ba
             JOIN holds h ON h.id = ba.hold_id
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
             LEFT JOIN venues v ON v.id = r.venue_id
             LEFT JOIN tours  t ON t.id = ba.tour_id
             WHERE ba.assigned_to = :a AND ba.status IN (" . implode(',', $names) . ")
             ORDER BY ba.status ASC, ba.created_at DESC",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}
