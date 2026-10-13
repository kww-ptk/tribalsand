<?php
/**
 * Attendance import — HR's Excel attendance workbook into the `attendance` table.
 *
 * HR keeps one workbook per year with, per month, a TIME SHEET (per person per
 * day: time in · time out · time in · time out · total hours · status · overtime)
 * and an ATTENDANCE code grid (P / OFF / SICK / LEAVE per day), plus summaries.
 * The layout drifts month to month (a number column or not, property section
 * headers, people added, a sheet named "JULY 2027" that holds July 2026), so the
 * parser never trusts sheet names or fixed rows:
 *  • a time sheet is found by its header row (≥ 3 "TIME IN" cells); each day's
 *    block starts at a "TIME IN" and its date is the cell above it;
 *  • a code grid is a row of ≥ 20 dates with "POSITION" in it;
 *  • the month is the dates' own month.
 * Times: the workbook uses the 1904 date system, so a night clock-out reads
 * "1904-01-02 06:00" = 1.25 days = 06:00 the next day (minutes ≥ 1440, the
 * `attendance` convention). A plain out earlier than its in also crosses midnight.
 *
 * Safety: the parsed months are stored in attendance_imports (not the session);
 * Preview writes nothing; Import never overwrites a kiosk day, and a day typed in
 * the system by hand only when HR ticks "replace". Every imported row carries
 * source='import' + import_id, so a re-import of the same month refreshes it.
 * Test: php tests/attendance_import_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/hr-establishment.php';
require_once __DIR__ . '/xlsx-reader.php';
require_once __DIR__ . '/hr-balances.php';

/** add_attendance_import.sql applied? (catalog lookups — safe inside a transaction) */
function attendance_import_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = (bool) db_query("SELECT to_regclass('public.attendance_imports')")->fetchColumn()
            && (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'attendance' AND column_name = 'source'")->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

// ── Pure: cells ─────────────────────────────────────────────────────────────

/**
 * A time cell → minutes after midnight, or null. Accepts the stored Excel number
 * (0.333… = 08:00; 1.25 = 06:00 next day = 1800) and typed text ("8:00", "08:00:00",
 * "07:00+1"). A number ≥ 2 is a full date-time someone typed — only its time counts.
 */
function att_imp_minutes(string $v): ?int {
    $v = trim($v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $f = (float)$v;
        if ($f < 0) return null;
        if ($f >= 2) $f -= floor($f);
        return (int) round($f * 1440);
    }
    if (preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?\s*(\+1)?$/', $v, $m)) {
        $min = (int)$m[1] * 60 + (int)$m[2];
        if ((int)$m[1] > 23 && !isset($m[3])) return $min;   // "30:00" = 06:00 next day
        return $min + (isset($m[3]) && $m[3] === '+1' ? 1440 : 0);
    }
    return null;
}

/** A header cell → 'Y-m-d' when it is a date (serial in the workbook's system, or text), else null. */
function att_imp_date(string $v, bool $is1904): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $f = (float)$v;
        if ($f < 30000 || $f > 80000) return null;   // not a date in 1982–2123 either system
        return xlsx_serial_to_ymd($f, $is1904);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
    return null;
}

/** Property words HR types in the status column for "worked at another unit that day". */
const ATT_IMP_UNIT_WORDS = ['ZURI', 'MK', 'MAYA KOBE', 'MAYA ILAI', 'ILAI', 'ENKARE', 'SANDBOX', 'MY AMANI', 'TRIBAL TABLE', 'OFFICE', 'TRIBAL DUNES'];

/**
 * The workbook's day status → [code, known, note].
 *  • the usual codes map to the system's (P, OFF, SK, LV, REC, PH, ABS, UL);
 *  • a property name ("ZURI") = on duty at another unit → P with a note;
 *  • junk copied into the column (0, a weekday name, "Not empl") → no code;
 *  • a sentence ("DATE OF HIRE - 11 AUGUST 2026") → no code, kept as a note;
 *  • anything else (OFF R, CONS, N …) is kept upper-case and flagged unknown —
 *    HR decides what it means on the preview; it is never guessed.
 */
function att_imp_status(string $raw): array {
    $k = strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw)));
    if ($k === '' || $k === '0' || in_array($k, hr_off_days(), true)) return ['', true, ''];
    if (str_starts_with($k, 'NOT EMPL')) return ['', true, ''];
    if (in_array($k, ATT_IMP_UNIT_WORDS, true)) return ['P', true, 'On duty at ' . ucwords(strtolower($k))];
    $code = match ($k) {
        'P', 'PRESENT'                          => 'P',
        'OFF', 'O'                              => 'OFF',
        'SICK', 'SK', 'S'                       => 'SK',
        'LEAVE', 'LV', 'L', 'AL'                => 'LV',
        'REC', 'R', 'RECOVERY'                  => 'REC',
        'PH', 'P/H', 'PUBLIC HOLIDAY'           => 'PH',
        'ABS', 'A', 'ABSENT'                    => 'ABS',
        'UNP/L', 'UNPL', 'UL', 'UNPAID', 'UNPAID LEAVE' => 'UL',
        default                                 => null,
    };
    if ($code !== null) return [$code, true, ''];
    if (preg_match('/\d/', $k) || mb_strlen($k) > 10) return ['', true, trim($raw)];   // a note, not a code
    return [$k, false, ''];
}

