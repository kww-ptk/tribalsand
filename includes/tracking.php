<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

// First-touch UTM + referrer capture — stored once per session
if (empty($_SESSION['tracking'])) {
    $_SESSION['tracking'] = [
        'source_page'   => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        'referrer'      => $_SERVER['HTTP_REFERER']                   ?? '',
        'utm_source'    => $_GET['utm_source']                        ?? '',
        'utm_medium'    => $_GET['utm_medium']                        ?? '',
        'utm_campaign'  => $_GET['utm_campaign']                      ?? '',
        'utm_term'      => $_GET['utm_term']                          ?? '',
        'utm_content'   => $_GET['utm_content']                       ?? '',
        'user_agent'    => $_SERVER['HTTP_USER_AGENT']                ?? '',
        'ip_address'    => $_SERVER['REMOTE_ADDR']                    ?? '',
    ];
}

function tracking_payload(): array {
    return $_SESSION['tracking'] ?? [];
}
