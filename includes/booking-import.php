<?php
declare(strict_types=1);
/**
 * Zuri channel-manager (Ezee) booking importer — parsing, room mapping,
 * dry-run resolution and commit. Mirrors api/sync-ical.php's writer: accepted
 * rows become availability_blocks (block_type='blocked') so they prevent
 * double-booking and show on the Gantt; existing website holds are surfaced as
 * channel_conflicts rather than silently overwritten. Idempotent — re-importing
 * the same sheet inserts nothing new.
 *
 * Pure logic only (no HTTP/session) so it is unit-testable — see
 * tests/booking_import_logic.php. Requires includes/db.php (+ booking.php for
 * room_conflict_unit_ids) and, for .xlsx, includes/xlsx-reader.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/bookings.php';   // financial ledger row per imported block

/**
 * Seed map for ZURI ONLY — the first property onboarded, kept so an existing
 * install keeps working with no setup. Every other property starts with an empty
 * map and is filled in through Admin → Import bookings.
 *
 * Live maps are per property and owner-editable (see import_room_map()). An
 * unmapped Ezee room BLOCKS its row — never guessed — and is surfaced in the
 * dry-run preview. "Twin" defaults to the Double suite (same physical room,
 * twin config) per owner sign-off.
 */
const ZURI_ROOM_MAP = [
    'standard garden view suite'       => 'zuri-maji',
    'tropical pool view double suite'  => 'zuri-mwezi',
    'tropical pool view twin suite'    => 'zuri-mwezi',   // owner: same room, twin config
    'tropical garden view suite'       => 'zuri-ua',
    'master double suite'              => 'zuri-anga',
    'family garden view suite'         => 'zuri-jua',
    'entire retreat buyout'            => 'zuri-buyout',
];

/** Lower-case, trim, collapse internal whitespace — for tolerant name matching. */
function import_norm(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)));
}

/**
 * The effective Ezee→slug map for ONE property.
 *
 * Stored in the `ezee_room_maps` setting as { "<venue_id>": { name: slug } }.
 * A saved map for a venue is authoritative — it fully replaces the seed, so
 * removing a row really unmaps that name rather than falling back to a default.
 *
 * Maps are per property because the import is per property: you pick the venue,
 * upload its export, and only that venue's names are consulted. Two properties
 * may therefore reuse an Ezee room name without colliding.
 *
 * Legacy fallback: before multi-property support there was a single flat
 * `zuri_room_map` setting. When a venue has no entry yet and it IS Zuri, that
 * old setting is read so an existing hand-edited mapping keeps working; Zuri
 * with neither falls back to ZURI_ROOM_MAP. Any other venue starts empty —
 * an unmapped name BLOCKS its row, it is never guessed.
 *
 * Memoised per request per venue; import_room_map_save() busts the cache.
 */
function import_room_map(int $venueId): array {
    if ($venueId <= 0) return [];
    if (isset($GLOBALS['__ezee_map_cache'][$venueId])) return $GLOBALS['__ezee_map_cache'][$venueId];

    $all = json_decode((string)setting('ezee_room_maps', ''), true);
    $mine = (is_array($all) && isset($all[(string)$venueId]) && is_array($all[(string)$venueId]))
        ? $all[(string)$venueId]
        : null;

    if ($mine === null) {
        // No per-venue map saved yet.
        if ($venueId === import_zuri_venue_id()) {
            $legacy = json_decode((string)setting('zuri_room_map', ''), true);
            $mine = is_array($legacy) && $legacy ? $legacy : ZURI_ROOM_MAP;
        } else {
            $mine = [];
        }
    }

    $clean = [];
    foreach ($mine as $ezee => $slug) {
        $k = import_norm((string)$ezee);
        $v = trim((string)$slug);
        if ($k !== '' && $v !== '') $clean[$k] = $v;
    }
    return $GLOBALS['__ezee_map_cache'][$venueId] = $clean;
}

/** Zuri's venue id, for the legacy single-map fallback only. 0 if absent. */
function import_zuri_venue_id(): int {
    if (isset($GLOBALS['__ezee_zuri_id'])) return $GLOBALS['__ezee_zuri_id'];
    return $GLOBALS['__ezee_zuri_id'] =
        (int) db_query("SELECT id FROM venues WHERE slug = 'zuri' LIMIT 1")->fetchColumn();
}

