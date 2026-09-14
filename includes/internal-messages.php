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

/** channel_key for the reads table: venue_id (>0) or 0 for the all-team channel. */
function internal_channel_key(?int $venueId): int { return $venueId && $venueId > 0 ? $venueId : 0; }

/**
 * Channels the current account can see, in display order:
 * all-team first, then each accessible venue. Owner = every published venue.
 * Returns [['venue_id'=>int|null, 'key'=>int, 'label'=>string], …].
 */
function internal_channels_for_user(): array {
    $out = [['venue_id' => null, 'key' => 0, 'label' => 'All team']];
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
        $out[] = ['venue_id' => (int)$v['id'], 'key' => (int)$v['id'], 'label' => (string)$v['name']];
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
function fetch_internal_messages_since(?int $venueId, int $after = 0, int $limit = 200): array {
    if (!internal_messages_supported()) return [];
    $cond = $venueId === null || $venueId === 0 ? 'm.channel_venue_id IS NULL' : 'm.channel_venue_id = :v';
    $params = [':after' => $after];
    if ($venueId) $params[':v'] = (int)$venueId;
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
function fetch_internal_messages(?int $venueId, int $limit = 200): array {
    return fetch_internal_messages_since($venueId, 0, $limit);
}

/** Insert a message. Returns the new id. Caller must have checked access. */
function post_internal_message(?int $venueId, int $senderId, string $body): int {
    $body = trim($body);
    if ($body === '') return 0;
    if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
    db_query(
        "INSERT INTO internal_messages (channel_venue_id, sender_admin_id, body) VALUES (:v, :s, :b)",
        [':v' => $venueId ?: null, ':s' => $senderId, ':b' => $body]
    );
    return (int) db()->lastInsertId();
}

/** Mark a channel read up to $lastId for the current account (high-water mark). */
function internal_mark_channel_read(int $adminId, ?int $venueId, int $lastId): void {
    if (!internal_messages_supported() || $lastId <= 0) return;
    $key = internal_channel_key($venueId);
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
    $out = [];
    foreach ($channels as $ch) {
        $key   = (int)$ch['key'];
        $after = $reads[$key] ?? 0;
        $cond  = $ch['venue_id'] === null ? 'channel_venue_id IS NULL' : 'channel_venue_id = :v';
        $params = [':a' => $after, ':me' => $adminId];
        if ($ch['venue_id'] !== null) $params[':v'] = (int)$ch['venue_id'];
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
    $venueClause = 'm.channel_venue_id IS NULL';                 // all-team, always
    $params = [':me' => $adminId];
    if ($ids === null) {
        $venueClause .= " OR m.channel_venue_id IN (SELECT id FROM venues WHERE is_published = TRUE)";
    } elseif ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $venueClause .= " OR m.channel_venue_id IN ($in)";
    }
    return (int) db_query(
        "SELECT COUNT(*)
           FROM internal_messages m
           LEFT JOIN internal_channel_reads r
             ON r.admin_user_id = :me
            AND r.channel_key = COALESCE(m.channel_venue_id, 0)
          WHERE ($venueClause)
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
