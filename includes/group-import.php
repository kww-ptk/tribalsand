<?php
declare(strict_types=1);
/**
 * Group allocation import — a group's room list (a wedding, a retreat) becomes one
 * confirmed booking per room, so every room has its own guest link (stay page +
 * check-in). Page: admin/import-group.php. Spec:
 * docs/superpowers/specs/2026-10-09-group-allocation-import-design.md.
 *
 * Owner rules: one booking per room; no price (a 0 ledger row, billed as a group);
 * no emails — staff send the links. Names: the booking carries someone staying in
 * the room (the head when they are in it), the head's email, and the room's guests
 * pre-filled into its check-in roster.
 *
 * Pure helpers first (tests/group_import_logic.php), then the DB resolver/writer.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/bookings.php';

const GI_ROOM_MAP_SETTING = 'group_import_room_map';   // {venue_id: {label: room_id}}
const GI_BATCH_PREFIX     = 'group_import:';           // + slug → {label, created_at, hold_ids}
const GI_MAX_ROWS         = 300;

// ── Pure ────────────────────────────────────────────────────────────────────

/** A date as typed in an allocation sheet → Y-m-d, or null (blank, "—", impossible). */
function gi_parse_date(string $s): ?string {
    $s = trim($s);
    if ($s === '' || preg_match('/^[-—–]+$/u', $s)) return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) { [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }
    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) { [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }   // day first (Kenya)
    elseif (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,9})\.?\s+(\d{4})$/', $s, $m)) {
        $mo = (int) date('n', strtotime('1 ' . substr($m[2], 0, 3) . ' 2000') ?: 0);
        if (!$mo || !str_starts_with(strtolower(date('F', mktime(0, 0, 0, $mo, 1, 2000))), strtolower(substr($m[2], 0, 3)))) return null;
        [$d, $y] = [(int)$m[1], (int)$m[3]];
    } else return null;
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** "Li (Eric) Shuai" → "Li Shuai": nicknames in brackets dropped, spaces collapsed. */
function gi_clean_name(string $s): string {
    $s = preg_replace('/\([^)]*\)/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * The guests column → clean names. Split on commas, "&" and "and". A bare first
 * name equal to the head's first name is the head ("Mita, Ravi" + head "Ravi
 * Bhatt" → Mita, Ravi Bhatt). "—" = nobody.
 */
function gi_split_names(string $guests, string $head): array {
    $head  = gi_clean_name($head);
    $hFirst = strtolower(explode(' ', $head)[0] ?? '');
    $out = [];
    foreach (preg_split('/\s*(?:,|&|\band\b)\s*/iu', $guests) ?: [] as $p) {
        $n = gi_clean_name($p);
        if ($n === '' || preg_match('/^[-—–]+$/u', $n)) continue;
        if (!str_contains($n, ' ') && $hFirst !== '' && strtolower($n) === $hFirst && str_contains($head, ' ')) $n = $head;
        $out[] = $n;
    }
    return $out;
}

/** The booking's name: the head when they stay in this room, else the first guest, else the head. */
function gi_booking_name(array $names, string $head): string {
    $head = gi_clean_name($head);
    foreach ($names as $n) if (strcasecmp($n, $head) === 0) return $head;
    return $names[0] ?? $head;
}

/** Check-in roster order: the booking name (lead) first, the rest as listed. */
function gi_roster(array $names, string $head): array {
    $lead = gi_booking_name($names, $head);
    if (!$names) return [];
    $rest = array_values(array_filter($names, fn($n) => strcasecmp($n, $lead) !== 0));
    return array_merge([$lead], $rest);
}

/**
 * A Maya Ilai room label → what it books. Other properties return null (staff map
 * them to a room on the preview).
 *   "Studio No. 03A"            → ['kind'=>'studio','n'=>3]
 *   "Villa 05: Room 501|502|503" → ['kind'=>'villa','n'=>5,'component'=>double_a|double_b|bunk,'product'=>…]
 * The room number must belong to the villa (Room 601 in Villa 05 is refused).
 */
function gi_resolve_label(string $venueSlug, string $label): ?array {
    if (str_replace('_', '-', strtolower($venueSlug)) !== 'maya-ilai') return null;   // the venue's slug is maya_ilai
    if (preg_match('/^\s*studio\s*(?:no\.?|number|#)?\s*0*(\d{1,2})\s*[a-z]?\b/i', $label, $m)) {
        return ['kind' => 'studio', 'n' => (int)$m[1]];
    }
    if (preg_match('/^\s*villa\s*0*(\d{1,2})\s*[:\-–]?\s*room\s*(\d)(\d)(\d)\b/i', $label, $m)) {
        $villa = (int)$m[1];
        if ((int)($m[2] . $m[3]) !== $villa && (int)$m[2] !== $villa) return null;
        $map = ['1' => ['double_a', 'maya-ilai-double'], '2' => ['double_b', 'maya-ilai-double'], '3' => ['bunk', 'maya-ilai-bunk-room']];
        if (!isset($map[$m[4]])) return null;
        return ['kind' => 'villa', 'n' => $villa, 'component' => $map[$m[4]][0], 'product' => $map[$m[4]][1]];
    }
    return null;
}

/**
 * Parse the allocation CSV. Columns (any order, header names tolerant): property,
 * room, guests, head, email, check_in, check_out. Comma, semicolon or tab.
 * @return array{rows:list<array>,errors:list<string>}
 */
function gi_parse_csv(string $csv): array {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
    $first = $lines[0] ?? '';
    $delim = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $csv); rewind($fh);
    $header = fgetcsv($fh, 0, $delim, '"', '');
    $aliases = [
        'property'  => ['property', 'venue', 'hotel'],
        'room'      => ['room', 'room name', 'unit'],
        'guests'    => ['guests', 'guest', 'names', 'guest names'],
        'head'      => ['head', 'head / group', 'head/group', 'group', 'lead', 'contact'],
        'email'     => ['email', 'e-mail', 'email address'],
        'check_in'  => ['check-in', 'check in', 'checkin', 'check_in', 'arrival'],
        'check_out' => ['check-out', 'check out', 'checkout', 'check_out', 'departure'],
    ];
    $col = [];
    foreach ((array)$header as $i => $h) {
        $k = strtolower(trim((string)$h));
        foreach ($aliases as $field => $names) if (in_array($k, $names, true) && !isset($col[$field])) $col[$field] = $i;
    }
    $missing = array_diff(['property', 'room', 'guests', 'head', 'email', 'check_in', 'check_out'], array_keys($col));
    if ($missing) { fclose($fh); return ['rows' => [], 'errors' => ['Missing column(s): ' . implode(', ', $missing) . '.']]; }

    $rows = []; $errors = []; $line = 1;
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') continue;
        $get = fn($f) => trim((string)($r[$col[$f]] ?? ''));
        $rows[] = [
            'line' => $line, 'property' => $get('property'), 'room' => $get('room'),
            'guests' => $get('guests'), 'head' => gi_clean_name($get('head')), 'email' => strtolower($get('email')),
            'check_in' => gi_parse_date($get('check_in')), 'check_out' => gi_parse_date($get('check_out')),
        ];
        if (count($rows) > GI_MAX_ROWS) { $errors[] = 'Only the first ' . GI_MAX_ROWS . ' rows are read.'; break; }
    }
    fclose($fh);
    return ['rows' => $rows, 'errors' => $errors];
}

