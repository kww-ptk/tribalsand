<?php
declare(strict_types=1);
/**
 * HTTP plumbing for the /sync/v1 endpoints — the request/response half of the
 * sync API (§5). The domain logic lives in includes/sync.php; this file only
 * reads the raw request, enforces the HMAC gate, and writes clean JSON.
 *
 * Mirrors includes/restaurant-api.php: it buffers output so a stray PHP warning
 * from deep in the app can never corrupt the JSON body or send headers early
 * (which would silently pin the status at 200 and make a failed apply read as a
 * success to the peer).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';

function sync_api_buffer_level(?int $set = null): int {
    static $level = 0;
    if ($set !== null) $level = $set;
    return $level;
}

function sync_api_begin(): void {
    sync_api_buffer_level(ob_get_level());
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // Server-to-server only: no CORS. A browser must never call these.
}

function sync_api_flush_stray(): void {
    $floor = sync_api_buffer_level();
    $stray = '';
    while (ob_get_level() > $floor) $stray .= (string) ob_get_clean();
    if (trim($stray) !== '') {
        error_log('[sync-api] discarded stray output: ' . substr(trim($stray), 0, 500));
    }
}

function sync_api_json(array $body, int $status = 200): never {
    sync_api_flush_stray();
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** The raw request body, exactly as received — the HMAC is computed over these bytes. */
function sync_api_raw_body(): string {
    $raw = file_get_contents('php://input');
    return $raw === false ? '' : $raw;
}

/** A request header, case-insensitive, from the $_SERVER HTTP_* set. */
function sync_api_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? ''));
}

/**
 * The full gate for an authenticated inbound request. Verifies the peer IP
 * allowlist and the HMAC signature over (timestamp . '.' . rawBody), and exits
 * with the right status on failure (401 bad_signature, 403 forbidden IP, 503 not
 * configured). Returns the raw body on success so the handler parses it once.
 */
function sync_api_authenticate(): string {
    $ip = client_ip();
    if (!sync_ip_allowed($ip)) {
        error_log('[sync-api] blocked IP: ' . $ip);
        sync_api_json(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $raw = sync_api_raw_body();
    $ts  = sync_api_header('X-Sync-Timestamp');
    $sig = sync_api_header('X-Sync-Signature');

    $v = sync_verify_signature($ts, $raw, $sig);
    if (!$v['ok']) {
        if ($v['error'] === 'bad_signature') {
            // Critical alert per §9 — a bad signature is either a misconfigured
            // secret or an attacker, and must be visible immediately.
            error_log('[sync-api] ALERT bad_signature from ' . $ip . ' src=' . sync_api_header('X-Sync-Source'));
        }
        sync_api_json(['ok' => false, 'error' => $v['error']], $v['code']);
    }
    return $raw;
}
