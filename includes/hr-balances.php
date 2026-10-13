<?php
/**
 * Staff balances — off days owed and annual leave — and the monthly figures behind
 * them (what HR's "ATTENDANCE SUMMARIES" sheet worked out by hand).
 *
 * The rule, one per month per person (HR to confirm; their sheets used different
 * formulas in different months, so the system writes ONE down):
 *   entitled off days = the person's weekly off day in the month + public holidays
 *                       (both counted when they fall on the same day, as HR does)
 *   off days taken    = days marked OFF, REC (recovery) or PH (holiday not worked)
 *   overtime hours    = Σ (hours − 8) over the days worked (short days count
 *                       against it) — HR's consolidated summary; overtime days = ÷ 8
 *   off-days balance  += entitled − taken + overtime days   (− days paid out)
 *   annual leave      += 1.75 per month (Kenya: 21 days a year; pro-rata for a part
 *                       month) − days marked LV
 *   Sick, unpaid and absent days change neither balance. Days with NO record at all
 *   change nothing either, and are counted ("not recorded") so HR can see the gap; a
 *   month with no record at all adds nothing (someone away all month, or gone).
 * A part month (joined or left mid-month) counts only from the first / to the last
 * recorded day.
 *
 * Storage: hr_balance_moves is an append-only ledger; a balance is the SUM of its
 * rows. 'opening' = HR's last summary (31 May 2026 in the 2026 workbook), 'month' =
 * Close month (re-closing replaces that month's rows), 'paid_out' and 'adjustment'
 * = HR entries with a note. A month row is only written for months after the
 * person's opening balance, so nothing is counted twice.
 * Test: php tests/hr_balances_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/holidays.php';
require_once __DIR__ . '/hr.php';

const HR_LEAVE_DAYS_PER_MONTH = 1.75;   // 21 working days a year (Employment Act s.28)

/** add_hr_balances.sql applied? (catalog lookup — safe inside a transaction) */
function hr_balances_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.hr_balance_moves')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

// ── Public holidays as HR counts them ───────────────────────────────────────

/**
 * Public holidays HR counts between two dates (inclusive): Y-m-d => name. Starts
 * from the Kenyan calendar (includes/holidays.php) and applies HR's own changes,
 * setting `hr_holiday_overrides` = {"2026-11-08": false, "2026-03-21": "Eid (moved)"}:
 * false switches a day off, a name adds one. Phase 6 gives HR a page for it.
 */