/** The message a head receives with their room links (WhatsApp / email body). */
function gi_share_text(string $head, array $rooms, string $label): string {
    $first = explode(' ', gi_clean_name($head))[0] ?: '';
    $out = 'Hi' . ($first !== '' ? ' ' . $first : '') . ', here '
         . (count($rooms) === 1 ? 'is the booking link' : 'are the booking links')
         . ($label !== '' ? ' for ' . $label : '')
         . ". Each link shows the stay and the online check-in for that room:\n";
    foreach ($rooms as $r) {
        $out .= "\n• " . trim(($r['property'] ?? '') . ' · ' . ($r['room'] ?? ''), ' ·')
              . (($r['guest'] ?? '') !== '' ? ' (' . $r['guest'] . ')' : '') . "\n" . ($r['link'] ?? '');
    }
    return $out;
}

/** "Chris & Bini wedding" → "chris-bini-wedding". */
function gi_batch_slug(string $label): string {
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) ?? '', '-');
    return $s !== '' ? substr($s, 0, 60) : 'group';
}

// ── Database ────────────────────────────────────────────────────────────────

/** Venues by slug and by lower-case name (the CSV may use either). */
function gi_venues(): array {
    $out = [];
    foreach (db_query('SELECT id, slug, name FROM venues')->fetchAll() as $v) {
        $out[strtolower((string)$v['slug'])] = $v;
        $out[str_replace('_', '-', strtolower((string)$v['slug']))] = $v;   // maya_ilai ↔ maya-ilai
        $out[strtolower(trim((string)$v['name']))] = $v;
    }
    return $out;
}

