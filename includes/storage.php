<?php
declare(strict_types=1);

/**
 * Object storage driver.
 *
 * Speaks S3-compatible SigV4 to EITHER Amazon S3 or Cloudflare R2 (R2 uses the
 * same signing protocol). Backend is chosen per call by _storage_cfg():
 *   - AWS S3  when S3_REGION + credentials are set (preferred at/after cutover)
 *   - Cloudflare R2 when R2_ACCOUNT_ID + keys are set (legacy / pre-cutover)
 *   - neither → local disk fallback (dev / no cloud configured)
 *
 * Credentials for S3 may be long-lived keys (S3_ACCESS_KEY / S3_SECRET_KEY) OR
 * the App Runner instance role's temporary creds (AWS_ACCESS_KEY_ID /
 * AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN). A session token, when present, is
 * signed in via x-amz-security-token — preferred in production (no keys in env).
 *
 * Public API is unchanged so callers never care which backend is live:
 *   storage_put(), storage_signed_get_url(), storage_delete(),
 *   storage_put_private(), storage_delete_private(), storage_local_path().
 */

// Upload a local file to storage. Returns the stored key (relative path or full URL).
function storage_put(string $local_path, string $filename, string $content_type = 'image/jpeg', string $folder = 'rooms'): string|false {
    $env = parse_env();

    $cfg = _storage_cfg($env, false);
    if ($cfg) {
        $url = _s3_put($local_path, $filename, $cfg, $content_type);
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

/** Presigned GET URL (default 5 min) for a private object key. '' if no private bucket configured. */
function storage_signed_get_url(string $key, int $ttl = 300): string {
    $env = parse_env();
    $cfg = _storage_cfg($env, true);
    if (!$cfg) return '';   // no private bucket → proxy serves the local private file
    return _s3_signed_get($key, $cfg, $ttl);
}

/** Absolute local path for a PRIVATE check-in file — OUTSIDE the web root. */
function storage_local_path(string $key): string {
    return checkin_private_dir() . '/' . $key;
}

// Delete a stored file by its stored key (relative path or full URL).
function storage_delete(string $stored): void {
    if (empty($stored)) return;

    if (str_starts_with($stored, 'http')) {
        $env = parse_env();
        $cfg = _storage_cfg($env, false);
        if ($cfg) {
            $key = ltrim(parse_url($stored, PHP_URL_PATH) ?? '', '/');
            // Strip bucket prefix if the stored URL was path-style (host/bucket/key)
            if ($cfg['bucket'] && str_starts_with($key, $cfg['bucket'] . '/')) {
                $key = substr($key, strlen($cfg['bucket']) + 1);
            }
            _s3_delete($key, $cfg);
        }
        return;
    }

    $path = __DIR__ . '/../assets/img/' . $stored;
    if (file_exists($path)) unlink($path);
}

/**
 * Resolve the active storage backend for a public (false) or private (true)
 * object. Returns null when no cloud backend is configured for that role
 * (caller then uses the local-disk fallback). AWS S3 takes precedence over R2.
 *
 * Shape: ['host','region','access','secret','token','bucket','public_url'].
 */
function _storage_cfg(array $env, bool $private): ?array {
    // --- Amazon S3 (preferred once S3_REGION is set) ---
    if (!empty($env['S3_REGION'])) {
        $region = $env['S3_REGION'];
        $bucket = $private ? ($env['S3_CHECKIN_BUCKET'] ?? '') : ($env['S3_BUCKET'] ?? '');
        if ($bucket === '') return null;   // private role with no private bucket → local fallback
        $creds = _s3_resolve_credentials($env);
        if ($creds === null) return null;  // S3 named but no usable credentials → fall through
        return [
            'host'       => "s3.{$region}.amazonaws.com",   // path-style: host/bucket/key
            'region'     => $region,
            'access'     => $creds['access'],
            'secret'     => $creds['secret'],
            'token'      => $creds['token'],
            'bucket'     => $bucket,
            'public_url' => rtrim($env['S3_PUBLIC_URL'] ?? '', '/'),
        ];
    }

    // --- Cloudflare R2 (legacy / pre-cutover) ---
    if (_r2_configured($env)) {
        $bucket = $private ? ($env['R2_CHECKIN_BUCKET'] ?? '') : ($env['R2_BUCKET'] ?? 'tribalsand-images');
        if ($private && $bucket === '') return null;
        return [
            'host'       => "{$env['R2_ACCOUNT_ID']}.r2.cloudflarestorage.com",
            'region'     => 'auto',
            'access'     => $env['R2_ACCESS_KEY'],
            'secret'     => $env['R2_SECRET_KEY'],
            'token'      => '',
            'bucket'     => $bucket,
            'public_url' => rtrim($env['R2_PUBLIC_URL'] ?? '', '/'),
        ];
    }

    return null;
}

function _r2_configured(array $env): bool {
    return !empty($env['R2_ACCOUNT_ID']) && !empty($env['R2_ACCESS_KEY']) && !empty($env['R2_SECRET_KEY']);
}

/**
 * Resolve S3 credentials, in order of preference:
 *   1. Explicit S3_ACCESS_KEY / S3_SECRET_KEY (+ optional S3_SESSION_TOKEN).
 *   2. Standard AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY (+ AWS_SESSION_TOKEN).
 *   3. The App Runner / ECS instance role, fetched from the container
 *      credentials endpoint — no keys stored anywhere (preferred in production).
 * Returns ['access','secret','token'] or null when none are available.
 */
function _s3_resolve_credentials(array $env): ?array {
    if (!empty($env['S3_ACCESS_KEY']) && !empty($env['S3_SECRET_KEY'])) {
        return ['access' => $env['S3_ACCESS_KEY'], 'secret' => $env['S3_SECRET_KEY'],
                'token' => (string)($env['S3_SESSION_TOKEN'] ?? '')];
    }
    if (!empty($env['AWS_ACCESS_KEY_ID']) && !empty($env['AWS_SECRET_ACCESS_KEY'])) {
        return ['access' => $env['AWS_ACCESS_KEY_ID'], 'secret' => $env['AWS_SECRET_ACCESS_KEY'],
                'token' => (string)($env['AWS_SESSION_TOKEN'] ?? '')];
    }
    return _s3_container_credentials($env);
}

/**
 * Fetch temporary credentials from the ECS/App Runner container credentials
 * endpoint (the instance role). Cached in-process until shortly before expiry.
 */
function _s3_container_credentials(array $env): ?array {
    static $cache = null;
    static $cache_exp = 0;
    if ($cache !== null && time() < $cache_exp - 60) return $cache;

    $rel  = $env['AWS_CONTAINER_CREDENTIALS_RELATIVE_URI'] ?? (getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI') ?: '');
    $full = $env['AWS_CONTAINER_CREDENTIALS_FULL_URI']     ?? (getenv('AWS_CONTAINER_CREDENTIALS_FULL_URI') ?: '');
    if ($rel !== '')       $url = 'http://169.254.170.2' . $rel;
    elseif ($full !== '')  $url = $full;
    else                   return null;

    $ctx  = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) return null;
    $d = json_decode($json, true);
    if (!is_array($d) || empty($d['AccessKeyId']) || empty($d['SecretAccessKey'])) return null;

    $cache     = ['access' => $d['AccessKeyId'], 'secret' => $d['SecretAccessKey'], 'token' => (string)($d['Token'] ?? '')];
    $cache_exp = !empty($d['Expiration']) ? (int)strtotime($d['Expiration']) : time() + 300;
    return $cache;
}

/** Derive the SigV4 signature for a string-to-sign. */
function _sig_v4(string $string_to_sign, string $secret, string $d, string $region, string $service): string {
    $k_date    = hash_hmac('sha256', $d,             "AWS4{$secret}", true);
    $k_region  = hash_hmac('sha256', $region,        $k_date,        true);
    $k_service = hash_hmac('sha256', $service,        $k_region,      true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service,     true);
    return hash_hmac('sha256', $string_to_sign, $k_signing);
}

/** PUT an object (path-style) to the resolved backend. Returns the public URL or false. */
function _s3_put(string $local_path, string $key, array $cfg, string $content_type = 'image/jpeg'): string|false {
    $host    = $cfg['host'];
    $bucket  = $cfg['bucket'];
    $body    = file_get_contents($local_path);
    $ct      = $content_type;
    $dt      = gmdate('Ymd\THis\Z');
    $d       = gmdate('Ymd');
    $phash   = hash('sha256', $body);
    $region  = $cfg['region'];
    $service = 's3';
    $token   = $cfg['token'] ?? '';

    // Signed headers (kept alphabetical; add security-token when present).
    $headers = [
        'content-type'         => $ct,
        'host'                 => $host,
        'x-amz-content-sha256' => $phash,
        'x-amz-date'           => $dt,
    ];
    if ($token !== '') $headers['x-amz-security-token'] = $token;
    ksort($headers);
    $signed_headers    = implode(';', array_keys($headers));
    $canonical_headers = '';
    foreach ($headers as $k => $v) $canonical_headers .= "{$k}:{$v}\n";

    $canonical_request = "PUT\n/{$bucket}/{$key}\n\n{$canonical_headers}\n{$signed_headers}\n{$phash}";
    $scope             = "{$d}/{$region}/{$service}/aws4_request";
    $string_to_sign    = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);
    $sig               = _sig_v4($string_to_sign, $cfg['secret'], $d, $region, $service);
    $auth              = "AWS4-HMAC-SHA256 Credential={$cfg['access']}/{$scope},SignedHeaders={$signed_headers},Signature={$sig}";

    $hdr_lines = [
        "Authorization: {$auth}",
        "Content-Type: {$ct}",
        "x-amz-content-sha256: {$phash}",
        "x-amz-date: {$dt}",
        'Content-Length: ' . strlen($body),
    ];
    if ($token !== '') $hdr_lines[] = "x-amz-security-token: {$token}";

    $ctx = stream_context_create(['http' => [
        'method'        => 'PUT',
        'header'        => implode("\r\n", $hdr_lines),
        'content'       => $body,
        'ignore_errors' => true,
    ]]);

    @file_get_contents("https://{$host}/{$bucket}/{$key}", false, $ctx);
    $hdrs   = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
    $status = (isset($hdrs[0]) && preg_match('~\s(\d{3})\s~', $hdrs[0], $m)) ? (int)$m[1] : 0;

    return ($status === 200) ? "{$cfg['public_url']}/{$key}" : false;
}

/** DELETE an object (path-style) from the resolved backend. */
function _s3_delete(string $key, array $cfg): void {
    $host    = $cfg['host'];
    $bucket  = $cfg['bucket'];
    $dt      = gmdate('Ymd\THis\Z');
    $d       = gmdate('Ymd');
    $ehash   = hash('sha256', '');
    $region  = $cfg['region'];
    $service = 's3';
    $token   = $cfg['token'] ?? '';

    $headers = [
        'host'                 => $host,
        'x-amz-content-sha256' => $ehash,
        'x-amz-date'           => $dt,
    ];
    if ($token !== '') $headers['x-amz-security-token'] = $token;
    ksort($headers);
    $signed_headers    = implode(';', array_keys($headers));
    $canonical_headers = '';
    foreach ($headers as $k => $v) $canonical_headers .= "{$k}:{$v}\n";

    $canonical_request = "DELETE\n/{$bucket}/{$key}\n\n{$canonical_headers}\n{$signed_headers}\n{$ehash}";
    $scope             = "{$d}/{$region}/{$service}/aws4_request";
    $string_to_sign    = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);
    $sig               = _sig_v4($string_to_sign, $cfg['secret'], $d, $region, $service);
    $auth              = "AWS4-HMAC-SHA256 Credential={$cfg['access']}/{$scope},SignedHeaders={$signed_headers},Signature={$sig}";

    $hdr_lines = [
        "Authorization: {$auth}",
        "x-amz-content-sha256: {$ehash}",
        "x-amz-date: {$dt}",
    ];
    if ($token !== '') $hdr_lines[] = "x-amz-security-token: {$token}";

    $ctx = stream_context_create(['http' => [
        'method'        => 'DELETE',
        'header'        => implode("\r\n", $hdr_lines),
        'ignore_errors' => true,
    ]]);

    @file_get_contents("https://{$host}/{$bucket}/{$key}", false, $ctx);
}

