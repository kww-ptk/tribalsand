<?php
declare(strict_types=1);
/**
 * Travel-agent sign-in. Mirrors the admin login convention (rate-limited, no
 * CSRF/Turnstile on the login POST itself) but authenticates against
 * travel_agents and sets the isolated `agent_id` session key.
 */
require_once __DIR__ . '/../includes/agent.php';
session_init();

// Already signed in → straight to the rates.
if (agent_current()) { header('Location: /agent/availability.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!agents_supported()) {
        $error = 'The trade portal is not enabled yet. Please contact us.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } elseif (is_rate_limited(strtolower($email), client_ip())) {
            $error = 'Too many failed attempts. Please wait 10 minutes and try again.';
        } elseif (agent_login($email, $password, client_ip())) {
            header('Location: /agent/availability.php');
            exit;
        } else {
            $error = 'Invalid email or password, or the account is inactive.';
        }
    }
}

$agentPageTitle = 'Trade sign in';
include __DIR__ . '/_layout.php';
?>
<div class="tp-login">
  <h1>Travel trade portal</h1>
  <p class="tp-sub">Sign in to check live availability, see your agreed rates and request bookings across all Tribal Sand properties.</p>

  <div class="card"><div class="card__body card__body--pad">
    <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

    <form method="POST" action="/agent/login.php" novalidate>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" class="inp" value="<?= e($_POST['email'] ?? '') ?>" required autofocus style="width:100%">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="inp" required style="width:100%">
      </div>
      <button type="submit" class="btn-primary" style="width:100%">Sign in</button>
    </form>
  </div></div>
  <p class="tp-sub" style="margin-top:16px"><a href="/">← Back to tribalsand.com</a></p>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
