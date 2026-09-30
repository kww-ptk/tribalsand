<?php
/**
 * LOCAL DEVELOPMENT database setup — builds, updates and resets a dev database.
 *
 *   php bin/dev-setup.php             First run: build everything. Later runs: apply only
 *                                     the migrations added since (safe to run after every pull).
 *   php bin/dev-setup.php --status    List applied / pending / failed migrations. Changes nothing.
 *   php bin/dev-setup.php --reset     Wipe the local database and build it again from scratch.
 *   php bin/dev-setup.php --retry     Also retry migrations that failed before.
 *   php bin/dev-setup.php --apply X.sql  Run one migration by hand (after importing a snapshot).
 *   php bin/dev-setup.php --accounts  (Re)create the test logins, one per role, and reset their password.
 *   php bin/dev-setup.php --sanitize  Scrub personal data again (runs automatically after a
 *                                     production snapshot is imported — see README).
 *
 * In Docker this runs automatically every time the app container starts
 * (docker/dev/app-start.sh). Natively, run it yourself after `git pull`.
 *
 * SAFETY: refuses to touch any database that is not on this machine / in the
 * compose network (see dev_guard()). It must never be pointed at production.
 *
 * Why this exists: db/migrations/*.sql have no numeric order and there is no
 * migration ledger in production (/admin/migrate.php runs files by hand). This
 * script keeps a LOCAL-ONLY ledger (`dev_migrations`) and resolves the order by
 * running in passes until nothing more succeeds — a migration that depends on
 * another simply succeeds on a later pass.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/db.php';

const DEV_ROOT = __DIR__ . '/..';

$args  = array_slice($argv, 1);
$flag  = fn(string $f) => in_array($f, $args, true);
if ($flag('--help') || $flag('-h')) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1], 2)[0]);
    exit(0);
}

dev_guard();

// logs/ is git-ignored, so a fresh clone lacks it — and MAIL_DRIVER=log writes emails there.
if (!is_dir(DEV_ROOT . '/logs')) @mkdir(DEV_ROOT . '/logs', 0775, true);

$pdo = dev_connect();

if ($flag('--status'))   { dev_status($pdo); exit(0); }
if ($flag('--reset'))    { dev_reset($pdo); }
if ($flag('--sanitize')) { dev_sanitize($pdo); exit(0); }
if ($flag('--accounts')) { dev_accounts($pdo); exit(0); }
if (($i = array_search('--apply', $args, true)) !== false) { dev_ledger_ensure($pdo); dev_apply_one($pdo, (string)($args[$i + 1] ?? '')); exit(0); }

dev_ledger_ensure($pdo);

$fresh      = !dev_table_exists($pdo, 'venues');
$hasLedger  = (int)$pdo->query('SELECT COUNT(*) FROM dev_migrations')->fetchColumn() > 0;

if ($fresh) {
    say("\n▶ Empty database — building it from scratch (takes a minute)…");
    dev_extensions($pdo);
    dev_run_statements($pdo, DEV_ROOT . '/db/schema.sql', true);
    // schema.sql runs ONCE, first — as it did in production. Its trailing statements
    // mirror early migrations and would narrow constraints later migrations widened
    // (booking_addons_kind_check loses 'event'), so it is never re-run afterwards.
    dev_migrate($pdo, true, false);
    dev_sql_seeds($pdo);
    dev_migrate($pdo, true);                                        // data migrations that needed the seeded rooms
    dev_php_seeds();
    dev_accounts($pdo);
    $pdo->exec("INSERT INTO dev_migrations (name, status) VALUES ('__setup_complete__', 'ok') ON CONFLICT (name) DO NOTHING");
} elseif (!$hasLedger) {
    // Tables exist but this script never ran here: a production snapshot was
    // just imported (docker/dev/db-init restores it into the empty volume).
    say("\n▶ Found an imported database — scrubbing personal data, then bringing it up to date…");
    dev_extensions($pdo);
    dev_sanitize($pdo);
    dev_adopt_migrations($pdo);
    dev_accounts($pdo);
    $pdo->exec("INSERT INTO dev_migrations (name, status) VALUES ('__setup_complete__', 'ok') ON CONFLICT (name) DO NOTHING");
} else {
    dev_migrate($pdo, $flag('--retry'));
}

dev_summary($pdo);
exit(0);

// ───────────────────────────────────────────────────────────────────────────

function say(string $s): void { fwrite(STDOUT, $s . "\n"); }

/** Refuse anything that is not a local database. There is no override flag on purpose. */
function dev_guard(): void {
    $env = parse_env();
    $url = (string)($env['DATABASE_URL'] ?? '');
    if ($url === '') {
        fwrite(STDERR, "DATABASE_URL is not set. Copy .env.example to .env (see README.md).\n");
        exit(1);
    }
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $local = ['localhost', '127.0.0.1', '::1', '[::1]', 'db', 'postgres', 'host.docker.internal'];
    if (!in_array($host, $local, true)) {
        fwrite(STDERR, "REFUSING: DATABASE_URL points at '{$host}', which is not a local database.\n"
            . "dev-setup only ever runs against a database on your own machine (or the compose 'db' service).\n");
        exit(1);
    }
    if (str_contains(strtolower((string)($env['APP_URL'] ?? '')), 'tribalsand.com')) {
        fwrite(STDERR, "REFUSING: APP_URL is the production site. Set APP_URL to your localhost address.\n");
        exit(1);
    }
}

