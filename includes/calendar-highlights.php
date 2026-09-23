<?php
declare(strict_types=1);
/**
 * Calendar highlights — admin-editable date ranges with a label (e.g. "Kenya
 * school holiday", "Easter week", "Kite festival") shown on the admin calendars
 * (Gantt + Timetable), ADDED to the built-in Kenyan public holidays from
 * includes/holidays.php. Migration: add_calendar_highlights.sql.
 *
 * Two sources, one day map:
 *   • 'ke'     — the computed Kenyan public holidays (always on, never stored).
 *   • 'custom' — calendar_highlights rows, edited in Admin → Calendar highlights.
 * Calendars call cal_day_map($from, $to) ONCE for their visible window (one
 * query), then cal_day_info($map[$ymd] ?? []) per cell — never a query per cell.
 *
 * calendar_highlights.date_to is INCLUSIVE (the last highlighted day).
 * Every read is pre-migration-safe via cal_highlights_supported().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/holidays.php';

/** True once add_calendar_highlights.sql has been applied (memoised). */
function cal_highlights_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.calendar_highlights')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Allowed colours → the friendly name shown in the admin picker. */
function cal_highlight_colors(): array {
    return [
        'amber'  => 'Amber',
        'red'    => 'Red',
        'green'  => 'Green',
        'blue'   => 'Blue',
        'purple' => 'Purple',
    ];
}

/** Strict Y-m-d (a real calendar date) or null. PURE. */
function cal_ymd(string $s): ?string {
    $s = trim($s);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
}

/**
 * Validate a highlight form. PURE + testable.
 * Returns [$clean, $errors]. A blank "to" means a one-day highlight. Ranges are
 * capped at 400 days so a typo (2062 for 2026) can't paint years of calendar.
 */
function cal_highlight_validate(array $in): array {
    $errors = [];
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '')                $errors['label'] = 'Give the highlight a name.';
    elseif (mb_strlen($label) > 120)  $errors['label'] = 'Keep the name under 120 characters.';

    $from = cal_ymd((string)($in['date_from'] ?? ''));
    $toRaw = trim((string)($in['date_to'] ?? ''));
    $to   = $toRaw === '' ? $from : cal_ymd($toRaw);
    if (!$from)                       $errors['date_from'] = 'Pick the first day.';
    if ($toRaw !== '' && !$to)        $errors['date_to'] = 'Pick a valid last day.';
    if ($from && $to && $to < $from)  $errors['date_to'] = 'The last day can’t be before the first day.';
    if ($from && $to && $to >= $from && (strtotime($to) - strtotime($from)) / 86400 > 400) {
        $errors['date_to'] = 'A highlight can cover at most 400 days.';
    }

    $color = (string)($in['color'] ?? 'amber');
    if (!isset(cal_highlight_colors()[$color])) $color = 'amber';

    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    return [['label' => $label, 'date_from' => $from, 'date_to' => $to, 'color' => $color, 'note' => $note], $errors];
}

