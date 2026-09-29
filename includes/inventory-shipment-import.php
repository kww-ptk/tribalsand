<?php
declare(strict_types=1);
/**
 * Inventory — reading a supplier's shipment list (Excel) — PURE, no DB.
 * Spec: docs/superpowers/specs/2026-09-28-inventory-shipments-design.md §4.1
 * Test: php tests/inventory_shipments_logic.php
 *
 * Input is xlsx_read_sheets(): [sheet name => rows of ['v' => string, 'b' => bold]].
 *   • A MASTER-LIST sheet has "Item No", "Qty" and "Description" headers and no
 *     Length / Weight / Cubes column (those are container packing lists — skipped).
 *     Only the row that actually carries the item/qty/description headers is
 *     checked for a packing column — a stray "Weight" cell elsewhere never
 *     disqualifies the sheet.
 *   • A "Containers" row (optional trailing colon, anywhere in the sheet, before or
 *     after the header) is captured separately and is never a section/line/continuation.
 *   • Below its header: code + qty + description = a LINE. A description-only row is
 *     a SECTION (destination hint, e.g. "Off-Duty") when it is bold, else a
 *     CONTINUATION of the line above (the linen spec rows). "Studio Rooms" follows
 *     a line directly, so position alone can't tell them apart — bold can. With no
 *     bold in the sheet: after a blank row = section, otherwise continuation. In a
 *     BOLD sheet, a plain description-only row that follows a blank row / section /
 *     header (nothing to continue) is neither — it lands in `skipped`.
 *   • Lines become ITEMS one per supplier CODE (inv_ship_group()): same code + same
 *     description merge; the same description under two codes are two items.
 */

const INV_SHIP_MAX_LINES = 2000;
const INV_SHIP_MAX_QTY   = 100000;
const INV_SHIP_KINDS     = ['operational' => 'Item', 'spare' => 'Spare / consumable', 'serial' => 'Serial-tracked'];
// Category suggestions: first match wins. Keywords match whole words; a trailing * is a prefix.
const INV_SHIP_CATEGORIES = [
    'Appliances'        => ['fridge*', 'kettle*', 'toaster*', 'microwave*', 'oven*', 'hob', 'washing', 'dryer*'],
    'Kitchen & dining'  => ['teaspoon*', 'napkin holder*', 'cutlery', 'crockery'],
    'Linen'             => ['sheet*', 'duvet*', 'duver', 'pillow*', 'mattress', 'servietts', 'napkin*', 'table cloth*', 'placemat*'],
    'Cushions & throws' => ['cushion*', 'throw*'],
    'Rugs'              => ['rug*', 'runner*'],
    'Curtains & blinds' => ['curtain*', 'blind*', 'romashade'],
    'Lighting'          => ['light*', 'lantern*', 'lamp*'],
    'Consumables'       => ['plug*', 'anchor*', 'scented candle*'],
    'Furniture'         => ['couch*', 'sofa*', 'table*', 'chair*', 'stool*', 'ottoman*', 'lounger*', 'bed', 'beds', 'bunker*', 'daybed*',
                            'cabinet*', 'server', 'shelving', 'dining set*', 'lounge set*', 'outdoor set*', 'wash station', 'console', 'workstation'],
];
// Appliances that are actually serial-tracked (an unnamed/blank item defaults to serial too).
const INV_SHIP_SERIAL_KEYWORDS = ['fridge*', 'washing', 'dryer*', 'microwave*', 'oven*'];

function inv_ship_text(string $s): string { return trim((string)preg_replace('/\s+/u', ' ', $s)); }

/** The merge key of a name: lower-case, single spaces, no trailing punctuation — PURE. */
function inv_ship_key(string $name): string { return rtrim(mb_strtolower(inv_ship_text($name)), ' .,;:'); }

/** A short, form-safe id for a group key — PURE. */
function inv_ship_gid(string $key): string { return substr(md5($key), 0, 12); }

function inv_ship_code(string $s): string { return mb_substr(trim(rtrim(inv_ship_text($s), '/')), 0, 40); }

/** "9404,90,90" → "9404.90.90"; a float cell's noise ("9403.8900000000003") → "9403.89" — PURE.
 *  Only a LONG fraction (9+ digits) is treated as float noise, so a genuine long HS
 *  code like "8471.300010" is left alone. */
function inv_ship_hs(string $s): string {
    $s = str_replace([',', ' '], ['.', ''], trim($s));
    if (preg_match('/^\d+\.\d{9,}$/', $s)) $s = rtrim(rtrim(number_format(round((float)$s, 4), 4, '.', ''), '0'), '.');
    return mb_substr($s, 0, 20);
}

