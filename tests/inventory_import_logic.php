<?php
declare(strict_types=1);
// Inventory — items imported from a supplier Excel, and shared stores. Run: php tests/inventory_import_logic.php
// Pure rules always run. The DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory_stores.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/inventory-shipment-import.php';
require_once __DIR__ . '/../includes/inventory-item-import.php';
require_once __DIR__ . '/../includes/inventory-orders.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
$fixture = __DIR__ . '/fixtures/shipment-maya-ilai.xlsx';
check('fixture: the real shipment list is present', is_file($fixture));

// ── Stores: venue sets and scope ────────────────────────────────────────────
$td    = ['kind' => 'store', 'venue_id' => 7, 'share_venue_ids' => '{6,8}'];      // TD Main Stock: Tribal Dunes, shared with Maya Ilai + Off-Duty
$mainS = ['kind' => 'store', 'venue_id' => null, 'share_venue_ids' => '{}'];
$mi    = ['kind' => 'property', 'venue_id' => 6];
$zuriP = ['kind' => 'property', 'venue_id' => 3];
check('pg int[]: text and PHP arrays both read', inv_pg_int_array('{6,8}') === [6, 8] && inv_pg_int_array([6, '8']) === [6, 8]
    && inv_pg_int_array(null) === [] && inv_pg_int_array('{}') === [] && inv_pg_int_array('') === []);
check('pg int[]: literal for a bind', inv_pg_int_array_literal([6, 8]) === '{6,8}' && inv_pg_int_array_literal([]) === '{}');
check('venue set: owner then shares', inv_location_venue_set($td) === [7, 6, 8]);
check('venue set: Main stock has none', inv_location_venue_set($mainS) === [] && inv_location_venue_set(['venue_id' => '3']) === [3]);
check('shared store: a Maya Ilai manager sees it', inv_location_visible($td, [6]));
check('shared store: a Zuri manager does not', !inv_location_visible($td, [3]));
check('shared store: Maya Ilai manager moves it to Maya Ilai', inv_move_in_scope($td, $mi, [6]));
check('shared store: …but not to Zuri', !inv_move_in_scope($td, $zuriP, [6]));
check('shared store: a Zuri manager cannot draw from it', !inv_move_in_scope($td, $zuriP, [3]));
check('shared store: its settings stay with the owning property', inv_location_editable($td, [7]) && !inv_location_editable($td, [6]));
check('Main stock: still shared with everyone', inv_location_visible($mainS, [3]) && inv_move_in_scope($mainS, $zuriP, [3]));
check('shares: cleaned, the owner dropped, sorted', inv_clean_share_ids(['8', '6', 'x', '6', '7', '-1'], 7) === [6, 8]);
check('restock source: the store serving the property wins over Main stock', inv_default_restock_source([
        ['id' => 1, 'kind' => 'store', 'is_main' => 't', 'venue_id' => null, 'share_venue_ids' => '{}'],
        ['id' => 9, 'kind' => 'store', 'is_main' => 'f', 'venue_id' => 7, 'share_venue_ids' => '{6,8}']], $mi) === 9);
check('restock source: otherwise Main stock', inv_default_restock_source([
        ['id' => 1, 'kind' => 'store', 'is_main' => 't', 'venue_id' => null, 'share_venue_ids' => '{}'],
        ['id' => 9, 'kind' => 'store', 'is_main' => 'f', 'venue_id' => 7, 'share_venue_ids' => '{6,8}']], $zuriP) === 1);
check('restock source: none offered', inv_default_restock_source([], $mi) === null);

// ── Review fixes ────────────────────────────────────────────────────────────
check('scope: an account with no properties sees nothing and moves nothing', !inv_location_visible($td, []) && !inv_move_in_scope($td, $mi, []));
check('shares: cleaned, owner dropped, sorted', inv_clean_share_ids(['6', '6', '0'], null) === [6]);
check('shared store: a sharing manager may receive into it or write off from it', inv_move_in_scope(null, $td, [6]) && inv_move_in_scope($td, null, [6]) && !inv_move_in_scope(null, $td, [3]));
check('restock source: an owned store beats one merely shared', inv_default_restock_source([
        ['id' => 1, 'kind' => 'store', 'is_main' => 't', 'venue_id' => null, 'share_venue_ids' => '{}'],
        ['id' => 9, 'kind' => 'store', 'is_main' => 'f', 'venue_id' => 7, 'share_venue_ids' => '{6,8}'],
        ['id' => 12, 'kind' => 'store', 'is_main' => 'f', 'venue_id' => 6, 'share_venue_ids' => '{}']], $mi) === 12);
check('sort: Main stock is pinned first, ahead of other stores', array_map(fn($l) => $l['name'], inv_sort_locations([
        ['kind' => 'store', 'name' => 'A store', 'is_main' => false, 'sort_order' => 0],
        ['kind' => 'store', 'name' => 'Main stock', 'is_main' => true, 'sort_order' => 0]])) === ['Main stock', 'A store']);

// ── xlsx reader ─────────────────────────────────────────────────────────────
$sheets = xlsx_read_sheets($fixture);
check('xlsx: every sheet, by name, in workbook order', array_keys($sheets) === ['Master Shipper Owned Container', 'PL NONE 6585458',
    'PL NONE6848636', 'Master List Vessel Container', 'PL MSBU781565', 'PL TEMU8316834']);
$soc = $sheets['Master Shipper Owned Container'];
check('xlsx: row numbers kept (row 7 is index 6)', ($soc[6][3]['v'] ?? '') === 'Villas' && ($soc[7][1]['v'] ?? '') === 'V001');
check('xlsx: bold section heading, plain line', ($soc[6][3]['b'] ?? false) === true && ($soc[7][3]['b'] ?? true) === false);
check('xlsx: first-sheet reader unchanged (plain strings)', is_string(xlsx_read_rows($fixture)[0][3] ?? null));

$xlsxStylesXml = '<?xml version="1.0"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
    <font><b val="0"/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <cellStyleXfs count="1"><xf fontId="1"/></cellStyleXfs>
  <cellXfs count="3">
    <xf fontId="0"/>
    <xf fontId="1"/>
    <xf fontId="2"/>
  </cellXfs>
  <dxfs count="1"><dxf><font><b/></font></dxf></dxfs>
</styleSheet>';
check('xlsx: bold styles read cellXfs/fonts, ignore cellStyleXfs and dxfs',
    xlsx_bold_styles($xlsxStylesXml) === [false, true, false]);

// The fixture's first sheet has no blank <row> elements the old fillGaps
// behaviour would have kept as empty rows, so the sparse-by-row-number
// sheet reader and the sequential first-sheet reader agree once compacted.
check('xlsx: sparse sheet rows compact to the same values as the sequential reader',
    array_values(array_map(fn($r) => array_map(fn($c) => $c['v'], $r), $soc)) === xlsx_read_rows($fixture));

check('xlsx: column index caps at XFD (16383)', xlsx_col_index('XFD1') === 16383);
check('xlsx: column past XFD throws', (function () {
    try { xlsx_col_index('XFE1'); return false; } catch (RuntimeException $e) { return true; }
})());

$xlsxSparseXml = '<?xml version="1.0"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>
<row r="2"><c r="A2"><v>x</v></c></row>
<row r="5"><c r="A5"><v>y</v></c></row>
</sheetData></worksheet>';
check('xlsx: keep-row-numbers mode stores rows sparsely by row − 1',
    array_keys(xlsx_sheet_cells($xlsxSparseXml, [], [], true)) === [1, 4]);

$xlsxHugeRowXml = '<?xml version="1.0"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>
<row r="2000000"><c r="A2000000"><v>z</v></c></row>
</sheetData></worksheet>';
check('xlsx: a row past Excel\'s 1,048,576-row cap throws', (function () use ($xlsxHugeRowXml) {
    try { xlsx_sheet_cells($xlsxHugeRowXml, [], [], true); return false; } catch (RuntimeException $e) { return true; }
})());

