<?php
/**
 * Zuri Restaurant — booking page (piece B, minimal).
 *
 * Deliberately plain: the point of piece B is that Zuri is genuinely bookable.
 * Piece C replaces the copy, layout and imagery around the same widget.
 * Served at /zuri-restaurant by the strip-.php rule in .htaccess.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/turnstile.php';   // captcha_site_key() is used below
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Book a Table · Zuri Restaurant · Watamu · Tribal Sand</title>
<meta name="description" content="Book a table at Zuri Restaurant in Watamu — Mediterranean, Italian and Kenyan coastal cooking. Perfect for romantic dinners and special occasions.">
<link rel="canonical" href="https://tribalsand.com/zuri-restaurant">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/datepicker.css?v=<?= filemtime(__DIR__ . '/css/datepicker.css') ?>">
<script src="/js/datepicker.js?v=<?= filemtime(__DIR__ . '/js/datepicker.js') ?>" defer></script>
<style>
:root{--sand:#B8965A;--teal:#1E5C6B;--teal-d:#102F3A;--dark:#141412;--off:#FAF8F4;--cream:#F5EFE3;--light:#8C7A60;--border:rgba(184,150,90,.22)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased}
.zr-head{background:var(--cream);text-align:center;padding:3rem 1.5rem 2.4rem;border-bottom:1px solid var(--border)}
.zr-logo{font-family:'Cormorant Garamond',serif;font-size:2.6rem;font-weight:300;color:var(--teal-d);line-height:1}
.zr-sub{font-size:.68rem;letter-spacing:.35em;text-transform:uppercase;color:var(--sand);margin:.4rem 0 1rem}
.zr-tag{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.1rem;color:var(--light);line-height:1.7;max-width:420px;margin:0 auto}
.zr-links{margin-top:1.2rem;font-size:.8rem}
.zr-links a{color:var(--teal);text-decoration:underline}
.zr-main{max-width:640px;margin:0 auto;padding:2.4rem 1.5rem 3.5rem}
.zr-h2{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:1.9rem;color:var(--teal-d);text-align:center;margin-bottom:.4rem}
.zr-lead{text-align:center;font-size:.92rem;color:var(--light);line-height:1.7;margin-bottom:2rem}
.zr-foot{background:var(--teal-d);color:rgba(255,255,255,.55);text-align:center;padding:2rem 1.5rem;font-size:.78rem}
.zr-foot a{color:rgba(184,150,90,.75)}
</style>
</head>
<body>

<header class="zr-head">
  <div class="zr-logo">Zuri</div>
  <div class="zr-sub">Restaurant &middot; Watamu</div>
  <p class="zr-tag">Mediterranean simplicity, Italian craftsmanship, Indian traditions and the richness of the Kenyan coast.</p>
  <p class="zr-links"><a href="/zuri-menu">View the menu &rarr;</a></p>
</header>

<main class="zr-main">
  <h1 class="zr-h2">Book a table</h1>
  <p class="zr-lead">Open to house guests and outside visitors alike — and well suited to anniversaries, birthdays and quiet romantic dinners by the coast.</p>
  <?php include __DIR__ . '/includes/form-restaurant.php'; ?>
</main>

<footer class="zr-foot">
  Zuri is part of the Tribal Sand collection.<br>
  <a href="https://tribalsand.com">tribalsand.com</a> &middot; reservations@tribalsand.com
</footer>

<?php if (captcha_site_key()): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</body>
</html>
