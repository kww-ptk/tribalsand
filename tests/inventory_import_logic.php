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
check('group: 154 items', count($g) === 154);
$canvas = $g[inv_ship_key('Wall Art - Canvas Print')];
check('group: same name merges across codes', $canvas['qty'] === 96 && count($canvas['lines']) === 5);
check('group: different linen sizes stay apart', count(array_filter($g, fn($x) => str_starts_with((string)$x['key'], 'mattress protector'))) === 4);
check('group: first-seen order', array_key_first($g) === inv_ship_key('Couch 2.6m x 1m'));
$split = inv_ship_group($wb['lines'], [$idx('S001') => 'Wall Art - Canvas Print (studio)']);
check('group: a rename splits a line off', $split[inv_ship_key('Wall Art - Canvas Print')]['qty'] === 88
    && $split[inv_ship_key('Wall Art - Canvas Print (studio)')]['qty'] === 8);
$merged = inv_ship_group($wb['lines'], [$idx('V023') => 'Woven Basket Medium', $idx('S004') => 'woven basket medium']);
check('group: a rename can merge into another item', $merged[inv_ship_key('Woven Basket Medium')]['qty'] === 40);

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
check('sku: a group with 30 long codes is capped at 60 chars', mb_strlen(inv_import_sku(
    ['lines' => range(0, 29)],
    array_map(fn($i) => ['code' => 'VERYLONGITEMCODE' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)], range(0, 29))
)) <= 60);
check('sku: codes are unique, in list order', inv_import_sku(['lines' => [0, 1, 2]],
    [['code' => 'A1'], ['code' => 'A2'], ['code' => 'A1']]) === 'A1, A2');

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
        check('import: the real fixture’s 154 groups become 154 new items', $res1['created'] === 154 && $res1['existing'] === 0);
    } else {
        check('import: created + existing accounts for every one of the 154 groups', $res1['created'] + $res1['existing'] === 154);
    }
    check('import: no stock moves were written for the created items', $res1['created_ids'] === []
        || $count('SELECT COUNT(*) FROM inv_moves WHERE item_id = ANY(CAST(:ids AS int[]))', [':ids' => inv_pg_int_array_literal($res1['created_ids'])]) === 0);

    $fridge = db_query("SELECT tracking, category FROM inv_items WHERE name = 'Mini Bar Fridge' AND is_active = TRUE ORDER BY id DESC LIMIT 1")->fetch();
    check('import: Mini Bar Fridge is created serial-tracked, category Appliances', $fridge && $fridge['tracking'] === 'serial' && $fridge['category'] === 'Appliances');

    $canvasItem = db_query("SELECT sku FROM inv_items WHERE name = 'Wall Art - Canvas Print' AND is_active = TRUE ORDER BY id DESC LIMIT 1")->fetch();
    check('import: sku is the group’s item codes, unique, in order', $canvasItem && $canvasItem['sku'] === 'V008, V028, S001, G009, G010');

    $dining = db_query("SELECT unit_label FROM inv_items WHERE name = 'Outdoor Dining Set (9 pce)' AND is_active = TRUE ORDER BY id DESC LIMIT 1")->fetch();
    check('import: unit_label follows the suggestion (sets)', $dining && $dining['unit_label'] === 'sets');

    $res2 = inv_import_items($wb['lines'], $g);
    check('import: importing the same list again creates nothing — every group already exists', $res2['created'] === 0 && $res2['existing'] === 154);

    // ── DB checks (each task inserts its block above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