/**
 * Two times as stored: [in, out] in minutes. HR's sheet writes a next-morning
 * clock-out either as a next-day date ("1904-01-02 06:00" → 1800, kept as is) or,
 * in Jan–Apr 2026, as a plain earlier time ("18:00 → 06:00", their total says 12 h).
 * A plain out earlier than its in therefore crosses midnight ONLY when it is the
 * day's single section and the shift is at most CLOCK_NIGHT_SHIFT_MAX_MIN (14 h, the
 * kiosk's own limit). Anything else ("17:25 → 08:55" as a second section, "06:00 →
 * 03:21") is a typo: kept as written, 0 hours, flagged by att_imp_day_flags().
 */
function att_imp_segment(?int $in, ?int $out, bool $onlySection = true): array {
    if ($in !== null && $out !== null && $out < 1440 && $out < $in
        && $onlySection && ($out + 1440 - $in) <= ATT_IMP_NIGHT_MAX_MIN) {
        $out += 1440;
    }
    return [$in, $out];
}

/** Longest shift a reversed time can be read as (minutes) — the kiosk's CLOCK_NIGHT_SHIFT_MAX_MIN. */
const ATT_IMP_NIGHT_MAX_MIN = 840;

/**
 * What is odd about one parsed day, for the check report:
 * 'missing'  a time in without its time out (or the reverse),
 * 'reversed' a time out earlier than its time in,
 * 'differs'  our hours ≠ the sheet's own TOTAL HRS (when that total is sensible, 0–24),
 * 'timesOnAbsence' the day is marked OFF / SICK / LEAVE … but had times (counted as the absence).
 */
function att_imp_day_flags(array $d): array {
    $missing  = ($d['in1'] !== null) !== ($d['out1'] !== null) || ($d['in2'] !== null) !== ($d['out2'] !== null);
    $reversed = ($d['in1'] !== null && $d['out1'] !== null && $d['out1'] < $d['in1'])
             || ($d['in2'] !== null && $d['out2'] !== null && $d['out2'] < $d['in2']);
    $xh = $d['xh'] ?? null;
    $differs = !$missing && !$reversed && empty($d['timesOnAbsence']) && $xh !== null && $xh >= 0 && $xh <= 24 && abs(att_imp_day_hours($d) - (float)$xh) > 0.05;
    return ['missing' => $missing, 'reversed' => $reversed, 'differs' => $differs, 'timesOnAbsence' => !empty($d['timesOnAbsence'])];
}

/** Sorted name words — the key a workbook name is remembered by. */
function att_imp_name_key(string $name): string {
    $t = hr_est_name_tokens($name);
    sort($t);
    return implode(' ', $t);
}

// ── Pure: sheets ────────────────────────────────────────────────────────────

/**
 * A TIME SHEET → ['kind'=>'timesheet','ym','people'=>[...]] or null when the rows
 * are not one. $rows: sparse [rowIndex => [col => string]] as xlsx_stream_sheet()
 * gives them. Each person: ['row','name','position','off','section','days' =>
 * ['Y-m-d' => ['in1','out1','in2','out2','status','raw','known','xh']]] — xh is the
 * sheet's own TOTAL HRS WORKED (for the check report), null when blank.
 */
function att_imp_parse_timesheet(array $rows, bool $is1904): ?array {
    ksort($rows);
    $hdr = null; $blocks = [];
    foreach ($rows as $ri => $r) {
        $ins = array_keys(array_filter($r, fn($v) => strtoupper(trim($v)) === 'TIME IN'));
        if (count($ins) >= 3) { $hdr = $ri; $hr = $r; break; }
    }
    if ($hdr === null) return null;
    $up = fn($v) => strtoupper(trim((string)$v));
    $posCol = null;
    foreach ($hr as $c => $v) if ($up($v) === 'POSITION') { $posCol = $c; break; }
    if ($posCol === null || $posCol < 1) return null;
    $nameCol = $posCol - 1; $offCol = $posCol + 1;
    // A block starts at a TIME IN followed by TIME OUT, TIME IN, TIME OUT, TOTAL…, STATUS.
    $dateRow = $rows[$hdr - 1] ?? [];
    foreach ($hr as $c => $v) {
        if ($up($v) !== 'TIME IN' || $up($hr[$c + 1] ?? '') !== 'TIME OUT' || $up($hr[$c + 2] ?? '') !== 'TIME IN') continue;
        $ymd = null;
        for ($k = $c; $k <= $c + 7 && $ymd === null; $k++) $ymd = att_imp_date((string)($dateRow[$k] ?? ''), $is1904);
        if ($ymd === null) continue;
        $st = $c + 5; $tot = $c + 4;
        for ($k = $c + 4; $k <= $c + 7; $k++) { if ($up($hr[$k] ?? '') === 'STATUS') $st = $k; if (str_starts_with($up($hr[$k] ?? ''), 'TOTAL')) $tot = $k; }
        $blocks[$ymd] = ['c' => $c, 'st' => $st, 'tot' => $tot];
    }
    if (count($blocks) < 7) return null;
    $months = array_count_values(array_map(fn($d) => substr($d, 0, 7), array_keys($blocks)));
    arsort($months);
    $ym = (string) array_key_first($months);

    $people = []; $section = '';
    foreach ($rows as $ri => $r) {
        if ($ri <= $hdr) continue;
        $name = trim((string) preg_replace('/\s+/', ' ', (string)($r[$nameCol] ?? '')));
        if ($name === '' || preg_match('/^(TOTAL|STAFF ATTENDANCE)/i', $name)) continue;
        $pos  = trim((string)($r[$posCol] ?? ''));
        $off  = strtoupper(trim((string)($r[$offCol] ?? '')));
        $days = []; $anyTime = false;
        foreach ($blocks as $ymd => $b) {
            if (substr($ymd, 0, 7) !== $ym) continue;
            $c = $b['c'];
            $t = [];
            foreach ([0 => 'in1', 1 => 'out1', 2 => 'in2', 3 => 'out2'] as $k => $f) $t[$f] = att_imp_minutes((string)($r[$c + $k] ?? ''));
            $two = $t['in2'] !== null || $t['out2'] !== null;
            [$t['in1'], $t['out1']] = att_imp_segment($t['in1'], $t['out1'], !$two);
            [$t['in2'], $t['out2']] = att_imp_segment($t['in2'], $t['out2'], $t['in1'] === null && $t['out1'] === null);
            $raw = trim((string)($r[$b['st']] ?? ''));
            [$code, $known, $note] = att_imp_status($raw);
            $hasTime = $t['in1'] !== null || $t['out1'] !== null || $t['in2'] !== null || $t['out2'] !== null;
            if ($hasTime) $anyTime = true;
            if (!$hasTime && $code === '') continue;
            $xh = (string)($r[$b['tot']] ?? '');
            $days[$ymd] = att_imp_absence_wins($t + ['status' => $code, 'raw' => $raw, 'known' => $known, 'note' => $note,
                                'xh' => is_numeric($xh) ? round((float)$xh * 24, 2) : null]);
        }
        // A heading row (a property name) has no position, no off day and no times — its
        // copied formulas can still leave "OFF" in a few status cells.
        if (!$anyTime && $pos === '' && !in_array($off, hr_off_days(), true)) { $section = $name; continue; }
        $people[] = ['row' => $ri + 1, 'name' => $name, 'position' => $pos,
                     'off' => in_array($off, hr_off_days(), true) ? $off : '', 'section' => $section, 'days' => $days];
    }
    return ['kind' => 'timesheet', 'ym' => $ym, 'people' => $people];
}

