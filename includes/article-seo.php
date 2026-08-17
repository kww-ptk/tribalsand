<?php
/**
 * TRIBAL SAND · Per-article SEO head fragment
 * ------------------------------------------------------------------
 * Drop this into the <head> of a Journal article, replacing the old
 * hand-rolled <title>:
 *
 *   <?php $article = 'the-pga-baobab-golf-course'; include 'includes/article-seo.php'; ?>
 *
 * It emits <title>, meta description, canonical, Open Graph, Twitter
 * card and Article/Breadcrumb JSON-LD — all pulled from the manifest
 * (includes/articles.php), so the Journal card and the article page
 * always agree. Falls back gracefully if the slug is unknown.
 */

require_once __DIR__ . '/articles.php';
if (!function_exists('e')) { require_once __DIR__ . '/db.php'; }

$__a_slug = $article ?? '';
$__a      = ts_article($__a_slug);

$__base   = 'https://tribalsand.com/';
$__title  = $__a['title'] ?? 'Journal';
$__desc   = $__a['desc']  ?? 'Stories from Kenya\'s North Coast by Tribal Sand.';
$__url    = $__base . $__a_slug . '.php';
$__img    = $__base . ltrim($__a['image'] ?? 'images/Maya-Kobe-1-hero.webp', '/');
$__cat    = $__a['category'] ?? 'Journal';

$__full_title = $__title . ' · Tribal Sand · Kenya';
?>
<title><?= htmlspecialchars($__full_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($__desc) ?>">
<link rel="canonical" href="<?= htmlspecialchars($__url) ?>">

<!-- Open Graph -->
<meta property="og:type"        content="article">
<meta property="og:site_name"   content="Tribal Sand">
<meta property="og:url"         content="<?= htmlspecialchars($__url) ?>">
<meta property="og:title"       content="<?= htmlspecialchars($__title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($__desc) ?>">
<meta property="og:image"       content="<?= htmlspecialchars($__img) ?>">

<!-- Twitter -->
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?= htmlspecialchars($__title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($__desc) ?>">
<meta name="twitter:image"       content="<?= htmlspecialchars($__img) ?>">

<!-- Structured data -->
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'Article',
    'headline' => $__title,
    'description' => $__desc,
    'image'    => $__img,
    'articleSection' => $__cat,
    'publisher' => [
        '@type' => 'Organization',
        'name'  => 'Tribal Sand',
        'logo'  => ['@type' => 'ImageObject', 'url' => $__base . 'images/whitelogo11.png'],
    ],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $__url],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',    'item' => $__base],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Journal', 'item' => $__base . 'blog.php'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $__title,  'item' => $__url],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
