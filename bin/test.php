<?php
/**
 * Run the test suites and print one line per suite.
 *
 *   php bin/test.php               every tests/*.php
 *   php bin/test.php rates quote   only suites whose file name contains "rates" or "quote"
 *   php bin/test.php -v rates      also print the output of failing suites
 *
 * Each suite runs in its own PHP process (they are standalone scripts that exit
 * non-zero on failure). DB-backed suites use the database in DATABASE_URL and
 * roll their changes back — run them against your LOCAL database only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$args    = array_slice($argv, 1);
$verbose = in_array('-v', $args, true);
$filters = array_values(array_filter($args, fn($a) => $a !== '-v'));

$files = glob(__DIR__ . '/../tests/*.php') ?: [];
sort($files);
if ($filters) {
    $files = array_values(array_filter($files, function ($f) use ($filters) {
        foreach ($filters as $x) if (str_contains(basename($f), $x)) return true;
        return false;
    }));
}
if (!$files) { fwrite(STDERR, "No test files match.\n"); exit(1); }

// Suites run in child processes, which do not inherit `-d extension=…` flags. If this
// process has sodium (the GHL webhook suite needs it) but a plain child would not,
// load it in the children too — the usual case with a Windows PHP build.
$php = escapeshellarg(PHP_BINARY);
if (extension_loaded('sodium') && trim((string)shell_exec($php . ' -r "echo (int) function_exists(\'sodium_crypto_sign_verify_detached\');"')) !== '1') {
    $php .= ' -d extension=sodium';
}
$pass = 0; $failed = [];
$t0 = microtime(true);
foreach ($files as $f) {
    $name = basename($f, '.php');
    $out = []; $rc = 0; $s = microtime(true);
    exec($php . ' -d memory_limit=512M ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $secs = number_format(microtime(true) - $s, 1);
    if ($rc === 0) { $pass++; printf("  ok    %-40s %5ss\n", $name, $secs); continue; }
    $failed[] = $name;
    $why = '';
    foreach ($out as $line) if (preg_match('/^(FAIL|PHP Fatal|Fatal error)/', $line)) { $why = $line; break; }
    printf("  FAIL  %-40s %5ss  %s\n", $name, $secs, mb_strimwidth($why, 0, 90, '…'));
    if ($verbose) echo '        ' . implode("\n        ", $out) . "\n";
}
printf("\n%d passed, %d failed  (%.0fs)\n", $pass, count($failed), microtime(true) - $t0);
if ($failed) echo "Re-run one with output:  php tests/{$failed[0]}.php\n";
exit($failed ? 1 : 0);
