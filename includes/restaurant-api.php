<?php
declare(strict_types=1);
/**
 * Shared plumbing for the public restaurant integration API — the surface a
 * standalone restaurant site (e.g. the Zuri restaurant website) integrates with
 * so it renders the SAME menu the team edits in Admin → Menus and drops its
 * table bookings into Admin → Reservations.
 *
 *   GET  /api/menu-feed.php         — published menus (read)
 *   POST /api/reservation-api.php   — create a reservation request (write)
 *   GET  /api/reservation-api.php   — look one up by reference (read)
 *
 * ONE source of truth: these endpoints call the same `includes/menu.php` /
 * `includes/reservations.php` readers and writers the Tribal Sand pages use, so
 * an admin edit is live on the partner site on its next fetch and a partner-site
 * booking lands in the same list as a tribalsand.com one. Never fork the data
 * shaping into a second copy here — add to the shared helpers instead.
 *
 * Auth: one shared key, `RESTAURANT_API_KEY` (legacy alias `MENU_API_KEY`),
 * presented as `Authorization: Bearer <key>` or `?key=<key>`.
 *   - READ endpoints: no key needed, ever — exactly like /menu.php already is,
 *     the menu is public information. A key sent anyway is ignored.
 *   - WRITE endpoints: the key is REQUIRED. With no key configured the write
 *     endpoint reports itself disabled rather than accepting anonymous writes,
 *     so forgetting to set the env var can never open an unauthenticated writer.
 */
require_once __DIR__ . '/db.php';

/** The configured integration key, or '' when the integration isn't configured. */
function restaurant_api_key(): string {
    $env = parse_env();
    $k = trim((string)($env['RESTAURANT_API_KEY'] ?? ''));
    if ($k === '') $k = trim((string)($env['MENU_API_KEY'] ?? ''));   // legacy name
    return $k;
}

/** The key the caller presented, from the Bearer header or ?key=. '' if none. */
function restaurant_api_presented_key(): string {
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($hdr, 'Bearer ') === 0) {
        $v = trim(substr($hdr, 7));
        if ($v !== '') return $v;
    }
    return trim((string)($_GET['key'] ?? ''));
}

/**
 * Discard anything the request printed before we got to the response.
 *
 * Load-bearing for an integration endpoint: these handlers call deep into the
 * app (the mailer, the DB layer), and a single PHP warning from any of it would
 * otherwise be prepended to the body — which both corrupts the JSON the partner
 * parses AND sends the headers early, so the status code silently stays 200. A
 * partner would read a failed write as a success. The stray output goes to the
 * error log instead, where it belongs.
 *
 * Only unwinds buffers this request opened (restaurant_api_begin records the
 * starting level), never one the server or php.ini owns.
 */
function restaurant_api_buffer_level(?int $set = null): int {
    static $level = 0;
    if ($set !== null) $level = $set;
    return $level;
}

function restaurant_api_flush_stray(): void {
    $floor = restaurant_api_buffer_level();
    $stray = '';
    while (ob_get_level() > $floor) $stray .= (string) ob_get_clean();
    if (trim($stray) !== '') {
        error_log('[restaurant-api] discarded stray output: ' . substr(trim($stray), 0, 500));
    }
}

/** Emit a JSON body and stop. */
function restaurant_api_json(array $body, int $status = 200): never {
    restaurant_api_flush_stray();
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Standard headers for an integration endpoint, plus the CORS pre-flight reply.
 *
 * $allowCors is TRUE only for read endpoints. The write endpoint is deliberately
 * server-to-server: a browser call would have to ship the shared key to the
 * visitor, so we don't advertise it as browser-callable.
 */
function restaurant_api_begin(bool $allowCors = true): void {
    restaurant_api_buffer_level(ob_get_level());
    ob_start();                       // see restaurant_api_flush_stray()
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if ($allowCors) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        restaurant_api_flush_stray();
        http_response_code(204);
        exit;
    }
}

/**
 * Gate the request on the shared key.
 *
 * $required=false (reads): ALWAYS open, whether or not a key is configured. A
 * menu is public information — /menu.php already serves it to anyone — and the
 * feed sends `Access-Control-Allow-Origin: *` so a partner can fetch it from the
 * browser, where a key could not safely go. Gating reads on a *configured* key
 * would mean that setting RESTAURANT_API_KEY (which the owner must do for
 * bookings) silently 401s the partner's menu fetch. A key sent anyway is simply
 * ignored, so an integrator that attaches it everywhere still works.
 *
 * $required=true (writes): a key MUST be configured and MUST match, else the
 * request is refused.
 */
function restaurant_api_authenticate(bool $required): void {
    if (!$required) return;

    $expected = restaurant_api_key();
    if ($expected === '') {
        restaurant_api_json([
            'ok'    => false,
            'error' => 'This endpoint is not enabled. Ask Tribal Sand to set RESTAURANT_API_KEY.',
        ], 503);
    }

    $given = restaurant_api_presented_key();
    if ($given === '' || !hash_equals($expected, $given)) {
        header('WWW-Authenticate: Bearer realm="tribalsand-restaurant-api"');
        restaurant_api_json(['ok' => false, 'error' => 'Invalid or missing API key.'], 401);
    }
}

/**
 * Serve a read payload with a data-derived ETag so an integrator can poll
 * cheaply. The ETag hashes the DATA ONLY — never a generated-at stamp, which
 * would change every request and never match — so a 304 really does mean
 * "nothing about this menu changed", including item edits that the parent row's
 * updated_at would miss.
 */
function restaurant_api_cached_json(array $core, int $maxAge = 300): never {
    $etag = '"' . md5(json_encode($core, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . max(0, $maxAge));
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        restaurant_api_flush_stray();   // a 304 must carry no body at all
        http_response_code(304);
        exit;
    }
    restaurant_api_json($core + ['generated_at' => date('c')]);
}

/**
 * The request body as an array, accepting either a JSON body or a normal
 * form POST. An integrator's HTTP client may send whichever is easiest.
 */
function restaurant_api_input(): array {
    if (!empty($_POST)) return $_POST;
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
