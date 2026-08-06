<?php
declare(strict_types=1);

// Upload a local file to storage. Returns the stored key (relative path or full URL).
function storage_put(string $local_path, string $filename, string $content_type = 'image/jpeg', string $folder = 'rooms'): string|false {
    $env = parse_env();

    if (_r2_configured($env)) {
        $url = _r2_put($local_path, $filename, $env, $content_type);
        return $url ?: false;
    }

    // Local fallback — store in assets/img/<folder>/
    $dest = __DIR__ . '/../assets/img/' . $folder . '/' . $filename;
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }

    if (copy($local_path, $dest)) {
        @unlink($local_path);
        return $folder . '/' . $filename;
    }
    return false;
}

/** Presigned GET URL (default 5 min) for a private R2 object key. '' if R2 unconfigured. */
function storage_signed_get_url(string $key, int $ttl = 300): string {
    $env = parse_env();
    if (!_r2_configured($env)) return '';   // local fallback: served by admin proxy reading disk
    $account_id = $env['R2_ACCOUNT_ID'];
    $bucket     = $env['R2_BUCKET'] ?? 'tribalsand-images';
    $access_key = $env['R2_ACCESS_KEY'];
    $secret_key = $env['R2_SECRET_KEY'];
    $host   = "{$account_id}.r2.cloudflarestorage.com";
    $dt     = gmdate('Ymd\THis\Z');
    $d      = gmdate('Ymd');
    $region = 'auto'; $service = 's3';
    $scope  = "{$d}/{$region}/{$service}/aws4_request";
    $q = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$access_key}/{$scope}",
        'X-Amz-Date'          => $dt,
        'X-Amz-Expires'       => (string)$ttl,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($q);
    $canon_query = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $canonical_request = "GET\n/{$bucket}/{$key}\n{$canon_query}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);
    $k_date=hash_hmac('sha256',$d,"AWS4{$secret_key}",true);
    $k_region=hash_hmac('sha256',$region,$k_date,true);
    $k_service=hash_hmac('sha256',$service,$k_region,true);
    $k_signing=hash_hmac('sha256','aws4_request',$k_service,true);
    $sig=hash_hmac('sha256',$string_to_sign,$k_signing);
    return "https://{$host}/{$bucket}/{$key}?{$canon_query}&X-Amz-Signature={$sig}";
}

/** Absolute local path for a key stored via the filesystem fallback. */
function storage_local_path(string $key): string {
    return __DIR__ . '/../assets/img/' . $key;
}

// Delete a stored file by its stored key (relative path or full URL).
function storage_delete(string $stored): void {
    if (empty($stored)) return;

    if (str_starts_with($stored, 'http')) {
        $env = parse_env();
        if (_r2_configured($env)) {
            $key = ltrim(parse_url($stored, PHP_URL_PATH) ?? '', '/');
            // Strip bucket prefix if present
            $bucket = $env['R2_BUCKET'] ?? '';
            if ($bucket && str_starts_with($key, $bucket . '/')) {
                $key = substr($key, strlen($bucket) + 1);
            }
            _r2_delete($key, $env);
        }
        return;
    }

    $path = __DIR__ . '/../assets/img/' . $stored;
    if (file_exists($path)) unlink($path);
}

function _r2_configured(array $env): bool {
    return !empty($env['R2_ACCOUNT_ID']) && !empty($env['R2_ACCESS_KEY']) && !empty($env['R2_SECRET_KEY']);
}

function _r2_put(string $local_path, string $key, array $env, string $content_type = 'image/jpeg'): string|false {
    $account_id = $env['R2_ACCOUNT_ID'];
    $bucket     = $env['R2_BUCKET'] ?? 'tribalsand-images';
    $access_key = $env['R2_ACCESS_KEY'];
    $secret_key = $env['R2_SECRET_KEY'];
    $public_url = rtrim($env['R2_PUBLIC_URL'] ?? '', '/');

    $host     = "{$account_id}.r2.cloudflarestorage.com";
    $body     = file_get_contents($local_path);
    $ct       = $content_type;
    $dt       = gmdate('Ymd\THis\Z');
    $d        = gmdate('Ymd');
    $phash    = hash('sha256', $body);
    $region   = 'auto';
    $service  = 's3';

    $signed_headers    = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonical_headers = "content-type:{$ct}\nhost:{$host}\nx-amz-content-sha256:{$phash}\nx-amz-date:{$dt}\n";
    $canonical_request = "PUT\n/{$bucket}/{$key}\n\n{$canonical_headers}\n{$signed_headers}\n{$phash}";

    $scope          = "{$d}/{$region}/{$service}/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);

    $k_date    = hash_hmac('sha256', $d,              "AWS4{$secret_key}", true);
    $k_region  = hash_hmac('sha256', $region,         $k_date,            true);
    $k_service = hash_hmac('sha256', $service,        $k_region,          true);
    $k_signing = hash_hmac('sha256', 'aws4_request',  $k_service,         true);
    $sig       = hash_hmac('sha256', $string_to_sign, $k_signing);

    $auth = "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope},SignedHeaders={$signed_headers},Signature={$sig}";

    $ctx = stream_context_create(['http' => [
        'method'        => 'PUT',
        'header'        => implode("\r\n", [
            "Authorization: {$auth}",
            "Content-Type: {$ct}",
            "x-amz-content-sha256: {$phash}",
            "x-amz-date: {$dt}",
            'Content-Length: ' . strlen($body),
        ]),
        'content'       => $body,
        'ignore_errors' => true,
    ]]);

    @file_get_contents("https://{$host}/{$bucket}/{$key}", false, $ctx);
    $hdrs   = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
    $status = (isset($hdrs[0]) && preg_match('~\s(\d{3})\s~', $hdrs[0], $m)) ? (int)$m[1] : 0;

    return ($status === 200) ? "{$public_url}/{$key}" : false;
}

function _r2_delete(string $key, array $env): void {
    $account_id = $env['R2_ACCOUNT_ID'];
    $bucket     = $env['R2_BUCKET'] ?? 'tribalsand-images';
    $access_key = $env['R2_ACCESS_KEY'];
    $secret_key = $env['R2_SECRET_KEY'];

    $host    = "{$account_id}.r2.cloudflarestorage.com";
    $dt      = gmdate('Ymd\THis\Z');
    $d       = gmdate('Ymd');
    $ehash   = hash('sha256', '');
    $region  = 'auto';
    $service = 's3';

    $signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
    $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$ehash}\nx-amz-date:{$dt}\n";
    $canonical_request = "DELETE\n/{$bucket}/{$key}\n\n{$canonical_headers}\n{$signed_headers}\n{$ehash}";

    $scope          = "{$d}/{$region}/{$service}/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);

    $k_date    = hash_hmac('sha256', $d,              "AWS4{$secret_key}", true);
    $k_region  = hash_hmac('sha256', $region,         $k_date,            true);
    $k_service = hash_hmac('sha256', $service,        $k_region,          true);
    $k_signing = hash_hmac('sha256', 'aws4_request',  $k_service,         true);
    $sig       = hash_hmac('sha256', $string_to_sign, $k_signing);

    $auth = "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope},SignedHeaders={$signed_headers},Signature={$sig}";

    $ctx = stream_context_create(['http' => [
        'method'        => 'DELETE',
        'header'        => implode("\r\n", [
            "Authorization: {$auth}",
            "x-amz-content-sha256: {$ehash}",
            "x-amz-date: {$dt}",
        ]),
        'ignore_errors' => true,
    ]]);

    @file_get_contents("https://{$host}/{$bucket}/{$key}", false, $ctx);
}
