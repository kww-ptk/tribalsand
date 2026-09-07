<?php
/**
 * TRIBAL SAND · Dynamic SEO Head
 * Include at the top of every page (before <body>).
 *
 * Variables to set before including:
 *   $page_title   — full <title> string
 *   $page_desc    — meta description (150-160 chars)
 *   $page_url     — canonical URL (full https://tribalsand.com/…)
 *   $page_image   — OG image (full URL, ideally 1200×630)
 *   $page_type    — OG type, default "website"
 *   $page_schema  — JSON-LD markup string(s) to inject, optional
 *   $page_preload — <link rel="preload"> href for hero image, optional
 *   $page_booking — set truthy to load the booking widget CSS/JS, optional
 *   $page_rooms_rates — set truthy to load the Rooms & Rates cards + booking modal assets, optional
 */

$page_title  = $page_title  ?? 'Tribal Sand · Luxury Beachfront Hotels & Villas · Kenya';
$page_desc   = $page_desc   ?? 'Luxury beachfront boutique hotels and private villas in Watamu, Kilifi and Vipingo, Kenya. Kenya as it was meant to be experienced.';
$page_url    = $page_url    ?? 'https://tribalsand.com/';
$page_image  = $page_image  ?? asset_url('images/Maya-Kobe-1-hero.webp');
$page_type   = $page_type   ?? 'website';
$page_schema = $page_schema ?? '';
$page_preload = $page_preload ?? '';
$noindex     = $noindex     ?? false; // set truthy on private pages (e.g. guest booking management) to block indexing

/*
 * Canonical hygiene. The server 301-redirects /foo.php → /foo (see .htaccess), so
 * the canonical URL — and every meta URL derived from it (og:url, twitter, hreflang) —
 * must be the clean, final form, never the .php variant that immediately redirects.
 * A self-referencing canonical that points at a 301 is a soft SEO error; normalise it
 * once here so every page is correct without touching each page's $page_url.
 * Homepage ('https://…/') has no .php and is unaffected.
 */
$page_url = preg_replace('~\.php(?=$|[?#])~i', '', $page_url);

require_once __DIR__ . '/turnstile.php'; // captcha_site_key() available on every page that has a form
$__ts_currency = current_currency();     // resolve early so a ?cur= choice can set its cookie before output
?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- ts-build: <?= date('Y-m-d H:i T', filemtime(__FILE__)) ?> · deploy-check-1 -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">

<!-- ── PRIMARY SEO ── -->
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex,nofollow">
<?php endif; ?><link rel="canonical" href="<?= htmlspecialchars($page_url) ?>">

<!-- ── OPEN GRAPH ── -->
<meta property="og:type"        content="<?= htmlspecialchars($page_type) ?>">
<meta property="og:site_name"   content="Tribal Sand">
<meta property="og:url"         content="<?= htmlspecialchars($page_url) ?>">
<meta property="og:title"       content="<?= htmlspecialchars($page_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($page_desc) ?>">
<meta property="og:image"       content="<?= htmlspecialchars($page_image) ?>">
<meta property="og:image:width"  content="1200">
<meta property="og:image:height" content="630">

<!-- ── TWITTER CARD ── -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= htmlspecialchars($page_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($page_desc) ?>">
<meta name="twitter:image"       content="<?= htmlspecialchars($page_image) ?>">

<!-- ── HREFLANG ── -->
<link rel="alternate" hreflang="en"        href="<?= htmlspecialchars($page_url) ?>">
<link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($page_url) ?>">

<!-- ── STRUCTURED DATA ── -->
<?php if (!empty($page_schema)): ?>
<?= $page_schema ?>
<?php endif; ?>

<!-- ── PRECONNECT HINTS ── -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com">

<!-- ── FONTS (non-blocking) ── -->
<link rel="preload"
  href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&family=Jost:wght@400;500&display=swap"
  as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</noscript>

<!-- ── ICONS ── -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- ── FAVICON ── -->
<link rel="icon" type="image/png" href="/images/favicon.png">
<link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
<link rel="manifest" href="/manifest.json">

<!-- ── GLOBAL CSS ── -->
<link rel="stylesheet" href="css/main.css?v=<?= filemtime(__DIR__ . '/../css/main.css') ?>">

<!-- ── MULTI-CURRENCY (display-only) ── -->
<script>
window.TS_FX = <?= json_encode(['base' => fx_rates()['base'] ?? 'USD', 'rates' => fx_rates()['rates']], JSON_UNESCAPED_SLASHES) ?>;
window.TS_CUR = <?= json_encode($__ts_currency) ?>;
window.TS_CUR_META = <?= json_encode(array_map(fn($m) => ['symbol' => $m['symbol'], 'round' => $m['round']], TS_CURRENCIES), JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="js/currency.js?v=<?= filemtime(__DIR__ . '/../js/currency.js') ?>" defer></script>
<script src="js/nice-select.js?v=<?= filemtime(__DIR__ . '/../js/nice-select.js') ?>" defer></script>

<?php if (captcha_site_key()): ?>
<!-- ── Cloudflare Turnstile (loaded site-wide so every form's widget works) ── -->
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>

<?php if (!empty($page_booking)): ?>
<!-- ── BOOKING WIDGET ── -->
<link rel="stylesheet" href="css/booking.css?v=<?= filemtime(__DIR__ . '/../css/booking.css') ?>">
<script src="js/datepicker.js?v=<?= filemtime(__DIR__ . '/../js/datepicker.js') ?>" defer></script>
<script src="js/booking-widget.js?v=<?= filemtime(__DIR__ . '/../js/booking-widget.js') ?>" defer></script>
<?php endif; ?>

<?php if (!empty($page_rooms_rates)): ?>
<!-- ── ROOMS & RATES + BOOKING MODAL ── -->
<link rel="stylesheet" href="css/booking.css?v=<?= filemtime(__DIR__ . '/../css/booking.css') ?>">
<link rel="stylesheet" href="css/rooms-and-rates.css?v=<?= filemtime(__DIR__ . '/../css/rooms-and-rates.css') ?>">
<script src="js/datepicker.js?v=<?= filemtime(__DIR__ . '/../js/datepicker.js') ?>" defer></script>
<script src="js/booking-widget.js?v=<?= filemtime(__DIR__ . '/../js/booking-widget.js') ?>" defer></script>
<script src="js/booking-modal.js?v=<?= filemtime(__DIR__ . '/../js/booking-modal.js') ?>" defer></script>
<script src="js/availability-search.js?v=<?= filemtime(__DIR__ . '/../js/availability-search.js') ?>" defer></script>
<?php endif; ?>

<?php if (!empty($page_preload)): ?>
<!-- ── HERO PRELOAD ── -->
<link rel="preload" as="image" href="<?= htmlspecialchars($page_preload) ?>">
<?php endif; ?>
