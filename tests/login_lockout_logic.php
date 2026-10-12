<?php
declare(strict_types=1);
// Sign-in lockout (TRI-76): 5 wrong tries per account, 20 per IP, 10 minutes,
// the message gives the real time left, a correct sign-in clears the account's
// mistakes, and POS PIN rows never count. Message wording is pure; the counting
// runs inside ONE rolled-back transaction and SKIPs with no database.
// Run: php tests/login_lockout_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('message: rounds up to whole minutes', login_lock_message(61) === 'Too many failed attempts. Please try again in 2 minutes.');
check('message: singular minute', login_lock_message(30) === 'Too many failed attempts. Please try again in 1 minute.');
check('limits: per IP looser than per account', LOGIN_MAX_FAILS_IP > LOGIN_MAX_FAILS_ACCOUNT);

try { db()->query('SELECT 1 FROM login_attempts LIMIT 1'); }
catch (Throwable $e) { echo "SKIP  database checks (no database)\n"; echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    $add = function (string $email, string $ip, bool $ok, int $secondsAgo): void {
        db_query('INSERT INTO login_attempts (email, ip_address, success, created_at) VALUES (:e, :ip, :ok, :t)',
            [':e' => $email, ':ip' => $ip, ':ok' => $ok ? 'TRUE' : 'FALSE', ':t' => date('Y-m-d H:i:s', time() - $secondsAgo)]);
    };
    $a = 'lockout-a@example.test'; $ip = '10.77.0.1';

    for ($i = 0; $i < 4; $i++) $add($a, '10.77.1.' . $i, false, 300);
    check('4 wrong tries: not locked', login_lock_seconds($a, '10.77.9.9') === 0);
    $add($a, '10.77.1.9', false, 240);
    $left = login_lock_seconds($a, '10.77.9.9');
    check('5th wrong try: locked about 5 minutes (oldest of the 5 + 10 min)', $left > 280 && $left <= 300);

    $add($a, '10.77.1.9', true, 60);
    check('a correct sign-in clears the account lock', login_lock_seconds($a, '10.77.9.9') === 0);

    // Office IP: other people's typos.
    for ($i = 0; $i < 19; $i++) $add('lockout-o' . $i . '@example.test', $ip, false, 100);
    check('19 wrong tries from one IP by others: you can still sign in', login_lock_seconds('lockout-me@example.test', $ip) === 0);
    $add('lockout-x@example.test', $ip, false, 50);
    check('20 from the IP: IP locked', login_lock_seconds('lockout-me@example.test', $ip) > 0);

    for ($i = 0; $i < 10; $i++) $add('pos:999999', '10.77.2.1', false, 30);
    check('POS PIN rows never lock sign-in', login_lock_seconds('lockout-me@example.test', '10.77.2.1') === 0);

    for ($i = 0; $i < 5; $i++) $add('lockout-old@example.test', '10.77.3.1', false, 700);
    check('failures older than 10 minutes do not count', login_lock_seconds('lockout-old@example.test', '10.77.3.2') === 0);
} finally {
    db()->rollBack();
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