// ── Importer (pure) ─────────────────────────────────────────────────────────
check('norm: HS codes with commas / float noise', inv_ship_hs('9404,90,90') === '9404.90.90' && inv_ship_hs('9403.8900000000003') === '9403.89' && inv_ship_hs('4602.12') === '4602.12');
check('norm: item codes trimmed', inv_ship_code('OV003/ ') === 'OV003' && inv_ship_code(' OS001 ') === 'OS001');
check('norm: quantities are whole positive numbers', inv_ship_qty('8') === 8 && inv_ship_qty('8.0') === 8 && inv_ship_qty('2.5') === null && inv_ship_qty('') === null && inv_ship_qty('x') === null);
check('norm: merge key ignores case, spacing, trailing dots', inv_ship_key('  Woven  Basket Med. ') === inv_ship_key('woven basket med'));
check('suggest: fridge → appliance, serial', inv_ship_suggest_category('Mini Bar Fridge') === 'Appliances' && inv_ship_suggest_kind('Appliances') === 'serial');
check('suggest: linen, throws, rugs, curtains', inv_ship_suggest_category('Fitted Sheet King - White') === 'Linen'
    && inv_ship_suggest_category('Bed Throw 1.83m x 0.3m') === 'Cushions & throws' && inv_ship_suggest_category('Runner Rug 0.8m x 3.0m') === 'Rugs'
    && inv_ship_suggest_category('Double Curtain Rails (166 pcs with brackets and screws)') === 'Curtains & blinds');
check('suggest: furniture, lighting, décor', inv_ship_suggest_category('Outdoor Dining Set (9 pce)') === 'Furniture'
    && inv_ship_suggest_category('Wood Acorn Lights 200mm') === 'Lighting' && inv_ship_suggest_category('Crab Statue') === 'Décor'
    && inv_ship_suggest_category('Candle Holders') === 'Décor' && inv_ship_suggest_category('Napkin Holder (Set of 6)') === 'Kitchen & dining');
check('suggest: consumables are spare stock', inv_ship_suggest_category('Plugs') === 'Consumables' && inv_ship_suggest_kind('Consumables') === 'spare'
    && inv_ship_suggest_kind('Décor') === 'operational');
check('suggest: sets are counted as sets', inv_ship_suggest_unit('Outdoor Dining Set (9 pce)') === 'sets' && inv_ship_suggest_unit('Lounge Set (3 pce)') === 'sets'
    && inv_ship_suggest_unit('Couch 2.6m x 1m') === 'pcs');

$wb = inv_ship_parse_workbook($sheets);
$by = fn(string $code): array => array_values(array_filter($wb['lines'], fn($l) => $l['code'] === $code));
$idx = fn(string $code): int => (int) array_key_first(array_filter($wb['lines'], fn($l) => $l['code'] === $code));
check('parse: both master lists, packing lists skipped', $wb['sheets'] === ['Master Shipper Owned Container', 'Master List Vessel Container']);
check('parse: 199 lines, 4,333 pieces', count($wb['lines']) === 199 && array_sum(array_column($wb['lines'], 'qty')) === 4333);
check('parse: the four containers', $wb['containers'] === ['NONE 6585458 45 G1', 'NONE 6848636 45 G1', 'MSBU781565 45G1', 'TEMU831683 45G1']);
check('parse: sections from the bold headings', $by('V001')[0]['section'] === 'Villas' && $by('S001')[0]['section'] === 'Studio Rooms'
    && $by('OD005')[0]['section'] === 'Off-Duty' && $by('B001')[0]['section'] === 'General');
check('parse: 11 sections', count(array_unique(array_column($wb['lines'], 'section'))) === 11);
check('parse: linen spec rows joined to their line', $by('B001')[0]['description'] === 'Mattress Protector Fitted Quilted · Microfibre King 183cm x 190cm x 30cm · T200 100% Cotton Percale');
check('parse: HS code normalised', $by('V006')[1]['hs_code'] === '9404.90.90' && $by('V004')[0]['hs_code'] === '9403.89');
check('parse: nothing skipped', $wb['skipped'] === []);
check('parse: spreadsheet row numbers kept', $by('V001')[0]['row'] === 8 && $by('V001')[0]['sheet'] === 'Master Shipper Owned Container');

$g = inv_ship_group($wb['lines']);
// Independent count: distinct (code, description key) pairs, and names shared by >1 code.
$pairSet = []; $nameCodes = [];
foreach ($wb['lines'] as $l) {
    $nk = inv_ship_key($l['description']);
    $pairSet[mb_strtoupper($l['code']) . '|' . $nk] = true;
    $nameCodes[$nk][mb_strtoupper($l['code'])] = true;
}
$sharedNames = array_filter($nameCodes, fn($c) => count($c) > 1);
check('group: one item per (code, name) — 196 items from 199 lines', count($g) === 196 && count($pairSet) === 196);
check('group: 27 names are shared by more than one code', count($sharedNames) === 27);
check('group: every group has a unique key, a single code and its lines all carry that code',
    count(array_unique(array_keys($g))) === 196 && !array_filter($g, function ($x) use ($wb) {
        foreach ($x['lines'] as $i) if (mb_strtoupper($wb['lines'][$i]['code']) !== mb_strtoupper($x['code'])) return true;
        return false;
    }));
check('group: every line is in exactly one group, pieces preserved',
    array_sum(array_map(fn($x) => count($x['lines']), $g)) === 199 && array_sum(array_column($g, 'qty')) === 4333);
$canvasCodes = ['G009', 'G010', 'S001', 'V008', 'V028'];
$canvasItems = array_filter($g, fn($x) => str_starts_with((string)$x['key'], 'wall art - canvas print'));
check('group: the five canvas-print codes become five items named with their code',
    count($canvasItems) === 5 && array_map(fn($x) => $x['name'], array_values(array_filter($g, fn($x) => in_array($x['code'], $canvasCodes, true) && str_starts_with($x['key'], 'wall art - canvas print'))))
        === array_map(fn($x) => $x['name'], array_values($canvasItems))
    && isset($g[inv_ship_key('Wall Art - Canvas Print (V008)')]) && $g[inv_ship_key('Wall Art - Canvas Print (V008)')]['qty'] === 16
    && !isset($g[inv_ship_key('Wall Art - Canvas Print')]));
$sideTables = array_filter($g, fn($x) => preg_match('/^side tables? \(/', (string)$x['key']) === 1);
$stCodes = array_map(fn($x) => $x['code'], $sideTables); sort($stCodes);
check('group: the six side-table codes are six items', $stCodes === ['OD011', 'OD029', 'OV005', 'SP003', 'SP005', 'V007']);
check('group: a name under a single code stays plain (Glass Box V011 — two lines merge, qty 8)',
    isset($g[inv_ship_key('Glass Box')]) && $g[inv_ship_key('Glass Box')]['qty'] === 8 && count($g[inv_ship_key('Glass Box')]['lines']) === 2
    && $g[inv_ship_key('Glass Box')]['code'] === 'V011');
check('group: Couch and Barstool stay plain', isset($g[inv_ship_key('Couch 2.6m x 1m')], $g[inv_ship_key('Barstool')]));
check('group: different linen sizes stay apart', count(array_filter($g, fn($x) => str_starts_with((string)$x['key'], 'mattress protector'))) === 4);
check('group: first-seen order', array_key_first($g) === inv_ship_key('Couch 2.6m x 1m'));
$split = inv_ship_group($wb['lines'], [$idx('S001') => 'Studio Canvas']);
check('group: a rename takes a line out of the shared name (the rest lose their code only if now single)',
    isset($split[inv_ship_key('Studio Canvas')]) && $split[inv_ship_key('Studio Canvas')]['qty'] === 8
    && isset($split[inv_ship_key('Wall Art - Canvas Print (V008)')]));
$merged = inv_ship_group($wb['lines'], [$idx('V023') => 'Woven Basket Medium', $idx('S004') => 'woven basket medium']);
check('group: a rename to the same name under two codes gets the code on both',
    isset($merged[inv_ship_key('Woven Basket Medium (V023)')], $merged[inv_ship_key('woven basket medium (S004)')]));

