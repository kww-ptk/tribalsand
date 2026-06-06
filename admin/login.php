<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

session_init();

// Already logged in
if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $tsToken  = $_POST['h-captcha-response'] ?? '';

    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } elseif (!verify_captcha($tsToken, client_ip())) {
        $error = 'Security check failed. Please try again.';
    } elseif (is_rate_limited($email, client_ip())) {
        $error = 'Too many failed attempts. Please wait 10 minutes and try again.';
    } elseif (login($email, $password)) {
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — Tribal Sand</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
  <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
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
      <?php if (captcha_site_key()): ?>
      <div class="h-captcha" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:12px 0"></div>
      <?php endif; ?>
      <button type="submit" class="btn-primary btn-full">Sign in</button>
    </form>

    <p class="login-back" style="text-align:center;margin-top:1rem;">
      <a href="/admin/forgot-password.php" style="font-size:.8rem;color:#6B6050;">Forgot password?</a>
    </p>
    <p class="login-back"><a href="/">← Back to website</a></p>
  </div>

</body>
</html>