/** Custom highlight rows overlapping [$from, $to] (both inclusive Y-m-d). [] pre-migration / on error. */
function fetch_cal_highlights_between(string $from, string $to): array {
    if (!cal_highlights_supported() || !cal_ymd($from) || !cal_ymd($to)) return [];
    try {
        return db_query(
            "SELECT * FROM calendar_highlights
             WHERE date_from <= :to AND date_to >= :from
             ORDER BY date_from, id",
            [':from' => $from, ':to' => $to]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[calendar-highlights] fetch failed: ' . $e->getMessage());
        return [];
    }
}

/** Every custom highlight, most recent first by start date (admin list). */
function fetch_all_cal_highlights(): array {
    if (!cal_highlights_supported()) return [];
    try {
        return db_query(
            "SELECT h.*, a.name AS author_name FROM calendar_highlights h
             LEFT JOIN admin_users a ON a.id = h.created_by
             ORDER BY h.date_from DESC, h.id DESC"
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Build the per-day map for a window. PURE core (no I/O) so it's testable:
 * $customRows are calendar_highlights-shaped rows. Kenyan holidays come first
 * on a day, then custom highlights in start-date order.
 * Returns ['Y-m-d' => [['label','color','source'], …], …] — days with nothing are absent.
 */
function cal_build_day_map(string $from, string $to, array $customRows, bool $includeKe = true): array {
    $map = [];
    if (!cal_ymd($from) || !cal_ymd($to) || $to < $from) return $map;
    if ($includeKe) {
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $n = ke_holiday_name($d);
            if ($n !== null) $map[$d][] = ['label' => $n, 'color' => 'red', 'source' => 'ke'];
        }
    }
    foreach ($customRows as $r) {
        $s = max((string)$r['date_from'], $from);
        $e = min((string)$r['date_to'], $to);
        for ($d = $s; $d <= $e; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $map[$d][] = ['label' => (string)$r['label'], 'color' => (string)($r['color'] ?? 'amber'), 'source' => 'custom'];
        }
    }
    return $map;
}

/** Day map for a calendar window: public holidays + stored highlights, one query. */
function cal_day_map(string $from, string $to): array {
    return cal_build_day_map($from, $to, fetch_cal_highlights_between($from, $to));
}

/**
 * Presentation for one day's items. PURE.
 * class: 'is-holiday' when a public holiday falls on it (keeps the existing red
 * styling), else 'is-hl is-hl--<colour>' from the first custom highlight, else ''.
 * title: all labels joined with " · " (tooltip). labels: the list of names.
 */
function cal_day_info(array $items): array {
    if (!$items) return ['class' => '', 'title' => '', 'labels' => []];
    $labels = array_values(array_unique(array_map(fn($i) => (string)$i['label'], $items)));
    $hasKe  = (bool) array_filter($items, fn($i) => ($i['source'] ?? '') === 'ke');
    if ($hasKe) {
        $class = 'is-holiday';
    } else {
        $color = (string)($items[0]['color'] ?? 'amber');
        if (!isset(cal_highlight_colors()[$color])) $color = 'amber';
        $class = 'is-hl is-hl--' . $color;
    }
    return ['class' => $class, 'title' => implode(' · ', $labels), 'labels' => $labels];
}

/** The distinct custom highlights inside a map (for a "this period" legend). PURE. */
function cal_map_custom_legend(array $map): array {
    $seen = [];
    foreach ($map as $items) {
        foreach ($items as $i) {
            if (($i['source'] ?? '') !== 'custom') continue;
            $seen[$i['label'] . '|' . $i['color']] = ['label' => $i['label'], 'color' => $i['color']];
        }
    }
    return array_values($seen);
}

/** Create ($id = 0) or update a highlight. Returns ['ok'=>bool,'errors'=>[],'id'=>int]. */
function cal_highlight_save(int $id, array $in, ?int $adminId): array {
    if (!cal_highlights_supported()) return ['ok' => false, 'errors' => ['_' => 'Run the add_calendar_highlights migration first.'], 'id' => 0];
    [$c, $errors] = cal_highlight_validate($in);
    if ($errors) return ['ok' => false, 'errors' => $errors, 'id' => $id];
    $p = [':l' => $c['label'], ':f' => $c['date_from'], ':t' => $c['date_to'], ':c' => $c['color'], ':n' => $c['note'] !== '' ? $c['note'] : null];
    if ($id > 0) {
        $n = db_query(
            "UPDATE calendar_highlights SET label=:l, date_from=:f, date_to=:t, color=:c, note=:n, updated_at=NOW() WHERE id=:id",
            $p + [':id' => $id]
        )->rowCount();
        return $n ? ['ok' => true, 'errors' => [], 'id' => $id] : ['ok' => false, 'errors' => ['_' => 'That highlight no longer exists.'], 'id' => $id];
    }
    db_query(
        "INSERT INTO calendar_highlights (label, date_from, date_to, color, note, created_by) VALUES (:l, :f, :t, :c, :n, :by)",
        $p + [':by' => $adminId ?: null]
    );
    return ['ok' => true, 'errors' => [], 'id' => (int) db()->lastInsertId()];
}

/** Delete one highlight. True when a row was removed. */
function cal_highlight_delete(int $id): bool {
    if ($id <= 0 || !cal_highlights_supported()) return false;
    return db_query('DELETE FROM calendar_highlights WHERE id = :id', [':id' => $id])->rowCount() > 0;
}

/** "3 Apr – 12 Apr 2026" / "3 Apr 2026" for one day. PURE. */
function cal_range_label(string $from, string $to): string {
    $f = strtotime($from); $t = strtotime($to);
    if ($from === $to) return date('j M Y', $f);
    if (date('Y', $f) === date('Y', $t)) return date('j M', $f) . ' – ' . date('j M Y', $t);
    return date('j M Y', $f) . ' – ' . date('j M Y', $t);
}
