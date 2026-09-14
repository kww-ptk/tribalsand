<?php
/**
 * Staff attendance — the HR/attendance module's rules engine + data access.
 *
 * DB-backed and multi-property (a port of the standalone attendance tool),
 * sitting on top of the hr_staff directory. Every read is pre-migration-safe via
 * attendance_supported(). Scope mirrors the rest of admin: pass admin_venue_ids()
 * (null = owner/all); a scoped account sees only its properties' staff.
 *
 * Times are minutes from midnight of the work day; an out that crosses midnight
 * is >= 1440 (a 19:00→07:00 security night stores out1 = 1860), so hours compute
 * correctly with no separate flag: hours = ((out1-in1)+(out2-in2)) / 60.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hr.php';   // hr_department(), hr scope

/** Table present? Cached per request. */
function attendance_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.attendance')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Status codes → labels, in display order. 'P' is implied by worked hours. */
function attendance_statuses(): array {
    return [
        'P'     => 'Present',
        'OFF'   => 'Off',
        'REC'   => 'Recovery',
        'PH'    => 'Public holiday',
        'SK'    => 'Sick',
        'ABS'   => 'Absent',
        'LV'    => 'Leave',
        'REC_L' => 'Rec + Leave',
    ];
}

function attendance_status_label(string $s): string { return attendance_statuses()[$s] ?? $s; }

function attendance_status_badge(string $s): string {
    return match ($s) {
        'P'     => 'badge--green',
        'OFF'   => 'badge--grey',
        'REC'   => 'badge--purple',
        'PH'    => 'badge--orange',
        'SK'    => 'badge--blue',
        'ABS'   => 'badge--red',
        'LV', 'REC_L' => 'badge--orange',
        default => 'badge--grey',
    };
}

/** Built-in shifts (minute pairs). Custom shifts are a later phase. */
function attendance_shifts(): array {
    return [
        'std'      => ['label' => 'Standard 8h (8–1 · 2–5)', 'in1' => 480,  'out1' => 780,  'in2' => 840, 'out2' => 1020],
        'secday'   => ['label' => 'Security day 07–19',      'in1' => 420,  'out1' => 1140, 'in2' => null, 'out2' => null],
        'secnight' => ['label' => 'Security night 19–07',    'in1' => 1140, 'out1' => 1860, 'in2' => null, 'out2' => null],
    ];
}

/** "HH:MM" → minutes from midnight, or null. Accepts a trailing "+1" (next day). */
function attendance_hhmm_to_min(?string $hhmm): ?int {
    $hhmm = trim((string)$hhmm);
    if ($hhmm === '') return null;
    $plus = 0;
    if (str_ends_with($hhmm, '+1')) { $plus = 1440; $hhmm = trim(substr($hhmm, 0, -2)); }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return null;
    $min = (int)$m[1] * 60 + (int)$m[2];
    return max(0, min(2879, $min + $plus));
}

/** minutes → "HH:MM" (or "HH:MM+1" when it crosses midnight), or '' for null. */
function attendance_min_to_hhmm(?int $min): string {
    if ($min === null) return '';
    $day = $min >= 1440 ? '+1' : '';
    $m = $min % 1440;
    return sprintf('%02d:%02d%s', intdiv($m, 60), $m % 60, $day);
}

/** Worked hours from the four minute columns. */
function attendance_hours(array $row): float {
    $h = 0;
    if ($row['in1'] !== null && $row['out1'] !== null) $h += (int)$row['out1'] - (int)$row['in1'];
    if ($row['in2'] !== null && $row['out2'] !== null) $h += (int)$row['out2'] - (int)$row['in2'];
    return round(max(0, $h) / 60, 2);
}

/** Role standard hours/day: Security = 12, everyone else = 8. */
function attendance_standard_hours(?string $department): int {
    return $department === 'Security' ? 12 : 8;
}

/** Overtime for a worked day = hours − role standard (may be negative). */
function attendance_overtime(array $row, ?string $department): float {
    if (attendance_hours($row) <= 0) return 0.0;
    return round(attendance_hours($row) - attendance_standard_hours($department), 2);
}