/** Connect, waiting for the database to come up (Docker starts it in parallel). */
function dev_connect(): PDO {
    $last = null;
    for ($i = 0; $i < 30; $i++) {
        try {
            $pdo = db();
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (Throwable $e) {
            $last = $e;
            sleep(2);
        }
    }
    fwrite(STDERR, "Could not connect to the database: " . ($last ? $last->getMessage() : '?') . "\n");
    exit(1);
}

function dev_table_exists(PDO $pdo, string $t): bool {
    $s = $pdo->prepare('SELECT to_regclass(:t) IS NOT NULL');
    $s->execute([':t' => 'public.' . $t]);
    return (bool)$s->fetchColumn();
}

function dev_ledger_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dev_migrations (
        name       TEXT PRIMARY KEY,
        status     TEXT NOT NULL,              -- ok | failed
        checksum   TEXT,
        error      TEXT,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");
}

/** Extensions production has. pgvector/pgcrypto may be missing on a native install — degrade, don't die. */
function dev_extensions(PDO $pdo): void {
    foreach (['pgcrypto', 'vector'] as $ext) {
        try { $pdo->exec("CREATE EXTENSION IF NOT EXISTS {$ext}"); }
        catch (Throwable $e) {
            if ($ext === 'vector') say("  ! pgvector not installed — the AI description search (RAG) stays off. Everything else works.");
            if ($ext === 'pgcrypto') {
                // add_booking_management.sql uses gen_random_bytes(); provide it without the extension.
                $pdo->exec("CREATE OR REPLACE FUNCTION gen_random_bytes(n int) RETURNS bytea LANGUAGE sql AS
                    \$f\$ SELECT decode(string_agg(lpad(to_hex((random()*255)::int), 2, '0'), ''), 'hex') FROM generate_series(1, n) \$f\$");
                say("  ! pgcrypto not installed — using a local gen_random_bytes() stand-in.");
            }
        }
    }
}

/**
 * Run a SQL file statement by statement, continuing past errors (for schema.sql,
 * whose last lines ALTER tables that only migrations create).
 */
function dev_run_statements(PDO $pdo, string $file, bool $quiet): int {
    $errors = 0;
    foreach (dev_split_sql((string)file_get_contents($file)) as $stmt) {
        try { $pdo->exec($stmt); }
        catch (Throwable $e) { $errors++; if (!$quiet) say('  ! ' . strtok($e->getMessage(), "\n")); }
    }
    return $errors;
}

/** Split SQL on top-level semicolons (respects quotes, $tag$ bodies and comments). */
function dev_split_sql(string $sql): array {
    $out = []; $buf = ''; $n = strlen($sql); $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        $two = substr($sql, $i, 2);
        if ($two === '--') { $j = strpos($sql, "\n", $i); $j = $j === false ? $n : $j; $buf .= substr($sql, $i, $j - $i); $i = $j; continue; }
        if ($two === '/*') { $j = strpos($sql, '*/', $i + 2); $j = $j === false ? $n : $j + 2; $buf .= substr($sql, $i, $j - $i); $i = $j; continue; }
        if ($c === "'" || $c === '"') {
            $j = $i + 1;
            while ($j < $n) { if ($sql[$j] === $c) { if (($sql[$j + 1] ?? '') === $c) { $j += 2; continue; } break; } $j++; }
            $buf .= substr($sql, $i, $j - $i + 1); $i = $j + 1; continue;
        }
        if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
            $tag = $m[0]; $j = strpos($sql, $tag, $i + strlen($tag));
            $j = $j === false ? $n : $j + strlen($tag);
            $buf .= substr($sql, $i, $j - $i); $i = $j; continue;
        }
        if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; $i++; continue; }
        $buf .= $c; $i++;
    }
    if (trim(preg_replace('/--[^\n]*/', '', $buf)) !== '') $out[] = trim($buf);
    return $out;
}