/** Saved label → room maps: [venue_id => [label => room_id]]. */
function gi_room_map(): array {
    $m = json_decode(setting(GI_ROOM_MAP_SETTING, ''), true);
    return is_array($m) ? $m : [];
}

/** Active units of a room in staff numbering order (Villa 1 = first by sort_order). */
function gi_units_of(string $roomSlug): array {
    return db_query(
        'SELECT u.id, u.name, r.id AS room_id FROM units u JOIN rooms r ON r.id = u.room_id
          WHERE r.slug = :s AND u.is_active = TRUE ORDER BY u.sort_order, u.id', [':s' => $roomSlug]
    )->fetchAll();
}

/** Any calendar block on these units over [ci, co)? */
function gi_units_busy(array $unitIds, string $ci, string $co): bool {
    $ids = array_values(array_filter(array_map('intval', $unitIds)));
    if (!$ids) return false;
    return (bool) db_query(
        'SELECT 1 FROM availability_blocks WHERE unit_id IN (' . implode(',', $ids) . ')
            AND date_from < :co AND date_to > :ci LIMIT 1', [':ci' => $ci, ':co' => $co]
    )->fetchColumn();
}

/** An existing (not cancelled) hold for the same unit, dates and email — the row was imported before. */
function gi_existing_hold(int $unitId, string $ci, string $co, string $email): ?int {
    $id = db_query(
        "SELECT id FROM holds WHERE unit_id = :u AND check_in = :ci AND check_out = :co
            AND lower(guest_email) = :e AND status NOT IN ('cancelled','expired','declined')
          ORDER BY id LIMIT 1", [':u' => $unitId, ':ci' => $ci, ':co' => $co, ':e' => strtolower($email)]
    )->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Resolve every row to what it would book. Writes nothing.
 * Status: ready · taken · imported · skipped · unmapped · scope · error.
 * $roomMap = [venue_id => [label => room_id]] (the preview's dropdowns).
 * $scope   = admin_venue_ids() (null = all properties).
 */
function gi_plan(array $rows, array $roomMap, ?array $scope): array {
    $venues = gi_venues();
    $claims = [];   // in-batch: "unit|ci|co" → components (null = whole unit)
    $overlap = function (array $a, array $b): bool { return $a[0] < $b[1] && $a[1] > $b[0]; };
    $claimed = function (int $unitId, string $ci, string $co, ?array $comp) use (&$claims, $overlap): bool {
        foreach ($claims as $c) {
            if ($c['unit'] !== $unitId || !$overlap([$ci, $co], [$c['ci'], $c['co']])) continue;
            if ($comp === null || $c['comp'] === null || array_intersect($comp, $c['comp'])) return true;
        }
        return false;
    };

    $out = [];
    foreach ($rows as $r) {
        $p = $r + ['status' => 'error', 'reason' => '', 'venue_id' => null, 'venue_name' => '', 'unit_id' => null,
                   'unit_name' => '', 'room_id' => null, 'components' => null, 'target' => '', 'hold_id' => null];
        $p['names']  = gi_split_names((string)$r['guests'], (string)$r['head']);
        $p['adults'] = max(1, count($p['names']));
        $p['booking_name'] = gi_booking_name($p['names'], (string)$r['head']);
        $out[] = &$p;

        $v = $venues[strtolower(trim((string)$r['property']))] ?? null;
        if (!$v) { $p['reason'] = 'Unknown property "' . $r['property'] . '".'; unset($p); continue; }
        $p['venue_id'] = (int)$v['id']; $p['venue_name'] = (string)$v['name'];
        if ($scope !== null && !in_array((int)$v['id'], array_map('intval', $scope), true)) {
            $p['status'] = 'scope'; $p['reason'] = 'Not one of your properties.'; unset($p); continue;
        }
        if (!$p['names'] && trim((string)$r['head']) === '') { $p['status'] = 'skipped'; $p['reason'] = 'Empty room.'; unset($p); continue; }
        if ($r['check_in'] === null || $r['check_out'] === null) { $p['status'] = 'skipped'; $p['reason'] = 'No dates.'; unset($p); continue; }
        if ($r['check_in'] >= $r['check_out']) { $p['reason'] = 'Check-out must be after check-in.'; unset($p); continue; }
        if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $p['reason'] = 'No valid email.'; unset($p); continue; }

        [$ci, $co] = [(string)$r['check_in'], (string)$r['check_out']];
        $lab = gi_resolve_label((string)$v['slug'], (string)$r['room']);
        $unit = null; $comp = null; $productRoom = null;

        if ($lab !== null && $lab['kind'] === 'studio') {
            $units = gi_units_of('maya-ilai-studio');
            $unit  = $units[$lab['n'] - 1] ?? null;
            if (!$unit) { $p['reason'] = "There is no studio {$lab['n']}."; unset($p); continue; }
            $productRoom = (int)$unit['room_id'];
            $p['target'] = 'Studio ' . $lab['n'];
            $busy = gi_units_busy([(int)$unit['id']], $ci, $co);
        } elseif ($lab !== null && $lab['kind'] === 'villa') {
            $units = gi_units_of(MAYA_ILAI_VILLA_ROOM_SLUG);
            $unit  = $units[$lab['n'] - 1] ?? null;
            $productRoom = (int) db_query('SELECT id FROM rooms WHERE slug = :s', [':s' => $lab['product']])->fetchColumn();
            if (!$unit || !$productRoom) { $p['reason'] = "There is no villa {$lab['n']} / {$lab['product']} on this site."; unset($p); continue; }
            $comp = [$lab['component']];
            $p['target'] = 'Villa ' . $lab['n'] . ' · ' . ['double_a' => 'first double', 'double_b' => 'second double', 'bunk' => 'bunk room'][$lab['component']];
            $taken = mi_villa_states((int)$unit['room_id'], $ci, $co)[(int)$unit['id']]['taken'] ?? [];
            $busy  = in_array($lab['component'], $taken, true);
        } else {
            $roomId = (int)($roomMap[(string)$v['id']][(string)$r['room']] ?? $roomMap[(int)$v['id']][(string)$r['room']] ?? 0);
            $room = $roomId ? db_query('SELECT id, slug, name, venue_id, is_entire_place FROM rooms WHERE id = :r', [':r' => $roomId])->fetch() : null;
            if (!$room || (int)$room['venue_id'] !== (int)$v['id']) { $p['status'] = 'unmapped'; $p['reason'] = 'Choose the room.'; unset($p); continue; }
            $productRoom = (int)$room['id'];
            $p['target'] = (string)$room['name'];
            // First active unit of that room not already used — by the calendar or by an earlier row.
            $units = db_query('SELECT id, name FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order, id', [':r' => $roomId])->fetchAll();
            $conflicts = room_conflict_unit_ids($room);
            $busy = true;
            foreach ($units as $u) {
                $prev = gi_existing_hold((int)$u['id'], $ci, $co, (string)$r['email']);
                if ($prev) { $unit = $u; $busy = false; break; }
                if (!gi_units_busy(array_merge([(int)$u['id']], $conflicts), $ci, $co) && !$claimed((int)$u['id'], $ci, $co, null)) {
                    $unit = $u; $busy = false; break;
                }
            }
            if (!$unit) { $p['status'] = 'taken'; $p['reason'] = $p['target'] . ' is not free on these dates.'; unset($p); continue; }
        }

        $p['unit_id'] = (int)$unit['id']; $p['unit_name'] = (string)$unit['name'];
        $p['room_id'] = $productRoom; $p['components'] = $comp;

        $prev = gi_existing_hold((int)$unit['id'], $ci, $co, (string)$r['email']);
        if ($prev) { $p['status'] = 'imported'; $p['hold_id'] = $prev; $p['reason'] = 'Already imported.'; unset($p); continue; }
        if ($busy || $claimed((int)$unit['id'], $ci, $co, $comp)) {
            $p['status'] = 'taken'; $p['reason'] = $p['target'] . ' is already booked on these dates.'; unset($p); continue;
        }
        $claims[] = ['unit' => (int)$unit['id'], 'ci' => $ci, 'co' => $co, 'comp' => $comp];
        $p['status'] = 'ready';
        unset($p);
    }
    return $out;
}

/**
 * Create every READY row as a confirmed booking, in ONE transaction (all or none).
 * Already-imported rows are added to the batch so their links show too.
 * @return array{hold_ids:list<int>, created:int, slug:string}
 */
function gi_create(array $plan, string $label, int $adminId): array {
    $label = trim($label) !== '' ? trim($label) : 'Group booking';
    $slug  = gi_batch_slug($label);
    $ids = []; $created = 0;
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        foreach ($plan as $p) {
            if ($p['status'] === 'imported' && $p['hold_id']) { $ids[] = (int)$p['hold_id']; continue; }
            if ($p['status'] !== 'ready') continue;
            // Re-check under the transaction: the preview may be minutes old.
            if ($p['components'] !== null) {
                $taken = mi_villa_states((int) db_query('SELECT room_id FROM units WHERE id = :u', [':u' => $p['unit_id']])->fetchColumn(),
                                         $p['check_in'], $p['check_out'])[$p['unit_id']]['taken'] ?? [];
                if (array_intersect($p['components'], $taken)) throw new RuntimeException("Line {$p['line']}: {$p['target']} was booked meanwhile — preview again.");
            } elseif (gi_units_busy([$p['unit_id']], $p['check_in'], $p['check_out'])) {
                throw new RuntimeException("Line {$p['line']}: {$p['target']} was booked meanwhile — preview again.");
            }
            $hid = create_hold_with_block((int)$p['unit_id'], null, $p['check_in'], $p['check_out'],
                $p['booking_name'], $p['email'], 'confirmed', null, $p['components'], (int)$p['room_id']);
            if (checkin_supported()) {
                db_query('UPDATE holds SET require_checkin = TRUE, guest_count = :n WHERE id = :h', [':n' => $p['adults'], ':h' => $hid]);
                foreach (gi_roster($p['names'], (string)$p['head']) as $i => $name) {
                    db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, passport_name) VALUES (:h, :l, FALSE, :n)',
                        [':h' => $hid, ':l' => $i === 0 ? 'TRUE' : 'FALSE', ':n' => $name]);
                }
            }
            // No price: a 0 ledger row, source direct, labelled with the group. A
            // non-website source is never re-priced by bookings_sync_hold().
            if (bookings_supported()) {
                db_query(
                    "INSERT INTO bookings (venue_id, room_id, unit_id, source, guest_name, guest_email, agent,
                            check_in, check_out, nights, gross_amount, currency, status, hold_id)
                     VALUES (:v, :r, :u, 'direct', :gn, :ge, :ag, :ci, :co, :n, 0, 'USD', 'confirmed', :h)",
                    [':v' => $p['venue_id'], ':r' => $p['room_id'], ':u' => $p['unit_id'], ':gn' => $p['booking_name'],
                     ':ge' => $p['email'], ':ag' => mb_substr($label, 0, 255), ':ci' => $p['check_in'], ':co' => $p['check_out'],
                     ':n' => (int) round((strtotime($p['check_out']) - strtotime($p['check_in'])) / 86400), ':h' => $hid]);
            }
            audit_log('group_import.create', 'hold', $hid, "{$label}: {$p['booking_name']} — {$p['venue_name']} {$p['target']} {$p['check_in']}→{$p['check_out']}");
            $ids[] = $hid; $created++;
        }
        $prev = gi_batch($slug);
        $all  = array_values(array_unique(array_merge($prev['hold_ids'] ?? [], $ids)));
        set_setting(GI_BATCH_PREFIX . $slug, json_encode([
            'label' => $label, 'created_at' => $prev['created_at'] ?? date('c'), 'updated_at' => date('c'),
            'by' => $adminId, 'hold_ids' => $all,
        ]));
        if ($own) db()->commit();
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return ['hold_ids' => $ids, 'created' => $created, 'slug' => $slug];
}