/** A whole, positive quantity, or null — PURE. */
function inv_ship_qty(string $s): ?int {
    $s = trim($s);
    if ($s === '' || !is_numeric($s)) return null;
    $f = (float)$s;
    if ($f < 1 || $f > INV_SHIP_MAX_QTY || abs($f - round($f)) > 1e-9) return null;
    return (int)round($f);
}

function inv_ship_kw_regex(string $kw): string {
    $prefix = str_ends_with($kw, '*');
    $word   = preg_quote(rtrim($kw, '*'), '/');
    return '/(?<![\p{L}\d])' . $word . ($prefix ? '[\p{L}\d]*' : '') . '(?![\p{L}\d])/u';
}

function inv_ship_suggest_category(string $name): string {
    $n = mb_strtolower(inv_ship_text($name));
    foreach (INV_SHIP_CATEGORIES as $cat => $kws) {
        foreach ($kws as $kw) if (preg_match(inv_ship_kw_regex($kw), $n)) return $cat;
    }
    return 'Décor';
}

/** 'spare' for Consumables; 'serial' for Appliances only when unnamed or a named
 *  serial-tracked appliance (fridge/washing machine/dryer/microwave/oven); else 'operational' — PURE. */
function inv_ship_suggest_kind(string $category, string $name = ''): string {
    if ($category === 'Consumables') return 'spare';
    if ($category === 'Appliances') {
        if ($name === '') return 'serial';
        $n = mb_strtolower($name);
        foreach (INV_SHIP_SERIAL_KEYWORDS as $kw) if (preg_match(inv_ship_kw_regex($kw), $n)) return 'serial';
        return 'operational';
    }
    return 'operational';
}

function inv_ship_suggest_unit(string $name): string {
    return preg_match('/\bsets?\b|\(\s*\d+\s*(pce|pcs|pc)\s*\)/i', $name) ? 'sets' : 'pcs';
}

/** Column map of a master-list header row, 'packing' for a packing-list header, or
 *  null when this row is not a header at all — PURE. A row only counts as a header
 *  (and so is only checked for a packing column) once it carries the item/qty/
 *  description headers; a stray "Weight" cell on some other row is irrelevant. */
function inv_ship_header(array $cells): array|string|null {
    $map = []; $packing = false;
    foreach ($cells as $i => $c) {
        $t = mb_strtolower(inv_ship_text((string)($c['v'] ?? '')));
        if (in_array($t, ['length', 'weight', 'cubes'], true)) { $packing = true; continue; }
        if (in_array($t, ['item no', 'item no.', 'code'], true)) $map['code'] ??= $i;
        elseif (in_array($t, ['qty', 'quantity'], true))       $map['qty']  ??= $i;
        elseif ($t === 'description')                           $map['desc'] ??= $i;
        elseif ($t === 'hs code')                               $map['hs']   ??= $i;
    }
    if (!isset($map['code'], $map['qty'], $map['desc'])) return null;
    return $packing ? 'packing' : $map;
}

/** A "Containers" row's container list, or null when this row isn't one — PURE.
 *  Matches the row's first NON-EMPTY cell, lower-cased and rtrimmed of an optional
 *  trailing colon, against "containers"; the rest of that row's non-empty cells
 *  (slash-separated) are the containers. */
function inv_ship_containers_row(array $cells): ?array {
    $first = null; $firstIdx = null;
    foreach ($cells as $i => $c) {
        $t = inv_ship_text((string)($c['v'] ?? ''));
        if ($t !== '') { $first = $t; $firstIdx = $i; break; }
    }
    if ($first === null || rtrim(mb_strtolower($first), ':') !== 'containers') return null;
    $rest = [];
    foreach (array_slice($cells, $firstIdx + 1, null, true) as $c) {
        if (($t = inv_ship_text((string)($c['v'] ?? ''))) !== '') $rest[] = $t;
    }
    $out = [];
    foreach (preg_split('#\s*/\s*#', implode(' / ', $rest)) ?: [] as $c) if (trim($c) !== '') $out[] = trim($c);
    return $out;
}