/**
 * An ATTENDANCE code grid → ['kind'=>'grid','ym','people'=>[['row','name','position','off','section','codes'=>['Y-m-d'=>raw]]]]
 * or null. Used only to fill days the time sheet leaves empty (sick, leave).
 */
function att_imp_parse_grid(array $rows, bool $is1904): ?array {
    ksort($rows);
    $hdr = null; $dates = [];
    foreach ($rows as $ri => $r) {
        $d = [];
        foreach ($r as $c => $v) if (($x = att_imp_date((string)$v, $is1904)) !== null) $d[$c] = $x;
        if (count($d) >= 20 && array_filter($r, fn($v) => strtoupper(trim((string)$v)) === 'POSITION')) { $hdr = $ri; $dates = $d; $hr = $r; break; }
    }
    if ($hdr === null) return null;
    $posCol = (int) array_key_first(array_filter($hr, fn($v) => strtoupper(trim((string)$v)) === 'POSITION'));
    if ($posCol < 1) return null;
    $months = array_count_values(array_map(fn($d) => substr($d, 0, 7), $dates));
    arsort($months);
    $ym = (string) array_key_first($months);
    $people = []; $section = '';
    foreach ($rows as $ri => $r) {
        if ($ri <= $hdr) continue;
        $name = trim((string) preg_replace('/\s+/', ' ', (string)($r[$posCol - 1] ?? '')));
        if ($name === '' || preg_match('/^(TOTAL|STAFF ATTENDANCE|UNIT)/i', $name)) continue;
        $codes = [];
        foreach ($dates as $c => $ymd) {
            if (substr($ymd, 0, 7) !== $ym) continue;
            $v = trim((string)($r[$c] ?? ''));
            if ($v !== '' && !is_numeric($v)) $codes[$ymd] = $v;
        }
        $pos = trim((string)($r[$posCol] ?? ''));
        $off = strtoupper(trim((string)($r[$posCol + 1] ?? '')));
        $real = array_filter($codes, fn($v) => strtoupper(trim($v)) !== 'OFF');
        if ($pos === '' && !in_array($off, hr_off_days(), true) && !$real) { $section = $name; continue; }
        $people[] = ['row' => $ri + 1, 'name' => $name, 'position' => $pos,
                     'off' => in_array($off, hr_off_days(), true) ? $off : '', 'section' => $section, 'codes' => $codes];
    }
    return ['kind' => 'grid', 'ym' => $ym, 'people' => $people];
}

/**
 * One month from its time sheet (and code grid, when there is one): the grid only
 * fills days the time sheet has nothing for; a person only on the grid is kept
 * with status-only days. People are keyed by att_imp_name_key().
 */
function att_imp_merge_month(?array $ts, ?array $grid): array {
    $out = [];
    foreach ($ts['people'] ?? [] as $p) {
        $k = att_imp_name_key($p['name']);
        if ($k === '') continue;
        if (isset($out[$k])) { $out[$k]['days'] += $p['days']; continue; }   // a name listed twice
        $out[$k] = $p + ['from' => 'timesheet'];
    }
    foreach ($grid['people'] ?? [] as $g) {
        $k = att_imp_name_key($g['name']);
        if ($k === '') continue;
        if (!isset($out[$k])) $out[$k] = ['row' => $g['row'], 'name' => $g['name'], 'position' => $g['position'], 'off' => $g['off'],
                                           'section' => $g['section'], 'days' => [], 'from' => 'grid'];
        foreach ($g['codes'] as $ymd => $raw) {
            if (isset($out[$k]['days'][$ymd])) continue;
            [$code, $known, $note] = att_imp_status($raw);
            if ($code === '') continue;
            $out[$k]['days'][$ymd] = ['in1' => null, 'out1' => null, 'in2' => null, 'out2' => null,
                                      'status' => $code, 'raw' => $raw, 'known' => $known, 'note' => $note, 'xh' => null];
        }
        if ($out[$k]['off'] === '' && $g['off'] !== '') $out[$k]['off'] = $g['off'];
    }
    foreach ($out as &$p) ksort($p['days']);
    unset($p);
    return array_values($out);
}