/** Persist one property's map (keys normalised, blanks dropped) and bust the memo. */
function import_room_map_save(int $venueId, array $map): void {
    if ($venueId <= 0) return;
    $clean = [];
    foreach ($map as $ezee => $slug) {
        $k = import_norm((string)$ezee);
        $v = trim((string)$slug);
        if ($k !== '' && $v !== '') $clean[$k] = $v;
    }
    $all = json_decode((string)setting('ezee_room_maps', ''), true);
    if (!is_array($all)) $all = [];
    $all[(string)$venueId] = $clean;
    set_setting('ezee_room_maps', json_encode($all, JSON_UNESCAPED_UNICODE));
    unset($GLOBALS['__ezee_map_cache'][$venueId]);
}

/** Map an Ezee room name to a website slug within one property, or null. */
function import_map_room_slug(string $ezeeRoom, int $venueId): ?string {
    return import_room_map($venueId)[import_norm($ezeeRoom)] ?? null;
}

/**
 * Parse a sheet date to Y-m-d, or null. Accepts DD/MM/YYYY (and D/M/YYYY),
 * YYYY-MM-DD, and an Excel serial number (days since 1899-12-30) — a real .xlsx
 * date cell stores the serial, not the display text.
 */
function import_parse_date(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;

    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        return null;
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $raw)) {
        return $raw;
    }
    // Excel serial (integer or with a fractional time part).
    if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
        $days = (int)floor((float)$raw);
        if ($days > 0 && $days < 100000) {
            $ts = ($days - 25569) * 86400;   // 25569 = 1970-01-01 in Excel's epoch
            return gmdate('Y-m-d', $ts);
        }
    }
    return null;
}

/**
 * Parse a money cell to a float, or null when blank/non-numeric. Tolerant of
 * currency symbols, thousands separators and trailing codes ("KES 12,500",
 * "$1,234.50", "1.200,00"→ best-effort). Returns null (not 0) for an empty or
 * unreadable cell so callers can tell "no amount" from "zero".
 */
function import_parse_amount(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '' || $raw === '-') return null;
    // Drop everything but digits, separators and sign.
    $s = preg_replace('/[^0-9.,\-]/', '', $raw);
    if ($s === '' || $s === '-') return null;
    // If both separators appear, the last one is the decimal point.
    if (str_contains($s, ',') && str_contains($s, '.')) {
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else                                      { $s = str_replace(',', '', $s); }
    } else {
        // Only commas → thousands separators (Ezee exports use "12,500").
        $s = str_replace(',', '', $s);
    }
    if (!is_numeric($s)) return null;
    return (float)$s;
}

/** Stable dedupe key for a source row (used only for reporting/audit). */
function import_row_key(string $guest, string $arrival, string $dept, string $room): string {
    return substr(sha1(import_norm($guest) . '|' . $arrival . '|' . $dept . '|' . import_norm($room)), 0, 16);
}

/** Resolve a website slug → room+unit context, or null if the slug is unknown. */
function import_room_unit(string $slug): ?array {
    $row = db_query(
        "SELECT r.id AS room_id, r.name AS room_name, r.venue_id, r.is_entire_place,
                r.price_currency,
                u.id AS unit_id
         FROM rooms r
         JOIN units u ON u.room_id = r.id AND u.is_active = TRUE
         WHERE r.slug = :slug
         ORDER BY u.sort_order ASC
         LIMIT 1",
        [':slug' => $slug]
    )->fetch();
    return $row ?: null;
}

/**
 * An identical 'blocked' block already on file (idempotent skip)? Matches the
 * iCal importer's guard: same unit + exact dates + block_type='blocked'.
 */
function import_block_exists(int $unitId, string $from, string $to): bool {
    return (bool) db_query(
        "SELECT 1 FROM availability_blocks
         WHERE unit_id=:u AND date_from=:f AND date_to=:t AND block_type='blocked' LIMIT 1",
        [':u' => $unitId, ':f' => $from, ':t' => $to]
    )->fetchColumn();
}

/**
 * A conflicting website hold/booking for these dates — on this unit OR any
 * mutual-exclusion sibling (so a Buyout row conflicts with a booked suite, and a
 * suite row conflicts with a booked whole-property). Returns the hold id or 0.
 */