/** One sheet → ['containers','lines','skipped'], or null when it is not a master list — PURE. */
function inv_ship_parse_sheet(string $sheetName, array $rows): ?array {
    $map = null; $headerAt = null; $containers = [];
    foreach ($rows as $n => $cells) {
        if (($c = inv_ship_containers_row($cells)) !== null) { array_push($containers, ...$c); continue; }
        $h = inv_ship_header($cells);
        if ($h === 'packing') return null;
        if (is_array($h)) { $map = $h; $headerAt = $n; break; }
    }
    if ($map === null) return null;

    $cell     = fn(array $cells, ?int $i): string => $i === null ? '' : inv_ship_text((string)($cells[$i]['v'] ?? ''));
    $descOnly = fn(array $cells): bool => $cell($cells, $map['code']) === '' && $cell($cells, $map['qty']) === '' && $cell($cells, $map['desc']) !== '';
    // Rows are SPARSE (xlsx_read_sheets() keys them by spreadsheet row − 1; a missing key is an empty row).
    $body     = array_filter($rows, fn($k) => $k > $headerAt, ARRAY_FILTER_USE_KEY);
    $boldMode = false;
    foreach ($body as $cells) if ($descOnly($cells) && !empty($cells[$map['desc']]['b'])) { $boldMode = true; break; }

    $lines = []; $skipped = []; $section = ''; $prev = null; $lastN = $headerAt;
    foreach ($body as $n => $cells) {
        if ($n !== $lastN + 1) $prev = null;   // a gap in row numbers = blank row(s)
        $lastN = $n;
        if (($c = inv_ship_containers_row($cells)) !== null) { array_push($containers, ...$c); $prev = null; continue; }
        $code   = inv_ship_code($cell($cells, $map['code']));
        $qtyRaw = $cell($cells, $map['qty']);
        $desc   = $cell($cells, $map['desc']);
        if ($code === '' && $qtyRaw === '' && $desc === '') { $prev = null; continue; }
        if ($descOnly($cells)) {
            $bold = !empty($cells[$map['desc']]['b']);
            if ($boldMode) {
                if ($bold) { $section = mb_substr($desc, 0, 120); $prev = null; }
                elseif ($prev !== null) { $lines[$prev]['description'] .= ' · ' . $desc; }
                else { $skipped[] = ['sheet' => $sheetName, 'row' => $n + 1, 'text' => $desc]; $prev = null; }
            } else {
                // fallback (no bold in the sheet): after a blank row = section, otherwise continuation
                if ($prev === null) { $section = mb_substr($desc, 0, 120); }
                else { $lines[$prev]['description'] .= ' · ' . $desc; }
            }
            continue;
        }
        $qty = inv_ship_qty($qtyRaw);
        if ($code !== '' && $desc !== '' && $qty !== null) {
            $lines[] = ['sheet' => $sheetName, 'row' => $n + 1, 'section' => $section, 'code' => $code,
                        'hs_code' => isset($map['hs']) ? inv_ship_hs($cell($cells, $map['hs'])) : '',
                        'description' => $desc, 'qty' => $qty];
            $prev = array_key_last($lines);
            continue;
        }
        $skipped[] = ['sheet' => $sheetName, 'row' => $n + 1, 'text' => trim("{$code} {$qtyRaw} {$desc}")];
        $prev = null;
    }
    return ['containers' => $containers, 'lines' => $lines, 'skipped' => $skipped];
}

/** Every master list in a workbook, lines in sheet order — PURE. A sheet whose rows
 *  ALL fail still contributes its `skipped` rows (for the import report) even though
 *  it has no lines and so is not counted in `sheets`/`containers`/`lines`. */
function inv_ship_parse_workbook(array $sheets): array {
    $out = ['containers' => [], 'lines' => [], 'skipped' => [], 'sheets' => []];
    foreach ($sheets as $name => $rows) {
        $s = inv_ship_parse_sheet((string)$name, $rows);
        if ($s === null) continue;
        array_push($out['skipped'], ...$s['skipped']);
        if (!$s['lines']) continue;
        $out['sheets'][] = (string)$name;
        array_push($out['containers'], ...$s['containers']);
        array_push($out['lines'], ...$s['lines']);
    }
    $out['containers'] = array_values(array_unique($out['containers']));
    return $out;
}

// ── Packing lists (container hints) ─────────────────────────────────────────

/**
 * One packing-list sheet → ['container','lines' => [['code','description','qty','row']]],
 * or null when the sheet is not a packing list — PURE. A packing list has an
 * Item No + Qty + Description header AND a Length / Weight / Cubes column. The
 * container is named by a "Container <name>" cell above the header (else the sheet
 * name without a leading "PL "). Rows with no code or no whole quantity (dimension
 * continuation rows) are ignored.
 */