/**
 * Apply every migration not yet recorded as ok, in passes until nothing more
 * succeeds. Each file runs as one implicit transaction (a multi-statement query),
 * so a failure leaves nothing half-applied.
 */
function dev_migrate(PDO $pdo, bool $retryFailed, bool $report = true): void {
    $files = dev_migration_files();
    $done = [];
    foreach ($pdo->query('SELECT name, status, checksum FROM dev_migrations') as $r) $done[$r['name']] = $r;

    $todo = [];
    foreach ($files as $f) {
        $name = basename($f);
        $sum  = md5_file($f);
        $row  = $done[$name] ?? null;
        if ($row && $row['status'] === 'ok') continue;
        if ($row && $row['status'] === 'failed' && !$retryFailed && $row['checksum'] === $sum) continue;
        $todo[$name] = $f;
    }
    if (!$todo) { say('✓ Migrations: nothing new.'); return; }

    say('▶ Applying ' . count($todo) . ' migration(s)…');
    $errors = [];
    do {
        $progress = false;
        foreach ($todo as $name => $f) {
            $sql = preg_replace('/^\s*CREATE\s+EXTENSION[^;]*pgcrypto[^;]*;/mi', '', (string)file_get_contents($f));
            try {
                $pdo->exec($sql);
                dev_ledger_set($pdo, $name, 'ok', md5_file($f), null);
                unset($todo[$name], $errors[$name]);
                $progress = true;
            } catch (Throwable $e) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}   // a file with its own BEGIN left the session aborted
                $errors[$name] = trim(preg_replace('/^SQLSTATE\[[^\]]*\]:[^:]*:\s*\d*\s*/', '', strtok($e->getMessage(), "\n")));
            }
        }
    } while ($progress && $todo);

    foreach ($todo as $name => $f) dev_ledger_set($pdo, $name, 'failed', md5_file($f), $errors[$name] ?? '');
    if (!$report) return;   // first build pass: data migrations wait for the seeds, retried below
    say("✓ Migrations applied. " . ($todo ? count($todo) . " could not run (see below)." : ''));
    foreach ($errors as $name => $msg) say("  · {$name}: " . mb_strimwidth($msg, 0, 160, '…'));
    if ($todo) say("  (Data migrations written for production rows are expected to refuse on seed data. `--retry` tries them again.)");
}

/**
 * Migration files in the order they were added (db/migrations/ORDER.txt); any
 * file missing from that list runs last, alphabetically, with a warning.
 */
