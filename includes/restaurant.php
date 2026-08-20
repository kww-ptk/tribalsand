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

/** Occasions offered on the booking form. Empty/absent is allowed. */
function restaurant_occasions(): array {
    return ['romantic', 'birthday', 'anniversary', 'business', 'other'];
}

/**
 * Validate a booking request against a venue's hours.
 * Returns a field => message map; an empty array means valid.
 *
 * $todayYmd is injected rather than read from date() so this stays pure and
 * the tests do not rot. Callers pass date('Y-m-d') (Nairobi-local).
 */
function restaurant_validate(array $in, array $cfg, string $todayYmd): array {
    // Pure only while $todayYmd parses. Not reachable from request data (callers
    // pass date('Y-m-d')), but guard anyway rather than silently reading the
    // real clock or throwing a TypeError under strict_types.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayYmd)) $todayYmd = date('Y-m-d');

    $err = [];

    // json_decode() can hand us any type. Casting an array to string yields the
    // literal "Array" (which passes a non-empty check) AND emits a warning that
    // corrupts the JSON response body, so reject non-scalars outright.
    $str = static fn($v) => (is_string($v) || is_int($v) || is_float($v)) ? trim((string)$v) : '';

    $name  = $str($in['name']  ?? '');
    $email = $str($in['email'] ?? '');
    if ($name === '')                               $err['name']  = 'Your name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err['email'] = 'A valid email address is required.';

    // Names/emails feed straight into mail headers (Task 5), so cap length and
    // reject CR/LF here — the single choke point — to prevent header injection.
    if ($name !== '' && (mb_strlen($name) > 120 || preg_match('/[\r\n]/', $name))) {
        $err['name'] = 'Please enter your name as plain text, up to 120 characters.';
    }
    if ($email !== '' && (mb_strlen($email) > 120 || preg_match('/[\r\n]/', $email))) {
        $err['email'] = 'Please enter your email as plain text, up to 120 characters.';
    }

    // is_numeric is false for bool, array and null, so party_size: true doesn't
    // silently become 1, and party_size: "20abc" doesn't silently become 20.
    $party = is_numeric($in['party_size'] ?? null) ? (int)$in['party_size'] : 0;
    if ($party < RESTAURANT_PARTY_MIN) {
        $err['party_size'] = 'Please tell us how many are dining.';
    } elseif ($party > RESTAURANT_PARTY_MAX) {
        $err['party_size'] = 'For parties over ' . RESTAURANT_PARTY_MAX
                           . ', please call us so we can look after you properly.';
    }

    $date = $str($in['date'] ?? '');
    $time = $str($in['time'] ?? '');

    // Same strict calendar check restaurant_slots_for() already uses, so a
    // regex-valid but impossible date (e.g. 2026-09-31) gets one honest message
    // instead of a misleading "closed that day" / "beyond horizon" one.
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateOk = $parsed !== false && $parsed->format('Y-m-d') === $date;
    if (!$dateOk) {
        $err['date'] = 'Please choose a date.';
    } elseif ($date < $todayYmd) {
        $err['date'] = 'That date has already passed.';
    } elseif ($date > date('Y-m-d', strtotime($todayYmd . ' +' . RESTAURANT_HORIZON_DAYS . ' days'))) {
        $err['date'] = 'We take bookings up to ' . RESTAURANT_HORIZON_DAYS . ' days ahead.';
    }

    // Only check the time once the date is sound — slots depend on the weekday.
    if (!isset($err['date'])) {
        $slots = restaurant_slots_for($date, $cfg);
        if (!$slots) {
            $err['date'] = 'We are closed that day. Please choose another date.';
        } elseif (!in_array($time, $slots, true)) {
            $err['time'] = 'Please choose one of the available times.';
        }
    }

    $occasion = $str($in['occasion'] ?? '');
    if ($occasion !== '' && !in_array($occasion, restaurant_occasions(), true)) {
        $err['occasion'] = 'Please choose one of the listed occasions.';
    }

    return $err;
}

/** Reference alphabet: no 0/O or 1/I, so a code survives being read over the phone. */
const RESTAURANT_REF_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

/** The only legal status moves. Anything else — including a no-op — is refused. */
function restaurant_can_transition(string $from, string $to): bool {
    $allowed = [
        'pending'   => ['confirmed', 'declined'],
        'confirmed' => ['cancelled'],
        'declined'  => [],
        'cancelled' => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

/** Generate a guest-quotable reference, e.g. ZR-8F3K2. Uniqueness is the DB's job. */
function restaurant_make_reference(): string {
    $out = '';
    $max = strlen(RESTAURANT_REF_ALPHABET) - 1;
    for ($i = 0; $i < 5; $i++) {
        $out .= RESTAURANT_REF_ALPHABET[random_int(0, $max)];
    }
    return 'ZR-' . $out;
}

/** Admin badge colour for a status — reuses the existing .badge--* classes. */
function restaurant_status_badge_class(string $status): string {
    return match ($status) {
        'pending'   => 'badge--orange',
        'confirmed' => 'badge--green',
        'declined'  => 'badge--red',
        default     => 'badge--grey',
    };
}
