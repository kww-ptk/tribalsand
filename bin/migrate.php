<?php
/**
 * Simple migration runner.
 *
 * Usage:
 *   php bin/migrate.php                              # runs all .sql files in db/migrations/
 *   php bin/migrate.php db/migrations/add_tours.sql  # runs one specific file
 *
 * Uses the same DB connection as the app (reads .env or DATABASE_URL env var).
 * Works locally and on Render (via the web service Shell tab).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$arg = $argv[1] ?? null;

if ($arg) {
    // Single file mode
    $files = [realpath($arg)];
    if (!$files[0] || !file_exists($files[0])) {
        fwrite(STDERR, "Error: File not found: {$arg}\n");
        exit(1);
    }
} else {
    // All migrations mode
    $dir = __DIR__ . '/../db/migrations';
    if (!is_dir($dir)) {
        fwrite(STDERR, "Error: Migrations directory not found: {$dir}\n");
        exit(1);
    }
    $files = glob($dir . '/*.sql');
    sort($files);
    if (!$files) {
        echo "No migration files found in db/migrations/\n";
        exit(0);
    }
}

echo "Running " . count($files) . " migration(s)...\n\n";

foreach ($files as $file) {
    $name = basename($file);
    echo "→ {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "FAILED (could not read file)\n";
        exit(1);
    }

    try {
        db()->exec($sql);
        echo "OK\n";
    } catch (PDOException $e) {
        echo "FAILED\n";
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nAll migrations completed successfully.\n";