/**
 * Read a whole workbook: every sheet is tried as a time sheet, then as a code grid;
 * the rest (summaries, balances) are ignored. When two sheets claim one month the
 * one with more people wins. Returns ['is_1904', 'months' => [ym => ['sheets' =>
 * [names], 'people' => [...]]], 'skipped' => [sheet names], 'balances' => opening balances from
 * the newest consolidated summary sheet (hr_bal_parse_summary()) or null]. Throws RuntimeException.
 */
function att_imp_read_workbook(string $path): array {
    $zip    = xlsx_file_bytes($path);
    $is1904 = xlsx_is_1904($zip);
    $ss     = xlsx_zip_read($zip, 'xl/sharedStrings.xml');
    $shared = $ss !== null ? xlsx_shared_strings($ss) : [];
    $ts = []; $grids = []; $skipped = []; $balances = null;
    foreach (xlsx_sheet_parts($zip) as $name => $part) {
        $rows = [];
        xlsx_stream_sheet($zip, $part, $shared, function (int $ri, array $cells) use (&$rows) { $rows[$ri] = $cells; });
        $name = (string)$name;
        if (($t = att_imp_parse_timesheet($rows, $is1904)) !== null) {
            if (!isset($ts[$t['ym']]) || count($t['people']) > count($ts[$t['ym']]['people'])) $ts[$t['ym']] = $t + ['sheet' => $name];
        } elseif (($g = att_imp_parse_grid($rows, $is1904)) !== null) {
            if (!isset($grids[$g['ym']]) || count($g['people']) > count($grids[$g['ym']]['people'])) $grids[$g['ym']] = $g + ['sheet' => $name];
        } else {
            $skipped[] = $name;
            // HR's consolidated summary carries the last balances they worked out — the opening point.
            $b = hr_bal_parse_summary($rows);
            if ($b !== null && ($balances === null || $b['as_of'] > $balances['as_of'])) $balances = $b + ['sheet' => $name];
        }
        unset($rows);
    }
    $months = [];
    foreach (array_unique(array_merge(array_keys($ts), array_keys($grids))) as $ym) {
        $months[$ym] = [
            'sheets' => array_values(array_filter([$ts[$ym]['sheet'] ?? null, $grids[$ym]['sheet'] ?? null])),
            'people' => att_imp_merge_month($ts[$ym] ?? null, $grids[$ym] ?? null),
        ];
    }
    ksort($months);
    if (!$months) throw new RuntimeException('No attendance month found — the workbook needs a time sheet (TIME IN / TIME OUT columns) or an attendance grid (a row of dates with POSITION).');
    return ['is_1904' => $is1904, 'months' => $months, 'skipped' => $skipped, 'balances' => $balances];
}

// ── Pure: what happens to one day ───────────────────────────────────────────

/**
 * What the import does with one day. $new: the parsed day; $cur: the existing
 * attendance row or null; $punched: kiosk punches exist that day.
 * Returns 'write' (no row yet) · 'update' (an earlier import, now different) ·
 * 'same' (already identical) · 'kiosk' (kept: clocked on the kiosk) ·
 * 'manual' (kept: typed in the system — or 'replace' when $replaceManual).
 */
function att_imp_day_action(array $new, ?array $cur, bool $punched, bool $replaceManual): string {
    if ($punched || ($cur['source'] ?? '') === 'kiosk') return 'kiosk';
    if ($cur === null) return 'write';
    $same = true;
    foreach (['in1', 'out1', 'in2', 'out2'] as $f) {
        $a = $cur[$f] ?? null; $b = $new[$f] ?? null;
        if (($a === null ? null : (int)$a) !== ($b === null ? null : (int)$b)) { $same = false; break; }
    }
    // A worked day is stored with no status (P is implied by the hours) — compare like with like.
    $ns = ($new['status'] === 'P' && att_imp_day_hours($new) > 0) ? '' : (string)$new['status'];
    if ($same && (string)($cur['status'] ?? '') !== $ns) $same = false;
    if ($same) return 'same';
    if (($cur['source'] ?? '') === 'import') return 'update';
    return $replaceManual ? 'replace' : 'manual';
}

/** Codes that mean "did not work" — on the sheet they win over times typed on the same day. */
const ATT_IMP_ABSENCE_CODES = ['OFF', 'SK', 'LV', 'UL', 'ABS', 'REC'];

/**
 * A day marked OFF / SICK / LEAVE / UNPAID / ABSENT / RECOVERY that ALSO has times
 * (copied from the row above, usually): HR counts it as the absence — their own
 * month summary does — so the times move into the note and the day is flagged
 * ('timesOnAbsence'). A public holiday with times is a holiday worked: kept as is.
 */
