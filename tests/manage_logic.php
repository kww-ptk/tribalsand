<?php
declare(strict_types=1);
// Pure-logic tests — no DB required. Run: php tests/manage_logic.php
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// --- generate_access_code ---
$code = generate_access_code();
check('code length is 6', strlen($code) === 6);
check('code is uppercase alnum, unambiguous alphabet',
      (bool)preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $code));
$codes = [];
for ($i = 0; $i < 200; $i++) $codes[generate_access_code()] = true;
check('codes vary (>150 unique of 200)', count($codes) > 150);

// --- guest ref round-trip (requires BOOKING_TOKEN_SECRET in .env) ---
$secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
if ($secret) {
    require_once __DIR__ . '/../includes/booking.php';
    $ref = make_guest_ref(4242);
    check('ref has TS-<id>-<hash> shape', (bool)preg_match('/^TS-4242-[0-9a-f]{8}$/', $ref));
    check('verify_guest_ref round-trips', verify_guest_ref($ref) === 4242);
    check('tampered ref rejected', verify_guest_ref('TS-4242-deadbeef') === false);
    check('make_manage_url contains ref', str_contains(make_manage_url(4242), 'ref='));
} else {
    echo "SKIP  ref tests (BOOKING_TOKEN_SECRET not set)\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