/** The effective status: worked hours ⇒ 'P'; else the stored code (or '' unset). */
function attendance_effective_status(array $row): string {
    if (attendance_hours($row) > 0) return 'P';
    $s = trim((string)($row['status'] ?? ''));
    return $s;
}

/** ISO-ish 3-letter weekday token for a Y-m-d date (MON..SUN). */
function attendance_weekday_token(string $ymd): string {
    return strtoupper(date('D', strtotime($ymd))); // MON, TUE, ...
}

/** Is this date the staff member's day off? Blank off_day ⇒ Sunday off. */
function attendance_is_off_day(?string $offDay, string $ymd): bool {
    $off = strtoupper(trim((string)$offDay));
    $wd  = attendance_weekday_token($ymd);
    return $off === '' ? ($wd === 'SUN') : ($off === $wd);
}

/**
 * Upsert one staff member's day. $data may carry either a status code (clears
 * times) or the four HH:MM strings (a worked shift ⇒ status becomes P). Returns
 * the stored row's id.
 */
function attendance_upsert(int $staffId, string $ymd, array $data, ?int $loggedBy): int {
    $status = trim((string)($data['status'] ?? ''));
    $in1 = $out1 = $in2 = $out2 = null;
    if ($status === '' || $status === 'P') {
        // Worked day — parse the four times; a valid pair makes it P.
        $in1  = array_key_exists('in1',  $data) ? attendance_hhmm_to_min($data['in1'])  : null;
        $out1 = array_key_exists('out1', $data) ? attendance_hhmm_to_min($data['out1']) : null;
        $in2  = array_key_exists('in2',  $data) ? attendance_hhmm_to_min($data['in2'])  : null;
        $out2 = array_key_exists('out2', $data) ? attendance_hhmm_to_min($data['out2']) : null;
        $status = ''; // P is implied by hours, never stored literally
    }
    db_query(
        "INSERT INTO attendance (hr_staff_id, work_date, status, in1, out1, in2, out2, logged_by, updated_at)
         VALUES (:s, :d, :st, :i1, :o1, :i2, :o2, :lb, now())
         ON CONFLICT (hr_staff_id, work_date)
         DO UPDATE SET status=:st, in1=:i1, out1=:o1, in2=:i2, out2=:o2, logged_by=:lb, updated_at=now()",
        [':s'=>$staffId, ':d'=>$ymd, ':st'=>($status ?: null),
         ':i1'=>$in1, ':o1'=>$out1, ':i2'=>$in2, ':o2'=>$out2, ':lb'=>$loggedBy]
    );
    return (int) db_query("SELECT id FROM attendance WHERE hr_staff_id=:s AND work_date=:d", [':s'=>$staffId, ':d'=>$ymd])->fetchColumn();
}

/** Staff (scoped) with their attendance row for one date — LEFT JOIN so blanks show. */
function fetch_attendance_for_date(?array $venueIds, string $ymd, array $filters = []): array {
    if (!attendance_supported() || !hr_staff_supported()) return [];
    $where = "WHERE s.status = 'active'"; $params = [':d' => $ymd];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params);
    // hr_scope_sql aliases as bare venue_id; qualify for the join.
    $scope = str_replace('venue_id', 's.venue_id', $scope);
    $where .= $scope;
    if (!empty($filters['department'])) { $where .= ' AND s.department = :dept'; $params[':dept'] = (string)$filters['department']; }
    if (!empty($filters['venue_id']))   { $where .= ' AND s.venue_id = :fvid';  $params[':fvid'] = (int)$filters['venue_id']; }
    return db_query(
        "SELECT s.id AS hr_staff_id, s.full_name, s.position, s.department, s.venue_id, s.unit_label, s.off_day,
                a.id AS att_id, a.status, a.in1, a.out1, a.in2, a.out2
           FROM hr_staff s
           LEFT JOIN attendance a ON a.hr_staff_id = s.id AND a.work_date = :d
          $where
          ORDER BY s.venue_id NULLS LAST, s.sort_order ASC, s.full_name ASC",
        $params
    )->fetchAll();
}

