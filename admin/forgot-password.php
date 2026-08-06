<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';

session_start();

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $admin = db_query(
                'SELECT id, email FROM admin_users WHERE email = :email',
                [':email' => $email]
            )->fetch();
        } catch (Throwable $e) {
            $admin = false;
        }

        // Always show success — don't reveal whether email exists
        if ($admin) {
            $token     = bin2hex(random_bytes(32));
            $key       = 'pwd_reset_' . md5($email);
            $payload   = json_encode(['token' => $token, 'expires' => time() + 3600]);

            try {
                set_setting($key, $payload);

                $env      = parse_env();
                $from     = $env['MAIL_FROM'] ?? ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'tribalsand.com'));
                $site_url = site_url();
                $reset_url = $site_url . '/admin/reset-password.php?token=' . urlencode($token) . '&email=' . urlencode($email);

                $subject = 'Tribal Sand Admin — Password Reset';
                $body    = "You requested a password reset for the Tribal Sand admin panel.\n\n"
                         . "Click the link below to set a new password. This link expires in 1 hour.\n\n"
                         . $reset_url . "\n\n"
                         . "If you did not request this, you can safely ignore this email.\n\n"
                         . "— Tribal Sand";

                _dispatch_mail($email, $subject, $body, $from, $from, $env);
            } catch (Throwable $e) {
                // Log but don't expose error to user
                error_log('Password reset mail error: ' . $e->getMessage());
            }
        }

        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password — Tribal Sand Admin</title>
  <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="login-page">

  <div class="login-box">
    <div class="login-logo">
      <img src="/images/whitelogo11.png" alt="Tribal Sand">
    </div>
    <h1 class="login-title">Reset Password</h1>

    <?php if ($sent): ?>
      <div class="alert alert--success">
        If that email is registered, a reset link has been sent. Check your inbox (and spam folder).
      </div>
      <p class="login-back" style="text-align:center;margin-top:1.5rem;">
        <a href="/admin/login.php">← Back to login</a>
      </p>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
      <?php endif; ?>
      <p style="font-size:.85rem;color:#6B6050;margin-bottom:1.5rem;line-height:1.6;">
        Enter your admin email address and we'll send you a link to reset your password.
      </p>
      <form method="POST" action="/admin/forgot-password.php" novalidate>
        <div class="field">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email"
                 value="<?= e($_POST['email'] ?? '') ?>"
                 placeholder="admin@tribalsand.com" required autofocus>
        </div>
        <button type="submit" class="btn-primary btn-full">Send Reset Link</button>
      </form>
      <p class="login-back"><a href="/admin/login.php">← Back to login</a></p>
    <?php endif; ?>
  </div>

</body>
</html>
