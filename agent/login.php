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
        $password = (string)($_POST['password'] ?? '');   // agent_login() handles stray spaces
        if ($email === '' || trim($password) === '') {
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
$agentBare      = true;   // full-bleed split screen, no top bar
include __DIR__ . '/_layout.php';
$tick = '<svg class="tp-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
?>
<div class="tp-login">
  <section class="tp-login__art">
    <a class="tp-brand" href="/">Tribal Sand <em>TRADE</em></a>
    <div>
      <h2>Your partner desk for Kenya's north coast.</h2>
      <ul>
        <li><?= $tick ?> Live availability across every property</li>
        <li><?= $tick ?> Your agreed trade rate, already applied</li>
        <li><?= $tick ?> Request a booking in one step and track every request</li>
      </ul>
    </div>
    <p class="tp-login__fine">Trade rates are confidential and for booking purposes only.</p>
  </section>

  <section class="tp-login__form">
    <div class="tp-login__box">
      <h1>Welcome back</h1>
      <p class="tp-sub">Sign in to the travel trade portal.</p>
      <?php if ($error): ?><div class="alert alert--error is-flash" role="alert"><?= e($error) ?></div><?php endif; ?>

      <form method="POST" action="/agent/login.php" novalidate>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" class="inp" value="<?= e($_POST['email'] ?? '') ?>" required autofocus autocomplete="username" placeholder="you@agency.com">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="inp" required autocomplete="current-password" placeholder="Enter your password">
        </div>
        <button type="submit" class="tp-btn">Sign in</button>
      </form>
      <p class="tp-login__help">Not a partner yet? <a href="/for-agents">Apply for a trade account</a><br><a href="/">← Back to tribalsand.com</a></p>
    </div>
  </section>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