/** A saved batch, or null. */
function gi_batch(string $slug): ?array {
    $b = json_decode(setting(GI_BATCH_PREFIX . $slug, ''), true);
    if (!is_array($b) || !isset($b['hold_ids'])) return null;
    $b['hold_ids'] = array_map('intval', (array)$b['hold_ids']);
    $b['slug'] = $slug;
    return $b;
}

/** Every saved batch, newest first: [[slug,label,count,updated_at],…]. */
function gi_batches(): array {
    $rows = db_query("SELECT key, value FROM settings WHERE key LIKE :p", [':p' => GI_BATCH_PREFIX . '%'])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $b = json_decode((string)$r['value'], true);
        if (!is_array($b)) continue;
        $out[] = ['slug' => substr((string)$r['key'], strlen(GI_BATCH_PREFIX)), 'label' => (string)($b['label'] ?? ''),
                  'count' => count((array)($b['hold_ids'] ?? [])), 'updated_at' => (string)($b['updated_at'] ?? $b['created_at'] ?? '')];
    }
    usort($out, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    return $out;
}

/**
 * The batch's bookings grouped by head email (the person the links go to):
 * [['email','head','rooms'=>[['hold_id','property','room','guest','check_in','check_out','status','link']]]].
 * The head's name is the most common booking name among the group's rooms that…
 * simply: the first booking name under that email.
 */
