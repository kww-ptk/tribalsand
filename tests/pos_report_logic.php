<?php
declare(strict_types=1);
// POS takings on the financial report. Run: php tests/pos_report_logic.php
// Pure summary always; the DB block reads real sales (read-only) and SKIPs with no DB.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pos.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$rows = [
    ['outlet' => 'Spa',  'currency' => 'KES', 'ym' => '2026-09', 'n' => 2, 'total' => '13500', 'tips' => '500', 'vat' => '1793.10', 'room_charged' => '6500'],
    ['outlet' => 'Spa',  'currency' => 'KES', 'ym' => '2026-08', 'n' => 1, 'total' => '6500',  'tips' => '0',   'vat' => '896.55',  'room_charged' => '0'],
    ['outlet' => 'Shop', 'currency' => 'USD', 'ym' => '2026-09', 'n' => 3, 'total' => '90',    'tips' => '0',   'vat' => '0',       'room_charged' => '0'],
];
$s = pos_report_summarize($rows);
check('summary: one block per currency', array_keys($s['currencies']) === ['KES', 'USD']);
check('summary: sales exclude tips', $s['currencies']['KES']['sales'] === 1950000 && $s['currencies']['KES']['tips'] === 50000);
check('summary: net = sales − VAT', $s['currencies']['KES']['net'] === 1950000 - 268965 && $s['currencies']['KES']['vat'] === 268965);
check('summary: counts add up', $s['currencies']['KES']['n'] === 3 && $s['currencies']['USD']['n'] === 3);
check('summary: by outlet keeps currencies apart', isset($s['by_outlet']['Spa']['KES']) && !isset($s['by_outlet']['Spa']['USD']) && $s['by_outlet']['Shop']['USD']['sales'] === 9000);
check('summary: by month is in date order', array_keys($s['by_month']) === ['2026-08', '2026-09'] && $s['by_month']['2026-09']['KES']['room_charged'] === 650000);
check('summary: empty input', pos_report_summarize([]) === ['currencies' => [], 'by_outlet' => [], 'by_month' => []]);

try { db()->query('SELECT 1'); } catch (Throwable $e) { echo "\nSKIP  DB block\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }
if (!pos_supported()) { echo "\nSKIP  DB block (POS not installed)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }
$all = array_map('intval', db_query('SELECT id FROM pos_outlets')->fetchAll(PDO::FETCH_COLUMN));
$r = pos_report_sales($all, '2000-01-01', date('Y-m-d'));
$direct = db_query("SELECT currency, SUM(total) AS t FROM pos_sales WHERE status = 'completed' GROUP BY currency")->fetchAll(PDO::FETCH_KEY_PAIR);
$fromReport = [];
foreach ($r as $x) $fromReport[$x['currency']] = ($fromReport[$x['currency']] ?? 0) + pos_cents($x['total']);
$ok = true;
foreach ($direct as $cur => $t) $ok = $ok && ($fromReport[$cur] ?? -1) === pos_cents($t);
check('db: report totals equal the completed sales, per currency (voids excluded)', $ok && count($fromReport) === count($direct));
check('db: no outlets in scope → nothing', pos_report_sales([], '2000-01-01', date('Y-m-d')) === []);

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
