<?php
/**
 * Internal team messaging helpers (team ↔ team), separate from the guest
 * booking_messages inbox. Channels are keyed off the property/team structure:
 * one channel per venue the account can access, plus an all-team channel.
 *
 * Every read is pre-migration-safe via internal_messages_supported(), so a
 * deploy without the add_internal_messages migration just hides the feature.
 *
 * Scope: any signed-in account may use the all-team channel; a venue channel is
 * visible to the accounts assigned to that venue (owner sees all). This is a
 * BROADER audience than guest messaging — ops & gate staff are included.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** Both tables present? Cached per request. */
function internal_messages_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = (bool) db_query("SELECT to_regclass('public.internal_messages')")->fetchColumn()
                 && (bool) db_query("SELECT to_regclass('public.internal_channel_reads')")->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

/** Custom group-channel tables present? Cached per request. */
function internal_group_channels_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = (bool) db_query("SELECT to_regclass('public.internal_channels')")->fetchColumn()
                 && (bool) db_query("SELECT to_regclass('public.internal_channel_members')")->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

/**
 * channel_key for the reads table. Group channels use the NEGATIVE group id, venue
 * channels their (positive) venue id, and the all-team channel 0 — one integer key
 * space with no collisions (venue ids are always > 0).
 */
function internal_channel_key(?int $venueId, ?int $groupId = null): int {
    if ($groupId && $groupId > 0) return -$groupId;
    return $venueId && $venueId > 0 ? $venueId : 0;
}

/**
 * Parse a channel address string into a [venue_id, group_id] pair:
 *   'all' / ''      → [null, null]  (all-team)
 *   'g<n>'          → [null, n]     (custom group)
 *   '<n>'           → [n, null]     (venue)
 */
function internal_parse_channel(string $raw): array {
    $raw = trim($raw);
    if ($raw === '' || $raw === 'all') return [null, null];
    if ($raw[0] === 'g' && ctype_digit(substr($raw, 1))) return [null, (int)substr($raw, 1)];
    return [(int)$raw, null];
}

/** The address string for a channel descriptor (inverse of internal_parse_channel). */
function internal_channel_addr(?int $venueId, ?int $groupId = null): string {
    if ($groupId && $groupId > 0) return 'g' . $groupId;
    return $venueId && $venueId > 0 ? (string)$venueId : 'all';
}

