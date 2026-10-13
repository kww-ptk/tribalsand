<?php
/**
 * Staff requests — leave, sick, day off, unpaid, corrections, overtime, swaps —
 * with a two-step approval (supervisor → HR). Built on leave_requests (migration
 * add_staff_requests.sql); page: Team → Attendance → Requests (admin/staff-requests.php).
 *
 * The rules:
 *  • Anyone who can see the person (owner, their manager, HR) can ENTER a request
 *    for them — most staff have no login. The record keeps who it is FOR
 *    (hr_staff_id), who ENTERED it (requested_by), who approved each step, when.
 *  • Entering never approves. Step 1 (supervisor): owner, a manager who looks after
 *    the person, or HR. Step 2 (HR): owner or HR — only for the types the owner says
 *    need HR (staff_request_hr_types(); all of them unless changed).
 *  • A sick or maternity note is a MEDICAL document: only the owner and HR open it
 *    (staff_request_doc_is_medical(), checked in admin/employee-file.php). When the owner or HR approves at
 *    step 1 both steps are recorded at once; "Submit and approve" (owner/HR) does
 *    the same at entry. Declining needs a reason.
 *  • Approval changes the attendance on its own (source 'request', request_id):
 *    leave types stamp their code on each day that is not the weekly off day
 *    (LV, SK, REC, UL); a correction sets the day's times. What each day held
 *    before is kept in details.replaced, so cancelling an approved request puts it
 *    back exactly. A day clocked on the kiosk (or worked) is never stamped over —
 *    it is skipped and named.
 *  • An approved shift swap exchanges the two people's planned shifts that day on
 *    the rota (includes/staff-rota.php); cancelling it puts them back.
 *  • Balances follow through Close month (an LV day is leave taken, a REC day uses
 *    an off day) — the request itself writes no ledger row.
 *  • Cancelling: a pending request by whoever may act on it; an APPROVED one only
 *    by the owner or HR, with a reason.
 * Test: php tests/staff_requests_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/staff-rota.php';

final class StaffRequestRefusal extends RuntimeException {}

/** add_staff_requests.sql applied? (catalog lookup — safe inside a transaction) */
function staff_requests_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'leave_requests' AND column_name = 'details'")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * Request types: key => [label, attendance code stamped on approval or null, needs dates].
 * 'day_off' uses the off-days balance (REC = recovery day).
 */
function staff_request_types(): array {
    return [
        'annual'        => ['Annual leave', 'LV', true],
        'sick'          => ['Sick leave', 'SK', true],
        'day_off'       => ['Day off / recovery day', 'REC', true],
        'unpaid'        => ['Unpaid leave', 'UL', true],
        'maternity'     => ['Maternity leave', 'LV', true],
        'paternity'     => ['Paternity leave', 'LV', true],
        'compassionate' => ['Compassionate leave', 'LV', true],
        'correction'    => ['Attendance correction', null, true],
        'overtime'      => ['Overtime', null, true],
        'swap'          => ['Shift swap', null, true],
        'other'         => ['Other', null, true],
    ];
}
function staff_request_type_label(string $t): string { return staff_request_types()[$t][0] ?? ucfirst($t); }

/** Status → [label, badge]. */
function staff_request_status(string $s): array {
    return match ($s) {
        'pending'    => ['Waiting for supervisor', 'badge--orange'],
        'pending_hr' => ['Waiting for HR', 'badge--orange'],
        'approved'   => ['Approved', 'badge--green'],
        'declined'   => ['Declined', 'badge--red'],
        'cancelled'  => ['Cancelled', 'badge--grey'],
        default      => [ucfirst($s), 'badge--grey'],
    };
}

/** The days a request covers that get a code stamped (weekly off days are left alone). Pure. */
function staff_request_stamp_days(string $from, string $to, string $offDay): array {
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (!attendance_is_off_day($offDay, $d)) $out[] = $d;
        if (count($out) > 366) break;
    }
    return $out;
}

