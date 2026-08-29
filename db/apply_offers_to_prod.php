<?php
/**
 * One-off: apply the Offers migration (+ optional demo seed) to a REMOTE
 * (production) database. Mirrors db/apply_nav_to_prod.php.
 *
 * WHY THIS EXISTS
 *   The GitHub -> ECS pipeline ships CODE only; it never touches the database.
 *   The Offers homepage strip + admin are pre-migration-safe, so they simply
 *   stay hidden until the `offers` table exists. This runner creates it, safely,
 *   in one step.
 *
 * SAFETY
 *   - Connects ONLY to the URL you pass in $PROD_DATABASE_URL. It never reads the
 *     local .env, so it can't accidentally touch your dev database.
 *   - Prints the target host + db and requires you to confirm the db name before
 *     writing anything (set PROD_DB_CONFIRM=<dbname> to skip the prompt in a
 *     non-interactive shell).
 *   - The migration is idempotent DDL (CREATE TABLE/INDEX IF NOT EXISTS — never
 *     drops), safe to re-run.
 *   - The demo seed is OPT-IN: it only runs if SEED_OFFERS=1. Leave it off and
 *     add real offers in Admin -> Offers. The seed itself is idempotent.
 *   - CLI only.
 *
 * USAGE — PowerShell (this machine):
 *   $env:PROD_DATABASE_URL = "postgresql://USER:PASS@HOST:5432/DBNAME"
 *   D:\php84\php.exe db\apply_offers_to_prod.php
 *   # optional demo content: also set  $env:SEED_OFFERS = "1"
 *
 * USAGE — bash:
 *   PROD_DATABASE_URL='postgresql://USER:PASS@HOST:5432/DBNAME' php db/apply_offers_to_prod.php
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
// so every db() call hits production and never the local database.
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
$sql = file_get_contents(__DIR__ . '/migrations/add_offers.sql');
if ($sql === false) { fwrite(STDERR, "Could not read add_offers.sql — aborted.\n"); exit(1); }
try {
    db()->exec($sql);
    fwrite(STDOUT, "\xE2\x9C\x93 Migration applied (offers table + index ensured).\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\nAborted.\n");
    exit(1);
}

// 2) Demo seed — OPT-IN only. Runs the existing idempotent seed against the SAME
//    connection now that DATABASE_URL points at prod.
$seed = getenv('SEED_OFFERS') ?: (string)($_SERVER['SEED_OFFERS'] ?? '');
if ($seed === '1') {
    require __DIR__ . '/seeds/seed_offers.php';
    fwrite(STDOUT, "Demo offers seeded.\n");
} else {
    fwrite(STDOUT, "Skipped demo seed (set SEED_OFFERS=1 to lay down sample offers).\n");
}

fwrite(STDOUT, "\nDone. Add/manage offers in Admin -> Offers; the homepage strip shows published ones.\n");
