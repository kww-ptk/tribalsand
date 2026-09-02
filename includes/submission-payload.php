<?php
declare(strict_types=1);
// Submission payload rendering for the admin inbox.
//
// `submissions.payload_json` holds a different shape per form. The simple forms
// (enquiry / contact / agency) store a FLAT map of scalars, but the Trip Builder
// stores a NESTED document (guest / trip / departure / special / itinerary).
// The admin view used to render every payload with one flat `e($v)` loop, which
// hit "Array to string conversion" and printed the literal "Array" for every
// Trip Builder section — the guest's whole itinerary was invisible in admin.
//
// Everything here is display-only and must never raise a warning, whatever the
// stored JSON looks like (old rows, partial posts, hand-edited data).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php'; // _tb_prop_name(), _tb_nights(), _tb_party()

/** Transport / anti-spam plumbing — captured on submit, never shown to staff. */
function submission_payload_hidden_keys(): array {
    return ['cf-turnstile-response', 'website', 'ts_raw_json'];
}

/** A Trip Builder document, as posted by trip-builder.php → api/trip-builder.php. */
function submission_is_trip_builder(array $payload): bool {
    return isset($payload['trip']) || isset($payload['itinerary']);
}

/**
 * Payload keys that have their own bespoke block in the submission view, so the
 * generic label/value list must not also render them as a flattened string.
 */
function submission_payload_own_render_keys(): array {
    return ['upsells'];
}

/** Booking add-ons the guest ticked during the enquiry, newest shape first. */
function submission_upsells(array $payload): array {
    $rows = $payload['upsells'] ?? null;
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;
        $out[] = [
            'id'    => (int)($r['id'] ?? 0),
            'name'  => $name,
            'price' => ($r['price_amount'] ?? null) !== null
                ? '$' . number_format((float)$r['price_amount'], ((float)$r['price_amount'] == floor((float)$r['price_amount'])) ? 0 : 2)
                  . (!empty($r['price_per_person']) ? ' pp' : '')
                : '',
        ];
    }
    return $out;
}

/** `agency_name` / `firstName` → `Agency Name` / `First Name`. */
function submission_payload_label(string $key): string {
    $s = str_replace(['_', '-'], ' ', $key);
    $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $s) ?? $s;
    return ucwords(trim($s));
}

/**
 * Any stored value → a display string. Never casts an array to string, so it
 * cannot emit the "Array to string conversion" warning that caused this bug.
 */
function submission_payload_value(mixed $v): string {
    if ($v === null || $v === '')      return '';
    if (is_bool($v))                   return $v ? 'Yes' : 'No';
    if (is_scalar($v))                 return trim((string)$v);
    if (!is_array($v))                 return '';       // resources, closures, …

    $parts = [];
    foreach ($v as $k => $item) {
        $s = submission_payload_value($item);
        if ($s === '') continue;
        // A list renders as plain values; a map keeps its keys for context.
        $parts[] = is_int($k) ? $s : submission_payload_label((string)$k) . ': ' . $s;
    }
    return implode(', ', $parts);
}

/**
 * Generic label/value rows for any payload — used for the simple forms, and as
 * a safety net for shapes we don't have a bespoke layout for.
 * @return list<array{0:string,1:string}>
 */
function submission_payload_rows(array $payload): array {
    $hidden = array_merge(submission_payload_hidden_keys(), submission_payload_own_render_keys());
    $rows   = [];
    foreach ($payload as $k => $v) {
        $key = (string)$k;
        if (in_array(strtolower($key), $hidden, true)) continue;
        $rows[] = [submission_payload_label($key), submission_payload_value($v)];
    }
    return $rows;
}

/** Drops empty values, so half-filled forms don't render a wall of blank rows. */
function _sp_block(string $title, array $rows): ?array {
    $keep = [];
    foreach ($rows as [$label, $value]) {
        $s = submission_payload_value($value);
        if ($s === '' || $s === '—') continue;
        $keep[] = [$label, $s];
    }
    return $keep ? ['title' => $title, 'rows' => $keep] : null;
}

/**
 * The Trip Builder laid out the way the staff email lays it out, so admin and
 * the notification email tell the same story.
 * @return list<array{title:string,rows:list<array{0:string,1:string}>}>
 */