// Synthetic: same code + same description merges; same description, two codes → two items with the code; one code, two descriptions → two items.
$mk = fn(string $code, string $desc, int $qty) => ['sheet' => 'T', 'row' => 2, 'section' => '', 'code' => $code, 'hs_code' => '', 'description' => $desc, 'qty' => $qty];
$syn = inv_ship_group([$mk('A1', 'Lamp', 2), $mk('a1', 'lamp.', 3), $mk('B1', 'Lamp', 4), $mk('A1', 'Vase', 1), $mk('A1', 'Bowl', 1)]);
check('group: same code + same description merges (case/punctuation-insensitive)',
    isset($syn[inv_ship_key('Lamp (A1)')]) && $syn[inv_ship_key('Lamp (A1)')]['qty'] === 5);
check('group: same description under another code is a separate item', isset($syn[inv_ship_key('Lamp (B1)')]) && $syn[inv_ship_key('Lamp (B1)')]['qty'] === 4
    && !isset($syn[inv_ship_key('Lamp')]));
check('group: different descriptions under one code are separate items', isset($syn[inv_ship_key('Vase')], $syn[inv_ship_key('Bowl')]) && count($syn) === 4);
$long = inv_ship_group([$mk('A1', str_repeat('x', 200), 1), $mk('B1', str_repeat('x', 200), 1)]);
check('group: a 160-char name with a code suffix stays within 160 and keeps the code',
    count($long) === 2 && !array_filter($long, fn($x) => mb_strlen($x['name']) > 160 || !str_ends_with($x['name'], ')')));

// ── Importer — review fixes ─────────────────────────────────────────────────
// Synthetic sheets: sparse rows of ['v' => .., 'b' => ..] cells.
$cv  = fn($v, bool $b = false): array => ['v' => (string)$v, 'b' => $b];
$hdr = [$cv('Item No'), $cv('Qty'), $cv('Description')];

// no-bold sheet: sections come from "after a blank row"; a continuation joins the line above.
$noBold = [
    0 => $hdr,
    1 => [$cv(''), $cv(''), $cv('Villas')],
    2 => [$cv('V1'), $cv('2'), $cv('Villa Item')],
    3 => [$cv(''), $cv(''), $cv('extra spec')],
    // row 4 skipped: a blank row
    5 => [$cv(''), $cv(''), $cv('Pool')],
    6 => [$cv('P1'), $cv('3'), $cv('Pool Item')],
];
$rNoBold = inv_ship_parse_sheet('NoBold', $noBold);
check('parse: no-bold sheet — sections from a blank row, continuations join', $rNoBold !== null && count($rNoBold['lines']) === 2
    && $rNoBold['lines'][0]['section'] === 'Villas' && $rNoBold['lines'][0]['description'] === 'Villa Item · extra spec'
    && $rNoBold['lines'][1]['section'] === 'Pool' && $rNoBold['lines'][1]['description'] === 'Pool Item');

// Bad quantities land in skipped, with their row numbers; a sheet where every row
// fails still reports its skipped rows via inv_ship_parse_workbook() but is not
// counted as a sheet with lines.
$allFail = [
    0 => $hdr,
    1 => [$cv('X1'), $cv('0'), $cv('Bad Qty Zero')],
    2 => [$cv('X2'), $cv('2.5'), $cv('Bad Qty Frac')],
    3 => [$cv('X3'), $cv('8 pcs'), $cv('Bad Qty Unit')],
];
$rAllFail = inv_ship_parse_sheet('AllFail', $allFail);
check('parse: bad quantities are skipped, with their row numbers', $rAllFail !== null && $rAllFail['lines'] === []
    && array_column($rAllFail['skipped'], 'row') === [2, 3, 4]);
$wbAllFail = inv_ship_parse_workbook(['AllFail' => $allFail]);
check('parse: a sheet where every row fails still reports skipped, not listed in sheets',
    count($wbAllFail['skipped']) === 3 && $wbAllFail['sheets'] === [] && $wbAllFail['lines'] === []);

// A packing column disqualifies only the row that would be the header.
$packing = [0 => [$cv('Item No'), $cv('Qty'), $cv('Description'), $cv('Length'), $cv('Weight'), $cv('Cubes')]];
check('parse: a header row with Length/Weight/Cubes is a packing list', inv_ship_parse_sheet('Packing', $packing) === null);
$strayWeight = [0 => [$cv('Weight')], 1 => $hdr, 2 => [$cv('X1'), $cv('5'), $cv('ok')]];
$rStray = inv_ship_parse_sheet('StrayWeight', $strayWeight);
check('parse: a stray "Weight" cell before the header does not disqualify the sheet', $rStray !== null && count($rStray['lines']) === 1);

// A "Containers:" row (optional colon) anywhere in the sheet is captured, not a section.
$withContainers = [0 => $hdr, 1 => [$cv('Containers:'), $cv('MSBU123456 45G1')], 2 => [$cv('X1'), $cv('2'), $cv('Item A')]];
$rContainers = inv_ship_parse_sheet('Containers', $withContainers);
check('parse: a "Containers:" row after the header is captured, not a section',
    $rContainers !== null && $rContainers['containers'] === ['MSBU123456 45G1']
    && count($rContainers['lines']) === 1 && $rContainers['lines'][0]['section'] === '');

// Bold mode: a plain description-only row right after a blank row is not a section — it's skipped.
$boldSheet = [0 => $hdr, 1 => [$cv(''), $cv(''), $cv('Section Bold', true)], 2 => [$cv('X1'), $cv('2'), $cv('Item A')],
    // row 3 skipped: a blank row
    4 => [$cv(''), $cv(''), $cv('stray plain line')]];
$rBold = inv_ship_parse_sheet('Bold', $boldSheet);
check('parse: bold mode — a plain desc-only row after a blank row is skipped, not a section',
    $rBold !== null && count($rBold['lines']) === 1 && count($rBold['skipped']) === 1 && $rBold['skipped'][0]['text'] === 'stray plain line');

// HS code: only long float noise gets truncated.
check('norm: a genuine long HS code is left alone', inv_ship_hs('8471.300010') === '8471.300010');

// An empty merge key (e.g. a description of only dots) falls back to "Item <code>".
$dotsLine  = [['sheet' => 'T', 'row' => 2, 'section' => '', 'code' => 'X1', 'hs_code' => '', 'description' => '...', 'qty' => 3]];
$dotsGroup = inv_ship_group($dotsLine);
check('group: an empty merge key falls back to "Item <code>"', count($dotsGroup) === 1 && array_values($dotsGroup)[0]['name'] === 'Item X1');

// Suggestions: serial only for the named appliances; a bare "set"/"bed" no longer over-matches; whitespace is collapsed first.
check('suggest: only named appliances are serial-tracked', inv_ship_suggest_kind('Appliances', 'Russel Hobbs Kettle / Toaster Set') === 'operational'
    && inv_ship_suggest_kind('Appliances', 'Mini Bar Fridge') === 'serial');
check('suggest: a bare "set" no longer forces Furniture', inv_ship_suggest_category('Dinner Set') === 'Décor');
check('suggest: "bed" only matches the whole word', inv_ship_suggest_category('Bedroom mirror') === 'Décor' && inv_ship_suggest_category('Massage Beds') === 'Furniture');
check('suggest: extra whitespace is collapsed before matching', inv_ship_suggest_category('Napkin  Holder') === 'Kitchen & dining');

// ── Import (pure) ────────────────────────────────────────────────────────────
check('sku: a very long code is capped at 60 chars', mb_strlen(inv_import_sku(['lines' => [0]], [['code' => str_repeat('C', 90)]])) === 60);
check('sku: the group’s own code', inv_import_sku(['code' => 'V008', 'lines' => [0, 1]], [['code' => 'V008'], ['code' => 'V008']]) === 'V008');
check('sku: falls back to the first code among the lines', inv_import_sku(['lines' => [0, 1]], [['code' => ''], ['code' => 'A2']]) === 'A2');

// ── Par levels: item-code prefix → place ────────────────────────────────────
check('prefix: leading letters, upper-cased', inv_ship_prefix('R006MB') === 'R' && inv_ship_prefix('OV003') === 'OV'
    && inv_ship_prefix('APP001') === 'APP' && inv_ship_prefix('CVL102') === 'CVL' && inv_ship_prefix('123') === '');
$parLines = [['code' => 'V001', 'qty' => 5], ['code' => 'V002', 'qty' => 3], ['code' => 'HS001', 'qty' => 2], ['code' => '007', 'qty' => 9]];
check('par plan: two lines of the same item at the same place sum into one entry', inv_import_par_plan($parLines,
    [0 => 100, 1 => 100, 2 => 200, 3 => 300], ['V' => 5, 'HS' => 6]) === ['100:5' => 8, '200:6' => 2]);