/** One person's month, keyed by day-of-month (1..31). */
function fetch_attendance_month(int $staffId, int $year, int $month): array {
    if (!attendance_supported()) return [];
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-d', strtotime("$start +1 month"));
    $out = [];
    foreach (db_query(
        "SELECT * FROM attendance WHERE hr_staff_id=:s AND work_date >= :a AND work_date < :b",
        [':s'=>$staffId, ':a'=>$start, ':b'=>$end]
    )->fetchAll() as $r) {
        $out[(int)date('j', strtotime($r['work_date']))] = $r;
    }
    return $out;
}

/**
 * Summary counts for one person over a month (matches the reference Sheet3):
 * daysWorked (P), offLeave (OFF+LV+REC_L), sick (SK), normalOff (OFF only),
 * rec (REC+REC_L), ph (PH), plus total hours and overtime.
 */
function attendance_person_summary(array $days, ?string $department): array {
    $sum = ['daysWorked'=>0,'offLeave'=>0,'sick'=>0,'normalOff'=>0,'rec'=>0,'ph'=>0,'leave'=>0,'abs'=>0,'hours'=>0.0,'ot'=>0.0];
    foreach ($days as $row) {
        $eff = attendance_effective_status($row);
        if ($eff === 'P') {
            $sum['daysWorked']++;
            $sum['hours'] += attendance_hours($row);
            $sum['ot']    += attendance_overtime($row, $department);
            continue;
        }
        switch ($eff) {
            case 'OFF':   $sum['normalOff']++; $sum['offLeave']++; break;
            case 'SK':    $sum['sick']++; break;
            case 'PH':    $sum['ph']++; break;
            case 'REC':   $sum['rec']++; break;
            case 'REC_L': $sum['rec']++; $sum['offLeave']++; break;
            case 'LV':    $sum['leave']++; $sum['offLeave']++; break;
            case 'ABS':   $sum['abs']++; break;
        }
    }
    $sum['hours'] = round($sum['hours'], 2);
    $sum['ot']    = round($sum['ot'], 2);
    return $sum;
}

/**
 * Whole-month matrix for the month grid: one entry per scoped active staff member
 * with their per-day effective status and a computed summary.
 * Returns ['days'=>N, 'staff'=>[ ['id','name','department','venue_id','cells'=>[day=>status], 'summary'=>[...] ] ]].
 */
function attendance_month_matrix(?array $venueIds, int $year, int $month, array $filters = []): array {
    if (!attendance_supported() || !hr_staff_supported()) return ['days'=>0, 'staff'=>[]];
    $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    $where = "WHERE s.status = 'active'"; $params = [];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params);
    $scope = str_replace('venue_id', 's.venue_id', $scope);
    $where .= $scope;
    if (!empty($filters['department'])) { $where .= ' AND s.department = :dept'; $params[':dept'] = (string)$filters['department']; }
    if (!empty($filters['venue_id']))   { $where .= ' AND s.venue_id = :fvid';  $params[':fvid'] = (int)$filters['venue_id']; }
    $staff = db_query(
        "SELECT s.id, s.full_name, s.department, s.venue_id, s.off_day
           FROM hr_staff s $where
          ORDER BY s.venue_id NULLS LAST, s.sort_order ASC, s.full_name ASC",
        $params
    )->fetchAll();
    if (!$staff) return ['days'=>$daysInMonth, 'staff'=>[]];

    $ids = array_map(fn($r) => (int)$r['id'], $staff);
    $in  = implode(',', $ids);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-d', strtotime("$start +1 month"));
    $byStaff = [];
    foreach (db_query(
        "SELECT * FROM attendance WHERE hr_staff_id IN ($in) AND work_date >= :a AND work_date < :b",
        [':a'=>$start, ':b'=>$end]
    )->fetchAll() as $r) {
        $byStaff[(int)$r['hr_staff_id']][(int)date('j', strtotime($r['work_date']))] = $r;
    }

    $out = [];
    foreach ($staff as $s) {
        $days = $byStaff[(int)$s['id']] ?? [];
        $cells = [];
        foreach ($days as $d => $row) $cells[$d] = attendance_effective_status($row);
        $out[] = [
            'id'         => (int)$s['id'],
            'name'       => $s['full_name'],
            'department' => (string)$s['department'],
            'venue_id'   => $s['venue_id'] !== null ? (int)$s['venue_id'] : null,
            'off_day'    => (string)$s['off_day'],
            'cells'      => $cells,
            'summary'    => attendance_person_summary($days, (string)$s['department']),
        ];
    }
    return ['days'=>$daysInMonth, 'staff'=>$out];
}