/** Group ids the account is a member of. Empty pre-migration. */
function internal_user_group_ids(int $adminId): array {
    if (!internal_group_channels_supported() || $adminId <= 0) return [];
    try {
        return array_map('intval', db_query(
            "SELECT m.channel_id FROM internal_channel_members m
             JOIN internal_channels c ON c.id = m.channel_id
             WHERE m.admin_user_id = :a AND c.is_active = TRUE",
            [':a' => $adminId]
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** True if the account is a member of the (active) group. */
function internal_can_access_group(int $groupId, int $adminId): bool {
    if ($groupId <= 0 || $adminId <= 0 || !internal_group_channels_supported()) return false;
    try {
        return (bool) db_query(
            "SELECT 1 FROM internal_channel_members m
             JOIN internal_channels c ON c.id = m.channel_id
             WHERE m.channel_id = :g AND m.admin_user_id = :a AND c.is_active = TRUE",
            [':g' => $groupId, ':a' => $adminId]
        )->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** One group row (id, name, created_by, …) or false. */
function fetch_internal_group(int $groupId): array|false {
    if (!internal_group_channels_supported() || $groupId <= 0) return false;
    try {
        $r = db_query("SELECT * FROM internal_channels WHERE id = :g AND is_active = TRUE", [':g' => $groupId])->fetch();
        return $r ?: false;
    } catch (Throwable $e) { return false; }
}

/** Member accounts of a group (id, name, email). */
function fetch_internal_group_members(int $groupId): array {
    if (!internal_group_channels_supported() || $groupId <= 0) return [];
    try {
        return db_query(
            "SELECT a.id, a.name, a.email FROM internal_channel_members m
             JOIN admin_users a ON a.id = m.admin_user_id
             WHERE m.channel_id = :g ORDER BY a.name ASC",
            [':g' => $groupId]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Create a group chat with a name and a member set (the creator is always a
 * member). Returns the new group id, or 0 on failure. Caller authorises.
 */
function create_internal_group(string $name, int $creatorId, array $memberIds, bool $isDirect = false): int {
    if (!internal_group_channels_supported()) return 0;
    $name = trim($name);
    if ($name === '' || $creatorId <= 0) return 0;
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
    $ids = [];
    foreach (array_merge([$creatorId], $memberIds) as $m) { $m = (int)$m; if ($m > 0) $ids[$m] = true; }
    // Manage a transaction only when the caller hasn't already opened one (PDO/pgsql
    // cannot nest) — same convention as rates_apply_ranges().
    $owns = !db()->inTransaction();
    try {
        if ($owns) db()->beginTransaction();
        db_query("INSERT INTO internal_channels (name, created_by, is_direct) VALUES (:n, :c, :d)", [':n' => $name, ':c' => $creatorId, ':d' => $isDirect ? 't' : 'f']);
        $gid = (int) db()->lastInsertId();
        // Only real, active accounts can be members.
        foreach (array_keys($ids) as $mid) {
            $ok = db_query("SELECT 1 FROM admin_users WHERE id = :i AND is_active = TRUE", [':i' => $mid])->fetchColumn();
            if ($ok) db_query(
                "INSERT INTO internal_channel_members (channel_id, admin_user_id) VALUES (:g, :a) ON CONFLICT DO NOTHING",
                [':g' => $gid, ':a' => $mid]
            );
        }
        if ($owns) db()->commit();
        return $gid;
    } catch (Throwable $e) {
        if ($owns && db()->inTransaction()) db()->rollBack();
        error_log('[internal-messages] create group failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Find (or create) the 1:1 direct-message channel between two accounts (Item 6).
 * Returns the group id, or 0. Reuses an existing direct channel between exactly
 * these two so a manager doesn't spawn duplicates by clicking Message twice.
 */
function internal_direct_channel(int $meId, int $otherId): int {
    if (!internal_group_channels_supported() || $meId <= 0 || $otherId <= 0 || $meId === $otherId) return 0;
    try {
        $gid = db_query(
            "SELECT c.id FROM internal_channels c
             WHERE c.is_active = TRUE AND c.is_direct = TRUE
               AND (SELECT COUNT(*) FROM internal_channel_members m WHERE m.channel_id = c.id) = 2
               AND EXISTS (SELECT 1 FROM internal_channel_members m WHERE m.channel_id = c.id AND m.admin_user_id = :me)
               AND EXISTS (SELECT 1 FROM internal_channel_members m WHERE m.channel_id = c.id AND m.admin_user_id = :o)
             LIMIT 1",
            [':me' => $meId, ':o' => $otherId]
        )->fetchColumn();
        if ($gid) return (int)$gid;

        // Only start a DM with a real, active account.
        $names = db_query(
            "SELECT id, COALESCE(NULLIF(TRIM(name), ''), email) AS n FROM admin_users
             WHERE id IN (:me, :o) AND is_active = TRUE",
            [':me' => $meId, ':o' => $otherId]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!isset($names[$meId], $names[$otherId])) return 0;
        // First-name-ish label, both people, so it reads the same for either party.
        $short = fn($s) => explode(' ', trim((string)$s))[0] ?: (string)$s;
        $label = $short($names[$meId]) . ' & ' . $short($names[$otherId]);
        return create_internal_group($label, $meId, [$otherId], true);
    } catch (Throwable $e) {
        error_log('[internal-messages] direct channel failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Channels the current account can see, in display order:
 * all-team first, then each accessible venue. Owner = every published venue.
 * Returns [['venue_id'=>int|null, 'key'=>int, 'label'=>string], …].
 */
function internal_channels_for_user(): array {
    $out = [['venue_id' => null, 'group_id' => null, 'key' => 0, 'label' => 'All team']];
    $ids = admin_venue_ids(); // null = owner (all)
    if ($ids === null) {
        $rows = db_query('SELECT id, name FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();
    } elseif ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $rows = db_query("SELECT id, name FROM venues WHERE id IN ($in) ORDER BY sort_order ASC, name ASC")->fetchAll();
    } else {
        $rows = [];
    }
    foreach ($rows as $v) {
        $out[] = ['venue_id' => (int)$v['id'], 'group_id' => null, 'key' => (int)$v['id'], 'label' => (string)$v['name']];
    }
    // Custom group chats the current account belongs to.
    if (internal_group_channels_supported()) {
        $meId = (int)($_SESSION['admin_id'] ?? 0);
        $gids = internal_user_group_ids($meId);
        if ($gids) {
            $in = implode(',', array_map('intval', $gids));
            foreach (db_query("SELECT id, name FROM internal_channels WHERE id IN ($in) AND is_active = TRUE ORDER BY name ASC")->fetchAll() as $g) {
                $out[] = ['venue_id' => null, 'group_id' => (int)$g['id'], 'key' => -(int)$g['id'], 'label' => (string)$g['name']];
            }
        }
    }
    return $out;
}

/** Can the current account read/post in this channel? NULL venue = all-team (any admin). */
function internal_can_access_channel(?int $venueId): bool {
    if ($venueId === null || $venueId === 0) return true; // all-team
    if (is_owner()) return true;
    $ids = admin_venue_ids();
    return $ids !== null && in_array((int)$venueId, array_map('intval', $ids), true);
}

/** Messages in a channel with id > $after (oldest → newest), with sender names. */
function fetch_internal_messages_since(?int $venueId, int $after = 0, int $limit = 200, ?int $groupId = null): array {
    if (!internal_messages_supported()) return [];
    $params = [':after' => $after];
    if ($groupId && internal_group_channels_supported()) {
        $cond = 'm.group_channel_id = :g';
        $params[':g'] = (int)$groupId;
    } elseif ($venueId) {
        $cond = 'm.channel_venue_id = :v';
        $params[':v'] = (int)$venueId;
    } else {
        // All-team: neither a venue nor (once supported) a group message.
        $cond = internal_group_channels_supported()
            ? 'm.channel_venue_id IS NULL AND m.group_channel_id IS NULL'
            : 'm.channel_venue_id IS NULL';
    }
    return db_query(
        "SELECT m.id, m.sender_admin_id, m.body, m.created_at, u.name AS sender_name
           FROM internal_messages m
           LEFT JOIN admin_users u ON u.id = m.sender_admin_id
          WHERE $cond AND m.id > :after
          ORDER BY m.id ASC
          LIMIT " . (int)$limit,
        $params
    )->fetchAll();
}

/** Whole thread for a channel (initial render). */
function fetch_internal_messages(?int $venueId, int $limit = 200, ?int $groupId = null): array {
    return fetch_internal_messages_since($venueId, 0, $limit, $groupId);
}

/** Insert a message. Returns the new id. Caller must have checked access. */
function post_internal_message(?int $venueId, int $senderId, string $body, ?int $groupId = null): int {
    $body = trim($body);
    if ($body === '') return 0;
    if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
    if ($groupId && internal_group_channels_supported()) {
        db_query(
            "INSERT INTO internal_messages (channel_venue_id, group_channel_id, sender_admin_id, body) VALUES (NULL, :g, :s, :b)",
            [':g' => (int)$groupId, ':s' => $senderId, ':b' => $body]
        );
    } else {
        db_query(
            "INSERT INTO internal_messages (channel_venue_id, sender_admin_id, body) VALUES (:v, :s, :b)",
            [':v' => $venueId ?: null, ':s' => $senderId, ':b' => $body]
        );
    }
    return (int) db()->lastInsertId();
}

/** Mark a channel read up to $lastId for the current account (high-water mark). */
function internal_mark_channel_read(int $adminId, ?int $venueId, int $lastId, ?int $groupId = null): void {
    if (!internal_messages_supported() || $lastId <= 0) return;
    $key = internal_channel_key($venueId, $groupId);
    db_query(
        "INSERT INTO internal_channel_reads (admin_user_id, channel_key, last_read_id)
         VALUES (:a, :k, :l)
         ON CONFLICT (admin_user_id, channel_key)
         DO UPDATE SET last_read_id = GREATEST(internal_channel_reads.last_read_id, EXCLUDED.last_read_id)",
        [':a' => $adminId, ':k' => $key, ':l' => $lastId]
    );
}

/**
 * Per-channel unread counts for the current account, keyed by channel_key.
 * A message the account sent itself is never unread to them.
 */
function internal_unread_by_channel(int $adminId, array $channels): array {
    if (!internal_messages_supported()) return [];
    // last_read per channel_key
    $reads = [];
    foreach (db_query('SELECT channel_key, last_read_id FROM internal_channel_reads WHERE admin_user_id = :a', [':a' => $adminId])->fetchAll() as $r) {
        $reads[(int)$r['channel_key']] = (int)$r['last_read_id'];
    }
    $groupsOn = internal_group_channels_supported();
    $out = [];
    foreach ($channels as $ch) {
        $key   = (int)$ch['key'];
        $after = $reads[$key] ?? 0;
        $params = [':a' => $after, ':me' => $adminId];
        $gid = $ch['group_id'] ?? null;
        if ($gid) {
            $cond = 'group_channel_id = :g';
            $params[':g'] = (int)$gid;
        } elseif ($ch['venue_id'] !== null) {
            $cond = 'channel_venue_id = :v';
            $params[':v'] = (int)$ch['venue_id'];
        } else {
            $cond = $groupsOn ? 'channel_venue_id IS NULL AND group_channel_id IS NULL' : 'channel_venue_id IS NULL';
        }
        $out[$key] = (int) db_query(
            "SELECT COUNT(*) FROM internal_messages
              WHERE $cond AND id > :a AND (sender_admin_id IS NULL OR sender_admin_id <> :me)",
            $params
        )->fetchColumn();
    }
    return $out;
}

/**
 * Total unread across all the account's channels — for the sidebar badge.
 * ONE query (this runs on every admin page via the layout), joining each
 * message to the reader's per-channel high-water mark; N+1 would be N COUNTs
 * per page load. Channel visibility mirrors internal_channels_for_user():
 * the all-team channel plus every venue the account can access.
 */
function internal_unread_total(int $adminId): int {
    if (!internal_messages_supported()) return 0;
    $ids = admin_venue_ids();   // null = owner (all published venues)
    $groupsOn = internal_group_channels_supported();
    // All-team is always visible; once groups exist it must exclude group messages.
    $clause = $groupsOn ? '(m.channel_venue_id IS NULL AND m.group_channel_id IS NULL)' : 'm.channel_venue_id IS NULL';
    $params = [':me' => $adminId];
    if ($ids === null) {
        $clause .= " OR m.channel_venue_id IN (SELECT id FROM venues WHERE is_published = TRUE)";
    } elseif ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $clause .= " OR m.channel_venue_id IN ($in)";
    }
    // Group channels the account belongs to.
    if ($groupsOn) {
        $gids = internal_user_group_ids($adminId);
        if ($gids) {
            $gin = implode(',', array_map('intval', $gids));
            $clause .= " OR m.group_channel_id IN ($gin)";
        }
    }
    // A group message maps to read-key -group_id; a venue message to its id; all-team to 0.
    $keyExpr = $groupsOn
        ? "CASE WHEN m.group_channel_id IS NOT NULL THEN -m.group_channel_id ELSE COALESCE(m.channel_venue_id, 0) END"
        : "COALESCE(m.channel_venue_id, 0)";
    return (int) db_query(
        "SELECT COUNT(*)
           FROM internal_messages m
           LEFT JOIN internal_channel_reads r
             ON r.admin_user_id = :me
            AND r.channel_key = $keyExpr
          WHERE ($clause)
            AND m.id > COALESCE(r.last_read_id, 0)
            AND (m.sender_admin_id IS NULL OR m.sender_admin_id <> :me)",
        $params
    )->fetchColumn();
}

/** Payload shape shared by initial render + poll append. */
function internal_message_payload(array $row, int $meId): array {
    require_once __DIR__ . '/booking.php'; // message_time_label()
    return [
        'id'          => (int)$row['id'],
        'mine'        => (int)($row['sender_admin_id'] ?? 0) === $meId,
        'sender_name' => trim((string)($row['sender_name'] ?? '')) ?: 'Team',
        'body'        => (string)$row['body'],
        'time_label'  => message_time_label((string)($row['created_at'] ?? 'now')),
    ];
}
