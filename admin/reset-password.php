<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

session_start();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));

$error   = '';
$success = false;
$valid   = false;

// ── Validate token ───────────────────────────────────────────────────────────
if ($token && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $key     = 'pwd_reset_' . md5($email);
    $payload = null;

    try {
        $raw = setting($key);
        if ($raw) {
            $payload = json_decode($raw, true);
        }
    } catch (Throwable $e) {
        $payload = null;
    }

    if (
        $payload &&
        isset($payload['token'], $payload['expires']) &&
        hash_equals($payload['token'], $token) &&
        $payload['expires'] > time()
    ) {
        $valid = true;
    }
}

if (!$valid && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Don't show the form if the link is invalid/expired
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

// ── Handle form submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);

            db_query(
                'UPDATE admin_users SET password_hash = :hash WHERE email = :email',
                [':hash' => $hash, ':email' => $email]
            );

            // Invalidate the token
            set_setting('pwd_reset_' . md5($email), '');

            $success = true;
        } catch (Throwable $e) {
            error_log('Password reset update error: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again or contact support.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password — Tribal Sand Admin</title>
  <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="login-page">

  <div class="login-box">
    <div class="login-logo">
      <img src="/images/whitelogo11.png" alt="Tribal Sand">
    </div>
    <h1 class="login-title">New Password</h1>

    <?php if ($success): ?>
      <div class="alert alert--success">
        Your password has been updated. You can now log in.
      </div>
      <p style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login.php" class="btn-primary" style="display:inline-block;text-decoration:none;">Go to Login</a>
      </p>

    <?php elseif (!$valid): ?>
      <div class="alert alert--error"><?= e($error) ?></div>
      <p class="login-back"><a href="/admin/forgot-password.php">← Request a new link</a></p>

    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="POST" action="/admin/reset-password.php" novalidate>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <div class="field">
          <label for="password">New password <small>(min. 10 chars)</small></label>
          <input type="password" id="password" name="password" required autofocus minlength="10" placeholder="Enter new password">
        </div>
        <div class="field">
          <label for="password2">Confirm new password</label>
          <input type="password" id="password2" name="password2" required minlength="10" placeholder="Re-enter new password">
        </div>
        <button type="submit" class="btn-primary btn-full">Set New Password</button>
      </form>
      <p class="login-back"><a href="/admin/login.php">← Back to login</a></p>
    <?php endif; ?>
  </div>

</body>
</html>
