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
 *   • Below its header: code + qty + description = a LINE. A description-only row is
 *     a SECTION (destination hint, e.g. "Off-Duty") when it is bold, else a
 *     CONTINUATION of the line above (the linen spec rows). "Studio Rooms" follows
 *     a line directly, so position alone can't tell them apart — bold can. With no
 *     bold in the sheet: after a blank row = section, otherwise continuation.
 *   • Lines become ITEMS by their normalised name ("merge by name"). The preview can
 *     rename a group (every line in it) or split one line off under a new name.
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
    'Furniture'         => ['couch*', 'sofa*', 'table*', 'chair*', 'stool*', 'ottoman*', 'lounger*', 'bed*', 'daybed*', 'cabinet*',
                            'server', 'shelving', 'set', 'wash station', 'console', 'workstation'],
];

function inv_ship_text(string $s): string { return trim((string)preg_replace('/\s+/u', ' ', $s)); }

/** The merge key of a name: lower-case, single spaces, no trailing punctuation — PURE. */
function inv_ship_key(string $name): string { return rtrim(mb_strtolower(inv_ship_text($name)), ' .,;:'); }

/** A short, form-safe id for a group key — PURE. */
function inv_ship_gid(string $key): string { return substr(md5($key), 0, 12); }

function inv_ship_code(string $s): string { return mb_substr(trim(rtrim(inv_ship_text($s), '/')), 0, 40); }

/** "9404,90,90" → "9404.90.90"; a float cell's noise ("9403.8900000000003") → "9403.89" — PURE. */
function inv_ship_hs(string $s): string {
    $s = str_replace([',', ' '], ['.', ''], trim($s));
    if (preg_match('/^\d+\.\d{5,}$/', $s)) $s = rtrim(rtrim(number_format(round((float)$s, 4), 4, '.', ''), '0'), '.');
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
    $n = mb_strtolower($name);
    foreach (INV_SHIP_CATEGORIES as $cat => $kws) {
        foreach ($kws as $kw) if (preg_match(inv_ship_kw_regex($kw), $n)) return $cat;
    }
    return 'Décor';
}

function inv_ship_suggest_kind(string $category): string {
    return match ($category) { 'Appliances' => 'serial', 'Consumables' => 'spare', default => 'operational' };
}

function inv_ship_suggest_unit(string $name): string {
    return preg_match('/\bsets?\b|\(\s*\d+\s*(pce|pcs|pc)\s*\)/i', $name) ? 'sets' : 'pcs';
}

/** Column map of a master-list header row, 'packing' for a packing-list header, or null — PURE. */
function inv_ship_header(array $cells): array|string|null {
    $map = [];
    foreach ($cells as $i => $c) {
        $t = mb_strtolower(inv_ship_text((string)($c['v'] ?? '')));
        if (in_array($t, ['length', 'weight', 'cubes'], true)) return 'packing';
        if (in_array($t, ['item no', 'item no.', 'code'], true)) $map['code'] ??= $i;
        elseif (in_array($t, ['qty', 'quantity'], true))       $map['qty']  ??= $i;
        elseif ($t === 'description')                           $map['desc'] ??= $i;
        elseif ($t === 'hs code')                               $map['hs']   ??= $i;
    }
    return isset($map['code'], $map['qty'], $map['desc']) ? $map : null;
}

