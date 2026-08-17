<?php
/**
 * Legacy route for the signed-consent record. The record now lives at the
 * clean, guest-facing /record.php URL so the generated evidence document never
 * carries an internal /admin/ backend route. This shim redirects old links
 * (bookmarks, previously issued guest links) to the new location, preserving
 * the auth query params (ref / g / hold / guest).
 */
declare(strict_types=1);
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /record.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