function att_imp_absence_wins(array $d): array {
    if (!in_array($d['status'], ATT_IMP_ABSENCE_CODES, true)) return $d;
    $has = $d['in1'] !== null || $d['out1'] !== null || $d['in2'] !== null || $d['out2'] !== null;
    if (!$has) return $d;
    $fmt = fn(?int $m) => $m === null ? '?' : attendance_min_to_hhmm($m);
    $times = $fmt($d['in1']) . '–' . $fmt($d['out1']) . ($d['in2'] !== null || $d['out2'] !== null ? ', ' . $fmt($d['in2']) . '–' . $fmt($d['out2']) : '');
    $d['note'] = trim(($d['note'] ?? '') . ' Sheet also had times ' . $times . '.');
    $d['in1'] = $d['out1'] = $d['in2'] = $d['out2'] = null;
    $d['timesOnAbsence'] = true;
    return $d;
}

/**
 * One person's days from two sheet rows (listed twice in a month): a day with
 * times beats a day with only a code; otherwise the first row's day stays.
 */
function att_imp_merge_days(array $a, array $b): array {
    $has = fn(array $d) => $d['in1'] !== null || $d['out1'] !== null || $d['in2'] !== null || $d['out2'] !== null;
    foreach ($b as $ymd => $d) {
        if (!isset($a[$ymd]) || (!$has($a[$ymd]) && $has($d))) $a[$ymd] = $d;
    }
    ksort($a);
    return $a;
}

/** Hours worked on a parsed day (the same rule as attendance_hours()). */
function att_imp_day_hours(array $d): float {
    return attendance_hours(['in1' => $d['in1'], 'out1' => $d['out1'], 'in2' => $d['in2'], 'out2' => $d['out2']]);
}

// ── DB ──────────────────────────────────────────────────────────────────────

/** Store an uploaded workbook's parsed months. Returns the import id. */
function att_imp_store(string $path, string $filename, ?int $adminId): int {
    if (!attendance_import_supported()) throw new RuntimeException('Run the add_attendance_import migration first.');
    $wb = att_imp_read_workbook($path);
    db_query('INSERT INTO attendance_imports (filename, is_1904, months, uploaded_by) VALUES (:f, :y, CAST(:m AS jsonb), :u)',
        [':f' => mb_substr($filename, 0, 200), ':y' => $wb['is_1904'] ? 'TRUE' : 'FALSE',
         ':m' => json_encode($wb['months'], JSON_UNESCAPED_UNICODE), ':u' => $adminId ?: null]);
    $id = (int) db()->lastInsertId();
    if ($wb['balances'] !== null && hr_balances_supported()) {
        db_query('UPDATE attendance_imports SET balances = CAST(:b AS jsonb) WHERE id = :id',
            [':b' => json_encode($wb['balances'], JSON_UNESCAPED_UNICODE), ':id' => $id]);
    }
    return $id;
}

/** One stored import (months decoded), or null. */
function att_imp_fetch(int $id): ?array {
    if (!attendance_import_supported()) return null;
    $r = db_query('SELECT * FROM attendance_imports WHERE id = :id', [':id' => $id])->fetch();
    if (!$r) return null;
    $r['months']   = json_decode((string)$r['months'], true) ?: [];
    $r['applied']  = json_decode((string)$r['applied'], true) ?: [];
    $r['balances'] = isset($r['balances']) && $r['balances'] !== null ? (json_decode((string)$r['balances'], true) ?: null) : null;
    return $r;
}

