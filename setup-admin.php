<?php
declare(strict_types=1);

// ONE-TIME admin setup — DELETE THIS FILE after use
define('SETUP_TOKEN', 'ts-setup-2026');

require_once __DIR__ . '/includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($token !== SETUP_TOKEN) {
        $error = 'Invalid setup token.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db_query(
            'INSERT INTO admin_users (email, password_hash) VALUES (:email, :hash)
             ON CONFLICT (email) DO UPDATE SET password_hash = :hash',
            [':email' => $email, ':hash' => $hash]
        );
        $success = "Admin user created: {$email}. Please delete this file now.";
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Admin Setup</title>
<style>body{font-family:sans-serif;max-width:400px;margin:80px auto;padding:0 20px}
input{width:100%;padding:8px;margin:6px 0 14px;box-sizing:border-box;border:1px solid #ccc;border-radius:4px}
button{width:100%;padding:10px;background:#1E5C6B;color:#fff;border:none;border-radius:4px;cursor:pointer}
.error{color:red}.success{color:green}</style>
</head>
<body>
<h2>Tribal Sand — Admin Setup</h2>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="success"><?= htmlspecialchars($success) ?></p><?php else: ?>
<form method="POST">
    <label>Setup Token</label>
    <input type="password" name="token" required>
    <label>Admin Email</label>
    <input type="email" name="email" required>
    <label>Password (min 8 chars)</label>
    <input type="password" name="password" required>
    <button type="submit">Create Admin</button>
</form>
<?php endif; ?>
</body>
</html>
