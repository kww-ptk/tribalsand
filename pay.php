<?php
/**
 * The guest's payment page for a payment link — /pay?l=<token> (noindex).
 * Shows what is being paid and to which company, then sends the guest to
 * Paystack's checkout (M-Pesa / card). Paystack sends them back here with
 * &back=1, and we ASK Paystack whether it was paid (no webhook — see
 * includes/paystack.php). Practice links (test servers only) show a pretend
 * "Pay" button instead. Logic: includes/pay-links.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';      // csrf_field(), session
require_once __DIR__ . '/includes/pay-links.php';

session_init();   // before any output: the Pay form carries a CSRF token, errors ride the session
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['l'] ?? $_POST['l'] ?? '');
$link  = paystack_supported() ? pay_link_by_token($token) : false;
$error = '';

if ($link && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'start') {
            $url = pay_link_start($link);
            if ($url !== '') { header('Location: ' . $url, true, 303); exit; }
        }
        if ($act === 'practice') {
            if ($link['mode'] !== 'practice' || !paystack_practice_allowed()) throw new PayLinkRefusal('This link can’t be paid that way.');
            if (pay_link_effective_status($link) !== 'waiting') throw new PayLinkRefusal('This link can no longer be paid.');
            pay_link_mark_paid((int)$link['id'], ['channel' => 'practice', 'txn_id' => null, 'paid_at' => null]);
        }
    } catch (PayLinkRefusal $e) {
        $_SESSION['pay_err'] = $e->getMessage();
    }
    header('Location: ' . pay_link_url($token), true, 303); exit;
}
$error = (string)($_SESSION['pay_err'] ?? ''); unset($_SESSION['pay_err']);

// Back from Paystack (or simply looking again): ask Paystack where it stands.
if ($link && in_array($link['status'], ['waiting', 'expired'], true) && !empty($link['started_at'])) {
    try { $link = pay_link_check((int)$link['id'], isset($_GET['back'])) ?: $link; } catch (Throwable $e) { error_log('[pay] check failed: ' . $e->getMessage()); }
}
$status   = $link ? pay_link_effective_status($link) : 'missing';
$company  = $link ? (trim((string)$link['company_legal_name']) ?: (string)$link['company_name']) : '';
$back     = isset($_GET['back']) && $status === 'waiting';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $link ? 'Pay ' . e(pay_link_money($link['amount'], (string)$link['currency'])) . ' · Tribal Sand' : 'Payment link · Tribal Sand' ?></title>
<link rel="icon" href="/images/favicon.png">
<style>
:root{--teal:#0f5f5c;--teal-2:#0b4a47;--ink:#1d2a2a;--muted:#657575;--line:#e4e9e9;--bg:#f3f1ec;--ok:#1f7a3f;--ok-bg:#e7f4ea;--warn:#8a5a00;--warn-bg:#fdf2dc;--red:#b42318;--red-bg:#fdecea}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;display:flex;justify-content:center;padding:28px 16px}
.pay{width:100%;max-width:420px}
.brand{text-align:center;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:0 0 18px}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px 22px}
.co{font-size:13px;color:var(--muted);margin:0}
h1{font-size:20px;font-weight:600;margin:4px 0 2px}
.for{margin:0;color:var(--muted)}
.amt{font-size:34px;font-weight:700;letter-spacing:-.01em;margin:18px 0 4px}
.who{font-size:13px;color:var(--muted);margin:0 0 20px}
.btn{display:block;width:100%;border:0;border-radius:12px;padding:15px 16px;font:inherit;font-weight:600;font-size:16px;cursor:pointer;text-align:center;text-decoration:none}
.btn--p{background:var(--teal);color:#fff}.btn--p:hover{background:var(--teal-2)}
.btn--o{background:#fff;color:var(--ink);border:1px solid var(--line);margin-top:10px}
.ways{display:flex;justify-content:center;gap:8px;margin:12px 0 0;font-size:12px;color:var(--muted)}
.ways span{border:1px solid var(--line);border-radius:999px;padding:3px 10px}
.state{border-radius:12px;padding:14px;margin:0 0 16px;font-size:14px}
.state--ok{background:var(--ok-bg);color:var(--ok)}.state--warn{background:var(--warn-bg);color:var(--warn)}.state--bad{background:var(--red-bg);color:var(--red)}
.state b{display:block;font-size:16px;margin-bottom:2px}
.practice{background:var(--warn-bg);color:var(--warn);border-radius:10px;padding:10px 12px;font-size:13px;margin:0 0 14px}
.foot{text-align:center;font-size:12px;color:var(--muted);margin:16px 0 0}
.ref{font-family:ui-monospace,Consolas,monospace;font-size:12px}
</style>
</head>
<body>
<main class="pay">
  <p class="brand">Tribal Sand</p>
  <div class="card">
  <?php if (!$link): ?>
    <div class="state state--bad"><b>Link not found</b>Please check the link you were sent, or contact us.</div>
  <?php else: ?>
    <p class="co"><?= e($company) ?></p>
    <h1><?= e((string)$link['description']) ?></h1>
    <?php if (trim((string)$link['customer_name']) !== ''): ?><p class="for">For <?= e((string)$link['customer_name']) ?></p><?php endif; ?>
    <div class="amt"><?= e(pay_link_money($link['amount'], (string)$link['currency'])) ?></div>
    <p class="who">Reference <span class="ref"><?= e(pay_link_number((int)$link['id'])) ?></span></p>

    <?php if ($error !== ''): ?><div class="state state--bad"><?= e($error) ?></div><?php endif; ?>

    <?php if ($status === 'paid'): ?>
      <div class="state state--ok"><b>Paid — thank you</b>We received your payment<?= !empty($link['paid_at']) ? ' on ' . e(date('j M Y, H:i', strtotime((string)$link['paid_at']))) : '' ?>. You can close this page.</div>
    <?php elseif ($status === 'cancelled'): ?>
      <div class="state state--bad"><b>This link was cancelled</b>Please contact us if you still need to pay.</div>
    <?php elseif ($status === 'expired'): ?>
      <div class="state state--warn"><b>This link has expired</b>Please ask us for a new one.</div>
    <?php else: ?>
      <?php if ($back): ?><div class="state state--warn"><b>Not paid yet</b>If you completed the payment, it can take a minute to show — refresh this page.</div><?php endif; ?>
      <?php if ($link['mode'] === 'practice'): ?>
        <p class="practice">Practice link — no real money moves. Press Pay to pretend the guest paid.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="l" value="<?= e($token) ?>"><input type="hidden" name="action" value="practice">
          <button class="btn btn--p" type="submit">Pay <?= e(pay_link_money($link['amount'], (string)$link['currency'])) ?> (practice)</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="l" value="<?= e($token) ?>"><input type="hidden" name="action" value="start">
          <button class="btn btn--p" type="submit">Pay <?= e(pay_link_money($link['amount'], (string)$link['currency'])) ?></button></form>
        <div class="ways"><span>M-Pesa</span><span>Card</span></div>
        <?php if ($back): ?><a class="btn btn--o" href="<?= e(pay_link_url($token) . '&back=1') ?>">Check again</a><?php endif; ?>
      <?php endif; ?>
      <p class="foot">This link is valid until <?= e(date('j M Y, H:i', strtotime((string)$link['expires_at']))) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
  </div>
  <p class="foot">🔒 Payments are processed securely by Paystack<?= $link ? ' and paid to ' . e($company) : '' ?>.</p>
</main>
</body>
</html>
