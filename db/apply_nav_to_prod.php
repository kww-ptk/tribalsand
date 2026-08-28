<?php
/**
 * One-off: apply the mega-menu migration + seed to a REMOTE (production) database.
 *
 * WHY THIS EXISTS
 *   The GitHub -> ECS pipeline ships CODE only; it never touches the database.
 *   The live nav therefore shows the hardcoded fallback until the nav tables
 *   exist and are seeded. This runner does both, safely, in one step.
 *
 * SAFETY
 *   - Connects ONLY to the URL you pass in $PROD_DATABASE_URL. It never reads the
 *     local .env, so it can't accidentally wipe/seed your dev database.
 *   - Prints the target host + db and requires you to confirm the db name before
 *     writing anything (set PROD_DB_CONFIRM=<dbname> to skip the prompt in a
 *     non-interactive shell).
 *   - Both steps are idempotent and safe to re-run:
 *       * migration = CREATE TABLE/INDEX IF NOT EXISTS  (never drops)
 *       * seed      = truncate nav_* + rebuild the current nav 1:1
 *   - CLI only.
 *
 * USAGE — PowerShell (this machine):
 *   $env:PROD_DATABASE_URL = "postgresql://USER:PASS@HOST:5432/DBNAME"
 *   D:\php84\php.exe db\apply_nav_to_prod.php
 *
 * USAGE — bash:
 *   PROD_DATABASE_URL='postgresql://USER:PASS@HOST:5432/DBNAME' php db/apply_nav_to_prod.php
 *
 * Non-interactive (CI / no TTY): also set PROD_DB_CONFIRM=DBNAME to skip the prompt.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$url = getenv('PROD_DATABASE_URL') ?: (string)($_SERVER['PROD_DATABASE_URL'] ?? '');
if ($url === '') {
    fwrite(STDERR, "PROD_DATABASE_URL is not set. See the header of this file for usage. Aborting.\n");
    exit(1);
}

// Force includes/db.php to use THIS url only (real env beats the .env fallback),
// so every db() call in the seed hits production and never the local database.
putenv("DATABASE_URL={$url}");
$_ENV['DATABASE_URL']    = $url;
$_SERVER['DATABASE_URL'] = $url;

require_once __DIR__ . '/../includes/db.php';

$p    = parse_url($url) ?: [];
$host = $p['host'] ?? '(unknown host)';
$dbn  = ltrim((string)($p['path'] ?? ''), '/');

fwrite(STDOUT, "\nTarget database:\n");
fwrite(STDOUT, "  host = {$host}\n");
fwrite(STDOUT, "  db   = {$dbn}\n\n");

$confirm = getenv('PROD_DB_CONFIRM') ?: (string)($_SERVER['PROD_DB_CONFIRM'] ?? '');
if ($confirm === '') {
    fwrite(STDOUT, "Type the database name (\"{$dbn}\") to proceed: ");
    $confirm = trim((string)fgets(STDIN));
}
if ($confirm !== $dbn || $dbn === '') {
    fwrite(STDERR, "Confirmation did not match the target db name — aborted. Nothing was changed.\n");
    exit(1);
}

// Sanity: connect and report the server we actually reached.
try {
    $who = db_query('SELECT current_database() AS db, inet_server_addr() AS addr')->fetch();
    fwrite(STDOUT, "Connected to \"{$who['db']}\"" . ($who['addr'] ? " at {$who['addr']}" : '') . ".\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect: {$e->getMessage()}\nAborted.\n");
    exit(1);
}

// 1) Migration — idempotent DDL.
$sql = file_get_contents(__DIR__ . '/migrations/add_nav_menu.sql');
if ($sql === false) { fwrite(STDERR, "Could not read add_nav_menu.sql — aborted.\n"); exit(1); }
try {
    db()->exec($sql);
    fwrite(STDOUT, "\xE2\x9C\x93 Migration applied (nav_items / nav_groups / nav_links ensured).\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\nAborted before seeding.\n");
    exit(1);
}

// 2) Seed — idempotent (truncate + rebuild the current nav 1:1). Runs the existing
//    seed against the SAME connection now that DATABASE_URL points at prod.
require __DIR__ . '/seeds/seed_nav_menu.php';

fwrite(STDOUT, "\nDone. The live nav now renders from the database.\n");
fwrite(STDOUT, "Verify: open the site, then edit something in Admin -> Site Menu.\n");
