<?php
declare(strict_types=1);

// ONE-TIME setup — DELETE THIS FILE after use
define('SETUP_TOKEN', 'ts-setup-2026');

require_once __DIR__ . '/includes/db.php';

$error   = '';
$success = '';
$migrate_log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $action   = $_POST['action'] ?? '';

    if ($token !== SETUP_TOKEN) {
        $error = 'Invalid setup token.';
    } elseif ($action === 'migrate') {
        // Run schema + all migrations
        $files = [__DIR__ . '/db/schema.sql'];
        foreach (glob(__DIR__ . '/db/migrations/*.sql') as $f) {
            $files[] = $f;
        }
        foreach ($files as $path) {
            try {
                db()->exec(file_get_contents($path));
                $migrate_log[] = '✓ ' . basename($path);
            } catch (PDOException $e) {
                $migrate_log[] = '✗ ' . basename($path) . ': ' . $e->getMessage();
            }
        }
        $success = 'Migrations complete.';
    } elseif ($action === 'create') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
            $success = "Admin user created: {$email}. Please delete this file now!";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Tribal Sand Setup</title>
<style>
body{font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 20px}
input{width:100%;padding:8px;margin:6px 0 14px;box-sizing:border-box;border:1px solid #ccc;border-radius:4px}
button{width:100%;padding:10px;background:#1E5C6B;color:#fff;border:none;border-radius:4px;cursor:pointer;margin-bottom:8px}
button.sec{background:#555}
.error{color:red}.success{color:green}
pre{background:#f4f4f4;padding:12px;border-radius:4px;font-size:12px;white-space:pre-wrap}
</style>
</head>
<body>
<h2>Tribal Sand — One-Time Setup</h2>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php if ($migrate_log): ?><pre><?= htmlspecialchars(implode("\n", $migrate_log)) ?></pre><?php endif; ?>

<form method="POST">
    <label>Setup Token</label>
    <input type="password" name="token" required>

    <hr style="margin:16px 0">
    <strong>Step 1 — Run Migrations</strong>
    <button type="submit" name="action" value="migrate" class="sec">Run Schema &amp; Migrations</button>

    <hr style="margin:16px 0">
    <strong>Step 2 — Create Admin User</strong>
    <label>Email</label>
    <input type="email" name="email">
    <label>Password (min 8 chars)</label>
    <input type="password" name="password">
    <button type="submit" name="action" value="create">Create Admin</button>
</form>
</body>
</html>