/** Can this account act on a request for this person at its current step? Pure: roles passed in. */
function staff_request_can_act(string $status, string $role, bool $personInScope): bool {
    if (!$personInScope) return false;
    if ($status === 'pending')    return in_array($role, ['owner', 'manager', 'hr'], true);
    if ($status === 'pending_hr') return in_array($role, ['owner', 'hr'], true);
    return false;
}

// ── Reads ───────────────────────────────────────────────────────────────────

/** One request with the person and the names of who entered / approved it. */
function staff_request_fetch(int $id): ?array {
    $r = db_query("SELECT l.*, s.full_name, s.position, s.venue_id, s.off_day, s.company_id, s.unit_label,
                          ub.name AS requested_by_name, us.name AS supervisor_name, uh.name AS hr_name, uc.name AS cancelled_by_name
                     FROM leave_requests l JOIN hr_staff s ON s.id = l.hr_staff_id
                     LEFT JOIN admin_users ub ON ub.id = l.requested_by LEFT JOIN admin_users us ON us.id = l.supervisor_by
                     LEFT JOIN admin_users uh ON uh.id = l.hr_by LEFT JOIN admin_users uc ON uc.id = l.cancelled_by
                    WHERE l.id = :id", [':id' => $id])->fetch();
    if (!$r) return null;
    $r['details'] = json_decode((string)($r['details'] ?? '{}'), true) ?: [];
    return $r;
}

/**
 * The inbox: requests for people in scope, filtered by status ('open' = both
 * waiting steps), type, property, company; waiting ones first.
 */
function staff_requests_list(?array $venueIds, ?array $companyIds, array $f = [], int $limit = 200): array {
    $where = 'WHERE 1=1'; $params = [];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params, 's', $companyIds); $where .= $scope;
    $st = (string)($f['status'] ?? 'open');
    if ($st === 'open') $where .= " AND l.status IN ('pending','pending_hr')";
    elseif ($st !== '' && $st !== 'all') { $where .= ' AND l.status = :st'; $params[':st'] = $st; }
    if (!empty($f['type']))       { $where .= ' AND l.leave_type = :t'; $params[':t'] = (string)$f['type']; }
    if (!empty($f['venue_id']))   { $where .= ' AND s.venue_id = :v';   $params[':v'] = (int)$f['venue_id']; }
    if (!empty($f['company_id']) && hr_staff_company_supported()) { $where .= ' AND s.company_id = :c'; $params[':c'] = (int)$f['company_id']; }
    if (!empty($f['staff_id']))   { $where .= ' AND l.hr_staff_id = :sid'; $params[':sid'] = (int)$f['staff_id']; }
    $rows = db_query("SELECT l.*, s.full_name, s.position, s.venue_id, s.off_day, s.phone, ub.name AS requested_by_name,
                             us.name AS supervisor_name, uh.name AS hr_name
                        FROM leave_requests l JOIN hr_staff s ON s.id = l.hr_staff_id LEFT JOIN admin_users ub ON ub.id = l.requested_by
                        LEFT JOIN admin_users us ON us.id = l.supervisor_by LEFT JOIN admin_users uh ON uh.id = l.hr_by
                        $where
                       ORDER BY CASE l.status WHEN 'pending' THEN 0 WHEN 'pending_hr' THEN 1 ELSE 2 END, l.start_date DESC, l.id DESC
                       LIMIT " . max(1, $limit), $params)->fetchAll();
    foreach ($rows as &$r) $r['details'] = json_decode((string)($r['details'] ?? '{}'), true) ?: [];
    unset($r);
    return $rows;
}

/** How many wait for THIS account: managers count step 1, owner/HR both steps. */
function staff_requests_waiting(?array $venueIds, ?array $companyIds, string $role): int {
    if (!staff_requests_supported()) return 0;
    $statuses = in_array($role, ['owner', 'hr'], true) ? "'pending','pending_hr'" : ($role === 'manager' ? "'pending'" : "''");
    $where = "WHERE l.status IN ($statuses)"; $params = [];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params, 's', $companyIds); $where .= $scope;
    return (int) db_query("SELECT COUNT(*) FROM leave_requests l JOIN hr_staff s ON s.id = l.hr_staff_id $where", $params)->fetchColumn();
}

