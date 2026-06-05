<?php
// Usage: php bin/reset-admin-password.php <email> <new_password>
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db.php';

$email = $argv[1] ?? '';
$pass  = $argv[2] ?? '';

if (!$email || !$pass) {
    fwrite(STDERR, "Usage: php bin/reset-admin-password.php <email> <new_password>\n");
    exit(1);
}

if (strlen($pass) < 8) {
    fwrite(STDERR, "Error: password must be at least 8 characters.\n");
    exit(1);
}

$user = db_query('SELECT id FROM admin_users WHERE email = :e', [':e' => $email])->fetch();
if (!$user) {
    fwrite(STDERR, "Error: no admin user found with email '{$email}'.\n");
    exit(1);
}

db_query(
    'UPDATE admin_users SET password_hash = :hash WHERE email = :email',
    [':hash' => password_hash($pass, PASSWORD_BCRYPT), ':email' => $email]
);

echo "Password reset for {$email}.\n";
