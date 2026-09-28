<?php
declare(strict_types=1);
// Switching a POS outlet to KES (converting its prices). Run: php tests/pos_kes_logic.php
// Pure rounding always; DB conversion in a rolled-back transaction (SKIPs without DB/POS).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pos.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('round: $4.50 at 129 → KES 581 (half up)', pos_to_whole_kes(4.5, 129.0) === 581.0);
check('round: $12 at 129 → KES 1,548', pos_to_whole_kes(12, 129.0) === 1548.0);
check('round: €1 at 140.4 → KES 140', pos_to_whole_kes(1, 140.4) === 140.0);

try { db()->query('SELECT 1'); } catch (Throwable $e) { echo "\nSKIP  DB block\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }
if (!pos_supported() || !pos_fx_rate('KES', 'USD')) { echo "\nSKIP  DB block (POS / USD rate missing)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    $rate = pos_fx_rate('KES', 'USD');
    db_query("INSERT INTO pos_outlets (name, slug, kind, currency) VALUES ('ZZ USD Bar', 'zz-usd-bar', 'other', 'USD')");
    $o = (int) db()->lastInsertId();
    db_query("INSERT INTO pos_items (outlet_id, name, kind, price, consignor_cost) VALUES (:o, 'ZZ Beer', 'product', 4.50, NULL), (:o2, 'ZZ Craft', 'product', 25, 18), (:o3, 'ZZ Open', 'service', NULL, NULL)",
        [':o' => $o, ':o2' => $o, ':o3' => $o]);
    db_query("INSERT INTO pos_sales (reference, outlet_id, customer_type, currency, subtotal, total, payment_method, client_uuid)
              VALUES ('ZZ-OLD-1', :o, 'walkin', 'USD', 9, 9, 'cash', 'zz-kes-old-sale')", [':o' => $o]);
    $r = pos_outlet_convert_to_kes($o);
    $items = db_query('SELECT name, price, consignor_cost FROM pos_items WHERE outlet_id = :o ORDER BY name', [':o' => $o])->fetchAll(PDO::FETCH_UNIQUE);
    check('db: the outlet now sells in KES', db_query('SELECT currency FROM pos_outlets WHERE id = :o', [':o' => $o])->fetchColumn() === 'KES' && $r['from'] === 'USD' && $r['items'] === 3);
    check('db: item prices converted at the rate, whole shillings', (float)$items['ZZ Beer']['price'] === pos_to_whole_kes(4.5, $rate) && (float)$items['ZZ Craft']['price'] === pos_to_whole_kes(25, $rate));
    check('db: fixed supplier cost converted too', (float)$items['ZZ Craft']['consignor_cost'] === pos_to_whole_kes(18, $rate));
    check('db: an open-price item stays open', $items['ZZ Open']['price'] === null);
    check('db: past sales keep their currency', db_query("SELECT currency FROM pos_sales WHERE reference = 'ZZ-OLD-1'")->fetchColumn() === 'USD');
    check('db: switching again changes nothing', pos_outlet_convert_to_kes($o)['from'] === 'KES'
        && (float) db_query("SELECT price FROM pos_items WHERE outlet_id = :o AND name = 'ZZ Beer'", [':o' => $o])->fetchColumn() === pos_to_whole_kes(4.5, $rate));
    db_query("INSERT INTO pos_outlets (name, slug, kind) VALUES ('ZZ Default', 'zz-default', 'other')");
    check('db: a new outlet defaults to KES', db_query("SELECT currency FROM pos_outlets WHERE slug = 'zz-default'")->fetchColumn() === 'KES');
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