/**
 * What to know before entering a request: the person's balances, other requests
 * that overlap, and days in the range already worked / clocked (they won't be
 * stamped). Returns ['overlap' => [requests], 'worked' => [Y-m-d]].
 */
function staff_request_conflicts(int $staffId, string $from, string $to, ?int $exceptId = null): array {
    $ov = db_query("SELECT id, leave_type, start_date, end_date, status FROM leave_requests
                     WHERE hr_staff_id = :s AND status IN ('pending','pending_hr','approved') AND start_date <= :b AND end_date >= :a"
                   . ($exceptId ? ' AND id <> ' . (int)$exceptId : ''), [':s' => $staffId, ':a' => $from, ':b' => $to])->fetchAll();
    $worked = [];
    foreach (db_query('SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date BETWEEN :a AND :b', [':s' => $staffId, ':a' => $from, ':b' => $to])->fetchAll() as $r) {
        if (attendance_hours($r) > 0 || ($r['source'] ?? '') === 'kiosk') $worked[] = (string)$r['work_date'];
    }
    return ['overlap' => $ov, 'worked' => $worked];
}

// ── Writes ──────────────────────────────────────────────────────────────────

/**
 * Enter a request. $in: type, from, to, reason, half_day, and per type: correction
 * times (in1..out2 as HH:MM), overtime hours + worked, swap with_staff_id. Refuses
 * an overlap with another open or approved request of the same kind of absence.
 * Returns the new id. ($approveAs: 'owner'|'hr' → "Submit and approve".)
 */
function staff_request_create(int $staffId, array $in, ?int $byAdmin, ?string $approveAs = null): int {
    if (!staff_requests_supported()) throw new StaffRequestRefusal('Run the add_staff_requests migration first.');
    $types = staff_request_types();
    $type = (string)($in['type'] ?? '');
    if (!isset($types[$type])) throw new StaffRequestRefusal('Pick what the request is for.');
    $from = (string)($in['from'] ?? ''); $to = (string)($in['to'] ?? '') ?: $from;
    $ok = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$ok($from) || !$ok($to)) throw new StaffRequestRefusal('Pick the date' . ($types[$type][1] ? 's' : '') . '.');
    if ($to < $from) throw new StaffRequestRefusal('The last day is before the first.');
    if ((strtotime($to) - strtotime($from)) / 86400 > 120) throw new StaffRequestRefusal('A request covers at most 120 days — enter longer leave in parts.');
    $p = db_query("SELECT * FROM hr_staff WHERE id = :s", [':s' => $staffId])->fetch();
    if (!$p) throw new StaffRequestRefusal('That person is not in the directory.');
    $reason = trim((string)($in['reason'] ?? ''));
    $det = [];
    if (!empty($in['half_day'])) {
        if ($from !== $to) throw new StaffRequestRefusal('A half day is a single day.');
        $det['half_day'] = true;
    }
    if ($type === 'correction') {
        if ($from !== $to) throw new StaffRequestRefusal('Correct one day at a time.');
        $t = [];
        foreach (['in1', 'out1', 'in2', 'out2'] as $k) $t[$k] = attendance_hhmm_to_min((string)($in[$k] ?? ''));
        if ($t['in1'] === null || $t['out1'] === null) throw new StaffRequestRefusal('Give the correct time in and time out.');
        if ($t['out1'] <= $t['in1'] && $t['out1'] < 1440) $t['out1'] += 1440;   // a night shift typed plainly
        if ($reason === '') throw new StaffRequestRefusal('Say what was wrong (e.g. "forgot to clock out").');
        $det['times'] = $t;
    }
    if ($type === 'overtime') {
        $h = (float) str_replace(',', '.', (string)($in['hours'] ?? ''));
        if ($h <= 0 || $h > 16) throw new StaffRequestRefusal('Give the overtime hours (up to 16).');
        $det['hours'] = round($h, 2);
        $det['worked'] = !empty($in['worked']);
    }
    if ($type === 'swap') {
        $w = (int)($in['with_staff_id'] ?? 0);
        if ($w <= 0 || $w === $staffId || !db_query("SELECT 1 FROM hr_staff WHERE id = :w AND status = 'active'", [':w' => $w])->fetchColumn()) {
            throw new StaffRequestRefusal('Pick the colleague who covers the shift.');
        }
        $det['with_staff_id'] = $w;
        if (!empty($in['accepted'])) $det['accepted_at'] = date('c');
    }
    if ($types[$type][1] !== null) {
        foreach (staff_request_conflicts($staffId, $from, $to)['overlap'] as $o) {
            if (($types[$o['leave_type']][1] ?? null) !== null) {
                throw new StaffRequestRefusal('These dates overlap ' . strtolower(staff_request_type_label((string)$o['leave_type'])) . ' from '
                    . date('j M', strtotime((string)$o['start_date'])) . ' to ' . date('j M Y', strtotime((string)$o['end_date'])) . ' (' . strtolower(staff_request_status((string)$o['status'])[0]) . ').');
            }
        }
    }
    if (!empty($in['doc_id'])) $det['doc_id'] = (int)$in['doc_id'];
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        db_query("INSERT INTO leave_requests (hr_staff_id, start_date, end_date, leave_type, reason, status, requested_by, details, doc_id)
                  VALUES (:s, :a, :b, :t, :r, 'pending', :by, CAST(:d AS jsonb), :doc)",
            [':s' => $staffId, ':a' => $from, ':b' => $to, ':t' => $type, ':r' => $reason !== '' ? $reason : null, ':by' => $byAdmin ?: null,
             ':d' => json_encode($det ?: new stdClass()), ':doc' => !empty($in['doc_id']) ? (int)$in['doc_id'] : null]);
        $id = (int) $pdo->lastInsertId();
        if ($approveAs !== null) staff_request_approve($id, $approveAs, $byAdmin, true);
        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Approve the current step. $role: 'owner' | 'manager' | 'hr' (the page checked the
 * person is in scope). Owner/HR at step 1 records both steps. Reaching 'approved'
 * applies the request to the attendance. Returns the new status.
 */
