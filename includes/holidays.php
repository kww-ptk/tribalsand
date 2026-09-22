<?php
declare(strict_types=1);
/**
 * Kenyan public holidays — used to highlight the admin calendars so the team can
 * see at a glance which days are non-working.
 *
 * Three kinds of holiday, in increasing order of how hard they are to pin down:
 *   • Fixed-date  (New Year, Labour Day, Jamhuri, Christmas …) — exact every year.
 *   • Easter-linked (Good Friday, Easter Monday) — computed from the pure-PHP
 *     Anonymous Gregorian algorithm, so no ext/calendar dependency (Windows dev
 *     ships PHP without it enabled).
 *   • Moon-sighting / declared (Eid al-Fitr, Eid al-Adha, Diwali) — gazetted per
 *     year and can shift a day either way, so they come from a small maintainable
 *     table below and are simply ABSENT (never guessed) for years not listed.
 *
 * Every date here is a Nairobi-local Y-m-d (includes/db.php pins the timezone), so
 * a calendar rendering Y-m-d day keys can call ke_holiday_name() directly.
 */

/** Fixed-date Kenyan public holidays: 'm-d' => name. */
function ke_fixed_holidays(): array {
    return [
        '01-01' => "New Year's Day",
        '05-01' => 'Labour Day',
        '06-01' => 'Madaraka Day',
        '10-10' => 'Utamaduni Day',   // formerly Moi Day / Huduma Day
        '10-20' => 'Mashujaa Day',
        '12-12' => 'Jamhuri Day',
        '12-25' => 'Christmas Day',
        '12-26' => 'Boxing Day',
    ];
}

/**
 * Easter Sunday (Y-m-d) for a Gregorian year — Anonymous Gregorian algorithm
 * (Meeus/Jones/Butcher). Pure integer maths, no extension needed.
 */
function ke_easter_sunday(int $year): string {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Gazetted moon-sighting / declared holidays by exact date: 'Y-m-d' => name.
 * These shift yearly with the lunar calendar and the official gazette, so keep
 * this table current; a year that is absent simply shows no Eid/Diwali highlight
 * rather than a wrong one. Dates below reflect the Kenyan observances.
 */
function ke_variable_holidays(): array {
    return [
        // 2024
        '2024-04-10' => 'Eid al-Fitr',
        '2024-06-17' => 'Eid al-Adha',
        '2024-11-01' => 'Diwali',
        // 2025
        '2025-03-31' => 'Eid al-Fitr',
        '2025-06-07' => 'Eid al-Adha',
        '2025-10-20' => 'Diwali',
        // 2026
        '2026-03-20' => 'Eid al-Fitr',
        '2026-05-27' => 'Eid al-Adha',
        '2026-11-08' => 'Diwali',
        // 2027
        '2027-03-10' => 'Eid al-Fitr',
        '2027-05-16' => 'Eid al-Adha',
        '2027-10-29' => 'Diwali',
    ];
}

/**
 * The full holiday map for one year: Y-m-d => name. Built once per year and
 * memoised, so a calendar can call ke_holiday_name() per cell cheaply.
 */
function ke_holidays_for_year(int $year): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];

    $map = [];
    foreach (ke_fixed_holidays() as $md => $name) {
        $map[sprintf('%04d-%s', $year, $md)] = $name;
    }
    $easter = ke_easter_sunday($year);
    $map[date('Y-m-d', strtotime($easter . ' -2 day'))] = 'Good Friday';
    $map[date('Y-m-d', strtotime($easter . ' +1 day'))] = 'Easter Monday';
    foreach (ke_variable_holidays() as $ymd => $name) {
        if (str_starts_with($ymd, sprintf('%04d-', $year))) $map[$ymd] = $name;
    }
    return $cache[$year] = $map;
}

/** Holiday name for a Nairobi-local Y-m-d date, or null if it is an ordinary day. */
function ke_holiday_name(string $ymd): ?string {
    if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $ymd, $m)) return null;
    return ke_holidays_for_year((int)$m[1])[$ymd] ?? null;
}