/** One sheet → ['containers','lines','skipped'], or null when it is not a master list — PURE. */
function inv_ship_parse_sheet(string $sheetName, array $rows): ?array {
    $map = null; $headerAt = null; $containers = [];
    foreach ($rows as $n => $cells) {
        $first = mb_strtolower(inv_ship_text((string)($cells[0]['v'] ?? '')));
        if ($first === 'containers') {
            $rest = [];
            foreach (array_slice($cells, 1) as $c) if (($t = inv_ship_text((string)($c['v'] ?? ''))) !== '') $rest[] = $t;
            foreach (preg_split('#\s*/\s*#', implode(' / ', $rest)) ?: [] as $c) if (trim($c) !== '') $containers[] = trim($c);
            continue;
        }
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
        $code   = inv_ship_code($cell($cells, $map['code']));
        $qtyRaw = $cell($cells, $map['qty']);
        $desc   = $cell($cells, $map['desc']);
        if ($code === '' && $qtyRaw === '' && $desc === '') { $prev = null; continue; }
        if ($descOnly($cells)) {
            $isSection = $boldMode ? !empty($cells[$map['desc']]['b']) : $prev === null;
            if ($isSection || $prev === null) { $section = mb_substr($desc, 0, 120); $prev = null; }
            else $lines[$prev]['description'] .= ' · ' . $desc;
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

/** Every master list in a workbook, lines in sheet order — PURE. */
function inv_ship_parse_workbook(array $sheets): array {
    $out = ['containers' => [], 'lines' => [], 'skipped' => [], 'sheets' => []];
    foreach ($sheets as $name => $rows) {
        $s = inv_ship_parse_sheet((string)$name, $rows);
        if ($s === null || !$s['lines']) continue;
        $out['sheets'][] = (string)$name;
        array_push($out['containers'], ...$s['containers']);
        array_push($out['lines'], ...$s['lines']);
        array_push($out['skipped'], ...$s['skipped']);
    }
    $out['containers'] = array_values(array_unique($out['containers']));
    return $out;
}

/**
 * Group lines into proposed items — PURE. $names: [line index => item name] (from
 * the preview); a line keeps its description as its name otherwise. Lines whose
 * names share a key are ONE item. Returns [key => ['key','name','qty','lines' =>
 * [line index…],'category','kind','unit']] in first-seen order.
 */
function inv_ship_group(array $lines, array $names = []): array {
    $g = [];
    foreach ($lines as $i => $l) {
        $name = inv_ship_text((string)($names[$i] ?? ''));
        if ($name === '') $name = inv_ship_text((string)$l['description']);
        $name = mb_substr($name, 0, 160);
        $key  = inv_ship_key($name);
        if (!isset($g[$key])) {
            $cat = inv_ship_suggest_category($name);
            $g[$key] = ['key' => $key, 'name' => $name, 'qty' => 0, 'lines' => [], 'category' => $cat,
                        'kind' => inv_ship_suggest_kind($cat), 'unit' => inv_ship_suggest_unit($name)];
        }
        $g[$key]['qty'] += (int)$l['qty'];
        $g[$key]['lines'][] = $i;
    }
    return $g;
}

/**
 * Fold the preview form into the per-line state — PURE. $groups: the grouping the
 * form was drawn from; $post: ['g' => [gid => name/category/kind/unit], 'split' =>
 * [line index => name]]. A renamed group renames every line in it; a non-empty
 * "split off as" wins for its line; a group's choices are remembered on each of
 * its lines. Returns [$names, $choices].
 */
function inv_ship_apply_preview(array $groups, array $post, array $names, array $choices): array {
    foreach ($groups as $key => $g) {
        $gp      = (array)($post['g'][inv_ship_gid((string)$key)] ?? []);
        $newName = inv_ship_text((string)($gp['name'] ?? ''));
        $unit    = mb_substr(inv_ship_text((string)($gp['unit'] ?? $g['unit'])), 0, 20);
        $ch = [
            'category' => mb_substr(inv_ship_text((string)($gp['category'] ?? $g['category'])), 0, 60),
            'kind'     => isset(INV_SHIP_KINDS[$gp['kind'] ?? '']) ? (string)$gp['kind'] : (string)$g['kind'],
            'unit'     => $unit !== '' ? $unit : 'pcs',
        ];
        foreach ($g['lines'] as $i) {
            if ($newName !== '' && $newName !== $g['name']) $names[$i] = mb_substr($newName, 0, 160);
            $choices[$i] = $ch;
        }
    }
    foreach ((array)($post['split'] ?? []) as $i => $n) {
        $n = inv_ship_text((string)$n);
        if ($n !== '' && is_numeric($i)) $names[(int)$i] = mb_substr($n, 0, 160);
    }
    return [$names, $choices];
}

/** inv_ship_group() with the remembered choices applied (a group's first line decides) — PURE. */
function inv_ship_groups_with_choices(array $lines, array $names, array $choices): array {
    $g = inv_ship_group($lines, $names);
    foreach ($g as &$x) {
        $c = $choices[$x['lines'][0]] ?? null;
        if ($c) { $x['category'] = $c['category']; $x['kind'] = $c['kind']; $x['unit'] = $c['unit']; }
    }
    unset($x);
    return $g;
}
