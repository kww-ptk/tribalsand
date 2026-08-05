<?php
declare(strict_types=1);
// Service pricing helpers. Run: php tests/services_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// format_price — pure formatting, explicit currency avoids depending on settings.
check('format_price: whole number, no decimals', format_price(500, 'KES') === 'KES 500');
check('format_price: fractional keeps 2dp',      format_price(12.5, 'KES') === 'KES 12.50');
check('format_price: thousands separator',       format_price(1500, 'USD') === 'USD 1,500');

// Seed two catalog rows (one active, one inactive).
db_query("INSERT INTO service_options (service,label,price_amount,is_active,sort_order) VALUES ('laundry','ZZ Active',500,TRUE,900)");
$activeId = (int)db()->lastInsertId();
db_query("INSERT INTO service_options (service,label,price_amount,is_active,sort_order) VALUES ('laundry','ZZ Inactive',0,FALSE,901)");
$inactiveId = (int)db()->lastInsertId();

$active = fetch_service_options('laundry', true);
$labels = array_column($active, 'label');
check('fetch_service_options(active) includes the active row', in_array('ZZ Active', $labels, true));
check('fetch_service_options(active) excludes the inactive row', !in_array('ZZ Inactive', $labels, true));

$all = fetch_service_options('laundry', false);
check('fetch_service_options(all) includes the inactive row', in_array('ZZ Inactive', array_column($all, 'label'), true));
check('fetch_service_options rejects unknown service', fetch_service_options('bogus') === []);

$one = fetch_service_option($activeId);
check('fetch_service_option returns the row', $one && $one['service'] === 'laundry' && (float)$one['price_amount'] === 500.0);
check('fetch_service_option: false for missing id', fetch_service_option(-1) === false);

check('addon_price_supported is true after migration', addon_price_supported() === true);

// Snapshot: an addon insert carrying the option's price stores it.
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    db_query("INSERT INTO booking_addons (hold_id, kind, details, price_amount) VALUES (:h,'laundry','ZZ Active',:p)",
             [':h'=>$hid, ':p'=>(float)$one['price_amount']]);
    $aid = (int)db()->lastInsertId();
    $stored = db_query("SELECT price_amount FROM booking_addons WHERE id=:id", [':id'=>$aid])->fetch();
    check('addon price snapshot stored', $stored && (float)$stored['price_amount'] === 500.0);
    db_query("DELETE FROM booking_addons WHERE id=:id", [':id'=>$aid]);
}

db_query("DELETE FROM service_options WHERE id IN (:a,:b)", [':a'=>$activeId, ':b'=>$inactiveId]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
