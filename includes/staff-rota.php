<?php
/**
 * Staff rota — the shift each person is planned to work each day (migration
 * add_staff_rota.sql). Planned on Attendance → Week → "Plan the week"; shown above
 * what was worked on the weekly grid; an approved shift swap exchanges two
 * people's planned shifts (and cancelling the swap puts them back).
 *
 * Rules:
 *  • One planned shift per person per day; choosing "—" removes it.
 *  • The shift's times are copied onto the row, so a later change to the presets
 *    never rewrites an old plan.
 *  • Copying last week's plan never overwrites a day already planned this week.
 *  • The plan never changes the attendance — it is a plan; attendance is what happened.
 * Test: php tests/staff_rota_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/attendance.php';

function staff_rota_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.staff_rota')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** The shifts a day can be planned as: key => [label, short, start, end] (minutes; end ≥ 1440 = next morning). */
function staff_rota_shifts(): array {
    return [
        'off'      => ['Off',                          'Off',   null, null],
        'day'      => ['Day 08:00–17:00',              'Day',   480,  1020],
        'early'    => ['Early 06:00–14:00',            'Early', 360,  840],
        'late'     => ['Late 14:00–22:00',             'Late',  840,  1320],
        'secday'   => ['Security day 06:00–18:00',     'S-day', 360,  1080],
        'secnight' => ['Security night 18:00–06:00',   'Night', 1080, 1800],
    ];
}

/** Short label for a planned row ("Day", "Night 18:00–06:00"). Pure. */
function staff_rota_label(?array $row, bool $withTimes = false): string {
    if (!$row) return '';
    $s = staff_rota_shifts()[$row['shift']] ?? null;
    $short = $s ? $s[1] : ucfirst((string)$row['shift']);
    if (!$withTimes || $row['start_min'] === null) return $short;
    return $short . ' ' . attendance_min_to_hhmm((int)$row['start_min']) . '–' . attendance_min_to_hhmm((int)$row['end_min']);
}

/** Planned rows for these people and days: [staff id => [Y-m-d => row]]. */
function staff_rota_for(array $staffIds, string $from, string $to): array {
    if (!staff_rota_supported() || !$staffIds) return [];
    $in = implode(',', array_map('intval', $staffIds));
    $out = [];
    foreach (db_query("SELECT * FROM staff_rota WHERE hr_staff_id IN ($in) AND work_date BETWEEN :a AND :b", [':a' => $from, ':b' => $to])->fetchAll() as $r) {
        $out[(int)$r['hr_staff_id']][(string)$r['work_date']] = $r;
    }
    return $out;
}

/** Plan one day ($shift '' = no plan). Returns true when something changed. */
function staff_rota_set(int $staffId, string $ymd, string $shift, ?int $adminId): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    if ($shift === '') {
        return db_query('DELETE FROM staff_rota WHERE hr_staff_id = :s AND work_date = :d', [':s' => $staffId, ':d' => $ymd])->rowCount() > 0;
    }
    $p = staff_rota_shifts()[$shift] ?? null;
    if (!$p) return false;
    $cur = db_query('SELECT shift FROM staff_rota WHERE hr_staff_id = :s AND work_date = :d', [':s' => $staffId, ':d' => $ymd])->fetchColumn();
    if ($cur === $shift) return false;
    db_query("INSERT INTO staff_rota (hr_staff_id, work_date, shift, start_min, end_min, created_by)
              VALUES (:s, :d, :sh, :a, :b, :u)
              ON CONFLICT (hr_staff_id, work_date) DO UPDATE SET shift = :sh, start_min = :a, end_min = :b, created_by = :u, updated_at = now()",
        [':s' => $staffId, ':d' => $ymd, ':sh' => $shift, ':a' => $p[2], ':b' => $p[3], ':u' => $adminId ?: null]);
    return true;
}

/**
 * Copy the plan of the week starting $fromMonday onto the week starting $toMonday
 * for these people, day for day. Days already planned in the target week are left
 * alone. Returns how many days were copied.
 */
function staff_rota_copy_week(array $staffIds, string $fromMonday, string $toMonday, ?int $adminId): int {
    if (!staff_rota_supported() || !$staffIds) return 0;
    $src = staff_rota_for($staffIds, $fromMonday, date('Y-m-d', strtotime($fromMonday . ' +6 days')));
    $dst = staff_rota_for($staffIds, $toMonday, date('Y-m-d', strtotime($toMonday . ' +6 days')));
    $shift = (int) round((strtotime($toMonday) - strtotime($fromMonday)) / 86400);
    $n = 0;
    foreach ($src as $sid => $days) {
        foreach ($days as $d => $row) {
            $t = date('Y-m-d', strtotime($d . " +{$shift} days"));
            if (isset($dst[$sid][$t])) continue;
            if (staff_rota_set($sid, $t, (string)$row['shift'], $adminId)) $n++;
        }
    }
    return $n;
}

/**
 * Plan vs worked for one day, for the weekly grid. Returns '' (fine), 'missing'
 * (a working shift was planned on a day that has passed and nothing was recorded),
 * or 'unplanned' (worked on a day planned as Off). Pure.
 */
function staff_rota_day_flag(?array $plan, ?array $att, string $ymd, string $today): string {
    if (!$plan) return '';
    $worked = $att && attendance_effective_status($att) === 'P';
    if ($plan['shift'] === 'off') return $worked ? 'unplanned' : '';
    if ($ymd < $today && !$att) return 'missing';
    return '';
}

/**
 * Exchange two people's planned shifts on a day (an approved shift swap). Returns
 * what each had before ([staff id => row|null]) so it can be put back.
 */
function staff_rota_swap(int $a, int $b, string $ymd, ?int $adminId): array {
    $rows = staff_rota_for([$a, $b], $ymd, $ymd);
    $ra = $rows[$a][$ymd] ?? null; $rb = $rows[$b][$ymd] ?? null;
    $before = [$a => $ra ? ['shift' => $ra['shift']] : null, $b => $rb ? ['shift' => $rb['shift']] : null];
    staff_rota_set($a, $ymd, $rb ? (string)$rb['shift'] : 'off', $adminId);
    staff_rota_set($b, $ymd, $ra ? (string)$ra['shift'] : 'off', $adminId);
    return $before;
}

/** Put planned shifts back as staff_rota_swap() found them. */
function staff_rota_restore(array $before, string $ymd, ?int $adminId): void {
    foreach ($before as $sid => $row) staff_rota_set((int)$sid, $ymd, $row ? (string)$row['shift'] : '', $adminId);
}
