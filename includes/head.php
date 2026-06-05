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
$page_image  = $page_image  ?? 'https://tribalsand.com/images/Maya-Kobe-1-hero.webp';
$page_type   = $page_type   ?? 'website';
$page_schema = $page_schema ?? '';
$page_preload = $page_preload ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">

<!-- ── PRIMARY SEO ── -->
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
<link rel="canonical" href="<?= htmlspecialchars($page_url) ?>">

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

<!-- ── FONTS ── -->
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&family=Jost:wght@400;500&display=swap" rel="stylesheet">

<!-- ── ICONS ── -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- ── FAVICON ── -->
<link rel="icon" href="images/favicon.png">

<!-- ── GLOBAL CSS ── -->
<link rel="stylesheet" href="css/main.css">

<?php if (!empty($page_booking)): ?>
<!-- ── BOOKING WIDGET ── -->
<link rel="stylesheet" href="css/booking.css">
<script src="js/booking-widget.js" defer></script>
<?php if (!empty(getenv('HCAPTCHA_SITE_KEY')) || !empty($_ENV['HCAPTCHA_SITE_KEY'])): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($page_rooms_rates)): ?>
<!-- ── ROOMS & RATES + BOOKING MODAL ── -->
<link rel="stylesheet" href="css/booking.css">
<link rel="stylesheet" href="css/rooms-and-rates.css">
<script src="js/booking-widget.js" defer></script>
<script src="js/booking-modal.js" defer></script>
<script src="js/availability-search.js" defer></script>
<?php if (!empty(getenv('HCAPTCHA_SITE_KEY')) || !empty($_ENV['HCAPTCHA_SITE_KEY'])): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($page_preload)): ?>
<!-- ── HERO PRELOAD ── -->
<link rel="preload" as="image" href="<?= htmlspecialchars($page_preload) ?>">
<?php endif; ?>
