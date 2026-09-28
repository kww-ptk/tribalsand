<?php
declare(strict_types=1);
// POS offline mode (server side). Run: php tests/pos_offline_logic.php
// Pure time check always; a real sale through pos_complete_sale() in a rolled-back
// transaction (SKIPs with no DB / POS / add_pos_offline.sql).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pos.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$now = strtotime('2026-09-28 12:00:00');
check('time: an hour ago is kept', pos_offline_sold_at(date('c', $now - 3600), $now) === date('Y-m-d H:i:sP', $now - 3600));
check('time: two days + ago is ignored', pos_offline_sold_at(date('c', $now - 49 * 3600), $now) === null);
check('time: the future (beyond clock drift) is ignored', pos_offline_sold_at(date('c', $now + 600), $now) === null);
check('time: small clock drift ahead is kept', pos_offline_sold_at(date('c', $now + 120), $now) !== null);
check('time: garbage / missing is ignored', pos_offline_sold_at('yesterday-ish', $now) === null && pos_offline_sold_at(null, $now) === null && pos_offline_sold_at(123, $now) === null);

try { db()->query('SELECT 1'); } catch (Throwable $e) { echo "\nSKIP  DB block\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }
if (!pos_supported() || !pos_offline_supported()) { echo "\nSKIP  DB block (POS / add_pos_offline.sql not applied)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    if (is_file(__DIR__ . '/../includes/acct.php')) { require_once __DIR__ . '/../includes/acct.php'; if (acct_supported()) db_query('UPDATE companies SET accounting_starts_on = NULL'); }
    db_query("INSERT INTO admin_users (email, role, name, is_active) VALUES ('zz-off@x.test', 'owner', 'ZZ Off', TRUE)");
    $owner = (int) db()->lastInsertId();
    db_query("INSERT INTO pos_outlets (name, slug, kind, currency) VALUES ('ZZ Off', 'zz-off-outlet', 'shop', 'KES')");
    $out = (int) db()->lastInsertId();
    db_query("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Hat', 'product', 900)", [':o' => $out]);
    $hat = (int) db()->lastInsertId();
    $soldAt = date('c', time() - 1800);
    $req = ['outlet_id' => $out, 'client_uuid' => '0badcafe-0000-4000-8000-00000000abcd', 'lines' => [['item_id' => $hat, 'qty' => 2]],
            'payment_method' => 'cash', 'customer' => ['type' => 'walkin'], 'offline_sold_at' => $soldAt];
    $r = pos_complete_sale($req, $owner);
    check('db: a queued sale goes through when sent', $r['ok'] && (float)$r['sale']['total'] === 1800.0);
    $row = db_query('SELECT offline_sold_at, created_at FROM pos_sales WHERE id = :s', [':s' => $r['sale']['id']])->fetch();
    check('db: it remembers when it really happened', $row['offline_sold_at'] !== null && abs(strtotime((string)$row['offline_sold_at']) - strtotime($soldAt)) <= 1);
    check('db: the payload shows it', pos_sale_payload(pos_fetch_sale((int)$r['sale']['id']))['offline_sold_at'] !== null);
    $again = pos_complete_sale($req, $owner);
    check('db: sending the same queued sale again returns the first one (no duplicate)', $again['ok'] && $again['duplicate'] === true
        && (int)$again['sale']['id'] === (int)$r['sale']['id']
        && (int) db_query("SELECT COUNT(*) FROM pos_sales WHERE client_uuid = :u", [':u' => $req['client_uuid']])->fetchColumn() === 1);
    $r2 = pos_complete_sale(['client_uuid' => '0badcafe-0000-4000-8000-00000000abce', 'offline_sold_at' => date('c', time() + 86400)] + $req, $owner);
    check('db: an implausible time is ignored, the sale still goes through', $r2['ok']
        && db_query('SELECT offline_sold_at FROM pos_sales WHERE id = :s', [':s' => $r2['sale']['id']])->fetchColumn() === null);
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
