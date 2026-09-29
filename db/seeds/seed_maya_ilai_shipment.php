<?php
/**
 * Seed the Maya Ilai fit-out shipment: items, par levels per property, and ONE
 * order (quantities ON ORDER, no stock) with the packing lists' container hints.
 *
 * Run:  D:\php84\php.exe db/seeds/seed_maya_ilai_shipment.php            (after add_inventory.sql + add_inventory_orders.sql)
 *       D:\php84\php.exe db/seeds/seed_maya_ilai_shipment.php --dry-run
 *       D:\php84\php.exe db/seeds/seed_maya_ilai_shipment.php path\to\list.xlsx
 *
 * Reads tests/fixtures/shipment-maya-ilai.xlsx (or the first non-flag argument) and
 * imports it EXACTLY as Admin -> Inventory -> Import does: both call
 * inv_import_list(). Before importing it makes sure the default places exist
 * (every property gets its inventory location; the areas "Hair Salon" and
 * "Tribal Table" under Tribal Dunes are created when missing) and maps each
 * item-code prefix to a place with inv_import_default_places().
 *
 * Idempotent: items that already exist are left alone, par levels are
 * overwritten with the same sums, and a second ORDER is never created for the
 * same list (inv_order_open_for()) — a re-run only refreshes items and pars.
 * --dry-run parses and prints the mapping and counts, and writes nothing.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/xlsx-reader.php';
require_once __DIR__ . '/../../includes/inventory-views.php';
require_once __DIR__ . '/../../includes/inventory-orders.php';

$dry  = in_array('--dry-run', $argv ?? [], true);
$args = array_values(array_filter(array_slice($argv ?? [], 1), fn($a) => !str_starts_with((string)$a, '--')));
$file = $args[0] ?? (__DIR__ . '/../../tests/fixtures/shipment-maya-ilai.xlsx');
$tag  = $dry ? '[dry-run] ' : '';

if (!inv_supported()) { fwrite(STDERR, "Inventory tables missing — run db/migrations/add_inventory.sql first.\n"); exit(1); }
if (!inv_orders_supported()) { fwrite(STDERR, "Orders are not set up — run db/migrations/add_inventory_orders.sql first.\n"); exit(1); }
if (!is_file($file)) { fwrite(STDERR, "File not found: {$file}\n"); exit(1); }

$sheets  = xlsx_read_sheets($file);
$parsed  = inv_ship_parse_workbook($sheets);
$packing = inv_ship_parse_packing($sheets);
if (!$parsed['lines']) { fwrite(STDERR, "No item list found in {$file}.\n"); exit(1); }
$filename = basename($file);
// The committed fixture has a technical name — store the order under the supplier file's real name.
if ($filename === 'shipment-maya-ilai.xlsx') $filename = 'Inventory List Maya Ilai 4 Containers Shipment 1.xlsx';
$fp       = inv_import_list_fingerprint($parsed['lines']);
$pieces   = array_sum(array_column($parsed['lines'], 'qty'));
echo "{$tag}Read {$filename}: " . count($parsed['lines']) . " lines, {$pieces} pieces, " . count($packing) . " packing list(s).\n";

/** Places (dry-run: report only) — Hair Salon / Tribal Table under Tribal Dunes. */
$ensureAreas = function () use ($dry, $tag): void {
    $vid = (int) db_query("SELECT id FROM venues WHERE slug = 'tribal-dunes'")->fetchColumn();
    if (!$vid) { echo "{$tag}Venue 'tribal-dunes' not found — skipping the Hair Salon / Tribal Table areas.\n"; return; }
    $parent = $dry ? (int) db_query("SELECT id FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $vid])->fetchColumn() : inv_property_location_id($vid);
    foreach (['Hair Salon', 'Tribal Table'] as $name) {
        $has = $parent && db_query("SELECT 1 FROM inv_locations WHERE parent_id = :p AND kind = 'area' AND is_active = TRUE AND lower(name) = lower(:n)", [':p' => $parent, ':n' => $name])->fetchColumn();
        if ($has) continue;
        echo "{$tag}area + {$name} (under Tribal Dunes)\n";
        if (!$dry) inv_create_area($parent, $name);
    }
};

$run = function () use ($dry, $tag, $ensureAreas, $parsed, $packing, $filename, $fp): void {
    if (!$dry) inv_ensure_default_locations();
    $ensureAreas();

    // Prefix → place, from the owner's defaults (or what the Import page remembered).
    $prefixes = [];
    foreach ($parsed['lines'] as $l) { $p = inv_ship_prefix((string)($l['code'] ?? '')); if ($p !== '' && !in_array($p, $prefixes, true)) $prefixes[] = $p; }
    $options = inv_import_place_options(null);
    $places  = inv_import_default_places($prefixes, $options);
    $label   = [];
    foreach ($options as $o) $label[(int)$o['id']] = $o['label'];
    echo "{$tag}Item-code prefix -> place:\n";
    $none = [];
    foreach ($prefixes as $p) {
        $id = (int)($places[$p] ?? 0);
        echo sprintf("  %-4s -> %s\n", $p, $id > 0 ? ($label[$id] ?? "#{$id}") : '(no place — item only)');
        if ($id <= 0) $none[] = $p;
    }
    if ($none) echo "{$tag}No place for prefix: " . implode(', ', $none) . " (their items are created, but no par level and no planned place).\n";

    $existing = inv_order_open_for($fp);
    if ($existing) echo "{$tag}An order from this list already exists: #{$existing['id']} \"{$existing['name']}\" — no second order will be created; items and pars are refreshed.\n";
    if ($dry) {
        $groups = inv_ship_group($parsed['lines']);
        $known  = inv_import_existing_items();
        $new = 0; foreach ($groups as $k => $g) if (!isset($known[$k])) $new++;
        echo "{$tag}Would import " . count($groups) . " items ({$new} new, " . (count($groups) - $new) . " existing), "
           . ($existing ? 'no new order' : 'one order of ' . array_sum(array_column($parsed['lines'], 'qty')) . ' pieces') . ".\n";
        return;
    }

    $r = inv_import_list($parsed, $packing, $places, $filename, null);
    $orderId = $r['order_id'] ?: (int)($existing['id'] ?? 0);
    $onOrder = 0; $diffs = 0; $conts = [];
    if ($orderId) {
        foreach (inv_order_lines($orderId, null) as $l) { $onOrder += (int)$l['qty_ordered']; if ($l['pack_diff'] !== null) $diffs++; }
        $conts = inv_order_containers($orderId, null);
    }
    echo "Items: {$r['created']} created, {$r['existing']} already there. Par levels set: {$r['pars']}.\n";
    echo $r['order_id'] ? "Order #{$orderId} \"{$r['order_name']}\" created" : ($orderId ? "Order #{$orderId} kept (already existed)" : 'No order') . "";
    echo $orderId ? ": {$onOrder} pieces on order, " . count($conts) . " container(s)"
        . ($r['containers'] ? " ({$r['containers']['matched']} packing lines matched, {$r['containers']['unmatched']} unmatched)" : '')
        . ", {$diffs} line(s) differ from the packing lists.\n" : ".\n";
};

if ($dry) { $run(); } else { inv_tx($run); }
echo $tag . "Done.\n";
