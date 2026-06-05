<?php
declare(strict_types=1);

// ONE-TIME admin password reset — DELETE THIS FILE after use
// Access: https://tribalsand.onrender.com/reset-admin.php?key=ts-reset-2026

$SECRET_KEY = 'ts-reset-2026'; // change this if you want extra security

if (($_GET['key'] ?? '') !== $SECRET_KEY) {
    http_response_code(403);
    exit('Forbidden. Add ?key=' . $SECRET_KEY . ' to the URL.');
}

require_once __DIR__ . '/includes/db.php';

$message = '';
$done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Valid email required.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db_query(
            'INSERT INTO admin_users (email, password_hash) VALUES (:email, :hash)
             ON CONFLICT (email) DO UPDATE SET password_hash = :hash',
            [':email' => $email, ':hash' => $hash]
        );
        $message = "✅ Admin account set for <strong>" . htmlspecialchars($email) . "</strong>. <a href='/admin/login'>Go to login →</a><br><br><strong>⚠️ Delete reset-admin.php from your repo now!</strong>";
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Admin — Tribal Sand</title>
  <style>
    body { font-family: sans-serif; max-width: 400px; margin: 80px auto; padding: 0 20px; }
    input { display: block; width: 100%; padding: 8px; margin: 6px 0 16px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; }
    button { background: #1a1a1a; color: #fff; border: none; padding: 10px 20px; border-radius: 4px; font-size: 15px; cursor: pointer; }
    .msg { padding: 10px; border-radius: 4px; margin-bottom: 16px; background: #f0f0f0; }
    .warn { background: #fff3cd; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 13px; }
  </style>
</head>
<body>
  <h2>Admin Password Reset</h2>
  <div class="warn">⚠️ <strong>Delete this file immediately after use.</strong></div>
  <?php if ($message): ?>
    <div class="msg"><?= $message ?></div>
  <?php endif; ?>
  <?php if (!$done): ?>
  <form method="POST" action="?key=<?= htmlspecialchars($SECRET_KEY) ?>">
    <label>Email</label>
    <input type="email" name="email" required placeholder="admin@tribalsand.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    <label>New Password</label>
    <input type="password" name="password" required placeholder="Min 8 characters">
    <label>Confirm Password</label>
    <input type="password" name="confirm" required placeholder="Repeat password">
    <button type="submit">Set Admin Password</button>
  </form>
  <?php endif; ?>
</body>
</html>
