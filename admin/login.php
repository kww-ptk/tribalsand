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
// Which panel opens first: the last-attempted form, else a ?tab= hint, else Admin.
if (($_POST['do'] ?? '') === 'staff' || ($_GET['tab'] ?? '') === 'staff') {
    $activeTab = 'staff';
} else {
    $activeTab = 'admin';
}

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
  <style>
    /* Segmented Admin / Staff switch */
    .login-switch{display:flex;gap:6px;background:#f1ece2;border-radius:999px;padding:4px;margin:0 0 20px}
    .login-switch__btn{flex:1;border:0;background:transparent;padding:9px 12px;border-radius:999px;
      font-size:.9rem;font-weight:600;color:var(--muted,#6b6050);cursor:pointer;transition:all .15s;
      text-align:center;text-decoration:none;line-height:1.2}
    .login-switch__btn:hover{color:var(--brand,#168e86)}
    .login-switch__btn.is-active{background:#fff;color:var(--brand,#168e86);box-shadow:0 1px 3px rgba(0,0,0,.08)}
    .login-switch__btn:focus-visible{outline:2px solid var(--brand,#168e86);outline-offset:2px}
    .login-pane{margin:0}
  </style>
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

    <div class="login-switch" role="tablist" aria-label="Choose login type">
      <a href="?tab=admin" class="login-switch__btn <?= $activeTab === 'admin' ? 'is-active' : '' ?>"
         data-pane="adminPane" role="tab" aria-selected="<?= $activeTab === 'admin' ? 'true' : 'false' ?>">Admin / Manager</a>
      <a href="?tab=staff" class="login-switch__btn <?= $activeTab === 'staff' ? 'is-active' : '' ?>"
         data-pane="staffPane" role="tab" aria-selected="<?= $activeTab === 'staff' ? 'true' : 'false' ?>">Onsite staff</a>
    </div>

    <!-- Admin / Manager / Reception: email + password -->
    <div id="adminPane" class="login-pane"<?= $activeTab === 'admin' ? '' : ' hidden' ?>>
      <form method="POST" action="/admin/login" novalidate>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email"
                 value="<?= e($_POST['email'] ?? '') ?>"
                 placeholder="admin@example.com"<?= $activeTab === 'admin' ? ' autofocus' : '' ?>>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="••••••••">
        </div>
        <button type="submit" class="btn-primary btn-full">Sign in</button>
      </form>
      <p class="login-back" style="text-align:center;margin-top:1rem;">
        <a href="/admin/forgot-password.php" style="font-size:.8rem;color:#6B6050;">Forgot password?</a>
      </p>
    </div>

    <!-- Onsite staff: access code only -->
    <div id="staffPane" class="login-pane"<?= $activeTab === 'staff' ? '' : ' hidden' ?>>
      <form method="POST" action="/admin/login" novalidate>
        <input type="hidden" name="do" value="staff">
        <div class="field">
          <label for="staff_code">Staff access code</label>
          <input type="text" id="staff_code" name="staff_code" placeholder="e.g. 113BF7C7E5AC"
                 autocomplete="off" style="text-transform:uppercase"<?= $activeTab === 'staff' ? ' autofocus' : '' ?>>
        </div>
        <button type="submit" class="btn-outline btn-full">Staff sign in</button>
      </form>
      <p class="login-back" style="text-align:center;margin-top:.9rem;">
        <small style="color:#6B6050;font-size:.78rem;">Use the access code from Admin → Staff → Login accounts. No email or password needed.</small>
      </p>
    </div>

    <p class="login-back"><a href="/">← Back to website</a></p>
  </div>

  <script>
    // Inline switch between the Admin and Staff panes (no reload). The links'
    // ?tab= hrefs are the no-JS fallback.
    (function () {
      var btns  = document.querySelectorAll('.login-switch__btn');
      var panes = document.querySelectorAll('.login-pane');
      btns.forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
          ev.preventDefault();
          var target = btn.getAttribute('data-pane');
          btns.forEach(function (b) {
            var on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          panes.forEach(function (p) { p.hidden = (p.id !== target); });
          var focusable = document.querySelector('#' + target + ' input:not([type=hidden])');
          if (focusable) focusable.focus();
        });
      });
    })();
  </script>
</body>
</html>