function staff_request_approve(int $id, string $role, ?int $byAdmin, bool $inScope): string {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $r = db_query('SELECT * FROM leave_requests WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$r) throw new StaffRequestRefusal('That request no longer exists.');
        if (!staff_request_can_act((string)$r['status'], $role, $inScope)) {
            throw new StaffRequestRefusal($r['status'] === 'pending_hr' ? 'HR gives the final approval.' : 'This request is not waiting for you.');
        }
        // Final when HR / the owner approves, or when the supervisor approves a type HR
        // does not need to see (owner's choice: staff_request_hr_types()).
        $final = in_array($role, ['owner', 'hr'], true) || ($r['status'] === 'pending' && !staff_request_needs_hr((string)$r['leave_type']));
        if ($r['status'] === 'pending') {
            db_query('UPDATE leave_requests SET supervisor_by = :u, supervisor_at = now(), updated_at = now() WHERE id = :id', [':u' => $byAdmin ?: null, ':id' => $id]);
        }
        if ($final) {
            $hrStep = in_array($role, ['owner', 'hr'], true);
            db_query("UPDATE leave_requests SET " . ($hrStep ? 'hr_by = :u, hr_at = now(), ' : '') . "status = 'approved', decided_by = :u, decided_at = now(), updated_at = now() WHERE id = :id",
                [':u' => $byAdmin ?: null, ':id' => $id]);
            staff_request_apply($id, $byAdmin);
            $status = 'approved';
        } else {
            db_query("UPDATE leave_requests SET status = 'pending_hr', updated_at = now() WHERE id = :id", [':id' => $id]);
            $status = 'pending_hr';
        }
        if ($own) $pdo->commit();
        return $status;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Decline at the current step, with a reason. */
function staff_request_decline(int $id, string $role, ?int $byAdmin, bool $inScope, string $reason): void {
    $reason = trim($reason);
    if ($reason === '') throw new StaffRequestRefusal('Say why it is declined — the person is told.');
    $r = db_query('SELECT status FROM leave_requests WHERE id = :id', [':id' => $id])->fetch();
    if (!$r) throw new StaffRequestRefusal('That request no longer exists.');
    if (!staff_request_can_act((string)$r['status'], $role, $inScope)) throw new StaffRequestRefusal('This request is not waiting for you.');
    $col = $r['status'] === 'pending' ? 'supervisor' : 'hr';
    db_query("UPDATE leave_requests SET status = 'declined', decline_reason = :why, decided_by = :u, decided_at = now(),
                     {$col}_by = :u, {$col}_at = now(), updated_at = now() WHERE id = :id", [':why' => $reason, ':u' => $byAdmin ?: null, ':id' => $id]);
}

/**
 * Cancel. A waiting request: anyone who may act on it (or who entered it). An
 * approved one: owner / HR only, with a reason — its attendance changes are undone
 * (each day back to what it held before).
 */
function staff_request_cancel(int $id, string $role, ?int $byAdmin, bool $inScope, string $reason): void {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $r = db_query('SELECT * FROM leave_requests WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$r) throw new StaffRequestRefusal('That request no longer exists.');
        if (!$inScope) throw new StaffRequestRefusal('That person is not one of yours.');
        if (in_array($r['status'], ['declined', 'cancelled'], true)) throw new StaffRequestRefusal('It is already ' . $r['status'] . '.');
        if ($r['status'] === 'approved') {
            if (!in_array($role, ['owner', 'hr'], true)) throw new StaffRequestRefusal('An approved request is cancelled by HR — ask them.');
            if (trim($reason) === '') throw new StaffRequestRefusal('Say why the approved request is cancelled.');
            staff_request_unapply($id);
        }
        db_query("UPDATE leave_requests SET status = 'cancelled', cancelled_by = :u, cancelled_at = now(), decline_reason = COALESCE(NULLIF(:why, ''), decline_reason),
                         updated_at = now() WHERE id = :id", [':u' => $byAdmin ?: null, ':why' => trim($reason), ':id' => $id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Write an approved request into the attendance (inside the approving transaction).
 * Keeps each day's previous row in details.replaced and the days it skipped (worked
 * or clocked) in details.skipped.
 */
function staff_request_apply(int $id, ?int $byAdmin): void {
    $r = db_query("SELECT l.*, s.off_day FROM leave_requests l JOIN hr_staff s ON s.id = l.hr_staff_id WHERE l.id = :id", [':id' => $id])->fetch();
    $det = json_decode((string)$r['details'], true) ?: [];
    $sid = (int)$r['hr_staff_id'];
    $code = staff_request_types()[$r['leave_type']][1] ?? null;
    $days = [];
    if ($code !== null) $days = staff_request_stamp_days((string)$r['start_date'], (string)$r['end_date'], (string)$r['off_day']);
    elseif ($r['leave_type'] === 'correction') $days = [(string)$r['start_date']];
    $replaced = []; $skipped = [];
    foreach ($days as $d) {
        $cur = db_query('SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date = :d FOR UPDATE', [':s' => $sid, ':d' => $d])->fetch() ?: null;
        if ($code !== null) {
            $punched = attendance_punches_supported_safe() && db_query('SELECT 1 FROM attendance_punches WHERE hr_staff_id = :s AND work_date = :d LIMIT 1', [':s' => $sid, ':d' => $d])->fetchColumn();
            if ($cur && (attendance_hours($cur) > 0 || ($cur['source'] ?? '') === 'kiosk' || $punched)) { $skipped[] = $d; continue; }
        }
        $replaced[$d] = $cur ? array_intersect_key($cur, array_flip(['status', 'in1', 'out1', 'in2', 'out2', 'note', 'source', 'night_duty'])) : null;
        if ($code !== null) {
            $note = !empty($det['half_day']) ? 'Half day' : null;
            db_query("INSERT INTO attendance (hr_staff_id, work_date, status, in1, out1, in2, out2, note, logged_by, source, request_id)
                      VALUES (:s, :d, :st, NULL, NULL, NULL, NULL, :n, :lb, 'request', :rid)
                      ON CONFLICT (hr_staff_id, work_date) DO UPDATE SET status = :st, in1 = NULL, out1 = NULL, in2 = NULL, out2 = NULL,
                             note = :n, logged_by = :lb, source = 'request', request_id = :rid, updated_at = now()",
                [':s' => $sid, ':d' => $d, ':st' => $code, ':n' => $note, ':lb' => $byAdmin ?: null, ':rid' => $id]);
        } else {
            $t = $det['times'] ?? [];
            db_query("INSERT INTO attendance (hr_staff_id, work_date, status, in1, out1, in2, out2, note, logged_by, source, request_id)
                      VALUES (:s, :d, NULL, :i1, :o1, :i2, :o2, :n, :lb, 'request', :rid)
                      ON CONFLICT (hr_staff_id, work_date) DO UPDATE SET status = NULL, in1 = :i1, out1 = :o1, in2 = :i2, out2 = :o2,
                             note = :n, logged_by = :lb, source = 'request', request_id = :rid, updated_at = now()",
                [':s' => $sid, ':d' => $d, ':i1' => $t['in1'] ?? null, ':o1' => $t['out1'] ?? null, ':i2' => $t['in2'] ?? null, ':o2' => $t['out2'] ?? null,
                 ':n' => 'Corrected: ' . (string)($r['reason'] ?? ''), ':lb' => $byAdmin ?: null, ':rid' => $id]);
        }
    }
    // A shift swap exchanges the two people's PLANNED shifts that day (the rota) —
    // what each had is kept so cancelling the swap puts them back.
    if ($r['leave_type'] === 'swap' && staff_rota_supported() && !empty($det['with_staff_id'])) {
        $det['rota_swap'] = staff_rota_swap($sid, (int)$det['with_staff_id'], (string)$r['start_date'], $byAdmin);
    }
    $det['replaced'] = $replaced;
    $det['skipped']  = $skipped;
    db_query('UPDATE leave_requests SET details = CAST(:d AS jsonb) WHERE id = :id', [':d' => json_encode($det), ':id' => $id]);
}

/** Undo an approved request's attendance changes: each day back to what it held before (only days still carrying this request). */
function staff_request_unapply(int $id): void {
    $r = db_query('SELECT * FROM leave_requests WHERE id = :id', [':id' => $id])->fetch();
    $det = json_decode((string)$r['details'], true) ?: [];
    if (!empty($det['rota_swap']) && staff_rota_supported()) staff_rota_restore($det['rota_swap'], (string)$r['start_date'], null);
    foreach (($det['replaced'] ?? []) as $d => $prev) {
        $cur = db_query('SELECT id FROM attendance WHERE hr_staff_id = :s AND work_date = :d AND request_id = :rid FOR UPDATE',
            [':s' => (int)$r['hr_staff_id'], ':d' => $d, ':rid' => $id])->fetchColumn();
        if (!$cur) continue;   // changed since by someone else — leave it
        if ($prev === null) { db_query('DELETE FROM attendance WHERE id = :id', [':id' => (int)$cur]); continue; }
        db_query('UPDATE attendance SET status = :st, in1 = :i1, out1 = :o1, in2 = :i2, out2 = :o2, note = :n, source = :src, request_id = NULL, updated_at = now()'
                 . (attendance_night_supported() ? ', night_duty = :nd' : '') . ' WHERE id = :id',
            [':st' => $prev['status'] ?? null, ':i1' => $prev['in1'] ?? null, ':o1' => $prev['out1'] ?? null, ':i2' => $prev['in2'] ?? null,
             ':o2' => $prev['out2'] ?? null, ':n' => $prev['note'] ?? null, ':src' => $prev['source'] ?? null, ':id' => (int)$cur]
            + (attendance_night_supported() ? [':nd' => isset($prev['night_duty']) && $prev['night_duty'] !== null ? ($prev['night_duty'] ? 'true' : 'false') : null] : []));
    }
}

/** attendance_punches exists? (local copy of the clock module's guard — this file must not need it loaded) */
function attendance_punches_supported_safe(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.attendance_punches')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * Who approves next, by email address: step 1 ('pending') = active managers whose
 * properties include the person's home or extra property, or who look after their
 * company; step 2 ('pending_hr') = active HR accounts. Nobody found → HR, then the
 * owners. The person who just acted is never emailed about their own step.
 */
function staff_request_approver_emails(int $staffId, string $status, ?int $exceptAdmin = null): array {
    $p = db_query('SELECT * FROM hr_staff WHERE id = :s', [':s' => $staffId])->fetch();
    if (!$p) return [];
    $emails = [];
    $add = function (array $rows) use (&$emails, $exceptAdmin) {
        foreach ($rows as $row) {
            if ((int)$row['id'] !== (int)$exceptAdmin && filter_var((string)$row['email'], FILTER_VALIDATE_EMAIL)) $emails[strtolower((string)$row['email'])] = true;
        }
    };
    if ($status === 'pending') {
        $venues = array_values(array_filter(array_merge([(int)($p['venue_id'] ?? 0)], hr_staff_venue_ids($staffId))));
        if ($venues) {
            $add(db_query("SELECT DISTINCT a.id, a.email FROM admin_users a JOIN admin_user_venues v ON v.admin_user_id = a.id
                            WHERE a.role = 'manager' AND a.is_active AND v.venue_id IN (" . implode(',', $venues) . ")")->fetchAll());
        }
        if (!empty($p['company_id']) && hr_staff_company_supported()) {
            $add(db_query("SELECT a.id, a.email FROM admin_users a JOIN hr_company_managers c ON c.admin_user_id = a.id
                            WHERE a.role = 'manager' AND a.is_active AND c.company_id = :c", [':c' => (int)$p['company_id']])->fetchAll());
        }
    }
    if (!$emails) $add(db_query("SELECT id, email FROM admin_users WHERE role = 'hr' AND is_active")->fetchAll());
    if (!$emails) $add(db_query("SELECT id, email FROM admin_users WHERE role = 'owner' AND is_active")->fetchAll());
    return array_keys($emails);
}

/** Email the next approver(s). Best-effort: a mail problem never undoes the request. Returns how many were emailed. */
function staff_request_notify(int $id, ?int $actorAdmin): int {
    try {
        $r = staff_request_fetch($id);
        if (!$r || !in_array($r['status'], ['pending', 'pending_hr'], true)) return 0;
        require_once __DIR__ . '/mail.php';
        $to = staff_request_approver_emails((int)$r['hr_staff_id'], (string)$r['status'], $actorAdmin);
        foreach ($to as $addr) send_staff_request_waiting($r, $addr, (string)$r['status']);
        return count($to);
    } catch (Throwable $e) {
        error_log('[staff request] notify failed: ' . $e->getMessage());
        return 0;
    }
}

// ── Telling the employee ────────────────────────────────────────────────────

/**
 * The message to send someone about their decided request (staff have no login —
 * a manager or HR tells them, on WhatsApp). Pure.
 */
function staff_request_result_text(array $r): string {
    $first = trim((string) strtok((string)($r['full_name'] ?? ''), ' '));
    $first = $first !== '' ? ucfirst(strtolower($first)) : 'Hello';
    $what  = strtolower(staff_request_type_label((string)$r['leave_type']));
    $dates = date('j M', strtotime((string)$r['start_date'])) . ($r['end_date'] !== $r['start_date'] ? ' to ' . date('j M', strtotime((string)$r['end_date'])) : '');
    return match ((string)$r['status']) {
        'approved'  => "Hi {$first}, your {$what} for {$dates} is approved.",
        'declined'  => "Hi {$first}, your {$what} for {$dates} was not approved" . (trim((string)($r['decline_reason'] ?? '')) !== '' ? ': ' . trim((string)$r['decline_reason']) : '') . '. Please speak to your supervisor.',
        'cancelled' => "Hi {$first}, your {$what} for {$dates} has been cancelled.",
        default     => "Hi {$first}, your {$what} for {$dates} is waiting for approval.",
    };
}

// ── Policy: which requests need HR's approval ───────────────────────────────

/** The types that need HR's final approval (setting staff_request_hr_types; default: every type). */
function staff_request_hr_types(): array {
    $all = array_keys(staff_request_types());
    $raw = setting('staff_request_hr_types', '');
    if ($raw === '') return $all;
    $v = json_decode($raw, true);
    return is_array($v) ? array_values(array_intersect($all, $v)) : $all;
}
function staff_request_needs_hr(string $type): bool { return in_array($type, staff_request_hr_types(), true); }
function staff_request_set_hr_types(array $types): void {
    set_setting('staff_request_hr_types', json_encode(array_values(array_intersect(array_keys(staff_request_types()), $types))));
}

// ── Check before entering ───────────────────────────────────────────────────

/**
 * What entering this request would do, for the form to show BEFORE submitting:
 * ['days' => [Y-m-d that would be stamped], 'off' => [weekly off days left alone],
 *  'worked' => [days already worked / clocked — left alone], 'overlap' => [other
 *  open/approved requests], 'balance' => ['off_days','annual_leave'], 'needs_hr' => bool].
 */
function staff_request_check(int $staffId, string $type, string $from, string $to): array {
    $p = db_query('SELECT off_day FROM hr_staff WHERE id = :s', [':s' => $staffId])->fetch();
    $out = ['days' => [], 'off' => [], 'worked' => [], 'overlap' => [], 'balance' => [], 'needs_hr' => staff_request_needs_hr($type)];
    if (!$p || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return $out;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) $to = $from;
    if ((strtotime($to) - strtotime($from)) / 86400 > 120) $to = date('Y-m-d', strtotime($from . ' +120 days'));
    $c = staff_request_conflicts($staffId, $from, $to);
    $out['worked'] = $c['worked'];
    foreach ($c['overlap'] as $o) {
        $out['overlap'][] = ['type' => staff_request_type_label((string)$o['leave_type']), 'from' => $o['start_date'], 'to' => $o['end_date'],
                             'status' => staff_request_status((string)$o['status'])[0]];
    }
    if ((staff_request_types()[$type][1] ?? null) !== null) {
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (in_array($d, $out['worked'], true)) continue;            // named once, as worked
            if (attendance_is_off_day((string)$p['off_day'], $d)) $out['off'][] = $d;
            else $out['days'][] = $d;
        }
    }
    if (function_exists('hr_bal_balances')) $out['balance'] = hr_bal_balances([$staffId])[$staffId] ?? [];
    return $out;
}

// ── Reminders ───────────────────────────────────────────────────────────────

/**
 * Remind the approver(s) of requests waiting at their step for $days or more —
 * at most once a day per request (details.reminded_on). Run daily by the
 * scheduler (bin/staff-request-reminders.php). Returns how many requests.
 */
function staff_requests_remind(int $days = 2): int {
    if (!staff_requests_supported()) return 0;
    $today = date('Y-m-d');
    $n = 0;
    foreach (db_query("SELECT id, details FROM leave_requests WHERE status IN ('pending','pending_hr')
                        AND updated_at < now() - make_interval(days => :d)", [':d' => max(1, $days)])->fetchAll() as $r) {
        $det = json_decode((string)$r['details'], true) ?: [];
        if (($det['reminded_on'] ?? '') === $today) continue;
        // Claim first, so two scheduler tasks never send the same reminder twice.
        $claimed = db_query("UPDATE leave_requests SET details = jsonb_set(details, '{reminded_on}', to_jsonb(CAST(:t AS text)))
                              WHERE id = :id AND COALESCE(details->>'reminded_on', '') <> :t", [':t' => $today, ':id' => (int)$r['id']])->rowCount();
        if (!$claimed) continue;
        if (staff_request_notify((int)$r['id'], null) > 0) $n++;
    }
    return $n;
}

// ── Medical notes ───────────────────────────────────────────────────────────

/** Is this employee document a sick / maternity note attached to a request? Then only the owner and HR open it. */
function staff_request_doc_is_medical(int $docId): bool {
    if ($docId <= 0 || !staff_requests_supported()) return false;
    try {
        return (bool) db_query("SELECT 1 FROM leave_requests WHERE doc_id = :d AND leave_type IN ('sick','maternity') LIMIT 1", [':d' => $docId])->fetchColumn();
    } catch (Throwable $e) { return false; }
}