function import_conflict_hold_id(array $ru, string $from, string $to): int {
    $room = ['id' => (int)$ru['room_id'], 'venue_id' => $ru['venue_id'], 'is_entire_place' => $ru['is_entire_place']];
    $unitIds = array_merge([(int)$ru['unit_id']], room_conflict_unit_ids($room));
    $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
    $in = implode(',', $unitIds);   // ints from DB — safe to inline
    $hid = db_query(
        "SELECT id FROM holds
         WHERE unit_id IN ($in)
           AND status IN ('pending','confirmed')
           AND check_in < :to AND check_out > :from
         ORDER BY id ASC LIMIT 1",
        [':to' => $to, ':from' => $from]
    )->fetchColumn();
    return $hid ? (int)$hid : 0;
}

/**
 * Turn raw sheet rows (row 0 = header) into normalised source records keyed by
 * detected column. Returns ['fields'=>bool-map of what was found, 'rows'=>[...]].
 * Column detection is header-name based (tolerant), so slight column-order
 * changes in future exports still work. The unit sub-name is the column
 * immediately after "Room" when present.
 */
function import_extract_rows(array $raw): array {
    if (!$raw) return ['fields' => [], 'rows' => []];
    $header = array_map(fn($h) => import_norm((string)$h), $raw[0]);

    $find = function (callable $pred) use ($header): ?int {
        foreach ($header as $i => $h) if ($pred($h)) return $i;
        return null;
    };
    $iGuest = $find(fn($h) => str_contains($h, 'guest'));
    $iArr   = $find(fn($h) => str_contains($h, 'arrival'));
    $iDept  = $find(fn($h) => str_starts_with($h, 'dept') || str_contains($h, 'depart'));
    $iRoom  = $find(fn($h) => $h === 'room');
    if ($iRoom === null) $iRoom = $find(fn($h) => str_contains($h, 'room') && !str_contains($h, 'rate'));
    $iAgent = $find(fn($h) => str_contains($h, 'travelagent') || str_contains($h, 'agent'));
    $iBook  = $find(fn($h) => str_contains($h, 'booking'));
    // Amount/total column — tolerant match. Prefer an explicit total over a
    // per-night "rate" when both are present (we store the booking's gross).
    $iAmt = $find(fn($h) => str_contains($h, 'grandtotal') || str_contains($h, 'totalamount') || $h === 'total')
        ?? $find(fn($h) => str_contains($h, 'total') || str_contains($h, 'amount')
                        || str_contains($h, 'revenue') || str_contains($h, 'payable'))
        ?? $find(fn($h) => str_contains($h, 'rate') || str_contains($h, 'tariff') || str_contains($h, 'price'));
    $iUnit  = ($iRoom !== null) ? $iRoom + 1 : null;   // optional unit/sub-name column

    $fields = [
        'guest' => $iGuest !== null, 'arrival' => $iArr !== null,
        'dept'  => $iDept !== null,  'room'    => $iRoom !== null,
        'agent' => $iAgent !== null, 'amount'  => $iAmt !== null,
    ];

    $rows = [];
    $n = count($raw);
    for ($r = 1; $r < $n; $r++) {
        $cells = $raw[$r];
        $cell = fn(?int $i) => ($i !== null && isset($cells[$i])) ? trim((string)$cells[$i]) : '';
        $guest = $cell($iGuest);
        $room  = $cell($iRoom);
        // Skip fully-blank trailing rows.
        if ($guest === '' && $room === '' && $cell($iArr) === '') continue;
        $unit = '';
        if ($iUnit !== null && $iUnit !== $iAgent && $iUnit !== $iAmt) {
            $u = $cell($iUnit);
            // Only treat it as a unit label if it isn't obviously the rate/agent column.
            if ($u !== '' && import_norm($u) !== import_norm($room)) $unit = $u;
        }
        $rows[] = [
            'guest'        => $guest,
            'arrival_raw'  => $cell($iArr),
            'dept_raw'     => $cell($iDept),
            'room_raw'     => $room,
            'unit_label'   => $unit,
            'agent'        => $cell($iAgent),
            'booking_date' => $cell($iBook),
            'amount_raw'   => $cell($iAmt),
        ];
    }
    return ['fields' => $fields, 'rows' => $rows];
}