/** Build a presigned GET URL (query-signed) for a private object. */
function _s3_signed_get(string $key, array $cfg, int $ttl): string {
    $host    = $cfg['host'];
    $bucket  = $cfg['bucket'];
    $dt      = gmdate('Ymd\THis\Z');
    $d       = gmdate('Ymd');
    $region  = $cfg['region'];
    $service = 's3';
    $token   = $cfg['token'] ?? '';
    $scope   = "{$d}/{$region}/{$service}/aws4_request";

    $q = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$cfg['access']}/{$scope}",
        'X-Amz-Date'          => $dt,
        'X-Amz-Expires'       => (string)$ttl,
        'X-Amz-SignedHeaders' => 'host',
    ];
    if ($token !== '') $q['X-Amz-Security-Token'] = $token;
    ksort($q);
    $canon_query       = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $canonical_request = "GET\n/{$bucket}/{$key}\n{$canon_query}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
    $string_to_sign    = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);
    $sig               = _sig_v4($string_to_sign, $cfg['secret'], $d, $region, $service);
    return "https://{$host}/{$bucket}/{$key}?{$canon_query}&X-Amz-Signature={$sig}";
}

/**
 * Base dir for PRIVATE check-in files (passports/waivers) — OUTSIDE the web/doc
 * root so it is never served by Apache OR `php -S`. Override with
 * CHECKIN_STORAGE_DIR (point it at a persistent disk in production); defaults to
 * the system temp dir. Sensitive PII must never live under assets/ (web-served).
 */