check('par plan: skips a line with no item, and a line whose prefix has no place', inv_import_par_plan($parLines,
    [0 => 100, 2 => 200], ['V' => 5]) === ['100:5' => 5]);
check('par plan: an unmapped prefix (0 or absent) contributes nothing', inv_import_par_plan($parLines,
    [0 => 100, 2 => 200], ['V' => 5, 'HS' => 0]) === ['100:5' => 5]);

// ── List fingerprint (pure) ─────────────────────────────────────────────────
$fpA = [['sheet' => 'S', 'row' => 2, 'section' => 'x', 'code' => 'V1', 'description' => 'Chair', 'qty' => 4], ['sheet' => 'S', 'row' => 3, 'section' => 'x', 'code' => 'V2', 'description' => 'Table', 'qty' => 2]];
$fpB = $fpA; $fpB[1]['qty'] = 3;
check('fingerprint: stable for identical lines', inv_import_list_fingerprint($fpA) === inv_import_list_fingerprint($fpA) && strlen(inv_import_list_fingerprint($fpA)) === 40);
check('fingerprint: changes when a quantity changes', inv_import_list_fingerprint($fpA) !== inv_import_list_fingerprint($fpB));

// ── Packing lists → container hints (pure) ──────────────────────────────────
$pack = inv_ship_parse_packing($sheets);
check('packing: the fixture has 4 containers, named from the "Container …" cell',
    array_column($pack, 'container') === ['NONE 6585458', 'NONE 6848636', 'MSBU781565', 'TEMU8316834']);
check('packing: the master lists are not packing lists', inv_ship_parse_packing_sheet('Master Shipper Owned Container', $sheets['Master Shipper Owned Container']) === null);
check('packing: a sheet with no "Container" cell falls back to its name minus "PL "', (inv_ship_parse_packing_sheet('PL ABC1',
    [0 => [['v' => 'Item No'], ['v' => 'Qty'], ['v' => 'Description'], ['v' => 'Weight']], 1 => [['v' => 'V001'], ['v' => '2'], ['v' => 'Couch']]])['container'] ?? '') === 'ABC1');
check('packing: code and quantity of a line are read, dimension-only rows ignored', (function () use ($pack) {
    foreach ($pack[1]['lines'] as $l) if ($l['code'] === 'V001') return $l['qty'] === 8 && $l['description'] === 'Couch 2.6m x 1m';
    return false; })());
// ── Full packing lists (pure): every row kept, dimensions read ──────────────
check('num: comma decimal, comma thousands, float noise, junk', inv_ship_num('1,25') === 1.25 && inv_ship_num('1,250.5') === 1250.5
    && inv_ship_num(' 0.86 ') === 0.86 && inv_ship_num('') === null && inv_ship_num('abc') === null && inv_ship_boxes('6') === 6 && inv_ship_boxes('0') === null && inv_ship_boxes('1.5') === null);
$cvp = fn(string $v) => ['v' => $v];
$plRows = [
    0 => [2 => $cvp('Container TEST1')],
    2 => [$cvp('Item No'), $cvp('Qty'), $cvp('Description'), $cvp('Qty'), $cvp('Length'), $cvp('Width'), $cvp('Height'), $cvp('Weight'), $cvp('Cubes')],
    4 => [$cvp('V001'), $cvp('2'), $cvp('Couch'), $cvp('2'), $cvp('2,64'), $cvp('1.03'), $cvp('0.75'), $cvp('600'), $cvp('16.3')],
    5 => [3 => $cvp('1'), 4 => $cvp('1.1'), 5 => $cvp('1.2'), 6 => $cvp('0.3'), 7 => $cvp('12')],                  // extra box: dimensions only
    6 => [],                                                                                                         // blank
    7 => [2 => $cvp('Double Curtain Rails (166 pcs)')],                                                                // heading
    8 => [$cvp('Tube 1'), $cvp('2'), $cvp('3860mm x 2830mm'), $cvp('1'), $cvp('4'), $cvp('0.11'), $cvp('0.11'), $cvp('6'), $cvp('0.048')],
    9 => [1 => $cvp('2'), 2 => $cvp('3840mm x 2830mm')],                                                             // continuation with a qty + description
    10 => [3 => $cvp('215'), 7 => $cvp('5380'), 8 => $cvp('70.1')],                                                  // footer total
];
$plp = inv_ship_parse_packing_sheet('PL TEST1', $plRows);
$plk = array_column($plp['lines'], 'kind');
check('packing parse: every non-blank row is kept, in sheet order (5 rows; the blank one is not)', count($plp['lines']) === 6 && array_column($plp['lines'], 'row') === [5, 6, 8, 9, 10, 11]);
check('packing parse: kinds — row / continuation / note / row / continuation / total', $plk === ['row', 'continuation', 'note', 'row', 'continuation', 'total']);
check('packing parse: boxes are the SECOND Qty column, dimensions/weight/cubes read (comma decimal too)',
    $plp['lines'][0]['qty'] === 2 && $plp['lines'][0]['boxes'] === 2 && $plp['lines'][0]['length'] === 2.64 && $plp['lines'][0]['width'] === 1.03
    && $plp['lines'][0]['height'] === 0.75 && $plp['lines'][0]['weight'] === 600.0 && $plp['lines'][0]['cubes'] === 16.3);
check('packing parse: a dimensions-only row is a continuation of the last coded row and keeps its own values',
    $plp['lines'][1]['code'] === '' && $plp['lines'][1]['continuation'] === true && $plp['lines'][1]['parent_code'] === 'V001'
    && $plp['lines'][1]['boxes'] === 1 && $plp['lines'][1]['weight'] === 12.0 && $plp['lines'][1]['qty'] === null);
check('packing parse: a qty + description row with no code is a continuation of Tube 1', $plp['lines'][4]['parent_code'] === 'Tube 1'
    && $plp['lines'][4]['qty'] === 2 && $plp['lines'][4]['description'] === '3840mm x 2830mm');
check('packing parse: the footer row is a total, not a continuation', $plp['lines'][5]['kind'] === 'total' && $plp['lines'][5]['boxes'] === 215 && $plp['lines'][5]['weight'] === 5380.0);
check('packing code: a bundle suffix maps to the master code', inv_ship_packing_code('CVL102B7', ['CVL102']) === 'CVL102');
check('packing code: exact (case-insensitive) match wins', inv_ship_packing_code('OV003', ['OV003']) === 'OV003' && inv_ship_packing_code('ov003/', ['OV003']) === 'OV003');
check('packing code: rails / unknown codes map to nothing', inv_ship_packing_code('Tube 3', ['CVL102', 'V001']) === null && inv_ship_packing_code('BRA104B1', ['V001']) === null);

// ── Description matching (pure) ─────────────────────────────────────────────
check('desc tokens: noise dropped, trailing s stripped, unique', inv_ship_desc_tokens('Fake Hanging Plants (4 pcs each)') === ['fake', 'hanging', 'plant', '4']);
check('desc score: same words, different punctuation = 1', inv_ship_desc_score('Bowls - Paper Mache', 'Bowls Paper Mache') === 1.0);
check('desc score: plural + "(4 pcs each)" vs "(4 Pce)" is a match', inv_ship_desc_score('Fake Hanging Plants (4 pcs each)', 'Fake Hanging Plant (4 Pce)') >= 0.6);
check('desc score: unrelated descriptions score 0', inv_ship_desc_score('Pot Stand', 'Crab Statue') === 0.0);
check('desc score: an extra word in one is still a match', inv_ship_desc_score('Bitan Footed Dish', 'Bitan Style Footed Dish') >= 0.6);
check('desc score: an empty side scores 0', inv_ship_desc_score('', 'Pot Stand') === 0.0 && inv_ship_desc_score('(4 pcs)', 'Pot Stand') === 0.0);