/** Read + extract an uploaded file (.csv/.tsv via fgetcsv, .xlsx via the reader). */
function import_read_file(string $path, string $ext): array {
    $ext = strtolower($ext);
    if ($ext === 'xlsx') {
        require_once __DIR__ . '/xlsx-reader.php';
        return import_extract_rows(xlsx_read_rows($path));
    }
    // CSV / TSV
    $rows = [];
    $fh = @fopen($path, 'r');
    if (!$fh) throw new RuntimeException('Could not open the uploaded file.');
    $delim = ($ext === 'tsv') ? "\t" : ',';
    // Explicit enclosure + empty escape: silences the PHP 8.4 default-escape
    // deprecation and avoids legacy backslash escaping mangling cell values.
    while (($line = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($line === [null]) continue;   // blank line
        $rows[] = array_map(fn($v) => (string)($v ?? ''), $line);
    }
    fclose($fh);
    return import_extract_rows($rows);
}

/**
 * Resolve one source row against the DB into a preview record with a status:
 *   ok | unmapped | bad_dates | duplicate | conflict
 * Never writes. $conflictHoldId is set when status === 'conflict'.
 */
function import_resolve_row(array $row, int $venueId): array {
    $out = $row + [
        'slug' => null, 'room_id' => null, 'unit_id' => null, 'room_name' => null,
        'arrival' => null, 'dept' => null, 'status' => 'ok', 'detail' => '',
        'conflict_hold_id' => 0,
        // Money: amount parsed from the sheet (null when the sheet has none —
        // staff type it in the preview); currency defaults, set from the room below.
        'amount' => import_parse_amount((string)($row['amount_raw'] ?? '')),
        'currency' => 'USD',
    ];
    $out['key'] = import_row_key($row['guest'], $row['arrival_raw'], $row['dept_raw'], $row['room_raw']);

    $slug = import_map_room_slug($row['room_raw'], $venueId);
    if ($slug === null) {
        $out['status'] = 'unmapped';
        $out['detail'] = 'Unknown room "' . $row['room_raw'] . '" — add it to this property’s room mapping';
        return $out;
    }
    $ru = import_room_unit($slug);
    if (!$ru) {
        // Two different causes, and naming the wrong one costs real debugging
        // time: the room may not exist, or it may exist with no ACTIVE unit
        // (import_room_unit() joins units ON is_active = TRUE).
        $roomExists = (int) db_query('SELECT COUNT(*) FROM rooms WHERE slug = :s', [':s' => $slug])->fetchColumn() > 0;
        $out['status'] = 'unmapped';
        $out['detail'] = $roomExists
            ? 'Room "' . $slug . '" has no active unit — add one under Rooms → Units'
            : 'No website room with slug "' . $slug . '"';
        return $out;
    }
    if ((int)$ru['venue_id'] !== $venueId) {
        // The map is per property, so this only happens if a map row points at
        // another property's room. Blocking is the safe call — importing it
        // would drop a booking into the wrong property's calendar.
        $out['status'] = 'unmapped';
        $out['detail'] = 'Room "' . $slug . '" belongs to a different property';
        return $out;
    }
    $out['slug']      = $slug;
    $out['room_id']   = (int)$ru['room_id'];
    $out['unit_id']   = (int)$ru['unit_id'];
    $out['room_name'] = $ru['room_name'];
    $out['currency']  = strtoupper(trim((string)($ru['price_currency'] ?? 'USD'))) ?: 'USD';

    $ci = import_parse_date($row['arrival_raw']);
    $co = import_parse_date($row['dept_raw']);
    if (!$ci || !$co || $ci >= $co) {
        $out['status'] = 'bad_dates';
        $out['detail'] = 'Unreadable or out-of-order dates';
        return $out;
    }
    $out['arrival'] = $ci;
    $out['dept']    = $co;

    if (import_block_exists((int)$ru['unit_id'], $ci, $co)) {
        $out['status'] = 'duplicate';
        $out['detail'] = 'Already imported';
        return $out;
    }
    $hid = import_conflict_hold_id($ru, $ci, $co);
    if ($hid) {
        $out['status'] = 'conflict';
        $out['conflict_hold_id'] = $hid;
        $out['detail'] = 'Overlaps existing booking (hold #' . $hid . ')';
        return $out;
    }
    return $out;
}

/** Resolve every extracted row. */
function import_resolve_all(array $rows, int $venueId): array {
    return array_map(fn(array $r) => import_resolve_row($r, $venueId), $rows);
}

/** A short human note stored on the availability block / conflict. */
function import_block_note(array $r): string {
    $note = 'Ezee import · ' . ($r['guest'] !== '' ? $r['guest'] : 'guest');
    if (!empty($r['unit_label'])) $note .= ' · ' . $r['unit_label'];
    if (!empty($r['agent']) && $r['agent'] !== '-') $note .= ' · ' . $r['agent'];
    return mb_substr($note, 0, 200);
}

/**
 * Commit resolved rows. Re-checks dedupe/conflict at write time (state may have
 * changed since preview). Inserts 'blocked' availability_blocks for clean rows;
 * records channel_conflicts for overlaps (does NOT insert the block); skips
 * duplicates/unmapped/bad dates. Returns counts + per-row outcome.
 */
function import_commit(array $resolved): array {
    $res = ['imported' => 0, 'duplicate' => 0, 'conflict' => 0, 'unmapped' => 0, 'bad_dates' => 0, 'rows' => []];
    foreach ($resolved as $r) {
        $outcome = $r['status'];

        if (in_array($r['status'], ['unmapped', 'bad_dates'], true)) {
            $res[$r['status']]++;
            $res['rows'][] = ['guest' => $r['guest'], 'room' => $r['room_raw'], 'outcome' => $outcome, 'detail' => $r['detail']];
            continue;
        }

        $unitId = (int)$r['unit_id'];
        $ci = $r['arrival']; $co = $r['dept'];

        if (import_block_exists($unitId, $ci, $co)) {
            $res['duplicate']++;
            $res['rows'][] = ['guest' => $r['guest'], 'room' => $r['room_name'], 'outcome' => 'duplicate', 'detail' => 'Already imported'];
            continue;
        }

        $ru = ['room_id' => $r['room_id'], 'unit_id' => $unitId, 'venue_id' => null, 'is_entire_place' => null];
        // Re-fetch room flags for an accurate mutual-exclusion check at commit time.
        $flags = db_query("SELECT venue_id, is_entire_place FROM rooms WHERE id=:id", [':id' => $r['room_id']])->fetch();
        $ru['venue_id'] = $flags['venue_id'] ?? null;
        $ru['is_entire_place'] = $flags['is_entire_place'] ?? null;

        $hid = import_conflict_hold_id($ru, $ci, $co);
        if ($hid) {
            $already = db_query(
                "SELECT 1 FROM channel_conflicts
                 WHERE unit_id=:u AND date_from=:f AND date_to=:t AND hold_id=:h AND status='pending' LIMIT 1",
                [':u' => $unitId, ':f' => $ci, ':t' => $co, ':h' => $hid]
            )->fetchColumn();
            if (!$already) {
                db_query(
                    "INSERT INTO channel_conflicts (unit_id, date_from, date_to, hold_id, ota_summary)
                     VALUES (:u, :f, :t, :h, :s)",
                    [':u' => $unitId, ':f' => $ci, ':t' => $co, ':h' => $hid, ':s' => import_block_note($r)]
                );
            }
            $res['conflict']++;
            $res['rows'][] = ['guest' => $r['guest'], 'room' => $r['room_name'], 'outcome' => 'conflict', 'detail' => 'Overlaps hold #' . $hid];
            continue;
        }

        $blockId = (int) db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
             VALUES (:u, :f, :t, 'blocked', :n) RETURNING id",
            [':u' => $unitId, ':f' => $ci, ':t' => $co, ':n' => import_block_note($r)]
        )->fetchColumn();

        // Financial ledger row alongside the calendar block (pre-migration-safe —
        // no-op until add_bookings_finance is applied). Amount comes from the sheet
        // or from what staff typed in the preview; 0 is a valid (unpriced) import.
        bookings_import_upsert($blockId, [
            'venue_id'     => $ru['venue_id'], 'room_id' => (int)$r['room_id'], 'unit_id' => $unitId,
            'guest_name'   => $r['guest'],     'agent'   => (string)($r['agent'] ?? ''),
            'check_in'     => $ci,             'check_out' => $co,
            'gross_amount' => (float)($r['amount'] ?? 0),
            'currency'     => (string)($r['currency'] ?? 'USD'),
            'external_ref' => (string)($r['booking_date'] ?? ''),
        ]);

        $res['imported']++;
        $res['rows'][] = ['guest' => $r['guest'], 'room' => $r['room_name'], 'outcome' => 'imported',
                          'detail' => $ci . ' → ' . $co];
    }
    return $res;
}
