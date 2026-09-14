<?php
declare(strict_types=1);

// The property (and its guests, staff and gate) operate in Kenya. Run the whole
// app in Africa/Nairobi so every date() / strtotime() / DateTime('now') and the
// Postgres NOW() / CURRENT_DATE / ::date all agree — otherwise "today" computed
// in Nairobi disagrees with UTC-stored rows (e.g. the gate visitor day-filter
// dropped today's entries after 21:00 UTC). Set once at module load.
date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/maya-ilai-inventory.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // The first connection after an idle period can be slow (pooler/backend
    // spin-up, network latency). The PHP CLI built-in dev server defaults to a
    // 30s max_execution_time, so a slow first connect can kill the page
    // mid-connect (the `connect_timeout` below caps unreachable hosts, but not a
    // slow post-handshake wait). Give the dev server room to ride it out;
    // production keeps its configured limit.
    if (PHP_SAPI === 'cli-server') @set_time_limit(90);

    $env = parse_env();

    // Many hosts provide a single DATABASE_URL connection string
    if (!empty($env['DATABASE_URL'])) {
        $u = parse_url($env['DATABASE_URL']);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $u['host'],
            $u['port'] ?? 5432,
            ltrim($u['path'], '/')
        );
        $user = $u['user'] ?? '';
        $pass = $u['pass'] ?? '';
    } else {
        $dsn  = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $env['DB_HOST'] ?? 'localhost',
            $env['DB_PORT'] ?? '5432',
            $env['DB_NAME'] ?? 'tribalsand'
        );
        $user = $env['DB_USER'] ?? '';
        $pass = $env['DB_PASS'] ?? '';
    }

    // Bound the connection attempt. A slow first connect after an idle period can
    // hang long enough to blow PHP's max_execution_time and kill the page at
    // `new PDO(...)`. libpq's connect_timeout caps each attempt (PDO::ATTR_TIMEOUT
    // is NOT reliably honored for the pgsql *connection*), and we retry a couple of
    // times because the DB usually answers by the second try. Tunable via env.
    $connectTimeout = max(2, (int)($env['DB_CONNECT_TIMEOUT'] ?? 6));   // seconds per attempt
    $maxAttempts    = max(1, (int)($env['DB_CONNECT_RETRIES']  ?? 3));  // total attempts
    $dsn .= ';connect_timeout=' . $connectTimeout;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => $connectTimeout,
        // Emulated prepares send plain SQL rather than server-side prepared
        // statements. This matters behind a transaction pooler (PgBouncer /
        // RDS Proxy): real prepares cache query plans in pooled backend
        // connections that survive app restarts, so a migration that adds columns
        // (e.g. SELECT h.*) triggers "cached plan must not change result type" and
        // an app redeploy can't clear it. No bound LIMIT/OFFSET params exist, so
        // this mode is safe here.
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    $attempt = 0;
    while (true) {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            break;
        } catch (PDOException $e) {
            // Retry only the transient cold-start case; give up (rethrow) once the
            // attempt budget is spent so a real misconfig still surfaces loudly.
            if (++$attempt >= $maxAttempts) throw $e;
            usleep(400000);   // 0.4s backoff, then let the woken compute answer
        }
    }

    // Align the DB session with the app's Nairobi timezone (above). Makes NOW(),
    // CURRENT_DATE, ::date casts and timestamptz rendering all Nairobi-local.
    // A transaction pooler tracks the TimeZone session parameter, so this persists
    // across pooled statements within the connection.
    $pdo->exec("SET TIME ZONE 'Africa/Nairobi'");

    return $pdo;
}

function parse_env(): array {
    static $env = null;
    if ($env !== null) return $env;

    // The host injects env vars directly — use those first
    $env = $_ENV + $_SERVER;

    // Fall back to .env file for local dev
    $file = __DIR__ . '/../.env';
    if (file_exists($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if (!isset($env[$key])) $env[$key] = $val;
        }
    }

    return $env;
}

function db_query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_room_by_slug(string $slug): array|false {
    return db_query(
        'SELECT * FROM rooms WHERE slug = :slug AND is_published = TRUE',
        [':slug' => $slug]
    )->fetch();
}

function fetch_room_images(int $room_id): array {
    return db_query(
        'SELECT * FROM room_images WHERE room_id = :id ORDER BY sort_order ASC',
        [':id' => $room_id]
    )->fetchAll();
}

function fetch_venue_images(int $venue_id): array {
    return db_query(
        'SELECT * FROM venue_images WHERE venue_id = :id ORDER BY is_hero DESC, sort_order ASC',
        [':id' => $venue_id]
    )->fetchAll();
}

function fetch_tour_by_slug(string $slug): array|false {
    return db_query(
        'SELECT * FROM tours WHERE slug = :slug AND is_published = TRUE',
        [':slug' => $slug]
    )->fetch();
}

function fetch_tour_images(int $tour_id): array {
    return db_query(
        'SELECT * FROM tour_images WHERE tour_id = :id ORDER BY sort_order ASC',
        [':id' => $tour_id]
    )->fetchAll();
}

function site_url(string $path = ''): string {
    $env  = parse_env();
    $base = rtrim($env['APP_URL'] ?? 'https://tribalsand.com', '/');
    return $base . ($path ? '/' . ltrim($path, '/') : '');
}

/**
 * URL for a static asset (image, PDF, media file).
 *
 * Assets may move to a CDN / object store (e.g. S3 + CloudFront) at the
 * AWS cutover. Set the ASSET_URL env var to that origin and every asset
 * link follows — no code changes. Until then it defaults to the current
 * production host where the /images tree lives, so links never break.
 */
function asset_url(string $path = ''): string {
    $env  = parse_env();
    $base = rtrim($env['ASSET_URL'] ?? 'https://tribalsand.com', '/');
    return $base . ($path ? '/' . ltrim($path, '/') : '');
}

function setting(string $key, string $default = ''): string {
    $row = db_query(
        'SELECT setting_value FROM settings WHERE setting_key = :key',
        [':key' => $key]
    )->fetch();
    return $row ? $row['setting_value'] : $default;
}

function set_setting(string $key, string $value): void {
    db_query(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (:key, :val, NOW())
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = :val, updated_at = NOW()',
        [':key' => $key, ':val' => $value]
    );
}

// ── Multi-currency (display-only) ───────────────────────────────
// Guests can VIEW prices in their currency; every booking still settles in the
// property's real price_currency. Rates are USD-based, cached in settings['fx_rates'],
// auto-refreshed daily (api/fx-sync.php) with per-rate admin overrides. Conversion is
// indicative only — never a source of truth. See MULTICURRENCY-PLAN.md.

