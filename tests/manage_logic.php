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

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
