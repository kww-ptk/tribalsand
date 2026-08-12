<?php
declare(strict_types=1);

// The property (and its guests, staff and gate) operate in Kenya. Run the whole
// app in Africa/Nairobi so every date() / strtotime() / DateTime('now') and the
// Postgres NOW() / CURRENT_DATE / ::date all agree — otherwise "today" computed
// in Nairobi disagrees with UTC-stored rows (e.g. the gate visitor day-filter
// dropped today's entries after 21:00 UTC). Set once at module load.
date_default_timezone_set('Africa/Nairobi');

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $env = parse_env();

    // Render (and most PaaS) provide a single DATABASE_URL
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

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Emulated prepares send plain SQL rather than server-side prepared
        // statements. Required behind Neon's PgBouncer pooler: real prepares there
        // cache query plans in pooled backend connections that survive app
        // restarts, so a migration that adds columns (e.g. SELECT h.*) triggers
        // "cached plan must not change result type" and an app redeploy can't
        // clear it. No bound LIMIT/OFFSET params exist, so this mode is safe here.
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);

    // Align the DB session with the app's Nairobi timezone (above). Makes NOW(),
    // CURRENT_DATE, ::date casts and timestamptz rendering all Nairobi-local.
    // Neon's PgBouncer tracks the TimeZone session parameter, so this persists
    // across pooled statements within the connection.
    $pdo->exec("SET TIME ZONE 'Africa/Nairobi'");

    return $pdo;
}

function parse_env(): array {
    static $env = null;
    if ($env !== null) return $env;

    // Render and other hosts inject env vars directly — use those first
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

// ── Availability helpers ────────────────────────────────────────

function fetch_units_by_room(int $room_id): array {
    return db_query(
        'SELECT * FROM units WHERE room_id = :id AND is_active = TRUE ORDER BY sort_order ASC',
        [':id' => $room_id]
    )->fetchAll();
}

/**
 * Every active Room—Unit pair, for the "convert to hold" dropdown.
 * Returns rows: unit_id, unit_name, room_id, room_name (ordered by room then unit).
 */
function fetch_room_unit_options(): array {
    return db_query(
        "SELECT u.id AS unit_id, u.name AS unit_name, r.id AS room_id, r.name AS room_name
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
         JOIN rooms r ON r.id = u.room_id
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
             JOIN rooms r ON r.id = u.room_id
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

function find_available_unit(int $room_id, string $check_in, string $check_out): array|false {
    expire_stale_holds();

    $room = db_query(
        'SELECT id, venue_id, is_entire_place FROM rooms WHERE id = :id',
        [':id' => $room_id]
    )->fetch();
    if (!$room) return false;

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
    ?int $expiresInHours = 24
): int {
    $confirmed = $status === 'confirmed';
    $expiresExpr = $expiresInHours === null ? 'NULL' : 'NOW() + make_interval(hours => :exph)';
    $hold_id = 0;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = generate_access_code();
        try {
            $stmt = db()->prepare(
                "INSERT INTO holds
                    (submission_id, unit_id, check_in, check_out, guest_name, guest_email,
                     access_code, status, confirmed_at, expires_at)
                 VALUES
                    (:sub, :unit, :ci, :co, :name, :email,
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
            if ($expiresInHours !== null) $params[':exph'] = $expiresInHours;
            $stmt->execute($params);
            $hold_id = (int)$stmt->fetchColumn();
            break;
        } catch (PDOException $e) {
            if (($e->getCode() === '23505') && $attempt < 4) continue;
            throw $e;
        }
    }

    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id)
         VALUES (:unit, :df, :dt, :bt, :hold)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out,
         ':bt' => $confirmed ? 'booked' : 'hold', ':hold' => $hold_id]
    );

    return $hold_id;
}

/**
 * Returns a list of fully-blocked dates (YYYY-MM-DD) for a room:
 * a date is fully blocked when every active unit has a block covering it.
 * Used by the public availability calendar widget.
 */
function get_room_blocked_dates(int $room_id, string $from, string $to): array {
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
        'SELECT id, venue_id, is_entire_place FROM rooms WHERE id = :id',
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
 */
function room_stay_quote(int $room_id, float $default_price, string $check_in, string $check_out): array {
    $nights = max(1, (int)((strtotime($check_out) - strtotime($check_in)) / 86400));
    $overlap = db_query(
        "SELECT date_from, date_to, price_amount FROM rates
         WHERE room_id = :rid AND date_from < :co AND date_to > :ci
         ORDER BY created_at DESC",
        [':rid' => $room_id, ':ci' => $check_in, ':co' => $check_out]
    )->fetchAll();
    $by_night = [];
    foreach ($overlap as $r) {
        $d = new DateTime($r['date_from']); $end = new DateTime($r['date_to']);
        while ($d < $end) { $k = $d->format('Y-m-d'); if (!isset($by_night[$k])) $by_night[$k] = (float)$r['price_amount']; $d->modify('+1 day'); }
    }
    $total = 0.0; $d = new DateTime($check_in); $end = new DateTime($check_out);
    while ($d < $end) { $total += $by_night[$d->format('Y-m-d')] ?? $default_price; $d->modify('+1 day'); }
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