/** Recent imports for the page's history list. */
function att_imp_recent(int $limit = 10): array {
    if (!attendance_import_supported()) return [];
    return db_query("SELECT i.id, i.filename, i.uploaded_at, i.applied, u.name AS uploaded_by_name,
                            (SELECT array_to_string(array_agg(k ORDER BY k), ',') FROM jsonb_object_keys(i.months) k) AS month_list
                       FROM attendance_imports i LEFT JOIN admin_users u ON u.id = i.uploaded_by
                      ORDER BY i.id DESC LIMIT " . max(1, $limit))->fetchAll();
}

/** Remembered workbook-name → person choices. */
function att_imp_name_map(): array {
    if (!attendance_import_supported()) return [];
    $m = [];
    foreach (db_query('SELECT name_key, hr_staff_id FROM attendance_import_names')->fetchAll() as $r) $m[(string)$r['name_key']] = (int)$r['hr_staff_id'];
    return $m;
}

function att_imp_remember_name(string $nameKey, int $staffId): void {
    if ($nameKey === '' || $staffId <= 0) return;
    db_query('INSERT INTO attendance_import_names (name_key, hr_staff_id) VALUES (:k, :s)
              ON CONFLICT (name_key) DO UPDATE SET hr_staff_id = EXCLUDED.hr_staff_id, updated_at = now()',
        [':k' => $nameKey, ':s' => $staffId]);
}

/**
 * Match a month's workbook names to people. A remembered choice wins; else the
 * staff-establishment name rules (order-free words, never a guess) over EVERY
 * person, active or not — past months include people who have since left.
 * Returns [name_key => ['status' => 'remembered'|'name'|'new'|'decide',
 * 'staff_id', 'candidates', 'why']].
 */
function att_imp_match_people(array $people): array {
    $existing = db_query('SELECT id, full_name, venue_id, position, unit_label, status FROM hr_staff ORDER BY id')->fetchAll();
    foreach ($existing as &$e) { $e['company_id'] = null; $e['payroll_no'] = null; }   // a company file is no obstacle here
    unset($e);
    $map  = att_imp_name_map();
    $byId = [];
    foreach ($existing as $e) $byId[(int)$e['id']] = $e;
    $rows = []; $keys = [];
    foreach ($people as $p) { $keys[] = att_imp_name_key($p['name']); $rows[] = ['payroll_no' => '', 'name' => $p['name']]; }
    $m = hr_est_match($rows, $existing, 0);
    $out = [];
    foreach ($keys as $i => $k) {
        if (isset($map[$k], $byId[$map[$k]])) {
            $out[$k] = ['status' => 'remembered', 'staff_id' => $map[$k], 'candidates' => [], 'why' => 'Chosen at an earlier import.'];
        } elseif (isset($m[$i]['claimed'])) {
            // The sheet lists one person twice in a month (main block + Tribal Table): both rows are
            // them — their days are merged on import (att_imp_merge_days()).
            $out[$k] = ['status' => 'name', 'staff_id' => (int)$m[$i]['claimed'], 'candidates' => $m[$i]['candidates'], 'why' => 'Listed twice on the sheet.'];
        } else {
            $out[$k] = $m[$i];
        }
    }
    return $out;
}

/**
 * What importing one month would do — writes nothing. Returns ['ym', 'people' =>
 * [person + 'key','match','staff','counts'=>[action=>n],'hours','xhours','unknown'=>[codes]],
 * 'totals' => [action=>n], 'unknown' => [code=>n]].
 */
function att_imp_preview(array $import, string $ym, bool $replaceManual = false, array $decisions = [], ?array $codeMap = null): array {
    $codeMap ??= att_imp_code_map();
    $month  = $import['months'][$ym] ?? null;
    if (!$month) throw new RuntimeException('That month is not in this workbook.');
    $people = $month['people'];
    $match  = att_imp_match_people($people);
    $staff  = [];
    foreach (db_query('SELECT id, full_name, venue_id, status FROM hr_staff')->fetchAll() as $s) $staff[(int)$s['id']] = $s;
    [$from, $to] = [$ym . '-01', date('Y-m-d', strtotime($ym . '-01 +1 month'))];
    $cur = []; $punch = [];
    foreach (db_query('SELECT * FROM attendance WHERE work_date >= :a AND work_date < :b', [':a' => $from, ':b' => $to])->fetchAll() as $r) {
        $cur[(int)$r['hr_staff_id']][(string)$r['work_date']] = $r;
    }
    if (attendance_punches_table_exists()) {
        foreach (db_query('SELECT DISTINCT hr_staff_id, work_date FROM attendance_punches WHERE work_date >= :a AND work_date < :b', [':a' => $from, ':b' => $to])->fetchAll() as $r) {
            $punch[(int)$r['hr_staff_id']][(string)$r['work_date']] = true;
        }
    }
    $totals = ['write' => 0, 'update' => 0, 'replace' => 0, 'same' => 0, 'kiosk' => 0, 'manual' => 0, 'unmatched' => 0];
    $unknown = []; $out = [];
    foreach ($people as $p) {
        $k  = att_imp_name_key($p['name']);
        $mt = $match[$k] ?? ['status' => 'new', 'staff_id' => null, 'candidates' => [], 'why' => ''];
        $sid = att_imp_resolved_staff($mt, (string)($decisions[$k] ?? ''));
        $counts = []; $hours = 0.0; $xhours = 0.0; $xhSeen = false; $unk = [];
        $flags = ['missing' => [], 'reversed' => [], 'differs' => [], 'timesOnAbsence' => []];
        foreach ($p['days'] as $ymd => $d0) {
            if (!$d0['known']) { $unk[$d0['status']] = true; $unknown[$d0['status']] = ($unknown[$d0['status']] ?? 0) + 1; }
            $d = att_imp_apply_code($d0, $codeMap);
            if ($d === null) continue;
            $hours += att_imp_day_hours($d);
            if ($d['xh'] !== null) { $xhours += (float)$d['xh']; $xhSeen = true; }
            foreach (att_imp_day_flags($d) as $fk => $fv) if ($fv) $flags[$fk][] = $ymd;
            $a = is_int($sid) ? att_imp_day_action($d, $cur[$sid][$ymd] ?? null, isset($punch[$sid][$ymd]), $replaceManual)
               : (in_array($sid, ['former', 'add'], true) ? 'write' : 'unmatched');
            $counts[$a] = ($counts[$a] ?? 0) + 1;
            $totals[$a] = ($totals[$a] ?? 0) + 1;
        }
        foreach ($mt['candidates'] as &$c) $c['name'] = (string)($staff[$c['id']]['full_name'] ?? ('#' . $c['id']));
        unset($c);
        $out[] = $p + ['key' => $k, 'match' => $mt, 'staff' => is_int($sid) ? ($staff[$sid] ?? null) : null, 'sid' => $sid, 'counts' => $counts, 'flags' => $flags,
                       'hours' => round($hours, 2), 'xhours' => $xhSeen ? round($xhours, 2) : null, 'unknown' => array_keys($unk)];
    }
    return ['ym' => $ym, 'sheets' => $month['sheets'], 'people' => $out, 'totals' => $totals, 'unknown' => $unknown];
}

/** What HR answered for codes the importer doesn't know ("OFF R" => "REC"), remembered in settings. */
function att_imp_code_map(): array {
    $m = json_decode(setting('attendance_import_codes', '{}'), true);
    return is_array($m) ? array_filter($m, 'is_string') : [];
}

/** Codes an unknown code may be read as ('' = leave those days out, 'keep' = store the code as written). */
function att_imp_code_targets(): array {
    return ['' => 'Leave those days out', 'keep' => 'Keep the code as written'] + attendance_statuses();
}

/** A parsed day with HR's answer for an unknown code applied (known codes untouched). Null = leave the day out. */
function att_imp_apply_code(array $d, array $codeMap): ?array {
    if (!empty($d['known'])) return $d;
    $to = $codeMap[$d['status']] ?? 'keep';
    if ($to === 'keep') return $d;
    if ($to === '' || !isset(attendance_statuses()[$to])) {
        $hasTime = $d['in1'] !== null || $d['out1'] !== null || $d['in2'] !== null || $d['out2'] !== null;
        return $hasTime ? ['status' => 'P', 'known' => true] + $d : null;
    }
    return ['status' => $to, 'known' => true] + $d;
}

/** attendance_punches present? (kiosk punches decide which days are never overwritten) */
function attendance_punches_table_exists(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.attendance_punches')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * The person a workbook name lands on: a posted choice ('skip', 'add' = add to the
 * directory (a trainee / new hire not on any payroll list yet), 'former' = add as a
 * former employee (inactive), 'match:<id>' — any existing person, HR picks freely),
 * else the automatic match ('remembered' / 'name'); 'new' and 'decide' rows with no
 * choice import nothing. Returns a staff id, 'former', or null.
 */
function att_imp_resolved_staff(array $match, string $posted): int|string|null {
    if ($posted === 'skip') return null;
    if ($posted === 'former' || $posted === 'add') return $posted;
    if (preg_match('/^match:(\d+)$/', $posted, $m)) return (int)$m[1];
    return in_array($match['status'], ['remembered', 'name'], true) ? (int)$match['staff_id'] : null;
}

/**
 * Import one month in ONE transaction. Re-matches and re-checks every day from the
 * database (the preview is never trusted). 'former' people are added to the
 * directory as inactive (their section becomes the unit label). A chosen person is
 * remembered for the next import. Returns ['written','updated','replaced','same',
 * 'kept_kiosk','kept_manual','people','former'].
 */
function att_imp_apply(int $importId, string $ym, array $decisions, bool $replaceManual, ?int $adminId, ?array $codeMap = null): array {
    $codeMap ??= att_imp_code_map();
    $imp = att_imp_fetch($importId);
    if (!$imp) throw new RuntimeException('That upload no longer exists — upload the workbook again.');
    $month = $imp['months'][$ym] ?? null;
    if (!$month) throw new RuntimeException('That month is not in this workbook.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $valid = array_map('intval', array_column(db_query('SELECT id FROM hr_staff')->fetchAll(), 'id'));
        $match = att_imp_match_people($month['people']);
        [$from, $to] = [$ym . '-01', date('Y-m-d', strtotime($ym . '-01 +1 month'))];
        $n = ['written' => 0, 'updated' => 0, 'replaced' => 0, 'same' => 0, 'kept_kiosk' => 0, 'kept_manual' => 0, 'people' => 0, 'former' => 0, 'added' => 0];
        $order = (int) db_query('SELECT COALESCE(MAX(sort_order), 0) FROM hr_staff')->fetchColumn();
        // Resolve every row to a person first; one person listed twice gets ONE merged month.
        $byPerson = [];
        foreach ($month['people'] as $p) {
            $k   = att_imp_name_key($p['name']);
            $sid = att_imp_resolved_staff($match[$k] ?? ['status' => 'new', 'staff_id' => null], (string)($decisions[$k] ?? ''));
            if ($sid === null || !$p['days']) continue;
            if ($sid === 'former' || $sid === 'add') {
                $order += 10;
                db_query("INSERT INTO hr_staff (full_name, position, department, unit_label, off_day, status, sort_order)
                          VALUES (:n, :p, :d, :u, :o, :st, :so)",
                    [':n' => strtoupper(trim((string) preg_replace('/\s+/', ' ', $p['name']))), ':p' => $p['position'], ':d' => hr_department($p['position']),
                     ':u' => $p['section'] !== '' ? $p['section'] : ($sid === 'former' ? 'Former staff' : ''), ':o' => $p['off'],
                     ':st' => $sid === 'former' ? 'inactive' : 'active', ':so' => $order]);
                $n[$sid === 'former' ? 'former' : 'added']++;
                $sid = (int) $pdo->lastInsertId();
                $valid[] = $sid;
            }
            if (!in_array($sid, $valid, true)) continue;
            att_imp_remember_name($k, $sid);
            $byPerson[$sid] = isset($byPerson[$sid]) ? att_imp_merge_days($byPerson[$sid], $p['days']) : $p['days'];
        }
        foreach ($byPerson as $sid => $days) {
            $p = ['days' => $days];
            $n['people']++;
            $cur = [];
            foreach (db_query('SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date >= :a AND work_date < :b FOR UPDATE',
                              [':s' => $sid, ':a' => $from, ':b' => $to])->fetchAll() as $r) $cur[(string)$r['work_date']] = $r;
            $punched = [];
            if (attendance_punches_table_exists()) {
                foreach (db_query('SELECT DISTINCT work_date FROM attendance_punches WHERE hr_staff_id = :s AND work_date >= :a AND work_date < :b',
                                  [':s' => $sid, ':a' => $from, ':b' => $to])->fetchAll() as $r) $punched[(string)$r['work_date']] = true;
            }
            foreach ($p['days'] as $ymd => $d) {
                if (substr($ymd, 0, 7) !== $ym) continue;
                $d = att_imp_apply_code($d, $codeMap);
                if ($d === null) continue;
                $a = att_imp_day_action($d, $cur[$ymd] ?? null, isset($punched[$ymd]), $replaceManual);
                if ($a === 'kiosk')  { $n['kept_kiosk']++;  continue; }
                if ($a === 'manual') { $n['kept_manual']++; continue; }
                if ($a === 'same')   { $n['same']++;        continue; }
                $status = $d['status'] === 'P' && att_imp_day_hours($d) > 0 ? null : ($d['status'] !== '' ? $d['status'] : null);
                db_query(
                    "INSERT INTO attendance (hr_staff_id, work_date, status, in1, out1, in2, out2, note, logged_by, source, import_id)
                     VALUES (:s, :d, :st, :i1, :o1, :i2, :o2, :nt, :lb, 'import', :imp)
                     ON CONFLICT (hr_staff_id, work_date) DO UPDATE SET status = :st, in1 = :i1, out1 = :o1, in2 = :i2, out2 = :o2,
                            note = :nt, logged_by = :lb, source = 'import', import_id = :imp, updated_at = now()",
                    [':s' => $sid, ':d' => $ymd, ':st' => $status, ':i1' => $d['in1'], ':o1' => $d['out1'],
                     ':i2' => $d['in2'], ':o2' => $d['out2'], ':nt' => ($d['note'] ?? '') !== '' ? $d['note'] : null,
                     ':lb' => $adminId ?: null, ':imp' => $importId]);
                $n[$a === 'write' ? 'written' : ($a === 'update' ? 'updated' : 'replaced')]++;
            }
        }
        db_query("UPDATE attendance_imports SET applied = applied || jsonb_build_object(CAST(:ym AS text), CAST(:info AS jsonb)) WHERE id = :id",
            [':ym' => $ym, ':info' => json_encode($n + ['at' => date('c'), 'by' => $adminId]), ':id' => $importId]);
        audit_log('attendance.import', 'attendance_import', $importId, "$ym: " . json_encode($n));
        if ($own) $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Opening balances from the workbook's summary: who each name is (the same matching
 * and remembered choices as the months) — writes nothing. Returns rows of
 * [summary person + 'key','match','staff'].
 */
function att_imp_openings_preview(array $balances): array {
    $match = att_imp_match_people($balances['people']);
    $staff = [];
    foreach (db_query('SELECT id, full_name, contract_start, contract_end FROM hr_staff')->fetchAll() as $s0) $staff[(int)$s0['id']] = $s0;
    $out = [];
    foreach ($balances['people'] as $p) {
        $k = att_imp_name_key($p['name']);
        $m = $match[$k] ?? ['status' => 'new', 'staff_id' => null, 'candidates' => [], 'why' => ''];
        foreach ($m['candidates'] as &$c) $c['name'] = (string)($staff[$c['id']]['full_name'] ?? ('#' . $c['id']));
        unset($c);
        $out[] = $p + ['key' => $k, 'match' => $m, 'staff' => $m['staff_id'] ? ($staff[$m['staff_id']] ?? null) : null];
    }
    return $out;
}

/**
 * Write the opening balances at the summary's date (one transaction): per matched
 * person an 'opening' row for off days (TOTAL PENDING) and one for annual leave
 * (PRORATA LEAVE DAYS), replacing their earlier openings. Empty contract dates are
 * filled from CONTRACT PERIOD when $fillContracts. Returns ['people','contracts'].
 */
function att_imp_apply_openings(int $importId, array $decisions, bool $fillContracts, ?int $adminId): array {
    $imp = att_imp_fetch($importId);
    $bal = $imp['balances'] ?? null;
    if (!$bal) throw new RuntimeException('This workbook has no summary sheet with balances.');
    if (!hr_balances_supported()) throw new RuntimeException('Run the add_hr_balances migration first.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $valid = array_map('intval', array_column(db_query('SELECT id FROM hr_staff')->fetchAll(), 'id'));
        $match = att_imp_match_people($bal['people']);
        $n = ['people' => 0, 'contracts' => 0];
        $done = [];
        foreach ($bal['people'] as $p) {
            $k   = att_imp_name_key($p['name']);
            $sid = att_imp_resolved_staff($match[$k] ?? ['status' => 'new', 'staff_id' => null], (string)($decisions[$k] ?? ''));
            if (!is_int($sid) || !in_array($sid, $valid, true) || isset($done[$sid])) continue;
            $done[$sid] = true;
            att_imp_remember_name($k, $sid);
            $note = 'From HR\'s summary: ' . $bal['label'];
            hr_bal_add_move($sid, 'off_days', 'opening', (float)$p['pending'], $bal['as_of'], $note, $adminId, ['sheet' => $bal['sheet'] ?? '']);
            if ($p['prorata'] !== null) {
                hr_bal_add_move($sid, 'annual_leave', 'opening', (float)$p['prorata'], $bal['as_of'], $note . ' (pro-rata leave)', $adminId,
                    ['sheet' => $bal['sheet'] ?? '', 'leave_balance_on_sheet' => $p['leave_balance']]);
            }
            $n['people']++;
            if ($fillContracts && hr_staff_profile_supported() && ($p['contract_from'] || $p['contract_to'])) {
                $n['contracts'] += db_query('UPDATE hr_staff SET contract_start = COALESCE(contract_start, CAST(:a AS date)),
                                                    contract_end = COALESCE(contract_end, CAST(:b AS date))
                                              WHERE id = :id AND (contract_start IS NULL OR contract_end IS NULL)',
                    [':a' => $p['contract_from'], ':b' => $p['contract_to'], ':id' => $sid])->rowCount();
            }
        }
        audit_log('hr.opening_balances', 'attendance_import', $importId, json_encode($n) . ' as at ' . $bal['as_of']);
        if ($own) $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
