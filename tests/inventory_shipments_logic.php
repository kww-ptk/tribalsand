<?php
declare(strict_types=1);
// Inventory — shipments and shared stores. Run: php tests/inventory_shipments_logic.php
// Pure rules always run. The DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory_shipments.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
$fixture = __DIR__ . '/fixtures/shipment-maya-ilai.xlsx';
check('fixture: the real shipment list is present', is_file($fixture));

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

    // ── DB checks (each task inserts its block above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