function inv_ship_parse_packing_sheet(string $sheetName, array $rows): ?array {
    $container = null; $map = null; $headerAt = null;
    foreach ($rows as $n => $cells) {
        $h = inv_ship_header($cells);
        if ($h === 'packing') {
            // Re-derive the column map (inv_ship_header() returns only the marker for a packing header).
            $map = [];
            foreach ($cells as $i => $c) {
                $t = mb_strtolower(inv_ship_text((string)($c['v'] ?? '')));
                if (in_array($t, ['item no', 'item no.', 'code'], true)) $map['code'] ??= $i;
                elseif (in_array($t, ['qty', 'quantity'], true))       $map['qty']  ??= $i;
                elseif ($t === 'description')                           $map['desc'] ??= $i;
            }
            $headerAt = $n; break;
        }
        if (is_array($h)) return null;   // a master list header
        foreach ($cells as $c) {
            $t = inv_ship_text((string)($c['v'] ?? ''));
            if ($t === '') continue;
            if ($container === null && preg_match('/^container\s+(.+)$/i', $t, $m)) $container = trim($m[1]);
            break;   // only the row's first non-empty cell
        }
    }
    if ($map === null) return null;
    if ($container === null || $container === '') $container = trim((string)preg_replace('/^PL\s+/i', '', inv_ship_text($sheetName)));
    if ($container === '') return null;
    $lines = [];
    foreach ($rows as $n => $cells) {
        if ($n <= $headerAt) continue;
        $code = inv_ship_code((string)($cells[$map['code']]['v'] ?? ''));
        $qty  = inv_ship_qty((string)($cells[$map['qty']]['v'] ?? ''));
        if ($code === '' || $qty === null) continue;
        $lines[] = ['code' => $code, 'description' => inv_ship_text((string)($cells[$map['desc']]['v'] ?? '')), 'qty' => $qty, 'row' => $n + 1];
    }
    return ['container' => mb_substr($container, 0, 80), 'lines' => $lines];
}

/** Every packing-list sheet of a workbook, in sheet order — PURE. */
function inv_ship_parse_packing(array $sheets): array {
    $out = [];
    foreach ($sheets as $name => $rows) {
        $p = inv_ship_parse_packing_sheet((string)$name, $rows);
        if ($p !== null) $out[] = $p;
    }
    return $out;
}

/** The master-list code a packing-list code stands for, or null — PURE. Exact
 *  (case-insensitive) match, else with a trailing bundle suffix (CVL102B7 → CVL102) removed. */
function inv_ship_packing_code(string $plCode, array $masterCodes): ?string {
    $plCode = inv_ship_code($plCode);
    if ($plCode === '') return null;
    $by = [];
    foreach ($masterCodes as $c) $by[mb_strtoupper((string)$c)] ??= (string)$c;
    $u = mb_strtoupper($plCode);
    if (isset($by[$u])) return $by[$u];
    $stripped = (string)preg_replace('/B\d+$/i', '', $plCode);
    if ($stripped !== '' && $stripped !== $plCode && isset($by[mb_strtoupper($stripped)])) return $by[mb_strtoupper($stripped)];
    return null;
}

/**
 * Group lines into proposed items — PURE. ONE ITEM PER SUPPLIER CODE: a group is
 * one (code, name) pair, because the supplier's codes are distinct products (a
 * "Side Table" under V007 and under OV005 are different tables). Two lines with
 * the same code AND the same name merge (a repeated line); the same name under
 * DIFFERENT codes stays apart; different names under one code stay apart.
 *
 * $names: [line index => item name] (from the preview); a line keeps its
 * description as its name otherwise. When a name (by merge key) occurs under more
 * than one code in this list, each of those items is named "{name} ({CODE})" so
 * the catalogue can tell them apart — a name under a single code stays plain. A
 * line with no code keeps the old behaviour: it merges by plain name. A name whose
 * merge key is empty (e.g. a description of only dots) falls back to the line's own
 * description, and if THAT key is also empty, to "Item <code>" (or plain "Item"
 * with no code) — so a line never silently vanishes into a same-named-nothing group.
 *
 * The group key is inv_ship_key() of the FINAL name, so it is unique per item and
 * stable across re-imports (the name carries the code whenever the key would
 * otherwise clash). Returns
 * [key => ['key','name','code','qty','lines' => [line index…],'category','kind','unit']]
 * in first-seen order.
 */
