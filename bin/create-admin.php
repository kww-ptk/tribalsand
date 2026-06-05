<?php
declare(strict_types=1);

// Usage: php bin/create-admin.php <email> <password>
if (PHP_SAPI !== 'cli') exit('CLI only.');

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <email> <password>\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

$email    = trim($argv[1]);
$password = trim($argv[2]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

db_query(
    'INSERT INTO admin_users (email, password_hash) VALUES (:email, :hash)
     ON CONFLICT (email) DO UPDATE SET password_hash = :hash',
    [':email' => $email, ':hash' => $hash]
);

echo "Admin user created: {$email}\n";
