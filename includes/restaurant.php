<?php
/**
 * Zuri restaurant reservations — shared logic.
 *
 * Everything here that can be pure IS pure, so api/restaurant-book.php and
 * admin/restaurant.php enforce identical rules and the whole rules layer is
 * testable without a database (tests/restaurant_logic.php).
 *
 * See docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md
 */
declare(strict_types=1);

const RESTAURANT_HORIZON_DAYS = 120;
const RESTAURANT_PARTY_MIN    = 1;
const RESTAURANT_PARTY_MAX    = 20;

/** Default service hours when a venue has none configured. */
function restaurant_default_hours(): array {
    return ['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '18:00', 'to' => '22:00', 'step' => 30];
}

/**
 * Coerce a stored hours config into a valid one, field by field. Any field that
 * is missing or malformed falls back to its default — a broken settings row
 * degrades to "open normal hours", never to "closed forever".
 */
function restaurant_normalise_hours(?array $cfg): array {
    $def = restaurant_default_hours();
    if (!$cfg) return $def;

    $isTime = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;

    $from = $isTime($cfg['from'] ?? null) ? $cfg['from'] : $def['from'];
    $to   = $isTime($cfg['to']   ?? null) ? $cfg['to']   : $def['to'];

    // Both are zero-padded 'HH:MM' strings, so a plain string comparison
    // sorts them the same as comparing minutes-since-midnight would — no
    // helper needed. An inverted or zero-length window (from >= to) must
    // fall back to BOTH defaults together, not just one field: replacing
    // only $from or only $to here could still leave an inverted pair.
    if ($from >= $to) { $from = $def['from']; $to = $def['to']; }

    $step = (isset($cfg['step']) && is_numeric($cfg['step']) && (int)$cfg['step'] > 0)
          ? (int)$cfg['step'] : $def['step'];

    $days = $def['days'];
    if (isset($cfg['days']) && is_array($cfg['days'])) {
        $clean = [];
        foreach ($cfg['days'] as $d) {
            if (is_numeric($d) && (int)$d >= 0 && (int)$d <= 6) $clean[] = (int)$d;
        }
        if ($clean) $days = array_values(array_unique($clean));
    }

    return ['days' => $days, 'from' => $from, 'to' => $to, 'step' => $step];
}

/**
 * Bookable slot times for a date, as 'HH:MM' strings.
 * `from` is inclusive, `to` is exclusive — `to` is closing time, not a seating.
 * Returns [] when the venue is closed that weekday.
 */
function restaurant_slots_for(string $ymd, array $cfg): array {
    $cfg = restaurant_normalise_hours($cfg);

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if ($d === false || $d->format('Y-m-d') !== $ymd) return [];
    if (!in_array((int)$d->format('w'), $cfg['days'], true)) return [];

    [$fh, $fm] = array_map('intval', explode(':', $cfg['from']));
    [$th, $tm] = array_map('intval', explode(':', $cfg['to']));
    $start = $fh * 60 + $fm;
    $end   = $th * 60 + $tm;

    $slots = [];
    for ($m = $start; $m < $end; $m += $cfg['step']) {
        $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $slots;
}