function checkin_private_dir(): string {
    $base = trim((string)(parse_env()['CHECKIN_STORAGE_DIR'] ?? ''));
    if ($base === '') $base = sys_get_temp_dir() . '/tribalsand_checkin';
    return rtrim($base, '/');
}

/**
 * Store a check-in file at an exact key. Uses a DEDICATED PRIVATE bucket
 * (S3_CHECKIN_BUCKET or R2_CHECKIN_BUCKET) when configured — never the public
 * image bucket — else a non-web-served local dir. Returns true on success. The
 * stored key (never a public URL) is what the DB holds; reads go only through
 * admin/checkin-file.php.
 */
function storage_put_private(string $local_path, string $key, string $content_type): bool {
    $env = parse_env();
    $cfg = _storage_cfg($env, true);
    if ($cfg) {
        return _s3_put($local_path, $key, $cfg, $content_type) !== false;
    }
    $dest = checkin_private_dir() . '/' . $key;
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0700, true);
    if (copy($local_path, $dest)) { @unlink($local_path); @chmod($dest, 0600); return true; }
    return false;
}

/** Delete a private check-in file by exact key (private bucket, else local). */
function storage_delete_private(string $key): void {
    if ($key === '') return;
    $env = parse_env();
    $cfg = _storage_cfg($env, true);
    if ($cfg) { _s3_delete($key, $cfg); return; }
    $path = checkin_private_dir() . '/' . $key;
    if (is_file($path)) @unlink($path);
}