/**
 * Dashboard aggregates for a month (scoped). KPI totals + by-property and
 * by-department breakdowns, built from the month matrix (one pass).
 */
function attendance_dashboard(?array $venueIds, int $year, int $month, array $filters = []): array {
    $matrix = attendance_month_matrix($venueIds, $year, $month, $filters);
    $daysInMonth = $matrix['days'];
    $kpi = ['staff'=>0,'daysWorked'=>0,'hours'=>0.0,'ot'=>0.0,'offLeave'=>0,'sick'=>0,'rec'=>0,'ph'=>0,'possible'=>0];
    $byProp = []; $byDept = [];
    $venueNames = [];
    foreach (db_query('SELECT id, name FROM venues')->fetchAll() as $v) { $venueNames[(int)$v['id']] = $v['name']; }

    foreach ($matrix['staff'] as $s) {
        $sm = $s['summary'];
        $kpi['staff']++;
        $kpi['daysWorked'] += $sm['daysWorked'];
        $kpi['hours']      += $sm['hours'];
        $kpi['ot']         += $sm['ot'];
        $kpi['offLeave']   += $sm['offLeave'];
        $kpi['sick']       += $sm['sick'];
        $kpi['rec']        += $sm['rec'];
        $kpi['ph']         += $sm['ph'];
        // Possible working days = month days minus this person's off days.
        $offCount = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $year, $month, $d);
            if (attendance_is_off_day($s['off_day'], $ymd)) $offCount++;
        }
        $kpi['possible'] += ($daysInMonth - $offCount);

        $pk = $s['venue_id'] !== null ? ('v'.$s['venue_id']) : 'u';
        $plabel = $s['venue_id'] !== null ? ($venueNames[$s['venue_id']] ?? 'Property') : 'Unassigned';
        if (!isset($byProp[$pk])) $byProp[$pk] = ['label'=>$plabel,'staff'=>0,'daysWorked'=>0,'hours'=>0.0,'ot'=>0.0];
        $byProp[$pk]['staff']++; $byProp[$pk]['daysWorked'] += $sm['daysWorked']; $byProp[$pk]['hours'] += $sm['hours']; $byProp[$pk]['ot'] += $sm['ot'];

        $dept = $s['department'] ?: 'Other';
        if (!isset($byDept[$dept])) $byDept[$dept] = ['label'=>$dept,'staff'=>0,'daysWorked'=>0,'hours'=>0.0,'ot'=>0.0];
        $byDept[$dept]['staff']++; $byDept[$dept]['daysWorked'] += $sm['daysWorked']; $byDept[$dept]['hours'] += $sm['hours']; $byDept[$dept]['ot'] += $sm['ot'];
    }
    $kpi['hours'] = round($kpi['hours'], 1);
    $kpi['ot']    = round($kpi['ot'], 1);
    $kpi['attendancePct'] = $kpi['possible'] > 0 ? round($kpi['daysWorked'] / $kpi['possible'] * 100) : 0;
    return ['kpi'=>$kpi, 'byProperty'=>array_values($byProp), 'byDepartment'=>array_values($byDept)];
}

