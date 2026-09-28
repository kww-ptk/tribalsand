<?php
declare(strict_types=1);
// Inventory — shipments and shared stores. Run: php tests/inventory_shipments_logic.php
// Pure rules always run. The DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory_shipments.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/inventory-shipment-import.php';

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

// The preview form: rename a whole group, split one line, choices remembered per line.
$canvasGid = inv_ship_gid(inv_ship_key('Wall Art - Canvas Print'));
[$names, $choices] = inv_ship_apply_preview($g, ['g' => [$canvasGid => ['name' => 'Canvas print', 'category' => 'Art', 'kind' => 'operational', 'unit' => 'pcs']],
                                                 'split' => [$idx('G009') => 'Canvas print (spare)']], [], []);
$g2 = inv_ship_groups_with_choices($wb['lines'], $names, $choices);
check('preview: renaming a group renames all its lines', $g2[inv_ship_key('Canvas print')]['qty'] === 70 && !isset($g2[inv_ship_key('Wall Art - Canvas Print')]));
check('preview: a split wins for its line', $g2[inv_ship_key('Canvas print (spare)')]['qty'] === 26);
check('preview: choices follow the lines', $g2[inv_ship_key('Canvas print')]['category'] === 'Art' && $g2[inv_ship_key('Canvas print (spare)')]['category'] === 'Art');
check('preview: an unknown kind falls back to the suggestion', inv_ship_apply_preview($g, ['g' => [$canvasGid => ['kind' => 'gadget']]], [], [])[1][$idx('V008')]['kind'] === 'operational');

// ── Pure checks (each task inserts its section above this line) ──

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_shipments_supported()) {
    echo "\nSKIP  DB block (add_inventory_shipments.sql not applied)\n";
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
    check('schema: shipments tables exist', $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('inv_shipments','inv_shipment_lines')") === 2);
    check('schema: moves link to a shipment line', $count("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inv_moves' AND column_name = 'shipment_line_id'") === 1);

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

    $unit = inv_create_item(['name' => "ZZ Ship unit {$sfx}", 'item_type' => 'operational', 'tracking' => 'serial', 'replacement_value' => 200]);
    inv_asset_create($unit, $tdStore, ['serial' => "ZZU-{$sfx}"], null);
    check('units: a serial unit at a shared store is visible to a sharing manager', count(inv_item_units($unit, [$vMI])) === 1);
    check('units: not visible to a non-sharing manager', count(inv_item_units($unit, [$vZ])) === 0);

    // ── DB checks (each task inserts its block above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