/** Supported display currencies. `round` = nearest N to round a *converted* figure to. */
const TS_CURRENCIES = [
    'USD' => ['symbol' => '$',    'name' => 'US Dollar',       'round' => 1],
    'EUR' => ['symbol' => '€',    'name' => 'Euro',            'round' => 1],
    'GBP' => ['symbol' => '£',    'name' => 'British Pound',   'round' => 1],
    'KES' => ['symbol' => 'KES ', 'name' => 'Kenyan Shilling', 'round' => 100],
];
const TS_CURRENCY_DEFAULT = 'USD';

/**
 * Symbol to print before a currency code in switcher chrome — '' when the
 * symbol IS the code (KES), which otherwise renders as "KES KES".
 */
function currency_symbol_prefix(string $code): string {
    $code = strtoupper(trim($code));
    $sym  = trim(TS_CURRENCIES[$code]['symbol'] ?? '');
    return ($sym === '' || strcasecmp($sym, $code) === 0) ? '' : $sym;
}

/** "$ USD" / "KES" — the switcher's label for one currency. */
function currency_label(string $code): string {
    $code = strtoupper(trim($code));
    return trim(currency_symbol_prefix($code) . ' ' . $code);
}

/** True when $code is a currency we support for display. */
function is_supported_currency(string $code): bool {
    return isset(TS_CURRENCIES[strtoupper(trim($code))]);
}

/** Seed rates (USD-based) — used before the first live sync, or if the setting is unreadable. */
function fx_rates_seed(): array {
    return [
        'base'       => 'USD',
        'fetched_at' => null,
        'rates'      => ['USD' => 1.0, 'KES' => 129.0, 'EUR' => 0.92, 'GBP' => 0.79],
        'locked'     => [],
    ];
}

/**
 * The cached FX rate table (USD-based), or the seed when unset/unparseable. Never
 * throws — a broken settings row (or a DB blip) must not take prices down. Any
 * missing/non-positive per-currency rate is backfilled from the seed.
 */
function fx_rates(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $seed = fx_rates_seed();
    try {
        $raw = setting('fx_rates', '');
        $dec = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($dec) && !empty($dec['rates']) && is_array($dec['rates'])) {
            foreach (array_keys(TS_CURRENCIES) as $c) {
                if (!isset($dec['rates'][$c]) || (float)$dec['rates'][$c] <= 0) {
                    $dec['rates'][$c] = $seed['rates'][$c] ?? null;
                }
            }
            $dec['base']   = $dec['base']   ?? 'USD';
            $dec['locked'] = is_array($dec['locked'] ?? null) ? $dec['locked'] : [];
            return $cache = $dec;
        }
    } catch (Throwable $e) { /* fall through to seed */ }
    return $cache = $seed;
}

/**
 * Pure currency resolver (side-effect free, so it's unit-testable): pick a
 * supported currency from the given request param + cookie, else $default (which
 * itself defaults to TS_CURRENCY_DEFAULT). $default lets the caller inject a
 * geo-guess for first-time visitors without breaking the pure signature.
 */
function resolve_currency(?string $param, ?string $cookie, ?string $default = null): string {
    $p = strtoupper(trim((string)$param));
    if ($p !== '' && is_supported_currency($p)) return $p;
    $c = strtoupper(trim((string)$cookie));
    if (is_supported_currency($c)) return $c;
    $d = strtoupper(trim((string)$default));
    return is_supported_currency($d) ? $d : TS_CURRENCY_DEFAULT;
}

/**
 * First-visit currency guess from the visitor's country (Cloudflare's CF-IPCountry
 * header, set by the CDN/edge in front of the app). Only ever returns a supported currency; unknown
 * or missing → TS_CURRENCY_DEFAULT. A guess only — the guest can always override.
 */
function geo_default_currency(): string {
    $cc = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($cc === '' || $cc === 'XX') return TS_CURRENCY_DEFAULT;
    if ($cc === 'KE') return 'KES';
    if ($cc === 'GB') return 'GBP';
    static $eurozone = ['AT','BE','CY','EE','FI','FR','DE','GR','IE','IT','LV','LT',
                        'LU','MT','NL','PT','SK','SI','ES','HR'];
    if (in_array($cc, $eurozone, true)) return 'EUR';
    return TS_CURRENCY_DEFAULT; // USD for everyone else
}

/**
 * The visitor's chosen display currency (request-cached). Resolution order:
 * valid ?cur= (also persisted as a 1-year cookie) → ts_currency cookie →
 * country geo-guess (first visit only) → default.
 */
