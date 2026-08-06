<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

session_init();

// Already logged in
if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . admin_home_url());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['do'] ?? '') === 'staff') {
        // Onsite staff — access-code sign-in. Land on the job-appropriate home.
        if (login_staff($_POST['staff_code'] ?? '', client_ip())) {
            header('Location: ' . admin_home_url());
            exit;
        }
        $error = 'Invalid or inactive staff code.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$password) {
            $error = 'Email and password are required.';
        } elseif (is_rate_limited($email, client_ip())) {
            $error = 'Too many failed attempts. Please wait 10 minutes and try again.';
        } elseif (login($email, $password)) {
            header('Location: ' . admin_home_url());
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — Tribal Sand</title>
  <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="login-page">

  <div class="login-box">
    <div class="login-logo">
      <img src="/images/whitelogo11.png" alt="Tribal Sand">
    </div>
    <h1 class="login-title">Admin Login</h1>

    <?php if ($error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/login" novalidate>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email"
               value="<?= e($_POST['email'] ?? '') ?>"
               placeholder="admin@example.com" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-primary btn-full">Sign in</button>
    </form>

    <p class="login-back" style="text-align:center;margin-top:1rem;">
      <a href="/admin/forgot-password.php" style="font-size:.8rem;color:#6B6050;">Forgot password?</a>
    </p>

    <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5e0d6">
    <form method="POST" action="/admin/login" novalidate>
      <input type="hidden" name="do" value="staff">
      <div class="field">
        <label for="staff_code">Onsite staff — access code</label>
        <input type="text" id="staff_code" name="staff_code" placeholder="Staff access code"
               autocomplete="off" style="text-transform:uppercase" required>
      </div>
      <button type="submit" class="btn-outline btn-full">Staff sign in</button>
    </form>

    <p class="login-back"><a href="/">← Back to website</a></p>
  </div>

</body>
</html>
