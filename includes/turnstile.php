<?php
declare(strict_types=1);

function captcha_site_key(): string {
    return parse_env()['HCAPTCHA_SITE_KEY'] ?? '';
}

function verify_captcha(string $token, string $ip = ''): bool {
    $secret = parse_env()['HCAPTCHA_SECRET_KEY'] ?? '';
    if (!$secret) return true;  // dev / not yet configured — bypass silently
    if (!$token)  return false;

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);

    $result = @file_get_contents('https://hcaptcha.com/siteverify', false, $ctx);
    if ($result === false) return false;

    $data = json_decode($result, true);
    return (bool)($data['success'] ?? false);
}
