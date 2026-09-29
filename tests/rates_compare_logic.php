<?php
declare(strict_types=1);
// Rates comparison + batch quote. Run: php tests/rates_compare_logic.php
// Pure helpers always run; the DB block runs in ONE rolled-back transaction and
// SKIPs when no database is reachable.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rates-compare.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── DB: room_stay_quotes() is the ONE summation ─────────────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "SKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $rooms = db_query('SELECT id, price_amount FROM rooms ORDER BY id LIMIT 2')->fetchAll();
        if (count($rooms) < 2) {
            echo "SKIP  batch quote (need 2 rooms)\n";
        } else {
            [$a, $b] = $rooms;
            db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
                      VALUES (:r, '2099-03-02', '2099-03-04', 777, 'Peak season')", [':r' => (int)$a['id']]);
            $defaults = [(int)$a['id'] => 100.0, (int)$b['id'] => 50.0];
            $batch = room_stay_quotes($defaults, '2099-03-01', '2099-03-05');
            check('batch: room A == single quote',
                $batch[(int)$a['id']] === room_stay_quote((int)$a['id'], 100.0, '2099-03-01', '2099-03-05'));
            check('batch: room B == single quote',
                $batch[(int)$b['id']] === room_stay_quote((int)$b['id'], 50.0, '2099-03-01', '2099-03-05'));
            check('batch: A = 2 base + 2 override nights', $batch[(int)$a['id']] === ['nights' => 4, 'total' => 1754.0]);
            check('batch: bad window is not a quote',
                room_stay_quotes($defaults, '2099-03-05', '2099-03-01')[(int)$a['id']] === ['nights' => 0, 'total' => 0.0]);
            $withMap = room_stay_quotes($defaults, '2099-03-01', '2099-03-05', true);
            check('batch: withNightly returns the nightly map',
                count($withMap[(int)$a['id']]['nightly']) === 4
                && $withMap[(int)$a['id']]['nightly']['2099-03-02']['label'] === 'Peak season');
            check('single quote keeps its exact shape', array_keys(room_stay_quote((int)$a['id'], 100.0, '2099-03-01', '2099-03-05')) === ['nights', 'total']);
        }
    } finally { $pdo->rollBack(); }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