function gi_batch_links(array $holdIds): array {
    $ids = array_values(array_filter(array_map('intval', $holdIds)));
    if (!$ids) return [];
    $rows = db_query(
        "SELECT h.id, h.guest_name, h.guest_email, h.check_in, h.check_out, h.status,
                v.name AS venue_name, r.name AS room_name, u.name AS unit_name
           FROM holds h JOIN units u ON u.id = h.unit_id
           JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
           LEFT JOIN venues v ON v.id = r.venue_id
          WHERE h.id IN (" . implode(',', $ids) . ")
          ORDER BY lower(h.guest_email), h.id"
    )->fetchAll();
    $byEmail = [];
    foreach ($rows as $h) {
        $e = strtolower((string)$h['guest_email']);
        $byEmail[$e] ??= ['email' => $e, 'head' => (string)$h['guest_name'], 'rooms' => []];
        $ref = make_guest_ref((int)$h['id']);
        $byEmail[$e]['rooms'][] = [
            'hold_id' => (int)$h['id'], 'property' => (string)$h['venue_name'],
            'room' => (string)$h['room_name'] . (((string)$h['unit_name'] !== '') ? ' · ' . $h['unit_name'] : ''),
            'guest' => (string)$h['guest_name'], 'check_in' => (string)$h['check_in'], 'check_out' => (string)$h['check_out'],
            'status' => (string)$h['status'], 'link' => $ref !== '' ? site_url('/booking.php?ref=' . $ref) : '',
        ];
    }
    return array_values($byEmail);
}
