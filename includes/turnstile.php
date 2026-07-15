<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php'; // parse_env() / client_ip()

function captcha_site_key(): string {
    return parse_env()['TURNSTILE_SITE_KEY'] ?? '';
}

function verify_captcha(string $token, string $ip = ''): bool {
    $env     = parse_env();
    $secret  = $env['TURNSTILE_SECRET_KEY'] ?? '';
    $siteKey = $env['TURNSTILE_SITE_KEY']   ?? '';

    // Both keys absent → pure dev/local mode, bypass OK
    if (!$secret && !$siteKey) return true;

    // Site key set but secret missing → production misconfiguration, fail closed
    if (!$secret) {
        error_log('Turnstile: TURNSTILE_SECRET_KEY not set but TURNSTILE_SITE_KEY is — failing closed');
        return false;
    }
    if (!$token) return false;

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);

    $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if ($result === false) return false;

    $data = json_decode($result, true);
    return (bool)($data['success'] ?? false);
}
