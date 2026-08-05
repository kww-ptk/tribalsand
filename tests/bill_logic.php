<?php
declare(strict_types=1);
// Room-bill helpers. Run: php tests/bill_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if (!$hid) { echo "SKIP  no hold to bill\n\nALL PASS\n"; exit(0); }

$before = bill_total($hid);   // this hold may already have real charges; assert the delta

$mk = function (string $status, $price) use ($hid): int {
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status,price_amount) VALUES (:h,'other','ZZ bill',:s,:p)",
             [':h'=>$hid, ':s'=>$status, ':p'=>$price]);
    return (int)db()->lastInsertId();
};
$aConf = $mk('confirmed', 1000);
$aComp = $mk('completed', 500);
$aDecl = $mk('declined',  999);
$aReq  = $mk('requested', 999);
$aNull = $mk('confirmed', null);   // accepted but not yet priced
db_query("INSERT INTO bill_items (hold_id,label,amount) VALUES (:h,'ZZ Minibar',300)", [':h'=>$hid]); $i1 = (int)db()->lastInsertId();
db_query("INSERT INTO bill_items (hold_id,label,amount) VALUES (:h,'ZZ Damage',200)",  [':h'=>$hid]); $i2 = (int)db()->lastInsertId();

$lineIds = array_map(fn($l) => (int)$l['id'], fetch_bill_lines($hid));
check('bill lines include confirmed + completed', in_array($aConf, $lineIds, true) && in_array($aComp, $lineIds, true));
check('bill lines include the unpriced confirmed', in_array($aNull, $lineIds, true));
check('bill lines exclude declined',  !in_array($aDecl, $lineIds, true));
check('bill lines exclude requested', !in_array($aReq,  $lineIds, true));

$labels = array_column(fetch_bill_items($hid), 'label');
check('bill items return the manual rows', in_array('ZZ Minibar', $labels, true) && in_array('ZZ Damage', $labels, true));

// delta = 1000 + 500 + 0(null) + 300 + 200 = 2000; declined/requested not counted
check('bill_total sums confirmed/completed + manual, excludes declined/requested',
      abs((bill_total($hid) - $before) - 2000.0) < 0.001);

db_query("DELETE FROM booking_addons WHERE id IN (:a,:b,:c,:d,:e)", [':a'=>$aConf,':b'=>$aComp,':c'=>$aDecl,':d'=>$aReq,':e'=>$aNull]);
db_query("DELETE FROM bill_items WHERE id IN (:a,:b)", [':a'=>$i1, ':b'=>$i2]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