function dev_migration_files(): array {
    $dir   = DEV_ROOT . '/db/migrations';
    $all   = [];
    foreach (glob($dir . '/*.sql') ?: [] as $f) $all[basename($f)] = $f;
    $order = [];
    foreach (@file($dir . '/ORDER.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !isset($all[$line])) continue;
        $order[] = $all[$line];
        unset($all[$line]);
    }
    if ($all) {
        ksort($all);
        say('  ! Not listed in db/migrations/ORDER.txt (running last): ' . implode(', ', array_keys($all)));
    }
    return array_merge($order, array_values($all));
}

/**
 * A production snapshot already HAS its migrations — production ran them in
 * order. Replaying them here would be unsafe (add_staff_role would narrow the
 * role constraint again), so every current file is recorded as applied. A
 * migration production has not run yet is applied by hand with --apply,
 * exactly as /admin/migrate.php does it in production.
 */
function dev_adopt_migrations(PDO $pdo): void {
    $s = $pdo->prepare("INSERT INTO dev_migrations (name, status, checksum, error) VALUES (:n, 'ok', :c, 'adopted from imported snapshot')
        ON CONFLICT (name) DO NOTHING");
    foreach (dev_migration_files() as $f) $s->execute([':n' => basename($f), ':c' => md5_file($f)]);
    say('✓ Migrations: recorded as already applied (they came with the snapshot).');
    say('  If production has not run a newer one yet: php bin/dev-setup.php --apply <file>.sql');
}

/** Run ONE migration by name, like /admin/migrate.php does in production. */
function dev_apply_one(PDO $pdo, string $name): void {
    $name = basename($name);
    $path = DEV_ROOT . '/db/migrations/' . $name;
    if ($name === '' || !is_file($path)) { fwrite(STDERR, "No such migration: db/migrations/{$name}
"); exit(1); }
    try {
        $pdo->exec((string)file_get_contents($path));
        dev_ledger_set($pdo, $name, 'ok', md5_file($path), null);
        say("✓ {$name} applied.");
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        dev_ledger_set($pdo, $name, 'failed', md5_file($path), strtok($e->getMessage(), "
"));
        fwrite(STDERR, "✗ {$name}: " . strtok($e->getMessage(), "
") . "
");
        exit(1);
    }
}

function dev_ledger_set(PDO $pdo, string $name, string $status, string $sum, ?string $err): void {
    $s = $pdo->prepare('INSERT INTO dev_migrations (name, status, checksum, error) VALUES (:n, :s, :c, :e)
        ON CONFLICT (name) DO UPDATE SET status = EXCLUDED.status, checksum = EXCLUDED.checksum, error = EXCLUDED.error, applied_at = NOW()');
    $s->execute([':n' => $name, ':s' => $status, ':c' => $sum, ':e' => $err]);
}

function dev_sql_seeds(PDO $pdo): void {
    say('▶ Loading demo data (properties, rooms, activities, photos)…');
    foreach (['seed_tribalsand.sql', 'seed_rooms_2026.sql', 'seed_activities.sql', 'seed_venue_images.sql'] as $f) {
        $errs = dev_run_statements($pdo, DEV_ROOT . "/db/{$f}", true);
        say("  · {$f}" . ($errs ? " ({$errs} statement(s) skipped)" : ''));
    }
}

/** The repo's own PHP seeders, each in its own process (they are CLI scripts that may exit()). */
function dev_php_seeds(): void {
    say('▶ Running seeders (menus, site menu, reviews, POS, staff, inventory order)…');
    $php = PHP_BINARY;
    foreach (['seed_nav_menu', 'seed_menus', 'seed_reviews', 'seed_venue_content', 'seed_offers',
              'seed_pos', 'seed_hr_staff', 'seed_maya_ilai_shipment'] as $s) {
        $path = DEV_ROOT . "/db/seeds/{$s}.php";
        if (!is_file($path)) continue;
        $out = []; $rc = 0;
        exec(escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        say('  · ' . $s . ($rc === 0 ? '' : '  (failed: ' . mb_strimwidth(trim((string)end($out)), 0, 120, '…') . ')'));
    }
}

/**
 * Local test logins, one per kind of account (owner, manager, reception, front desk,
 * housekeeping, gate security, shop till). Passwords come from DEV_ADMIN_PASSWORD
 * (see .env.example); they only ever exist in the local database.
 */
function dev_accounts(PDO $pdo): void {
    $env  = parse_env();
    $pw   = (string)($env['DEV_ADMIN_PASSWORD'] ?? '');
    if (strlen($pw) < 8) {
        say('  ! DEV_ADMIN_PASSWORD is not set (min 8 chars) — no test logins created. See .env.example.');
        return;
    }
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $up = $pdo->prepare("INSERT INTO admin_users (email, name, password_hash, role, job_type, is_active)
        VALUES (:e, :n, :h, :r, :j, TRUE)
        ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash, role = EXCLUDED.role,
            job_type = EXCLUDED.job_type, is_active = TRUE
        RETURNING id");
    $ids = [];
    // [email, name, role, job type] — one login per kind of account the admin treats differently.
    $accounts = [['owner@tribalsand.test', 'Dev Owner', 'owner', null],
                 ['manager@tribalsand.test', 'Dev Manager', 'manager', null],
                 ['reception@tribalsand.test', 'Dev Reception', 'reception', null],
                 ['frontdesk@tribalsand.test', 'Dev Front Desk', 'staff', 'frontdesk'],
                 ['housekeeping@tribalsand.test', 'Dev Housekeeping', 'staff', 'housekeeping'],
                 ['security@tribalsand.test', 'Dev Security', 'staff', 'security'],
                 ['shop@tribalsand.test', 'Dev Shop', 'staff', 'shop']];
    foreach ($accounts as [$e, $n, $r, $j]) {
        try {
            $up->execute([':e' => $e, ':n' => $n, ':h' => $hash, ':r' => $r, ':j' => $j]);
            $ids[strtok($e, '@')] = (int)$up->fetchColumn();
        } catch (Throwable $ex) {
            // e.g. the 'shop' job type before add_pos_job_types.sql has run
            say('  ! ' . $e . ' not created: ' . strtok($ex->getMessage(), "\n"));
        }
    }
    // Everyone but the owner is scoped to the first property, so venue scoping can be tested.
    if (dev_table_exists($pdo, 'admin_user_venues')) {
        $venue = $pdo->query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
        if ($venue) {
            foreach ($ids as $who => $id) {
                if ($who === 'owner') continue;
                $pdo->prepare('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a, :v) ON CONFLICT DO NOTHING')
                    ->execute([':a' => $id, ':v' => $venue]);
            }
        }
    }
    // The shop login sells at the first outlet (that is what makes "Open till" appear).
    if (isset($ids['shop']) && dev_table_exists($pdo, 'pos_outlet_staff')) {
        try {
            $outlet = $pdo->query('SELECT id FROM pos_outlets ORDER BY id LIMIT 1')->fetchColumn();
            if ($outlet) {
                $pdo->prepare('INSERT INTO pos_outlet_staff (outlet_id, admin_user_id) VALUES (:o, :a) ON CONFLICT DO NOTHING')
                    ->execute([':o' => $outlet, ':a' => $ids['shop']]);
            }
        } catch (Throwable $ex) { say('  ! shop login not assigned to an outlet: ' . strtok($ex->getMessage(), "\n")); }
    }
    say('✓ Test logins ready (' . count($ids) . '): ' . implode(', ', array_keys($ids)) . ' @tribalsand.test — password = DEV_ADMIN_PASSWORD.');
}

/**
 * Remove personal data from an imported production copy. Generic by column
 * name/type so new tables are covered without editing this list:
 *   emails → stable fakes (same address ⇒ same fake, so joins still line up),
 *   phones / ID numbers / guest names → placeholders, secrets & tokens → random,
 *   IPs cleared, signatures and stored email bodies emptied, and any email or
 *   +phone inside JSON or free text replaced. Every login's password becomes
 *   DEV_ADMIN_PASSWORD. Staff login emails are kept so people recognise accounts.
 */
function dev_sanitize(PDO $pdo): void {
    say('▶ Scrubbing personal data…');
    $cols = $pdo->query("SELECT c.table_name, c.column_name, c.data_type, c.is_nullable
        FROM information_schema.columns c
        JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
        WHERE c.table_schema = 'public' AND c.table_name <> 'dev_migrations'
          AND c.data_type IN ('text', 'character varying', 'json', 'jsonb')
        ORDER BY c.table_name, c.ordinal_position")->fetchAll(PDO::FETCH_ASSOC);

    $pw   = (string)(parse_env()['DEV_ADMIN_PASSWORD'] ?? 'change-me-locally');
    $hash = $pdo->quote(password_hash($pw, PASSWORD_DEFAULT));
    $emailRe = "'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}'";
    $phoneRe = "'\\+[0-9][0-9 ()-]{7,}[0-9]'";
    $keepEmailTables = ['admin_users'];
    $touched = 0;

    foreach ($cols as $c) {
        $t = $c['table_name']; $col = $c['column_name']; $type = $c['data_type'];
        $q = '"' . str_replace('"', '""', $t) . '"'; $qc = '"' . str_replace('"', '""', $col) . '"';
        $blank = $c['is_nullable'] === 'YES' ? 'NULL' : "''";
        $lc = strtolower($col);
        $set = null;

        if ($type === 'json' || $type === 'jsonb') {
            $set = "{$qc} = regexp_replace(regexp_replace({$qc}::text, {$emailRe}, 'redacted@example.test', 'g'), {$phoneRe}, '+254700000000', 'g')::{$type}";
        } elseif ($lc === 'password_hash' || $lc === 'pos_pin_hash') {
            $set = $lc === 'password_hash' ? "{$qc} = {$hash}" : "{$qc} = NULL";
            if ($lc === 'pos_pin_hash' && $c['is_nullable'] !== 'YES') $set = null;
        } elseif (preg_match('/(^|_)email(_address)?$/', $lc) && in_array($t, $keepEmailTables, true)) {
            continue;   // staff logins keep their address so people recognise their accounts
        } elseif (preg_match('/(^|_)email(_address)?$/', $lc)) {
            $set = "{$qc} = CASE WHEN {$qc} IS NULL OR {$qc} = '' THEN {$qc} ELSE 'u' || substr(md5(lower({$qc})), 1, 12) || '@example.test' END";
        } elseif (preg_match('/phone|mobile|whatsapp/', $lc)) {
            $set = "{$qc} = CASE WHEN {$qc} IS NULL OR {$qc} = '' THEN {$qc} ELSE '+254700000000' END";
        } elseif (preg_match('/passport|national_id|id_number|id_no$/', $lc) && !preg_match('/(file|key|expiry|path)/', $lc)) {
            $set = "{$qc} = CASE WHEN {$qc} IS NULL OR {$qc} = '' THEN {$qc} ELSE 'REDACTED' END";
        } elseif (preg_match('/^(guest|first|last|full|traveller|customer|contact|waiver_signed)_name$|^waiver_name_snapshot$/', $lc)) {
            $set = "{$qc} = CASE WHEN {$qc} IS NULL OR {$qc} = '' THEN {$qc} ELSE 'Guest ' || substr(md5({$qc}), 1, 5) END";
        } elseif (preg_match('/(^|_)(ip|client_ip|ip_address)$/', $lc)) {
            $set = "{$qc} = {$blank}";
        } elseif (preg_match('/signature/', $lc) && !preg_match('/(_at|method|version|supported)$/', $lc)) {
            $set = "{$qc} = CASE WHEN {$qc} IS NULL THEN NULL ELSE '' END";
        } elseif (preg_match('/(token|secret)/', $lc) && !preg_match('/(_at|expires|type)$/', $lc)) {
            $set = "{$qc} = CASE WHEN {$qc} IS NULL OR {$qc} = '' THEN {$qc} ELSE md5(random()::text || clock_timestamp()::text) END";
        } elseif ($t === 'email_log' && preg_match('/body|html|text/', $lc)) {
            $set = "{$qc} = {$blank}";
        } else {
            // Free text (messages, notes, payloads stored as text): scrub embedded addresses/phones.
            $set = "{$qc} = regexp_replace(regexp_replace({$qc}, {$emailRe}, 'redacted@example.test', 'g'), {$phoneRe}, '+254700000000', 'g')";
            $where = " WHERE {$qc} ~ {$emailRe} OR {$qc} ~ {$phoneRe}";
        }
        if ($set === null) continue;
        try {
            $pdo->exec("UPDATE {$q} SET {$set}" . ($where ?? ''));
            $touched++;
        } catch (Throwable $e) {
            say("  ! {$t}.{$col}: " . strtok($e->getMessage(), "\n"));
        }
        unset($where);
    }

    // Secrets kept in the settings table (API keys, reset tokens…).
    if (dev_table_exists($pdo, 'settings')) {
        $pdo->exec("DELETE FROM settings WHERE setting_key ILIKE 'pwd_reset_%'");
        $pdo->exec("UPDATE settings SET setting_value = '' WHERE setting_key ~* '(secret|api_key|apikey|password|token)'");
    }
    say("✓ Personal data scrubbed ({$touched} columns checked). Logins now use DEV_ADMIN_PASSWORD.");
}

function dev_reset(PDO $pdo): void {
    say('▶ Resetting the local database (dropping every table)…');
    $pdo->exec('DROP SCHEMA public CASCADE');
    $pdo->exec('CREATE SCHEMA public');
    dev_ledger_ensure($pdo);
}

function dev_status(PDO $pdo): void {
    dev_ledger_ensure($pdo);
    $done = [];
    foreach ($pdo->query('SELECT name, status, error FROM dev_migrations') as $r) $done[$r['name']] = $r;
    $pending = $failed = 0;
    foreach (glob(DEV_ROOT . '/db/migrations/*.sql') ?: [] as $f) {
        $n = basename($f); $r = $done[$n] ?? null;
        if (!$r) { $pending++; say("  pending  {$n}"); }
        elseif ($r['status'] === 'failed') { $failed++; say("  failed   {$n} — " . mb_strimwidth((string)$r['error'], 0, 120, '…')); }
    }
    say("Pending: {$pending}   Failed: {$failed}   (everything else is applied)");
}

function dev_summary(PDO $pdo): void {
    $count = fn(string $t) => dev_table_exists($pdo, $t) ? (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() : 0;
    $url = rtrim((string)(parse_env()['APP_URL'] ?? 'http://localhost:8080'), '/');
    say(sprintf("\n✓ Database ready — %d properties, %d rooms, %d bookings.", $count('venues'), $count('rooms'), $count('holds')));
    say("  Site:  {$url}/");
    say("  Admin: {$url}/admin/login.php");
}