function inv_ship_group(array $lines, array $names = []): array {
    // Pass 1: each line's base name (override → description → "Item <code>") and code.
    $base = [];
    $codesByName = [];   // merge key of the base name => [UPPER code => [first name, first code spelling]]
    foreach ($lines as $i => $l) {
        $name = inv_ship_text((string)($names[$i] ?? ''));
        if ($name === '') $name = inv_ship_text((string)$l['description']);
        $name = mb_substr($name, 0, 160);
        $code = inv_ship_code((string)($l['code'] ?? ''));
        if (inv_ship_key($name) === '') {
            $desc = inv_ship_text((string)$l['description']);
            if (inv_ship_key($desc) !== '') {
                $name = mb_substr($desc, 0, 160);
            } else {
                $name = $code !== '' ? 'Item ' . $code : 'Item';
            }
        }
        $base[$i] = ['name' => $name, 'code' => $code];
        $nk = inv_ship_key($name);
        if ($code !== '') $codesByName[$nk][mb_strtoupper($code)] ??= [$name, $code];   // first-seen spelling of this (name, code)
    }
    // Pass 2: name each line's item — the code is added only where the name is shared across codes.
    $g = [];
    foreach ($lines as $i => $l) {
        $name = $base[$i]['name'];
        $code = $base[$i]['code'];
        $seen = $codesByName[inv_ship_key($name)] ?? [];
        if ($code !== '' && count($seen) > 1) {
            // Spelling of the first line for this (name, code), so "Lamp"/"lamp." under one code merge.
            [$name, $code] = $seen[mb_strtoupper($code)];
            $suffix = ' (' . $code . ')';
            $name = mb_substr($name, 0, 160 - mb_strlen($suffix)) . $suffix;
        }
        $key = inv_ship_key($name);
        if (!isset($g[$key])) {
            $cat = inv_ship_suggest_category($name);
            $g[$key] = ['key' => $key, 'name' => $name, 'code' => $code, 'qty' => 0, 'lines' => [], 'category' => $cat,
                        'kind' => inv_ship_suggest_kind($cat, $name), 'unit' => inv_ship_suggest_unit($name)];
        }
        $g[$key]['qty'] += (int)$l['qty'];
        $g[$key]['lines'][] = $i;
    }
    return $g;
}

// ── Item-code prefix → place (par levels) ───────────────────────────────────

/** The leading ASCII letters of an item code, upper-cased ('' when it starts with none) — PURE.
 *  "R006MB" → "R", "OV003" → "OV", "APP001" → "APP", "CVL102" → "CVL", "123" → "". */
function inv_ship_prefix(string $code): string {
    return preg_match('/^[A-Za-z]+/', trim($code), $m) ? strtoupper($m[0]) : '';
}

/**
 * The owner's mapping for the Maya Ilai fit-out lists: item-code prefix → the
 * NAME of the inventory location whose par levels that prefix's items feed.
 * Used only as a DEFAULT when a location with this exact name (case-insensitive)
 * exists — see inv_import_default_places(). V/S/OV/WT/SP/G and APP/B/CVL/DR/BL
 * are all Maya Ilai components/spares; OD is the (hidden) Off-Duty property; HS
 * and R are areas under Tribal Dunes (Hair Salon, Tribal Table); MK is Maya Kobe.
 */
const INV_IMPORT_DEFAULT_PREFIX_PLACES = [
    'V' => 'Maya Ilai', 'S' => 'Maya Ilai', 'OV' => 'Maya Ilai', 'OS' => 'Maya Ilai', 'WT' => 'Maya Ilai', 'SP' => 'Maya Ilai', 'G' => 'Maya Ilai',
    'APP' => 'Maya Ilai', 'B' => 'Maya Ilai', 'CVL' => 'Maya Ilai', 'DR' => 'Maya Ilai', 'BL' => 'Maya Ilai',
    'OD' => 'Off-Duty', 'HS' => 'Hair Salon', 'MK' => 'Maya Kobe', 'R' => 'Tribal Table',
];

/**
 * The par levels an import would set — PURE, no DB. $lineItem: [line index =>
 * item id] (from inv_import_items()'s 'group_items', expanded per line);
 * $prefixPlace: [prefix => location id] (0 or missing = no place chosen).
 * A line with no resolved item, or whose prefix has no place, sets nothing.
 * Returns ["<item id>:<location id>" => qty summed over every matching line].
 */
function inv_import_par_plan(array $lines, array $lineItem, array $prefixPlace): array {
    $out = [];
    foreach ($lines as $i => $l) {
        $itemId = $lineItem[$i] ?? null;
        if (!$itemId) continue;
        $locId = (int)($prefixPlace[inv_ship_prefix((string)($l['code'] ?? ''))] ?? 0);
        if ($locId <= 0) continue;
        $key = $itemId . ':' . $locId;
        $out[$key] = ($out[$key] ?? 0) + (int)($l['qty'] ?? 0);
    }
    return $out;
}
