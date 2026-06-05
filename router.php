<?php
/**
 * LOCAL DEV ROUTER — PHP built-in server only
 * Missing images/assets → proxy from live tribalsand.com
 */
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Serve existing files normally (PHP, CSS, JS, actual local images)
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// Strip-.php emulation: live server serves /foo from foo.php — do the same locally
if ($uri !== '/' && file_exists($file . '.php') && pathinfo($uri, PATHINFO_EXTENSION) === '') {
    $_SERVER['SCRIPT_FILENAME'] = $file . '.php';
    include $file . '.php';
    exit;
}

// Redirect missing images/assets to live site
$imageExts = ['jpg','jpeg','png','gif','webp','svg','ico','woff','woff2','pdf','mp4','mp3'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
if (in_array($ext, $imageExts)) {
    header('Location: https://tribalsand.com' . $uri, true, 302);
    exit;
}

// For PHP pages / directories, fall through to normal routing
return false;