function trip_builder_sections(array $payload): array {
    $g   = is_array($payload['guest']     ?? null) ? $payload['guest']     : [];
    $t   = is_array($payload['trip']      ?? null) ? $payload['trip']      : [];
    $dep = is_array($payload['departure'] ?? null) ? $payload['departure'] : [];
    $sp  = is_array($payload['special']   ?? null) ? $payload['special']   : [];

    // Same wording as _trip_builder_html() in includes/mail.php.
    $arrModes = [
        'flight'   => 'International Flight', 'domestic' => 'Domestic Flight',
        'road'     => 'Road / Self-Drive',    'charter'  => 'Charter / Private',
        'here'     => 'Already in Kenya',
    ];
    $transfers = [
        'yes' => 'Arranged by Tribal Sand', 'no' => 'Guest will manage', 'tbc' => 'To confirm',
    ];
    $date = function (mixed $v): string {
        $s = submission_payload_value($v);
        return ($s !== '' && ($ts = strtotime($s))) ? date('D, j M Y', $ts) : '';
    };
    $list = fn(mixed $v) => submission_payload_value(is_array($v) ? array_values(array_filter(
        $v, fn($x) => $x !== null && $x !== '' && $x !== 'none')) : $v);

    $slug    = submission_payload_value($t['prop'] ?? '');
    $nights  = _tb_nights($t);
    $arrMode = submission_payload_value($t['arrMode'] ?? '');
    $tTrans  = submission_payload_value($t['transfer'] ?? '');
    $dTrans  = submission_payload_value($dep['transfer'] ?? '');

    $sections = [
        _sp_block('Guest', [
            ['Name',        trim(submission_payload_value($g['firstName'] ?? '') . ' '
                               . submission_payload_value($g['lastName'] ?? ''))],
            ['Email',       $g['email'] ?? ''],
            ['Phone',       $g['phone'] ?? ''],
            ['Nationality', $g['nationality'] ?? ''],
            ['Residence',   $g['country'] ?? ''],
        ]),
        _sp_block('Stay', [
            ['Property',  $slug !== '' ? _tb_prop_name($slug) : ''],
            ['Arrival',   $date($t['arrDate'] ?? '')],
            ['Departure', $date($t['depDate'] ?? '')],
            ['Duration',  $nights ? $nights . ' night' . ($nights === 1 ? '' : 's') : ''],
            ['Party',     $t ? _tb_party($t) : ''],
            ['Trip Type', $t['purpose'] ?? ''],
        ]),
        _sp_block('Arrival', [
            ['Arriving By',  $arrModes[$arrMode] ?? $arrMode],
            ['Flight',       $t['flightNum'] ?? ''],
            ['Airport',      $t['airport'] ?? ''],
            ['Landing Time', $t['arrTime'] ?? ''],
            ['Flying From',  $t['fromCity'] ?? ''],
            ['Transfer',     $transfers[$tTrans] ?? ''],
        ]),
        _sp_block('Departure', [
            ['Flight',         $dep['flight'] ?? ''],
            ['Airport',        $dep['airport'] ?? ''],
            ['Departure Time', $dep['time'] ?? ''],
            ['Transfer',       $transfers[$dTrans] ?? ''],
            ['Final Requests', $dep['notes'] ?? ''],
        ]),
        _sp_block('Special Touches', [
            ['Occasions',     $list($sp['occasions'] ?? [])],
            ['Dietary',       $list($sp['diet'] ?? [])],
            ['Accessibility', $list($sp['mobility'] ?? [])],
            ['Trip Pace',     $sp['pace'] ?? ''],
            ['Notes',         $sp['extraNotes'] ?? ''],
        ]),
    ];
    return array_values(array_filter($sections));
}

/**
 * Day-by-day itinerary; days the guest left empty are dropped.
 * @return list<array{label:string,date:string,items:list<string>}>
 */
function trip_builder_itinerary(array $payload): array {
    $days = $payload['itinerary'] ?? [];
    if (!is_array($days)) return [];

    $out = [];
    foreach ($days as $i => $day) {
        if (!is_array($day)) continue;
        $slots = $day['slots'] ?? [];
        if (!is_array($slots) || !$slots) continue;

        $items = [];
        foreach ($slots as $slot) {
            $name = is_array($slot)
                ? submission_payload_value($slot['name'] ?? '')
                : submission_payload_value($slot);
            if ($name === '') continue;
            $time = is_array($slot) ? submission_payload_value($slot['time'] ?? '') : '';
            $items[] = $time !== '' ? $name . ' · ' . $time : $name;
        }
        if (!$items) continue;

        $label = submission_payload_value($day['label'] ?? '');
        $out[] = [
            'label' => $label !== '' ? $label : 'Day ' . ((int)$i + 1),
            'date'  => submission_payload_value($day['date'] ?? ''),
            'items' => $items,
        ];
    }
    return $out;
}