function hr_public_holidays(string $from, string $to): array {
    $over = json_decode(setting('hr_holiday_overrides', '{}'), true);
    $over = is_array($over) ? $over : [];
    $out = [];
    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        foreach (ke_holidays_for_year($y) as $d => $n) $out[$d] = $n;
    }
    foreach ($over as $d => $v) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) continue;
        if ($v === false) unset($out[$d]); elseif (is_string($v) && $v !== '') $out[$d] = $v;
    }
    $out = array_filter($out, fn($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
    ksort($out);
    return $out;
}

// ── Pure: one month ─────────────────────────────────────────────────────────

/**
 * One person's month. $days: [Y-m-d => attendance row] for the month; $offDay:
 * MON..SUN or '' (= Sunday); $holidays: [Y-m-d => name]; $from/$to: the days the
 * person was employed in this month (null = the whole month). Returns the figures
 * (see the file docblock), or null when the window is empty.
 */
function hr_bal_month_figures(array $days, string $offDay, string $ym, array $holidays, ?string $from = null, ?string $to = null): ?array {
    $start = $ym . '-01';
    $end   = date('Y-m-t', strtotime($start));
    $from  = max($start, $from ?? $start);
    $to    = min($end, $to ?? $end);
    if ($from > $to) return null;
    $f = ['from' => $from, 'to' => $to, 'days' => 0, 'entitled' => 0, 'worked' => 0, 'off_taken' => 0, 'rec' => 0, 'ph_taken' => 0,
          'sick' => 0, 'leave' => 0, 'unpaid' => 0, 'absent' => 0, 'other' => 0, 'not_recorded' => 0,
          'hours' => 0.0, 'ot_hours' => 0.0, 'rest_day_worked' => 0, 'holiday_worked' => 0, 'holiday_hours' => 0.0, 'nights' => 0];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $f['days']++;
        $isOff = attendance_is_off_day($offDay, $d);
        $isPh  = isset($holidays[$d]);
        $f['entitled'] += ($isOff ? 1 : 0) + ($isPh ? 1 : 0);
        $row = $days[$d] ?? null;
        if ($row === null) { $f['not_recorded']++; continue; }
        $eff = attendance_effective_status($row);
        switch ($eff) {
            case 'P':
                $f['worked']++;
                $h = attendance_hours($row);
                $f['hours'] += $h;
                $f['ot_hours'] += $h - ATTENDANCE_STANDARD_HOURS;
                if ($isOff) $f['rest_day_worked']++;
                if (attendance_is_night($row)) $f['nights']++;
                if ($isPh)  { $f['holiday_worked']++; $f['holiday_hours'] += $h; }
                break;
            case 'OFF':   $f['off_taken']++; break;
            case 'REC':   $f['off_taken']++; $f['rec']++; break;
            case 'REC_L': $f['off_taken']++; $f['rec']++; break;
            case 'PH':    $f['off_taken']++; $f['ph_taken']++; break;
            case 'SK':    $f['sick']++; break;
            case 'LV':    $f['leave']++; break;
            case 'UL':    $f['unpaid']++; break;
            case 'ABS':   $f['absent']++; break;
            case '':      $f['not_recorded']++; break;
            default:      $f['other']++;
        }
    }
    $monthDays = (int) date('t', strtotime($start));
    $f['hours']        = round($f['hours'], 2);
    $f['holiday_hours']= round($f['holiday_hours'], 2);
    $f['ot_hours']     = round($f['ot_hours'], 2);
    $f['ot_days']      = round($f['ot_hours'] / ATTENDANCE_STANDARD_HOURS, 3);
    $f['off_change']   = $f['entitled'] - $f['off_taken'];
    $f['off_earned']   = round($f['off_change'] + $f['ot_days'], 3);
    $f['leave_accrued']= round(HR_LEAVE_DAYS_PER_MONTH * $f['days'] / $monthDays, 3);
    $f['leave_change'] = round($f['leave_accrued'] - $f['leave'], 3);
    return $f;
}

// ── Pure: opening balances from HR's consolidated summary sheet ─────────────

/**
 * HR's "ATTENDANCE SUMMARIES" sheet → opening balances at its latest month:
 * ['as_of' => 'Y-m-d' (month end), 'label', 'people' => [['name','pending','prorata',
 * 'leave_balance','contract_from','contract_to']]] or null. Finds the header row by
 * its "TOTAL PENDING AS AT …" cells and takes the LAST of them (the newest month).
 */
function hr_bal_parse_summary(array $rows): ?array {
    ksort($rows);
    foreach ($rows as $ri => $r) {
        $pend = [];
        foreach ($r as $c => $v) if (preg_match('/TOTAL\s+PENDING\s+AS\s+AT/i', (string)$v)) $pend[$c] = (string)$v;
        if (!$pend) continue;
        $pc = max(array_keys($pend));
        if (!preg_match('/(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\.?\s+(\d{4})/i', $pend[$pc], $m)) continue;
        $asOf = date('Y-m-t', strtotime('1 ' . $m[1] . ' ' . $m[2]));
        $col = fn(string $re) => array_key_first(array_filter($r, fn($v) => (bool) preg_match($re, (string)$v)));
        $pro = $col('/PRORATA\s+LEAVE/i'); $bal = $col('/^\s*LEAVE\s+BALANCE/i'); $con = $col('/CONTRACT\s+PERIOD/i');
        $num = fn($v) => is_numeric(trim((string)$v)) ? round((float)$v, 3) : null;
        $people = [];
        foreach ($rows as $rj => $p) {
            if ($rj <= $ri) continue;
            $name = trim((string) preg_replace('/\s+/', ' ', (string)($p[0] ?? '')));
            if ($name === '' || is_numeric($name) || preg_match('/^(TOTAL|STAFF NAME)/i', $name)) continue;
            // A property heading: no position, no off day, and it reads like a place.
            if (trim((string)($p[1] ?? '')) === '' && trim((string)($p[2] ?? '')) === '' && hr_bal_is_heading($name)) continue;
            $pending = $num($p[$pc] ?? '');
            if ($pending === null) continue;
            [$cf, $ct] = $con !== null ? hr_bal_contract_period((string)($p[$con] ?? '')) : [null, null];
            $people[] = ['name' => $name, 'pending' => $pending, 'prorata' => $pro !== null ? $num($p[$pro] ?? '') : null,
                         'leave_balance' => $bal !== null ? $num($p[$bal] ?? '') : null, 'contract_from' => $cf, 'contract_to' => $ct];
        }
        return $people ? ['as_of' => $asOf, 'label' => trim($pend[$pc]), 'people' => $people] : null;
    }
    return null;
}