function current_currency(): string {
    static $cur = null;
    if ($cur !== null) return $cur;

    $param = $_GET['cur'] ?? '';
    $cur   = resolve_currency($param, $_COOKIE['ts_currency'] ?? '', geo_default_currency());

    // Persist an explicit, valid choice so it survives navigation. Guarded — some
    // render paths (and CLI/tests) have already sent headers; never fatal.
    $p = strtoupper(trim((string)$param));
    if ($p !== '' && $p === $cur) {
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            setcookie('ts_currency', $cur, [
                'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['ts_currency'] = $cur;
    }
    return $cur;
}

/**
 * Convert an amount between currencies (USD-based table). $to defaults to the
 * visitor's current currency. Returns ['amount','currency','converted']. If either
 * rate is missing, returns the ORIGINAL amount+currency (converted=false) so a
 * price is never rendered broken.
 */
function convert_price(float $amount, string $from, ?string $to = null): array {
    $from = strtoupper(trim($from)) ?: TS_CURRENCY_DEFAULT;
    $to   = strtoupper(trim((string)($to ?? current_currency())));
    if (!is_supported_currency($to)) $to = TS_CURRENCY_DEFAULT;

    if ($from === $to) {
        return ['amount' => $amount, 'currency' => $from, 'converted' => true];
    }

    $rates = fx_rates()['rates'];
    $rf = isset($rates[$from]) ? (float)$rates[$from] : 0.0;
    $rt = isset($rates[$to])   ? (float)$rates[$to]   : 0.0;
    if ($rf <= 0 || $rt <= 0) {
        return ['amount' => $amount, 'currency' => $from, 'converted' => false];
    }

    $out   = ($amount / $rf) * $rt;                 // → USD base → target
    $round = TS_CURRENCIES[$to]['round'] ?? 1;
    $out   = $round > 1 ? round($out / $round) * $round : round($out);

    return ['amount' => (float)$out, 'currency' => $to, 'converted' => true];
}

/** Format a bare amount with its currency symbol + thousands grouping (no decimals). */
function format_money(float $amount, string $currency): string {
    $currency = strtoupper(trim($currency));
    $sym = TS_CURRENCIES[$currency]['symbol'] ?? ($currency . ' ');
    return $sym . number_format($amount, 0);
}

/**
 * A price rendered in the visitor's current currency, wrapped so js/currency.js can
 * re-render it instantly on switch (no reload). Carries the ORIGINAL base amount +
 * currency as data-* so the client re-converts from source, not from a rounded value.
 */
function money_html(float $amount, string $from): string {
    $from = strtoupper(trim($from)) ?: TS_CURRENCY_DEFAULT;
    $c = convert_price($amount, $from);
    // Mark as approximate only when a real conversion happened (displayed currency
    // differs from the price's own currency). Exact when shown in its base currency
    // or when a missing rate forced the original through.
    $approx = ($c['converted'] && $c['currency'] !== $from) ? '≈ ' : '';
    return '<span class="ts-price" data-base-amount="' . e((string)$amount)
         . '" data-base-cur="' . e($from) . '">'
         . e($approx . format_money($c['amount'], $c['currency'])) . '</span>';
}

/**
 * Convert the "$1,234" tokens inside a free-text price string (e.g. tour prices
 * like "From $60 / person", "$840 / boat", "On Request") into currency-aware
 * spans, leaving the surrounding words intact. "$" is read as USD. Anything with
 * no "$amount" token (e.g. "On Request") passes through unchanged. Output is HTML.
 */
function money_text_html(string $text): string {
    $safe = e($text); // escape the words first; htmlspecialchars leaves "$60" intact
    return preg_replace_callback('/\$\s?([\d,]+(?:\.\d{1,2})?)/', function ($m) {
        $amt = (float) str_replace(',', '', $m[1]);
        return $amt > 0 ? money_html($amt, 'USD') : $m[0];
    }, $safe);
}

/** Persist the FX rate table (USD-based). Resets the request cache so later reads see it. */
function fx_save(array $rates, array $locked = []): void {
    $rates['USD'] = 1.0; // base is always exactly 1
    set_setting('fx_rates', json_encode([
        'base'       => 'USD',
        'fetched_at' => date('c'),
        'rates'      => $rates,
        'locked'     => $locked,
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * Refresh rates from the free open.er-api.com feed (USD base, no key). Locked
 * currencies are never overwritten. On any fetch/parse failure the last-good
 * rates are kept untouched. Callable from the cron endpoint or the admin button.
 * Returns a status array; never throws.
 */
function fx_sync_rates(): array {
    $cur    = fx_rates();
    $locked = is_array($cur['locked'] ?? null) ? $cur['locked'] : [];

    $ctx = stream_context_create(['http' => [
        'timeout' => 15, 'ignore_errors' => true, 'user_agent' => 'TribalSand/1.0 FXSync',
    ]]);
    $raw  = @file_get_contents('https://open.er-api.com/v6/latest/USD', false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;

    if (!is_array($data) || ($data['result'] ?? '') !== 'success' || empty($data['rates']) || !is_array($data['rates'])) {
        return ['ok' => false, 'stale' => true,
                'message' => 'FX fetch failed — kept last-good rates.',
                'rates' => $cur['rates']];
    }

    $newRates = $cur['rates'];
    $updated  = [];
    foreach (array_keys(TS_CURRENCIES) as $c) {
        if (!empty($locked[$c])) continue;                      // never overwrite a locked rate
        if (isset($data['rates'][$c]) && (float)$data['rates'][$c] > 0) {
            $newRates[$c] = (float)$data['rates'][$c];
            $updated[]    = $c;
        }
    }
    fx_save($newRates, $locked);

    return ['ok' => true, 'fetched_at' => date('c'),
            'updated' => $updated,
            'locked'  => array_keys(array_filter($locked)),
            'rates'   => $newRates];
}

// ── Availability helpers ────────────────────────────────────────

function fetch_units_by_room(int $room_id): array {
    return db_query(
        'SELECT * FROM units WHERE room_id = :id AND is_active = TRUE ORDER BY sort_order ASC',
        [':id' => $room_id]
    )->fetchAll();
}

/**
 * The room whose units actually carry this room's bookable inventory.
 *
 * Maya Ilai's composite products own no units of their own: their inventory is
 * the eight villa units, owned by maya-ilai-villa (units.room_id is NOT NULL, so
 * those eight units can belong to exactly one room). Every other room owns its
 * own units and is its own inventory room.
 *
 * Any guard asking "does this room have units?" MUST ask through here. Asking
 * fetch_units_by_room($room['id']) directly silently downgrades the six unitless
 * composite products to enquiry mode, so they render a booking form and never
 * create a hold.
 *
 * $room must carry 'slug' — mi_is_composite_room() reads it and returns false
 * when the key is absent, which would leave a caller looking fixed while still
 * asking about the wrong room. Fails toward existing behaviour: a missing villa
 * room (pre-migration, or another install) returns the room's own id rather than
 * throwing.
 */
function room_inventory_room_id(array $room): int {
    $ownId = (int)($room['id'] ?? 0);
    if (!mi_is_composite_room($room)) return $ownId;

    // Memoized: these guards run on every render of a Maya Ilai property page.
    static $villaRoomId = null;
    if ($villaRoomId === null) {
        try {
            $villaRoomId = (int) db_query(
                'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
            )->fetchColumn();
        } catch (Throwable $e) {
            $villaRoomId = 0;
        }
    }
    return $villaRoomId ?: $ownId;
}

/**
 * Every active Room—Unit pair, for the "convert to hold" dropdown.
 * Returns rows: unit_id, unit_name, room_id, room_name (ordered by room then unit).
 */
function fetch_room_unit_options(): array {
    return db_query(
        "SELECT u.id AS unit_id, u.name AS unit_name, r.id AS room_id, r.name AS room_name, r.venue_id AS venue_id
         FROM units u
         JOIN rooms r ON r.id = u.room_id
         WHERE u.is_active = TRUE
         ORDER BY r.sort_order ASC, r.name ASC, u.sort_order ASC"
    )->fetchAll();
}

/** The most recent hold linked to a submission, or false. */
function fetch_hold_by_submission(int $submission_id): array|false {
    if ($submission_id <= 0) return false;
    return db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name
         FROM holds h
         JOIN units u ON u.id = h.unit_id
         JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
         WHERE h.submission_id = :sid
         ORDER BY h.id DESC LIMIT 1",
        [':sid' => $submission_id]
    )->fetch();
}

function expire_stale_holds(): void {
    $stmt = db()->prepare(
        "UPDATE holds SET status='expired' WHERE status='pending' AND expires_at < NOW() RETURNING id"
    );
    $stmt->execute();
    $expired_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($expired_ids)) return;

    foreach ($expired_ids as $hid) {
        db_query(
            "DELETE FROM availability_blocks WHERE hold_id = :hid AND block_type = 'hold'",
            [':hid' => $hid]
        );
    }

    // Notify each guest — lazy-load mail to avoid circular dependency
    require_once __DIR__ . '/mail.php';
    foreach ($expired_ids as $hid) {
        $hold = db_query(
            "SELECT h.*, u.name AS unit_name, r.name AS room_name
             FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
             WHERE h.id = :id",
            [':id' => $hid]
        )->fetch();
        if ($hold && !empty($hold['guest_email'])) {
            send_hold_cancelled($hold, 'expired');
        }
    }
}

/**
 * "Entire villa vs by-room" mutual exclusion.
 * Returns the unit IDs (belonging to OTHER rooms in the same venue) whose bookings
 * must also be free for this room to be bookable:
 *   • Whole-villa room (is_entire_place)  → conflicts with EVERY other unit in the venue
 *     (you can't rent the whole place while any individual room is taken).
 *   • Individual room                     → conflicts only with the venue's whole-villa unit(s)
 *     (booking one room blocks the whole-villa option, but not the other rooms).
 * A venue with no whole-villa room returns [] — rooms are then independent as before.
 */
function room_conflict_unit_ids(array $room): array {
    $venue_id = $room['venue_id'] ?? null;
    if (!$venue_id) return [];

    if (!empty($room['is_entire_place'])) {
        // Maya Ilai's exclusion is per-villa and handled by mi_find_villa_unit();
        // the venue-wide buyout rule would block the whole property off one
        // booking. Narrow ON PURPOSE — only this direction is exempt. The other
        // direction ("this room is blocked when the venue's whole-place room is
        // booked") must keep working, or re-adding a compound buyout would let
        // composite products sell villas out from under it.
        if (mi_is_composite_room($room)) return [];
        $sql = "SELECT u.id FROM units u
                JOIN rooms r ON r.id = u.room_id
                WHERE r.venue_id = :vid AND r.id <> :rid AND u.is_active = TRUE";
        $params = [':vid' => $venue_id, ':rid' => $room['id']];
    } else {
        $sql = "SELECT u.id FROM units u
                JOIN rooms r ON r.id = u.room_id
                WHERE r.venue_id = :vid AND r.is_entire_place = TRUE AND u.is_active = TRUE";
        $params = [':vid' => $venue_id];
    }
    return array_map('intval', db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The occupancy of every Maya Ilai villa over a date range.
 *
 * Returns [unit_id => ['unit_id'=>int, 'sort_order'=>int, 'taken'=>string[]], …].
 * A block with components IS NULL means the whole villa is taken, so it
 * contributes every component — that is how pre-existing and staff-entered blocks
 * keep working unchanged.
 */
function mi_villa_states(int $villaRoomId, string $check_in, string $check_out): array {
    $units = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order',
        [':r' => $villaRoomId]
    )->fetchAll();

    $states = [];
    foreach ($units as $u) {
        $states[(int)$u['id']] = [
            'unit_id'    => (int)$u['id'],
            'sort_order' => (int)$u['sort_order'],
            'taken'      => [],
        ];
    }
    if (!$states) return [];

    $blocks = db_query(
        "SELECT ab.unit_id, ab.components::text AS components
           FROM availability_blocks ab
           JOIN units u ON u.id = ab.unit_id
          WHERE u.room_id = :r AND u.is_active = TRUE
            AND ab.date_from < :co AND ab.date_to > :ci",
        [':r' => $villaRoomId, ':ci' => $check_in, ':co' => $check_out]
    )->fetchAll();

    foreach ($blocks as $b) {
        $uid = (int)$b['unit_id'];
        if (!isset($states[$uid])) continue;
        // mi_block_taken_components() owns the NULL-means-whole-unit rule. Do NOT
        // call mi_pg_array_decode() directly here — it returns [] for NULL, which
        // reads as "nothing is taken" and would oversell the villa.
        $comp = mi_block_taken_components($b['components']);
        $states[$uid]['taken'] = array_values(array_unique(
            array_merge($states[$uid]['taken'], $comp)
        ));
    }
    return $states;
}

/**
 * Allocate a villa for a Maya Ilai composite product.
 *
 * Returns the chosen unit row with the resolved component list under
 * '_mi_components', which create_hold_with_block() writes onto the block.
 */
function mi_find_villa_unit(array $room, string $check_in, string $check_out): array|false {
    $pattern = mi_product_map()[$room['slug']] ?? null;
    if ($pattern === null) return false;

    $villaRoomId = (int) db_query(
        'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetchColumn();
    if (!$villaRoomId) return false;

    $states = mi_villa_states($villaRoomId, $check_in, $check_out);
    if (!$states) return false;

    // mi_order_villas() derives the villa total from the list it is given, so
    // $states MUST be the complete villa set — never a pre-filtered subset.
    $reserved = max(0, (int) setting('maya_ilai_reserved_villas', '2'));
    $ordered  = mi_order_villas(
        array_values($states),
        $room['slug'] === MAYA_ILAI_VILLA_ROOM_SLUG,
        $reserved
    );

    foreach ($ordered as $villa) {
        $resolved = mi_resolve($pattern, $villa['taken']);
        if ($resolved === null) continue;
        $unit = db_query('SELECT * FROM units WHERE id = :id', [':id' => $villa['unit_id']])->fetch();
        if (!$unit) continue;
        $unit['_mi_components'] = $resolved;
        return $unit;
    }
    return false;
}

function find_available_unit(int $room_id, string $check_in, string $check_out): array|false {
    expire_stale_holds();

    $room = db_query(
        'SELECT id, slug, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
    if (!$room) return false;

    // Maya Ilai sells several products over the same villas; allocation is by
    // component, not by whole unit. This must run BEFORE the is_entire_place
    // conflict logic below, which does not apply to this property.
    if (mi_is_composite_room($room)) {
        return mi_find_villa_unit($room, $check_in, $check_out);
    }

    // Whole-villa / by-room mutual exclusion: if a conflicting sibling unit is
    // booked for the range, this room cannot be booked at all.
    $conflict_ids = room_conflict_unit_ids($room);
    if ($conflict_ids) {
        $placeholders = implode(',', $conflict_ids); // ints from DB — safe to inline
        $clash = db_query(
            "SELECT 1 FROM availability_blocks
             WHERE unit_id IN ($placeholders)
               AND date_from < :check_out AND date_to > :check_in
             LIMIT 1",
            [':check_in' => $check_in, ':check_out' => $check_out]
        )->fetchColumn();
        if ($clash) return false;
    }

    return db_query(
        "SELECT u.* FROM units u
         WHERE u.room_id = :room_id AND u.is_active = TRUE
           AND NOT EXISTS (
               SELECT 1 FROM availability_blocks ab
               WHERE ab.unit_id = u.id
                 AND ab.date_from < :check_out
                 AND ab.date_to   > :check_in
           )
         ORDER BY u.sort_order ASC
         LIMIT 1",
        [':room_id' => $room_id, ':check_in' => $check_in, ':check_out' => $check_out]
    )->fetch();
}

/**
 * True once holds.room_id exists (migration: add_holds_room_id.sql). Memoised.
 *
 * This column is read on the path of EVERY property, so a deploy that has not
 * run the migration yet must degrade to the old unit -> room behaviour rather
 * than fatal — the house *_supported() contract (checkin_deposit_supported()
 * and friends), with the probe query chosen as explained below.
 */
function holds_room_id_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    // A catalog lookup, not the usual "SELECT <col> ... LIMIT 1" probe, because
    // this is first reached from inside create_hold_with_block() — which
    // mi_allocate_and_hold() runs inside a transaction. In Postgres a failed
    // statement aborts the WHOLE transaction, so on a pre-migration database the
    // probe's own error would kill the booking it was asked about. This query
    // cannot fail. (bookings_supported() uses to_regclass for the same reason.)
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'holds'
                AND column_name = 'room_id'"
        )->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * SQL expression resolving which ROOM (product) a hold is for.
 *
 * A hold's product used to be derivable from its unit, because each room owned
 * its own units. Maya Ilai's composite products break that: six of them own no
 * units and allocate against the villa units, so unit->room reports the villa
 * for every one of them. holds.room_id records the product directly.
 *
 * Falls back to the unit's room for pre-migration rows and for every hold
 * created before this column existed — which is correct for them.
 *
 * Both aliases must be in scope in the query using this fragment (the fallback
 * needs the unit alias even when the column exists).
 */
function hold_room_id_sql(string $holdAlias = 'h', string $unitAlias = 'u'): string {
    return holds_room_id_supported()
        ? "COALESCE({$holdAlias}.room_id, {$unitAlias}.room_id)"
        : "{$unitAlias}.room_id";
}

/**
 * $expiresInHours: NULL = the hold never auto-expires. Staff-typed bookings use
 * NULL so expire_stale_holds() cannot cancel them overnight and free the dates —
 * its predicate (expires_at < NOW()) is NULL for a NULL column and never matches.
 * The interval is built in SQL against NOW() on purpose: the app and database
 * clocks are not in the same timezone, so computing it in PHP would skew it.
 */
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending',
    ?int $expiresInHours = 24,
    ?array $components = null,
    ?int $roomId = null
): int {
    $confirmed = $status === 'confirmed';
    $expiresExpr = $expiresInHours === null ? 'NULL' : 'NOW() + make_interval(hours => :exph)';
    $hold_id = 0;

    // Which product this hold is for. Only written when the column exists, so a
    // deploy that has not run add_holds_room_id.sql yet still books normally —
    // its holds simply fall back to the unit's room, as they always did.
    $writeRoom = $roomId !== null && $roomId > 0 && holds_room_id_supported();
    $roomCol   = $writeRoom ? 'room_id, ' : '';
    $roomVal   = $writeRoom ? ':room, '   : '';

    // In Postgres a statement that raises an error aborts the WHOLE transaction:
    // every later statement dies with "current transaction is aborted". So once
    // this runs inside a transaction (mi_allocate_and_hold() does), the access-code
    // retry below would turn one duplicate-key collision into a hard, unrelated-
    // looking failure. A SAVEPOINT around the INSERT scopes the abort to the
    // attempt, so the retry keeps working.
    //
    // A SAVEPOINT is only legal inside a transaction — outside one Postgres raises
    // a warning and no savepoint exists to roll back to. The non-Maya-Ilai callers
    // run with no transaction at all (each statement is its own), where a failed
    // INSERT poisons nothing, so that path takes no savepoint and behaves exactly
    // as it did before.
    $inTx = db()->inTransaction();
    $sp   = 'hold_access_code';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = generate_access_code();
        if ($inTx) db()->exec("SAVEPOINT {$sp}");
        try {
            $stmt = db()->prepare(
                "INSERT INTO holds
                    ({$roomCol}submission_id, unit_id, check_in, check_out, guest_name, guest_email,
                     access_code, status, confirmed_at, expires_at)
                 VALUES
                    ({$roomVal}:sub, :unit, :ci, :co, :name, :email,
                     :code, :status, :confirmed_at, {$expiresExpr})
                 RETURNING id"
            );
            $params = [
                ':sub'          => $submission_id,
                ':unit'         => $unit_id,
                ':ci'           => $check_in,
                ':co'           => $check_out,
                ':name'         => $guest_name,
                ':email'        => $guest_email,
                ':code'         => $code,
                ':status'       => $status,
                ':confirmed_at' => $confirmed ? date('Y-m-d H:i:s') : null,
            ];
            if ($writeRoom)                $params[':room'] = $roomId;
            if ($expiresInHours !== null) $params[':exph'] = $expiresInHours;
            $stmt->execute($params);
            $hold_id = (int)$stmt->fetchColumn();
            if ($inTx) db()->exec("RELEASE SAVEPOINT {$sp}");
            break;
        } catch (PDOException $e) {
            if ($inTx) {
                try {
                    db()->exec("ROLLBACK TO SAVEPOINT {$sp}");
                } catch (\Throwable $spFailed) {
                    // The transaction is unrecoverable — surface the real error,
                    // never the rollback's, and never retry into a dead session.
                    throw $e;
                }
            }
            if (($e->getCode() === '23505') && $attempt < 4) continue;
            throw $e;
        }
    }

    // NULL components means "the whole unit", which is what every non-Maya-Ilai
    // booking means and what every pre-existing row already says.
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id, components)
         VALUES (:unit, :df, :dt, :bt, :hold, :comp)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out,
         ':bt' => $confirmed ? 'booked' : 'hold', ':hold' => $hold_id,
         ':comp' => $components === null ? null : mi_pg_array_encode($components)]
    );

    return $hold_id;
}

/**
 * Namespace key for the advisory lock taken around Maya Ilai villa allocation.
 *
 * pg_advisory_xact_lock() has a one-key (bigint) form and a two-key (int4, int4)
 * form, and they share no lock space with each other. Using the two-key form with
 * a fixed namespace here means a future advisory lock somewhere else in the app
 * can only collide with this one if it deliberately picks the same namespace —
 * a bare room id in the one-key form cannot.
 */
const MI_ADVISORY_LOCK_NS = 19785; // arbitrary, fixed: "Maya Ilai villa allocation"

/**
 * Allocate a villa and write its hold atomically, for Maya Ilai only.
 *
 * The engine allocates (find_available_unit) and books (create_hold_with_block)
 * in two separate calls, so two concurrent requests can claim the same
 * inventory. For whole units that race is at least visible afterwards — two
 * overlapping blocks on one unit. For components it is not: two blocks each
 * claiming `bunk` violate no constraint and read as ordinary shared occupancy.
 *
 * So for this property the allocation is REDONE here inside a transaction that
 * holds a Postgres advisory lock keyed on the villa room, and the block is
 * written before the lock is released. The caller's earlier find_available_unit()
 * result is only a fast pre-filter; this re-allocation is the authoritative one.
 *
 * Returns the hold id, or FALSE when the dates were taken while we waited.
 */
function mi_allocate_and_hold(
    array $room, ?int $submissionId,
    string $check_in, string $check_out,
    string $guestName, string $guestEmail,
    string $status = 'pending', ?int $expiresInHours = 24
): int|false {
    // Every other property still goes through find_available_unit() +
    // create_hold_with_block(). Landing here with one of those rooms means a
    // caller wired the wrong branch, not that the dates are unavailable.
    if (!mi_is_composite_room($room)) {
        throw new InvalidArgumentException(
            'mi_allocate_and_hold() is for Maya Ilai composite rooms only; got ' .
            ($room['slug'] ?? '(no slug)')
        );
    }

    $villaRoomId = (int) db_query(
        'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetchColumn();
    // No villa room means nothing to allocate from and nothing sane to key the
    // lock on. Fail closed, exactly as mi_find_villa_unit() would.
    if (!$villaRoomId) return false;

    $pdo   = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        // Serialises every concurrent allocation against this villa pool. An
        // xact lock releases itself at commit/rollback, so there is no unlock
        // path that can leak a held lock on an error.
        db_query(
            'SELECT pg_advisory_xact_lock(:ns::int, :room::int)',
            [':ns' => MI_ADVISORY_LOCK_NS, ':room' => $villaRoomId]
        );

        // Re-allocate INSIDE the lock. Whatever the caller resolved earlier was
        // read without one and may already be sold.
        $unit = mi_find_villa_unit($room, $check_in, $check_out);
        if ($unit === false) {
            if ($ownTx) $pdo->rollBack();
            return false;
        }

        // The PRODUCT is $room; the UNIT is a villa. Recording the room on the
        // hold is the only thing that tells anything downstream (the revenue
        // ledger first of all) which of the eight products was actually sold.
        $holdId = create_hold_with_block(
            (int)$unit['id'], $submissionId, $check_in, $check_out,
            $guestName, $guestEmail, $status, $expiresInHours,
            $unit['_mi_components'] ?? null,
            (int)$room['id']
        );

        if ($ownTx) $pdo->commit();
        return $holdId;
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Dates on which no villa can satisfy a Maya Ilai product's component pattern.
 *
 * Resolved one night at a time: availability is a per-night question, and a stay
 * is sellable only when every night of it is.
 */
function mi_blocked_dates(array $room, string $from, string $to): array {
    $pattern = mi_product_map()[$room['slug']] ?? null;
    if ($pattern === null) return [];

    $villaRoomId = (int) db_query(
        'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetchColumn();
    if (!$villaRoomId) return [];

    $reserved = max(0, (int) setting('maya_ilai_reserved_villas', '2'));
    $isVilla  = $room['slug'] === MAYA_ILAI_VILLA_ROOM_SLUG;

    $blocked = [];
    $d   = new DateTime($from);
    $end = new DateTime($to);
    while ($d < $end) {
        $night = $d->format('Y-m-d');
        $next  = (clone $d)->modify('+1 day')->format('Y-m-d');

        $states  = mi_villa_states($villaRoomId, $night, $next);
        $ordered = mi_order_villas(array_values($states), $isVilla, $reserved);

        $fits = false;
        foreach ($ordered as $villa) {
            if (mi_resolve($pattern, $villa['taken']) !== null) { $fits = true; break; }
        }
        if (!$fits) $blocked[] = $night;

        $d->modify('+1 day');
    }
    return $blocked;
}

/**
 * Returns a list of fully-blocked dates (YYYY-MM-DD) for a room:
 * a date is fully blocked when every active unit has a block covering it.
 * Used by the public availability calendar widget.
 */
function get_room_blocked_dates(int $room_id, string $from, string $to): array {
    // Maya Ilai composite products own no units of their own; a date is blocked
    // when no villa can satisfy the product's component pattern that night.
    $miRoom = db_query('SELECT id, slug FROM rooms WHERE id = :id', [':id' => $room_id])->fetch();
    if ($miRoom && mi_is_composite_room($miRoom)) {
        return mi_blocked_dates($miRoom, $from, $to);
    }

    $unit_count = (int)db_query(
        'SELECT COUNT(*) FROM units WHERE room_id = :id AND is_active = TRUE',
        [':id' => $room_id]
    )->fetchColumn();

    if ($unit_count === 0) return [];

    $blocks = db_query(
        "SELECT ab.unit_id, ab.date_from, ab.date_to
         FROM availability_blocks ab
         JOIN units u ON u.id = ab.unit_id
         WHERE u.room_id = :rid AND u.is_active = TRUE
           AND ab.date_to > :from AND ab.date_from < :to
         ORDER BY ab.date_from",
        [':rid' => $room_id, ':from' => $from, ':to' => $to]
    )->fetchAll();

    // Map each date to the set of unit IDs blocking it
    $date_units = [];
    foreach ($blocks as $b) {
        $d   = new DateTime($b['date_from']);
        $end = new DateTime($b['date_to']);
        while ($d < $end) {
            $key = $d->format('Y-m-d');
            $date_units[$key][$b['unit_id']] = true;
            $d->modify('+1 day');
        }
    }

    $fully_blocked = [];
    foreach ($date_units as $date => $uid_map) {
        if (count($uid_map) >= $unit_count) {
            $fully_blocked[] = $date;
        }
    }

    // Whole-villa / by-room mutual exclusion: a date is also blocked whenever any
    // conflicting sibling unit is booked (see room_conflict_unit_ids()).
    $room = db_query(
        'SELECT id, slug, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
    $conflict_ids = $room ? room_conflict_unit_ids($room) : [];
    if ($conflict_ids) {
        $placeholders = implode(',', $conflict_ids); // ints from DB — safe to inline
        $cblocks = db_query(
            "SELECT ab.date_from, ab.date_to FROM availability_blocks ab
             WHERE ab.unit_id IN ($placeholders)
               AND ab.date_to > :from AND ab.date_from < :to",
            [':from' => $from, ':to' => $to]
        )->fetchAll();
        $seen = array_flip($fully_blocked);
        foreach ($cblocks as $b) {
            $d   = new DateTime($b['date_from']);
            $end = new DateTime($b['date_to']);
            while ($d < $end) {
                $key = $d->format('Y-m-d');
                if (!isset($seen[$key])) { $fully_blocked[] = $key; $seen[$key] = true; }
                $d->modify('+1 day');
            }
        }
    }

    sort($fully_blocked);
    return $fully_blocked;
}

function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

// Resolve a stored image filename/URL to a browser-usable URL.
// R2 images: stored as full https:// URL — returned as-is.
// Uploaded images: stored as "rooms/abc.jpg" → /assets/img/rooms/abc.jpg
// Seeded images: stored as "hero.jpg" → /assets/img/hero.jpg
function storage_url(string $filename): string {
    if (empty($filename)) return '';
    // Legacy Cloudflare R2 uploads: the bucket contents were migrated to S3/CloudFront
    // under the SAME object keys, but old DB rows still hold the now-dead pub-*.r2.dev
    // host (the R2 domain no longer resolves, so every such image 404s). Repoint them to
    // the current asset origin (ASSET_URL → CloudFront), preserving the object key. New
    // uploads already store S3 URLs, so no fresh r2.dev values are ever created.
    if (preg_match('#^https?://[^/]*\.r2\.dev/(.+)$#', $filename, $m)) {
        return rtrim(asset_url(''), '/') . '/' . $m[1];
    }
    if (str_starts_with($filename, 'http')) return $filename;
    if ($filename[0] === '/') return $filename;            // already a root-relative URL (e.g. /images/...)
    return '/assets/img/' . $filename;
}

function audit_log(string $action, string $target_type = '', int $target_id = 0, string $notes = ''): void {
    $admin_id = $_SESSION['admin_id'] ?? null;
    db_query(
        'INSERT INTO admin_audit_log (admin_id, action, target_type, target_id, notes)
         VALUES (:aid, :action, :type, :tid, :notes)',
        [':aid'    => $admin_id,
         ':action' => $action,
         ':type'   => $target_type,
         ':tid'    => $target_id ?: null,
         ':notes'  => $notes]
    );
}

function client_ip(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded) {
        $ip = trim(explode(',', $forwarded)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Short-window idempotency guard for lead forms. Returns the id of an identical
 * submission created in the last $seconds (same type + email + check-in/out), or
 * 0 if none. Shared by api/search-lead.php and api/submit-enquiry.php to
 * neutralise double-submits (double-tap on mobile, retry, re-fired POST) without
 * blocking a legitimate repeat enquiry a minute later. Fails open (returns 0) so
 * a query error never blocks a real submission.
 */
function find_recent_duplicate_submission(string $type, string $email,
                                          ?string $checkin = null, ?string $checkout = null,
                                          int $seconds = 30): int {
    $email = trim($email);
    if ($email === '') return 0;
    try {
        $row = db_query(
            "SELECT id FROM submissions
             WHERE type = :type AND lower(guest_email) = lower(:email)
               AND COALESCE(check_in::text,  '') = :ci
               AND COALESCE(check_out::text, '') = :co
               AND created_at > :win
             ORDER BY id DESC LIMIT 1",
            [
                ':type'  => $type,
                ':email' => $email,
                ':ci'    => (string)($checkin  ?? ''),
                ':co'    => (string)($checkout ?? ''),
                ':win'   => date('Y-m-d H:i:s', time() - $seconds),
            ]
        )->fetch();
        return $row ? (int)$row['id'] : 0;
    } catch (Throwable $e) {
        error_log('[lead-dedupe] check failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Generate a short, human-friendly booking access code.
 * Uppercase, unambiguous alphabet (no 0/O/1/I/L). Uses random_int (CSPRNG).
 */
function generate_access_code(int $len = 8): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Price a stay for a room over [check_in, check_out), honouring per-night rate
 * overrides in the `rates` table and falling back to the room's default price.
 * Returns ['nights' => int, 'total' => float]. Shared by the availability API
 * and the search results page so quotes never drift.
 *
 * The per-night resolution itself lives in rates_nightly_map() (includes/rates.php)
 * — the single source of truth the admin rates calendar renders from — so a price
 * shown in Admin can never differ from the price a guest is quoted.
 */
function room_stay_quote(int $room_id, float $default_price, string $check_in, string $check_out): array {
    // Required here, not at file scope: rates.php requires this file, so a
    // file-scope require would be a load-order cycle.
    require_once __DIR__ . '/rates.php';

    // A window we cannot parse is not a $0 stay, and it is not a 47,000-night
    // stay either — strtotime() returns false for garbage, which silently
    // becomes epoch. It is simply not a quote. Say so, and let the caller
    // decide; every caller must reject nights === 0 before displaying a price.
    $ci = rates_window_ymd($check_in);
    $co = rates_window_ymd($check_out);
    if ($ci === null || $co === null || $ci >= $co) return ['nights' => 0, 'total' => 0.0];

    $nights = max(1, (int)((strtotime($co) - strtotime($ci)) / 86400));

    // A valid window always yields a full map (defaults included), so this can
    // never sum to zero.
    $total = 0.0;
    foreach (rates_nightly_map($room_id, $default_price, $ci, $co) as $night) {
        $total += $night['price'];
    }
    return ['nights' => $nights, 'total' => round($total, 2)];
}

/**
 * Cross-property availability for a date range. Returns one entry per published
 * venue with its available published rooms (priced), a count and a "from" total.
 * Used by the /search results page.
 */
function ts_search_availability(string $check_in, string $check_out, int $guests = 1): array {
    $venues = db_query('SELECT * FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC')->fetchAll();
    $results = [];
    foreach ($venues as $v) {
        $rooms = db_query(
            "SELECT r.*, (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero
             FROM rooms r WHERE r.venue_id = :vid AND r.is_published = TRUE
             ORDER BY r.is_entire_place ASC, r.sort_order ASC",
            [':vid' => $v['id']]
        )->fetchAll();

        // Free/booked status per room for the requested dates.
        $free = [];
        foreach ($rooms as $r) {
            $free[$r['id']] = (bool) find_available_unit((int)$r['id'], $check_in, $check_out);
        }
        // Whole-villa vs individual-room mutual exclusion:
        //  · the Entire Villa is bookable only when it AND every individual room is free
        //  · individual rooms disappear once the Entire Villa is booked for these dates
        $entire_booked  = false; // someone holds the whole villa
        $all_rooms_free = true;  // every individual (non-entire) room is free
        foreach ($rooms as $r) {
            if (!empty($r['is_entire_place'])) {
                if (!$free[$r['id']]) $entire_booked = true;
            } else {
                if (!$free[$r['id']]) $all_rooms_free = false;
            }
        }

        $mkItem = function(array $r, bool $entire) use ($check_in, $check_out) {
            $q = room_stay_quote((int)$r['id'], (float)$r['price_amount'], $check_in, $check_out);
            return [
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'entire'     => $entire,
                'capacity'   => (int)($r['capacity'] ?? 0),
                'short_desc' => $r['short_desc'] ?? '',
                'tag'        => $r['tag_label'] ?: ($entire ? 'Whole property' : ''),
                'price'      => (float)$r['price_amount'],
                'currency'   => $r['price_currency'] ?: 'USD',
                'nights'     => $q['nights'],
                'total'      => $q['total'],
                'hero'       => !empty($r['hero']) ? storage_url($r['hero']) : null,
            ];
        };

        $available = [];
        foreach ($rooms as $r) {
            $cap = (int)($r['capacity'] ?? 0);
            if ($cap > 0 && $guests > 0 && $cap < $guests) continue; // too small for the party
            $entire = !empty($r['is_entire_place']);
            if ($entire) {
                // Entire villa: only when the villa itself and all rooms are free
                if (!$free[$r['id']] || !$all_rooms_free) continue;
            } else {
                // Individual room: only when it's free and the villa isn't taken
                if (!$free[$r['id']] || $entire_booked) continue;
            }
            $available[] = $mkItem($r, $entire);
        }

        $vimgs = fetch_venue_images((int)$v['id']);
        $results[] = [
            'venue'    => $v,
            'hero'     => $vimgs ? storage_url($vimgs[0]['filename']) : null,
            'rooms'    => $available,
            'count'    => count($available),
            'from'     => $available ? min(array_map(fn($r) => $r['total'], $available)) : null,
            'currency' => $available[0]['currency'] ?? 'USD',
        ];
    }
    return $results;
}

/**
 * Editable property page copy (venues.tagline / about_heading / about_body).
 * Returns [] and never throws if the columns/migration aren't present yet,
 * so pages safely fall back to their built-in text.
 */
function ts_venue_content(string $slug): array {
    static $cache = [];
    if (!array_key_exists($slug, $cache)) {
        try {
            $row = db_query('SELECT tagline, about_heading, about_body FROM venues WHERE slug = :s', [':s' => $slug])->fetch();
            $cache[$slug] = $row ?: [];
        } catch (Throwable $e) {
            $cache[$slug] = [];
        }
    }
    return $cache[$slug];
}

/**
 * The admin-managed hero image URL for a venue (venue_images, is_hero first),
 * or the given fallback when none is set / the DB is unavailable. Cached per
 * request. Lets the homepage cards reflect what's uploaded in the admin.
 */
function venue_hero_url(string $slug, string $fallback = ''): string {
    static $cache = [];
    if (!array_key_exists($slug, $cache)) {
        try {
            $v = db_query('SELECT id FROM venues WHERE slug = :s', [':s' => $slug])->fetch();
            $imgs = $v ? fetch_venue_images((int)$v['id']) : [];
            $cache[$slug] = $imgs ? storage_url($imgs[0]['filename']) : '';
        } catch (Throwable $e) {
            $cache[$slug] = '';
        }
    }
    return $cache[$slug] !== '' ? $cache[$slug] : $fallback;
}

/**
 * Do the editable-content columns (tagline / about_*) exist on `venues`?
 * On a DB whose Neon→RDS move brought the base table but not this migration
 * they are absent, and an unguarded `UPDATE venues SET tagline=…` would 500
 * the admin save. Callers guard the content UPDATE with this. Cached per request.
 */
function venue_content_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT tagline, about_heading, about_body FROM venues LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Do the per-property stay/location columns (address / maps_url / stay_*) exist? */
function venue_stay_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT address, maps_url, stay_wifi, stay_checkout, stay_house_rules, stay_area_guide FROM venues LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** One editable field for a venue, or the fallback when unset/blank. */
function ts_venue_text(string $slug, string $field, string $fallback = ''): string {
    $c = ts_venue_content($slug);
    return (isset($c[$field]) && trim((string)$c[$field]) !== '') ? (string)$c[$field] : $fallback;
}

/** Convert *word* to <em>word</em> in already-escaped text (for headings). */
function ts_emphasis(string $escaped): string {
    return preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped);
}

/**
 * Coerce form input to valid UTF-8. Browsers (and pastes from Word/Outlook/Excel)
 * can submit Windows-1252 bytes — most commonly the em-dash 0x97 or smart quotes —
 * which PostgreSQL's UTF-8 columns reject with a fatal "invalid byte sequence"
 * error, silently breaking admin saves. Normalising once here, before any handler
 * reads the superglobals, turns those bytes into proper UTF-8 instead of crashing.
 */
function normalize_utf8(&$value): void {
    if (is_array($value)) {
        foreach ($value as &$v) normalize_utf8($v);
        return;
    }
    if (is_string($value) && $value !== '' && !mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
normalize_utf8($_POST);
normalize_utf8($_GET);
normalize_utf8($_REQUEST);

require_once __DIR__ . '/turnstile.php';