// ── Leave requests (request → approve/decline) ───────────────────────────────

/** Table present? Cached per request. */
function leave_requests_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.leave_requests')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Leave types → labels. */
function leave_types(): array {
    return ['annual' => 'Annual', 'sick' => 'Sick', 'unpaid' => 'Unpaid', 'other' => 'Other'];
}

function leave_status_badge(string $s): string {
    return match ($s) {
        'approved' => 'badge--green',
        'declined' => 'badge--red',
        default    => 'badge--orange',
    };
}

/** Scoped list of leave requests (pending first, then most recent). */
function fetch_leave_requests(?array $venueIds, array $filters = []): array {
    if (!leave_requests_supported() || !hr_staff_supported()) return [];
    $where = 'WHERE 1=1'; $params = [];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params);
    $scope = str_replace('venue_id', 's.venue_id', $scope);
    $where .= $scope;
    if (!empty($filters['status'])) { $where .= ' AND l.status = :st'; $params[':st'] = (string)$filters['status']; }
    return db_query(
        "SELECT l.*, s.full_name, s.department, s.venue_id, s.off_day
           FROM leave_requests l
           JOIN hr_staff s ON s.id = l.hr_staff_id
          $where
          ORDER BY CASE l.status WHEN 'pending' THEN 0 ELSE 1 END, l.start_date DESC, l.id DESC",
        $params
    )->fetchAll();
}

/** Create a leave request (validated dates). Returns the new id, or 0 on bad input. */
function leave_create(int $staffId, string $start, string $end, string $type, string $reason, ?int $by): int {
    if (!leave_requests_supported()) return 0;
    $ok = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$ok($start) || !$ok($end) || $end < $start) return 0;
    if (!array_key_exists($type, leave_types())) $type = 'annual';
    db_query(
        "INSERT INTO leave_requests (hr_staff_id, start_date, end_date, leave_type, reason, status, requested_by)
         VALUES (:s, :a, :b, :t, :r, 'pending', :by)",
        [':s'=>$staffId, ':a'=>$start, ':b'=>$end, ':t'=>$type, ':r'=>($reason ?: null), ':by'=>$by]
    );
    return (int) db()->lastInsertId();
}

/** One leave request row (unscoped — caller enforces scope). */
function fetch_leave_request(int $id): array|false {
    if (!leave_requests_supported()) return false;
    return db_query(
        "SELECT l.*, s.full_name, s.venue_id, s.off_day FROM leave_requests l
           JOIN hr_staff s ON s.id = l.hr_staff_id WHERE l.id = :id",
        [':id'=>$id]
    )->fetch();
}

/**
 * Decide a request. Approving stamps 'LV' into attendance for each NON-off day in
 * the range (a rest day inside a leave span stays a rest day, not leave). Idempotent
 * on the request row. Returns the number of attendance days stamped (0 for decline).
 */
function leave_decide(int $id, string $status, ?int $by): int {
    if (!leave_requests_supported()) return 0;
    $status = $status === 'approved' ? 'approved' : 'declined';
    $req = fetch_leave_request($id);
    if (!$req || $req['status'] !== 'pending') return 0;

    db_query(
        "UPDATE leave_requests SET status=:st, decided_by=:by, decided_at=now() WHERE id=:id",
        [':st'=>$status, ':by'=>$by, ':id'=>$id]
    );
    if ($status !== 'approved') return 0;

    $stamped = 0;
    $cur = $req['start_date'];
    while ($cur <= $req['end_date']) {
        if (!attendance_is_off_day($req['off_day'] ?? '', $cur)) {
            attendance_upsert((int)$req['hr_staff_id'], $cur, ['status' => 'LV'], $by);
            $stamped++;
        }
        $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }
    return $stamped;
}

/** Count of pending leave requests in scope — for a nav/section badge. */
function leave_pending_count(?array $venueIds): int {
    if (!leave_requests_supported()) return 0;
    return count(fetch_leave_requests($venueIds, ['status' => 'pending']));
}