/** A sheet row that names a place, not a person ("ZURI - WATAMU", "Watamu - Plot 6", "Tribal Sand Center (office)"). */
function hr_bal_is_heading(string $name): bool {
    return (bool) preg_match('/\d|\(|\b(TRIBAL TABLE|TRIBAL DUNES|MAYA ILAI|MAYA KOBE|ENKARE|SANDBOX|MY AMANI|ZURI|OFFICE|PLOT|KITE|TRAINEES|WATAMU|KILIFI|VIPINGO)\b/i', $name);
}

/** "1/12/2025 - 31/5/2026" (day first, as HR writes it) → ['2025-12-01', '2026-05-31']; nulls when unreadable. */
function hr_bal_contract_period(string $s): array {
    if (!preg_match_all('#(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m, PREG_SET_ORDER)) return [null, null];
    $d = fn($x) => checkdate((int)$x[2], (int)$x[1], (int)$x[3]) ? sprintf('%04d-%02d-%02d', $x[3], $x[2], $x[1]) : null;
    return [$d($m[0]), isset($m[1]) ? $d($m[1]) : null];
}

// ── DB: balances ────────────────────────────────────────────────────────────

/** Balances per person: [staff id => ['off_days' => n, 'annual_leave' => n]] as at a date (null = everything). */
function hr_bal_balances(array $staffIds, ?string $asOf = null): array {
    if (!hr_balances_supported() || !$staffIds) return [];
    $ids = implode(',', array_map('intval', $staffIds));
    $out = [];
    foreach (db_query("SELECT hr_staff_id, kind, SUM(days) AS d FROM hr_balance_moves WHERE hr_staff_id IN ($ids)"
                      . ($asOf !== null ? ' AND effective_on <= :d' : '') . ' GROUP BY 1, 2', $asOf !== null ? [':d' => $asOf] : [])->fetchAll() as $r) {
        $out[(int)$r['hr_staff_id']][(string)$r['kind']] = round((float)$r['d'], 3);
    }
    return $out;
}

/** The latest opening date per person: [staff id => Y-m-d]. */
function hr_bal_openings(array $staffIds): array {
    if (!hr_balances_supported() || !$staffIds) return [];
    $ids = implode(',', array_map('intval', $staffIds));
    $out = [];
    foreach (db_query("SELECT hr_staff_id, MAX(effective_on) AS d FROM hr_balance_moves WHERE reason = 'opening' AND hr_staff_id IN ($ids) GROUP BY 1")->fetchAll() as $r) {
        $out[(int)$r['hr_staff_id']] = (string)$r['d'];
    }
    return $out;
}

/** One person's ledger, newest first (for the person page). */
function hr_bal_moves(int $staffId, int $limit = 40): array {
    if (!hr_balances_supported()) return [];
    return db_query('SELECT m.*, u.name AS by_name FROM hr_balance_moves m LEFT JOIN admin_users u ON u.id = m.created_by
                      WHERE m.hr_staff_id = :s ORDER BY m.effective_on DESC, m.id DESC LIMIT ' . max(1, $limit), [':s' => $staffId])->fetchAll();
}

/** A closed month's record, or null. */
function hr_bal_month_closed(string $ym): ?array {
    if (!hr_balances_supported()) return null;
    $r = db_query('SELECT c.*, u.name AS by_name FROM hr_month_closes c LEFT JOIN admin_users u ON u.id = c.closed_by WHERE c.period = :p', [':p' => $ym])->fetch();
    return $r ?: null;
}

/**
 * The month for everyone in scope: [['staff' => row, 'fig' => figures|null,
 * 'before' => [kind => n], 'after' => [kind => n], 'opened' => ?Y-m-d,
 * 'counts' => bool (false = the month is covered by their opening balance)]].
 * Figures are worked out live from attendance; balances come from the ledger
 * (before = up to the end of the previous month).
 */
function hr_bal_month(string $ym, ?array $venueIds, ?array $companyIds = null, array $filters = []): array {
    if (!attendance_supported() || !hr_staff_supported()) return [];
    $start = $ym . '-01'; $end = date('Y-m-t', strtotime($start));
    $where = 'WHERE 1=1'; $params = [':a' => $start, ':b' => $end];
    $scope = ''; hr_scope_sql($venueIds, $scope, $params, 's', $companyIds);
    $where .= $scope . attendance_filter_sql($filters, $params);
    // Everyone active, plus anyone (even inactive) with attendance this month.
    $staff = db_query("SELECT s.*, (SELECT MIN(work_date) FROM attendance x WHERE x.hr_staff_id = s.id) AS first_day,
                              (SELECT MAX(work_date) FROM attendance x WHERE x.hr_staff_id = s.id) AS last_day
                         FROM hr_staff s $where
                          AND (s.status = 'active' OR EXISTS (SELECT 1 FROM attendance a WHERE a.hr_staff_id = s.id AND a.work_date BETWEEN :a AND :b))
                        ORDER BY s.company_id NULLS LAST, s.venue_id NULLS LAST, s.sort_order, s.full_name", $params)->fetchAll();
    if (!$staff) return [];
    $ids = array_map(fn($s) => (int)$s['id'], $staff);
    $in  = implode(',', $ids);
    $byStaff = [];
    foreach (db_query("SELECT * FROM attendance WHERE hr_staff_id IN ($in) AND work_date BETWEEN :a AND :b", [':a' => $start, ':b' => $end])->fetchAll() as $r) {
        $byStaff[(int)$r['hr_staff_id']][(string)$r['work_date']] = $r;
    }
    $hol = hr_public_holidays($start, $end);
    $before = hr_bal_balances($ids, date('Y-m-d', strtotime($start . ' -1 day')));
    $after  = hr_bal_balances($ids, $end);
    $opened = hr_bal_openings($ids);
    $out = [];
    foreach ($staff as $s) {
        $sid = (int)$s['id'];
        $days = $byStaff[$sid] ?? [];
        // A part month: joined this month (first record), or left (inactive, last record).
        $from = ($s['first_day'] && $s['first_day'] >= $start) ? (string)$s['first_day'] : null;
        $to   = (($s['status'] ?? '') !== 'active' && $s['last_day'] && $s['last_day'] <= $end) ? (string)$s['last_day'] : null;
        if (!$days) $fig = null;                                          // nothing recorded this month: it adds nothing
        elseif ($s['first_day'] && $s['first_day'] > $end) $fig = null;   // starts later
        else $fig = hr_bal_month_figures($days, (string)$s['off_day'], $ym, $hol, $from, $to);
        $out[] = ['staff' => $s, 'fig' => $fig, 'before' => $before[$sid] ?? [], 'after' => $after[$sid] ?? [],
                  'opened' => $opened[$sid] ?? null, 'counts' => !isset($opened[$sid]) || $opened[$sid] < $start];
    }
    return $out;
}

/**
 * Close a month: writes each person's 'month' rows (off days earned, leave accrued
 * − taken) with the figures as detail, replacing that month's earlier rows. Months
 * already covered by a person's opening balance are skipped for that person.
 * Owner/HR only (the page checks). Refuses a month that has not ended (Nairobi).
 * Returns ['people' => n, 'skipped' => n].
 */
function hr_bal_close_month(string $ym, ?int $adminId): array {
    if (!hr_balances_supported()) throw new RuntimeException('Run the add_hr_balances migration first.');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) throw new RuntimeException('Pick a month.');
    if ($ym >= date('Y-m')) throw new RuntimeException('A month can be closed once it has ended.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        db_query('SELECT pg_advisory_xact_lock(8124001, :k)', [':k' => (int) str_replace('-', '', $ym)]);
        $rows = hr_bal_month($ym, null);
        db_query("DELETE FROM hr_balance_moves WHERE reason = 'month' AND period = :p", [':p' => $ym]);
        $end = date('Y-m-t', strtotime($ym . '-01'));
        $n = ['people' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            if (!$r['fig']) continue;
            if (!$r['counts']) { $n['skipped']++; continue; }
            $sid = (int)$r['staff']['id'];
            foreach (['off_days' => $r['fig']['off_earned'], 'annual_leave' => $r['fig']['leave_change']] as $kind => $d) {
                db_query("INSERT INTO hr_balance_moves (hr_staff_id, kind, reason, period, effective_on, days, detail, created_by)
                          VALUES (:s, :k, 'month', :p, :e, :d, CAST(:det AS jsonb), :by)",
                    [':s' => $sid, ':k' => $kind, ':p' => $ym, ':e' => $end, ':d' => $d, ':det' => json_encode($r['fig']), ':by' => $adminId ?: null]);
            }
            $n['people']++;
        }
        db_query('INSERT INTO hr_month_closes (period, people, closed_by, closed_at) VALUES (:p, :n, :by, now())
                  ON CONFLICT (period) DO UPDATE SET people = EXCLUDED.people, closed_by = EXCLUDED.closed_by, closed_at = now()',
            [':p' => $ym, ':n' => $n['people'], ':by' => $adminId ?: null]);
        audit_log('hr.month_close', 'hr_month', (int) str_replace('-', '', $ym), json_encode($n));
        if ($own) $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * HR's own entry on a balance: 'opening' (replaces that person's earlier opening of
 * this kind), 'paid_out' (stored negative — days paid instead of taken) or
 * 'adjustment' (±, a note is required). Returns the new row id.
 */
function hr_bal_add_move(int $staffId, string $kind, string $reason, float $days, string $on, string $note, ?int $adminId, array $detail = []): int {
    if (!hr_balances_supported()) throw new RuntimeException('Run the add_hr_balances migration first.');
    if (!in_array($kind, ['off_days', 'annual_leave'], true)) throw new RuntimeException('Pick off days or annual leave.');
    if (!in_array($reason, ['opening', 'paid_out', 'adjustment'], true)) throw new RuntimeException('Unknown kind of change.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) throw new RuntimeException('Pick the date.');
    $note = trim($note);
    if ($reason === 'adjustment' && $note === '') throw new RuntimeException('Say why the balance is being corrected.');
    if ($reason === 'paid_out') { if ($days <= 0) throw new RuntimeException('Days paid out must be more than 0.'); $days = -$days; }
    if (abs($days) > 400) throw new RuntimeException('That is more days than a balance can hold.');
    if ($reason === 'opening') {
        db_query("DELETE FROM hr_balance_moves WHERE hr_staff_id = :s AND kind = :k AND reason = 'opening'", [':s' => $staffId, ':k' => $kind]);
    }
    db_query("INSERT INTO hr_balance_moves (hr_staff_id, kind, reason, effective_on, days, detail, note, created_by)
              VALUES (:s, :k, :r, :e, :d, CAST(:det AS jsonb), :n, :by)",
        [':s' => $staffId, ':k' => $kind, ':r' => $reason, ':e' => $on, ':d' => round($days, 3),
         ':det' => json_encode($detail ?: new stdClass()), ':n' => $note !== '' ? $note : null, ':by' => $adminId ?: null]);
    return (int) db()->lastInsertId();
}

/** Short labels for the ledger. */
function hr_bal_reason_label(string $r): string {
    return ['opening' => 'Opening balance', 'month' => 'Month closed', 'paid_out' => 'Paid out', 'adjustment' => 'Correction'][$r] ?? $r;
}

/** "13.125" → "13.1", "-2" → "−2", 0 → "0" — balances read in days, one decimal. */
function hr_bal_fmt(?float $d): string {
    if ($d === null) return '—';
    $s = number_format(round($d, 1), 1);
    $s = str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
    return str_starts_with($s, '-') ? '−' . substr($s, 1) : $s;
}