// ── Pure checks (each task inserts its section above this line) ──

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_stores_supported()) {
    echo "\nSKIP  DB block (add_inventory_stores.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx     = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins     = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count   = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };

    check('schema: Main stock is flagged, exactly once', $count('SELECT COUNT(*) FROM inv_locations WHERE is_main') === 1);
    check('schema: share_venue_ids exists', $count("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inv_locations' AND column_name = 'share_venue_ids'") === 1);

    // ── Stores ──
    $vTD  = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Dunes')", [':s' => "zz-td-{$sfx}"]);
    $vMI  = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Ilai')",  [':s' => "zz-mi-{$sfx}"]);
    $vZ   = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Zuri')",  [':s' => "zz-zu-{$sfx}"]);
    $vOff = $ins("INSERT INTO venues (slug, name, is_published) VALUES (:s, 'ZZ Off-Duty', FALSE)", [':s' => "zz-od-{$sfx}"]);
    $main = inv_store_location_id();
    $tdStore = inv_create_store("ZZ TD Main Stock {$sfx}", $vTD, [$vMI, $vOff, $vTD]);
    $tdRow   = inv_fetch_location($tdStore);
    $sharesExpected = [$vMI, $vOff]; sort($sharesExpected);
    check('store: created with its owner and shares (the owner is not repeated)',
        (int)$tdRow['venue_id'] === $vTD && inv_pg_int_array($tdRow['share_venue_ids']) === $sharesExpected);
    check('store: Main stock is unchanged', inv_store_location_id() === $main && !inv_bool($tdRow['is_main']));
    check('store: a duplicate name is refused', str_contains($refused(fn() => inv_create_store("zz td main stock {$sfx}", null, [])), 'already called'));
    check('store: an unknown property is refused', str_contains($refused(fn() => inv_create_store("ZZ Other {$sfx}", 99999999, [])), 'exist'));
    check('store: Main stock cannot get an owner', str_contains($refused(fn() => inv_update_store_owner($main, $vTD, [])), 'no owner'));
    $visMI = array_map(fn($l) => (int)$l['id'], inv_locations_visible([$vMI]));
    $visZ  = array_map(fn($l) => (int)$l['id'], inv_locations_visible([$vZ]));
    check('store: listed for a Maya Ilai manager, not for a Zuri manager', in_array($tdStore, $visMI, true) && !in_array($tdStore, $visZ, true));
    check('store: Main stock still listed for everyone', in_array($main, $visZ, true));
    inv_update_store_owner($tdStore, $vTD, [$vMI]);
    check('store: shares can be changed', !in_array($tdStore, array_map(fn($l) => (int)$l['id'], inv_locations_visible([$vOff])), true));
    inv_update_store_owner($tdStore, $vTD, [$vMI, $vOff]);
    inv_ensure_default_locations();
    check('hidden property: gets its inventory location', $count("SELECT COUNT(*) FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $vOff]) === 1);
    check('hidden property: offered in the filters', isset(inv_visible_venues(null)[$vOff]));
    $plate = inv_create_item(['name' => "ZZ Ship plate {$sfx}", 'item_type' => 'operational', 'replacement_value' => 100]);
    inv_move(['item_id' => $plate, 'qty' => 5, 'to' => $tdStore, 'reason' => 'receive']);
    $miLoc = inv_property_location_id($vMI);
    inv_transfer($plate, 2, $tdStore, $miLoc, null);
    $hist = inv_item_history($plate, [$vMI]);
    check('history: a shared store is named for a manager who shares it', $hist && !array_filter($hist, fn($m) => $m['from_name'] === 'Another location' || $m['to_name'] === 'Another location'));

    // ── Review fixes ──
    check('store: no owner but shares is refused (create)',
        str_contains($refused(fn() => inv_create_store("ZZ Shareless {$sfx}", null, [$vMI])), 'before sharing'));
    check('store: no owner but shares is refused (update)',
        str_contains($refused(fn() => inv_update_store_owner($tdStore, null, [$vMI])), 'before sharing'));
    $freeStore = inv_create_store("ZZ Freehold {$sfx}", null, []);
    check('store: no owner, no shares is allowed', inv_fetch_location($freeStore)['venue_id'] === null);

    $mainName = (string) db_query('SELECT name FROM inv_locations WHERE id = :id', [':id' => $main])->fetchColumn();
    check('store: renaming to another store’s name is refused',
        str_contains($refused(fn() => inv_update_location($tdStore, ['name' => $mainName])), 'already called'));

    $vThrow = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Throwaway')", [':s' => "zz-tw-{$sfx}"]);
    inv_update_store_owner($tdStore, $vTD, [$vMI, $vOff, $vThrow]);
    inv_deactivate_linked_locations('venue_id', $vThrow);
    $afterShares = inv_pg_int_array(inv_fetch_location($tdStore)['share_venue_ids']); sort($afterShares);
    $expectAfter = [$vMI, $vOff]; sort($expectAfter);
    check('venue delete: dropped from a store’s shares, other shares remain', $afterShares === $expectAfter);

    $vOwn2 = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Ownable')", [':s' => "zz-ow-{$sfx}"]);
    $ownedStore = inv_create_store("ZZ Ownable Stock {$sfx}", $vOwn2, [$vMI, $vZ]);
    inv_deactivate_linked_locations('venue_id', $vOwn2);
    check('venue delete: a store it OWNS has its own shares cleared too (an ownerless store keeps no shares)',
        inv_pg_int_array(inv_fetch_location($ownedStore)['share_venue_ids']) === []);

    $unit = inv_create_item(['name' => "ZZ Ship unit {$sfx}", 'item_type' => 'operational', 'tracking' => 'serial', 'replacement_value' => 200]);
    inv_asset_create($unit, $tdStore, ['serial' => "ZZU-{$sfx}"], null);
    check('units: a serial unit at a shared store is visible to a sharing manager', count(inv_item_units($unit, [$vMI])) === 1);
    check('units: not visible to a non-sharing manager', count(inv_item_units($unit, [$vZ])) === 0);

    // ── Import items from Excel (DB) ──
    $existingKeys = array_keys(inv_import_existing_items());
    $expectFresh  = true;
    foreach (array_keys($g) as $__k) if (in_array($__k, $existingKeys, true)) { $expectFresh = false; break; }

    $res1 = inv_import_items($wb['lines'], $g);
    if ($expectFresh) {
        check('import: the real fixture’s 196 groups become 196 new items', $res1['created'] === 196 && $res1['existing'] === 0);
    } else {
        check('import: created + existing accounts for every one of the 196 groups', $res1['created'] + $res1['existing'] === 196);
    }
    check('import: no stock moves were written for the created items', $res1['created_ids'] === []
        || $count('SELECT COUNT(*) FROM inv_moves WHERE item_id = ANY(CAST(:ids AS int[]))', [':ids' => inv_pg_int_array_literal($res1['created_ids'])]) === 0);

    $fridge = db_query("SELECT tracking, category FROM inv_items WHERE name = 'Mini Bar Fridge' AND is_active = TRUE ORDER BY id DESC LIMIT 1")->fetch();
    check('import: Mini Bar Fridge is created serial-tracked, category Appliances', $fridge && $fridge['tracking'] === 'serial' && $fridge['category'] === 'Appliances');

    $canvasItem = db_query("SELECT sku FROM inv_items WHERE name = 'Wall Art - Canvas Print (V008)' AND is_active = TRUE ORDER BY id DESC LIMIT 1")->fetch();
    check('import: sku is the item’s own code', $canvasItem && $canvasItem['sku'] === 'V008');
    check('import: the old merged canvas item is not created', $count("SELECT COUNT(*) FROM inv_items WHERE name = 'Wall Art - Canvas Print' AND is_active = TRUE") === 0 || !$expectFresh);

    $dining = db_query("SELECT unit_label FROM inv_items WHERE name = 'Outdoor Dining Set (9 pce)' AND is_active = TRUE ORDER BY id DESC LIMIT 1")->fetch();
    check('import: unit_label follows the suggestion (sets)', $dining && $dining['unit_label'] === 'sets');

    $res2 = inv_import_items($wb['lines'], $g);
    check('import: importing the same list again creates nothing — every group already exists', $res2['created'] === 0 && $res2['existing'] === 196);

    // ── Par levels: item-code prefix → place (DB) ──
    // Force a clean slate for the remembered mapping, so the defaults asserted
    // below never depend on whatever an earlier manual test of the admin page
    // may have saved to this DB.
    db_query("DELETE FROM settings WHERE setting_key = :k", [':k' => INV_IMPORT_PREFIX_PLACES_SETTING]);

    $vTDreal = (int) db_query("SELECT id FROM venues WHERE slug = 'tribal-dunes'")->fetchColumn();
    if (!$vTDreal) $vTDreal = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'Tribal Dunes')", [':s' => "zz-tdreal-{$sfx}"]);
    $tdLocReal = inv_property_location_id($vTDreal);
    $hsId = (int) db_query("SELECT id FROM inv_locations WHERE parent_id = :p AND kind = 'area' AND lower(name) = 'hair salon'", [':p' => $tdLocReal])->fetchColumn();
    if (!$hsId) $hsId = inv_create_area($tdLocReal, 'Hair Salon');
    $rrId = (int) db_query("SELECT id FROM inv_locations WHERE parent_id = :p AND kind = 'area' AND lower(name) = 'tribal table'", [':p' => $tdLocReal])->fetchColumn();
    if (!$rrId) $rrId = inv_create_area($tdLocReal, 'Tribal Table');

    $vMIreal = (int) db_query("SELECT id FROM venues WHERE lower(name) = 'maya ilai'")->fetchColumn();
    if (!$vMIreal) $vMIreal = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'Maya Ilai')", [':s' => "zz-mireal-{$sfx}"]);
    $miLocReal = inv_property_location_id($vMIreal);

    $vODreal = (int) db_query("SELECT id FROM venues WHERE lower(name) = 'off-duty'")->fetchColumn();
    if (!$vODreal) $vODreal = $ins("INSERT INTO venues (slug, name, is_published) VALUES (:s, 'Off-Duty', FALSE)", [':s' => "zz-odreal-{$sfx}"]);
    inv_ensure_default_locations();
    $odLocReal = inv_property_location_id($vODreal);

    $prefixes = [];
    foreach ($wb['lines'] as $l) { $pfx = inv_ship_prefix((string)$l['code']); if ($pfx !== '' && !in_array($pfx, $prefixes, true)) $prefixes[] = $pfx; }
    $placeOptions = inv_import_place_options(null);
    $prefixPlace  = inv_import_default_places($prefixes, $placeOptions);
    check('default places: V/S/OV/WT/SP/G/APP/B/CVL/DR/BL → Maya Ilai', ($prefixPlace['V'] ?? 0) === $miLocReal && ($prefixPlace['S'] ?? 0) === $miLocReal
        && ($prefixPlace['OV'] ?? 0) === $miLocReal && ($prefixPlace['G'] ?? 0) === $miLocReal);
    check('default places: HS → the Hair Salon area, R → Tribal Table area', ($prefixPlace['HS'] ?? 0) === $hsId && ($prefixPlace['R'] ?? 0) === $rrId);
    check('default places: OD → Off-Duty', ($prefixPlace['OD'] ?? 0) === $odLocReal);

    $lineItem = [];
    foreach ($g as $gkey => $grp) {
        $itemId = $res2['group_items'][$gkey] ?? null;
        if (!$itemId) continue;
        foreach ($grp['lines'] as $i) $lineItem[$i] = $itemId;
    }
    $plan    = inv_import_par_plan($wb['lines'], $lineItem, $prefixPlace);
    $parsSet = inv_import_apply_pars($plan);

    $couchId    = $res2['group_items'][inv_ship_key('Couch 2.6m x 1m')] ?? 0;
    $canvasId   = $res2['group_items'][inv_ship_key('Wall Art - Canvas Print (V008)')] ?? 0;
    $barstoolId = $res2['group_items'][inv_ship_key('Barstool')] ?? 0;
    $fridgeId   = $res2['group_items'][inv_ship_key('Mini Bar Fridge')] ?? 0;
    $parOf = fn(int $item, int $loc) => db_query('SELECT par_qty FROM inv_balances WHERE item_id = :i AND location_id = :l', [':i' => $item, ':l' => $loc])->fetchColumn();

    check('par: Couch 2.6m x 1m at Maya Ilai = 8', (int)$parOf($couchId, $miLocReal) === 8);
    check('par: Wall Art - Canvas Print (V008) at Maya Ilai = 16', (int)$parOf($canvasId, $miLocReal) === 16);
    check('par: Barstool at Off-Duty = 15', (int)$parOf($barstoolId, $odLocReal) === 15);
    check('par: Mini Bar Fridge (serial) got no par', $parOf($fridgeId, $miLocReal) === false);
    check('par: no stock was moved for the imported items',
        $count('SELECT COUNT(*) FROM inv_moves WHERE item_id = ANY(CAST(:ids AS int[]))', [':ids' => inv_pg_int_array_literal($res1['created_ids'])]) === 0);

    $plan2    = inv_import_par_plan($wb['lines'], $lineItem, $prefixPlace);
    $parsSet2 = inv_import_apply_pars($plan2);
    check('par: re-importing gives the same par, never doubled', $parsSet2 === $parsSet && (int)$parOf($couchId, $miLocReal) === 8);

    // ── The import creates an ORDER (DB): quantities go on order, no stock moves ──
    $uid = (int) db_query('SELECT id FROM admin_users ORDER BY id LIMIT 1')->fetchColumn() ?: null;
    $balOf = fn(int $item, int $loc) => (int) db_query('SELECT qty FROM inv_balances WHERE item_id = :i AND location_id = :l', [':i' => $item, ':l' => $loc])->fetchColumn();
    if (!inv_orders_supported()) {
        echo "SKIP  orders (add_inventory_orders.sql not applied)\n";
    } else {
        $fp = inv_import_list_fingerprint($wb['lines']);
        $before = [$balOf($couchId, $miLocReal), $balOf($barstoolId, $odLocReal)];
        // Exactly what the confirm step does: line → place by prefix, one order per list.
        $linePlace = [];
        foreach ($wb['lines'] as $i => $l) $linePlace[$i] = (int)($prefixPlace[inv_ship_prefix((string)($l['code'] ?? ''))] ?? 0);
        // Independent of the dev DB: an order already made from this list (e.g. while clicking through the page) is removed inside this rolled-back transaction.
        db_query('DELETE FROM inv_orders WHERE fingerprint = :f', [':f' => $fp]);
        $ordersBefore = $count('SELECT COUNT(*) FROM inv_orders WHERE fingerprint = :f', [':f' => $fp]);
        check('order: this list has no order yet', inv_order_open_for($fp) === null && $ordersBefore === 0);
        $oid = inv_order_create('shipment-maya-ilai', $wb['lines'], $lineItem, $linePlace, 'shipment-maya-ilai.xlsx', $fp, $uid);
        $nLines = $count('SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o', [':o' => $oid]);
        $groupsKeys = []; foreach ($wb['lines'] as $i => $l) $groupsKeys[($lineItem[$i] ?? 0) . ':' . ($linePlace[$i] ?? 0)] = true;
        check('order: one line per item + planned place', $nLines === count($groupsKeys) && $nLines >= 196);
        check('order: every list piece is on order', (int) db_query('SELECT SUM(qty_ordered) FROM inv_order_lines WHERE order_id = :o', [':o' => $oid])->fetchColumn() === (int) array_sum(array_column($wb['lines'], 'qty')));
        $coL = db_query('SELECT * FROM inv_order_lines WHERE order_id = :o AND item_id = :i', [':o' => $oid, ':i' => $couchId])->fetch();
        check('order: Couch 2.6m x 1m is 8 on order for Maya Ilai', $coL && (int)$coL['qty_ordered'] === 8 && (int)$coL['planned_location_id'] === $miLocReal);
        check('order: the serial Mini Bar Fridge is on order too (units come on receipt)', (int) db_query('SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o AND item_id = :i', [':o' => $oid, ':i' => $fridgeId])->fetchColumn() === 1);
        check('order: importing put NOTHING in stock',
            [$balOf($couchId, $miLocReal), $balOf($barstoolId, $odLocReal)] === $before && (int) db_query('SELECT COALESCE(SUM(qty),0) FROM inv_balances WHERE item_id = :i', [':i' => $fridgeId])->fetchColumn() === 0
            && $count('SELECT COUNT(*) FROM inv_moves WHERE item_id = ANY(CAST(:ids AS int[])) AND note LIKE :n', [':ids' => inv_pg_int_array_literal(array_values($lineItem)), ':n' => 'Order #' . $oid . ' %']) === 0);
        check('order: par levels are still set from the same list', (int)$parOf($couchId, $miLocReal) === 8);
        // A re-import must not make a second order (the confirm step checks this).
        check('order: a re-import finds the order and creates no second one', ($ex = inv_order_open_for($fp)) !== null && (int)$ex['id'] === $oid
            && $count('SELECT COUNT(*) FROM inv_orders WHERE fingerprint = :f', [':f' => $fp]) === 1);
        check('order: the list fingerprint on the order matches', inv_order_fetch($oid)['fingerprint'] === $fp);

        // ── Containers (packing-list hints) ──
        $att = inv_order_attach_containers($oid, $pack);
        check('containers: packing lines are matched onto order lines, the unmatched (rails, brackets) counted', $att['matched'] > 0 && $att['unmatched'] > 0);
        $couchLine = db_query('SELECT id FROM inv_order_lines WHERE order_id = :o AND item_id = :i', [':o' => $oid, ':i' => $couchId])->fetchColumn();
        $oLines = []; foreach (inv_order_lines($oid, null) as $l) $oLines[(int)$l['id']] = $l;
        check('containers: the V001 couch is in NONE 6848636 only, 8 pieces', ($oLines[(int)$couchLine]['containers'] ?? null) === ['NONE 6848636' => 8]);
        $cvl = array_values(array_filter($oLines, fn($l) => $l['code'] === 'CVL102'));
        check('containers: the CVL102 curtain bundles sum to 332 across its containers', $cvl && array_sum($cvl[0]['containers']) === 332);
        check('containers: the order still orders what the master list said (hints never change qty_ordered)',
            (int) db_query('SELECT SUM(qty_ordered) FROM inv_order_lines WHERE order_id = :o', [':o' => $oid])->fetchColumn() === (int) array_sum(array_column($wb['lines'], 'qty')));
        $conts = inv_order_containers($oid, null);
        check('containers: the four containers are listed in packing-list order with lines and pieces',
            array_column($conts, 'container') === ['NONE 6585458', 'NONE 6848636', 'MSBU781565', 'TEMU8316834'] && $conts[0]['lines'] > 0 && $conts[0]['pieces'] > 0);
        $again = inv_order_attach_containers($oid, $pack);
        check('containers: attaching twice is idempotent', $again === $att && array_sum($oLines[(int)$couchLine]['containers']) === 8
            && $count('SELECT COUNT(*) FROM inv_order_line_containers WHERE line_id = :l', [':l' => $couchLine]) === 1);

        $balBefore = $balOf($couchId, $miLocReal);
        $otherLine = null;   // a line that is in NONE 6585458 but not in NONE 6848636
        foreach (inv_order_lines($oid, null) as $l) if (isset($l['containers']['NONE 6585458']) && !isset($l['containers']['NONE 6848636']) && $l['tracking'] !== 'serial') { $otherLine = $l; break; }
        $rc = inv_order_receive_container($oid, 'NONE 6848636', null, $uid);
        check('receive container: the couch 8 land in Maya Ilai', $balOf($couchId, $miLocReal) === $balBefore + 8 && $rc['lines'] > 0 && $rc['pieces'] >= 8);
        check('receive container: a line only in another container is untouched', $otherLine !== null
            && (int) db_query('SELECT qty_received FROM inv_order_lines WHERE id = :l', [':l' => $otherLine['id']])->fetchColumn() === 0);
        $rc2 = inv_order_receive_container($oid, 'NONE 6848636', null, $uid);
        check('receive container: a second go receives nothing more for fully received lines', $balOf($couchId, $miLocReal) === $balBefore + 8
            && (int) db_query('SELECT qty_received FROM inv_order_lines WHERE id = :l', [':l' => $couchLine])->fetchColumn() === 8);
        $refusedC = ''; try { inv_order_receive_container($oid, 'NONE 6848636', [-1], $uid); } catch (InvRefusal $e) { $refusedC = 'x'; }
        check('receive container: an account that sees none of the lines receives nothing', $refusedC === '' && inv_order_receive_container($oid, 'NONE 6848636', [-1], $uid)['pieces'] === 0);
    }

    // ── Container matching + packing differences on the real fixture (DB) ──
    if (inv_orders_supported()) {
        $packedOf = function (string $code, string $desc) use ($oid): ?array {
            foreach (inv_order_lines($oid, null) as $l) if ($l['code'] === $code && inv_ship_key($l['description']) === inv_ship_key($desc)) return $l['containers'];
            return null;
        };
        $tot = fn(?array $c) => $c === null ? null : array_sum($c);
        check('match: Pot Stand’s 8 (V010) land on Crab Statue', $tot($packedOf('V010', 'Crab Statue')) === 8);
        check('match: Tealight Holders (V011) = 16', $tot($packedOf('V011', 'Tealight Holders')) === 16);
        check('match: Bowls Paper Mache (V011) = 8, all in MSBU781565', $packedOf('V011', 'Bowls Paper Mache') === ['MSBU781565' => 8]);
        check('match: Scatter Cushion (G011) = 6', $tot($packedOf('G011', 'Scatter Cushion')) === 6);
        check('match: Fake Hanging Plant (G011) = 8', $tot($packedOf('G011', 'Fake Hanging Plant (4 Pce)')) === 8);
        check('match: Bitan Style Footed Dish (G011) = 8', $tot($packedOf('G011', 'Bitan Style Footed Dish')) === 8);
        check('match: the V001 couch is still NONE 6848636 × 8', $packedOf('V001', 'Couch 2.6m x 1m') === ['NONE 6848636' => 8]);
        $diffs = ['less_packed' => [], 'not_packed' => [], 'more_packed' => []];
        foreach (inv_order_lines($oid, null) as $l) if ($l['pack_diff'] !== null) $diffs[$l['pack_diff']][] = $l['code'] . ' ' . $l['description'];
        check('differences: less packed = OD038 Lantern + G001 Makoro', $diffs['less_packed'] === ['OD038 Lantern Natural with glass 40x40x60cm', 'G001 Makoro']);
        check('differences: not on any packing list = the 7 expected lines', $diffs['not_packed'] === [
            'OD015 Coral Barnacle Statue Pink', 'OD015 Crown Orchid', 'OD033 Coral Barnacle Statue Pink', 'OD033 Crown Orchid',
            'G011 Rattan Style Storage Basket (2pce)', 'G011 Wooden Crab Figurine', 'DR100 Double Curtain Rails (166 pcs with brackets and screws)']);
        check('differences: more packed = OV001, WT001, WT002, MK005, MK008 (sets packed as pieces)',
            array_map(fn($x) => explode(' ', $x)[0], $diffs['more_packed']) === ['OV001', 'WT001', 'WT002', 'MK005', 'MK008']);
        $hasDiff = 0; foreach (inv_order_lines($oid, null) as $l) if ($l['pack_diff'] !== null) $hasDiff++;
        check('differences: 14 flagged lines, everything else is null', $hasDiff === 14);
    }

    // ── The whole spreadsheet is stored: HS codes + every packing-list row (DB) ──
    if (inv_order_packing_supported()) {
        // Expected counts come straight from the workbook, not from our parser.
        $expRows = []; $expWeight = []; $expTotalWeight = []; $expTotals = [];
        foreach ($sheets as $sn => $srows) {
            if (!preg_match('/^PL /', (string)$sn)) continue;
            $cont = null; $hdr = null;
            foreach ($srows as $n => $cells) {
                $t0 = trim((string)($cells[2]['v'] ?? ''));
                if ($cont === null && preg_match('/^Container (.+)$/', $t0, $m)) $cont = trim($m[1]);
                if (trim((string)($cells[0]['v'] ?? '')) === 'Item No') { $hdr = $n; break; }
            }
            $expRows[$cont] = 0; $expWeight[$cont] = 0.0; $expTotals[$cont] = 0; $expTotalWeight[$cont] = 0.0;
            foreach ($srows as $n => $cells) {
                if ($n <= $hdr) continue;
                $c = []; for ($k = 0; $k <= 8; $k++) $c[$k] = trim((string)($cells[$k]['v'] ?? ''));
                if (implode('', $c) === '') continue;                       // blank rows only
                $expRows[$cont]++;
                $w = is_numeric($c[7]) ? (float)$c[7] : 0.0;
                $expWeight[$cont] += $w;
                if ($c[0] === '' && $c[1] === '' && $c[2] === '' && $c[4] === '' && $c[5] === '' && $c[6] === '') { $expTotals[$cont]++; $expTotalWeight[$cont] += $w; }
            }
        }
        $storedRows = []; $storedWeight = [];
        foreach (db_query('SELECT container, COUNT(*) n, COALESCE(SUM(weight_kg),0) w FROM inv_order_packing WHERE order_id = :o GROUP BY container', [':o' => $oid])->fetchAll() as $r) {
            $storedRows[$r['container']] = (int)$r['n']; $storedWeight[$r['container']] = (float)$r['w'];
        }
        ksort($expRows); ksort($storedRows);
        check('packing stored: every non-blank data row of each packing sheet is stored (' . implode('+', $expRows) . ')', $expRows === $storedRows && count($expRows) === 4);
        $wOk = true; foreach ($expWeight as $c => $w) if (abs(($storedWeight[$c] ?? -1) - $w) > 0.05) $wOk = false;
        check('packing stored: the weight of each container adds up to the sheet\'s Weight column', $wOk);
        $sum = []; foreach (inv_order_packing_summary($oid) as $r) $sum[$r['container']] = $r;
        $sOk = true; foreach ($expWeight as $c => $w) if (abs($sum[$c]['weight'] + $expTotalWeight[$c] - $w) > 0.05 || abs(($sum[$c]['sheet_weight'] ?? -1) - $expTotalWeight[$c]) > 0.05) $sOk = false;
        check('packing stored: the summary leaves the sheet\'s footer total out of the computed one', $sOk && $sum['NONE 6585458']['boxes'] === 215 && $sum['NONE 6585458']['sheet_boxes'] === 215);
        check('packing stored: footer rows are kind "total" (one or two per sheet)', (int) db_query("SELECT COUNT(*) FROM inv_order_packing WHERE order_id = :o AND kind = 'total'", [':o' => $oid])->fetchColumn() === array_sum($expTotals));
        $tubes = db_query("SELECT line_id FROM inv_order_packing WHERE order_id = :o AND code ~ '^Tube [0-9]+\$'", [':o' => $oid])->fetchAll(PDO::FETCH_COLUMN);
        check('packing stored: the 29 "Tube" curtain-rail rows are stored, on no order line', count($tubes) === 29 && count(array_filter($tubes, fn($x) => $x !== null)) === 0);
        $tube1 = db_query("SELECT * FROM inv_order_packing WHERE order_id = :o AND code = 'Tube 1'", [':o' => $oid])->fetch();
        check('packing stored: Tube 1 keeps its size, box count and measures', $tube1 && str_contains((string)$tube1['description'], '3860mm x 2830mm') && (int)$tube1['boxes'] === 1
            && (float)$tube1['length_m'] === 4.0 && (float)$tube1['width_m'] === 0.11 && (float)$tube1['weight_kg'] === 6.0 && (float)$tube1['cubes_m3'] === 0.0484);
        $bra = (int) db_query("SELECT COUNT(*) FROM inv_order_packing WHERE order_id = :o AND code LIKE 'BRA104B%' AND line_id IS NULL", [':o' => $oid])->fetchColumn();
        check('packing stored: unmatched bracket rows (BRA104B…) are kept too', $bra > 0);
        $v001 = db_query("SELECT p.line_id, p.boxes, p.weight_kg FROM inv_order_packing p WHERE p.order_id = :o AND p.container = 'NONE 6848636' AND p.code = 'V001'", [':o' => $oid])->fetch();
        check('packing stored: the V001 couch row is linked to its order line, 8 boxes, 600 kg', $v001 && (int)$v001['line_id'] === (int)$couchLine && (int)$v001['boxes'] === 8 && (float)$v001['weight_kg'] === 600.0);
        // Continuation rows inherit the line of the nearest coded row above them.
        $cOk = true; $nCont = 0;
        foreach (db_query("SELECT container, seq, kind, line_id FROM inv_order_packing WHERE order_id = :o ORDER BY container, seq", [':o' => $oid])->fetchAll() as $r) {
            if ($r['kind'] === 'row') { $par = [$r['container'], $r['line_id']]; }
            elseif ($r['kind'] === 'continuation') { $nCont++; if (!isset($par) || $par[0] !== $r['container'] || $par[1] !== $r['line_id']) $cOk = false; }
        }
        check('packing stored: every continuation row takes its parent row\'s order line (' . $nCont . ' rows)', $cOk && $nCont > 0);
        $matchedIds = db_query('SELECT DISTINCT line_id FROM inv_order_packing WHERE order_id = :o AND line_id IS NOT NULL', [':o' => $oid])->fetchAll(PDO::FETCH_COLUMN);
        check('packing stored: matched rows point at this order\'s lines only', $matchedIds && (int) db_query('SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o AND id = ANY(CAST(:ids AS int[]))', [':o' => $oid, ':ids' => inv_pg_int_array_literal(array_map('intval', $matchedIds))])->fetchColumn() === count($matchedIds));
        $pr = inv_order_packing_rows($oid, 'NONE 6848636', null);
        check('packing rows: read back in sheet order with the matched item name', count($pr) === $storedRows['NONE 6848636'] && $pr[0]['code'] === 'V001' && $pr[0]['item_name'] === 'Couch 2.6m x 1m'
            && array_column($pr, 'seq') === range(1, count($pr)));
        check('packing rows: an account that sees none of the lines sees no item names', array_filter(array_column(inv_order_packing_rows($oid, 'NONE 6848636', [-1]), 'item_name')) === []);

        // HS codes: every order line carries the first non-empty HS of its merged master lines.
        $expHs = [];
        foreach ($wb['lines'] as $i => $l) {
            $key = ($lineItem[$i] ?? 0) . ':' . ($linePlace[$i] ?? 0);
            if (($lineItem[$i] ?? 0) <= 0) continue;
            $expHs[$key] ??= '';
            if ($expHs[$key] === '' && ($l['hs_code'] ?? '') !== '') $expHs[$key] = $l['hs_code'];
        }
        $withHs = count(array_filter($expHs, fn($h) => $h !== ''));
        $storedHs = (int) db_query("SELECT COUNT(*) FROM inv_order_lines WHERE order_id = :o AND hs_code IS NOT NULL AND hs_code <> ''", [':o' => $oid])->fetchColumn();
        check('hs: every order line whose master lines had an HS code stores one (' . $withHs . ')', $withHs > 100 && $storedHs === $withHs);
        $hsOk = true;
        foreach (db_query('SELECT item_id, planned_location_id, hs_code FROM inv_order_lines WHERE order_id = :o', [':o' => $oid])->fetchAll() as $r) {
            if (($expHs[$r['item_id'] . ':' . (int)$r['planned_location_id']] ?? '') !== (string)($r['hs_code'] ?? '')) $hsOk = false;
        }
        check('hs: each stored code is the first HS of the line\'s merged master lines', $hsOk);
        $v001hs = db_query("SELECT DISTINCT hs_code FROM inv_order_lines WHERE order_id = :o AND code = 'V001'", [':o' => $oid])->fetchAll(PDO::FETCH_COLUMN);
        check('hs: V001 = 9401.80.90', $v001hs === ['9401.80.90']);
        $lnH = null; foreach (inv_order_lines($oid, null) as $l) if ($l['code'] === 'V001') { $lnH = $l; break; }
        check('hs: inv_order_lines() returns it for the order page', $lnH && $lnH['hs_code'] === '9401.80.90');
        // Re-attaching replaces the stored rows — never doubles them.
        inv_order_attach_containers($oid, $pack);
        check('packing stored: attaching again replaces the rows, never doubles them', (int) db_query('SELECT COUNT(*) FROM inv_order_packing WHERE order_id = :o', [':o' => $oid])->fetchColumn() === array_sum($expRows));
    } else {
        echo "SKIP  full packing lists (add_inventory_orders.sql packing part not applied)\n";
    }

    // ── DB checks (each task inserts its block above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
