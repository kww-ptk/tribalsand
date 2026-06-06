<?php
declare(strict_types=1);

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
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

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

function find_available_unit(int $room_id, string $check_in, string $check_out): array|false {
    expire_stale_holds();
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

function create_hold_with_block(
    int $unit_id, int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email
): int {
    $stmt = db()->prepare(
        "INSERT INTO holds (submission_id, unit_id, check_in, check_out, guest_name, guest_email, expires_at)
         VALUES (:sub, :unit, :ci, :co, :name, :email, NOW() + INTERVAL '24 hours')
         RETURNING id"
    );
    $stmt->execute([
        ':sub'   => $submission_id,
        ':unit'  => $unit_id,
        ':ci'    => $check_in,
        ':co'    => $check_out,
        ':name'  => $guest_name,
        ':email' => $guest_email,
    ]);
    $hold_id = (int)$stmt->fetchColumn();

    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id)
         VALUES (:unit, :df, :dt, 'hold', :hold)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out, ':hold' => $hold_id]
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

require_once __DIR__ . '/turnstile.php';
