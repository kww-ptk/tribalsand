<?php
declare(strict_types=1);
/**
 * Internal staff-only notes thread for submissions (redesign #15).
 *
 * A CRM-style timestamped log per submission — who wrote what, when. This is
 * distinct from the guest↔staff Messages surface; notes here are never shown to
 * guests. Every read/write is pre-migration-safe (see submission_notes_supported())
 * so an older deploy without the table never 42P01s the submissions admin.
 *
 * Depends on includes/db.php (db_query, e()).
 */

/** True if the submission_notes table exists (memoised). False pre-migration. */
function submission_notes_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_name = 'submission_notes' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}

/**
 * True once add_submission_notes_kind.sql has been applied (the kind +
 * author_name columns exist). False pre-migration — callers then fall back to
 * plain notes. Memoised.
 */
function submission_notes_kind_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!submission_notes_supported()) return $cached = false;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'submission_notes' AND column_name = 'kind' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}

/**
 * Notes for one submission, oldest-first. Each row carries `kind`
 * (note|reply|guest_reply — 'note' pre-migration), the frozen `author_name`
 * captured at write time (frozen_author), and the linked admin's current
 * name/email for a fallback label. [] pre-migration / on error.
 */
function fetch_submission_notes(int $submission_id): array {
    if ($submission_id <= 0 || !submission_notes_supported()) return [];
    $kindSel = submission_notes_kind_supported()
        ? "n.kind, NULLIF(n.author_name, '') AS frozen_author,"
        : "'note'::text AS kind, NULL::text AS frozen_author,";
    try {
        return db_query(
            "SELECT n.id, n.body, n.created_at, n.admin_id, {$kindSel}
                    a.name AS author_name, a.email AS author_email
             FROM submission_notes n
             LEFT JOIN admin_users a ON a.id = n.admin_id
             WHERE n.submission_id = :sid
             ORDER BY n.created_at ASC, n.id ASC",
            [':sid' => $submission_id]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[submission-notes] fetch failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Notes for one submission with id > $afterId (oldest-first) — the "poll" query
 * that live messaging uses to fetch only what is new since the last seen id.
 * Same row shape as fetch_submission_notes(). [] pre-migration / on error.
 */
function fetch_submission_notes_since(int $submission_id, int $afterId): array {
    if ($submission_id <= 0 || !submission_notes_supported()) return [];
    $kindSel = submission_notes_kind_supported()
        ? "n.kind, NULLIF(n.author_name, '') AS frozen_author,"
        : "'note'::text AS kind, NULL::text AS frozen_author,";
    try {
        return db_query(
            "SELECT n.id, n.body, n.created_at, n.admin_id, {$kindSel}
                    a.name AS author_name, a.email AS author_email
             FROM submission_notes n
             LEFT JOIN admin_users a ON a.id = n.admin_id
             WHERE n.submission_id = :sid AND n.id > :after
             ORDER BY n.created_at ASC, n.id ASC",
            [':sid' => $submission_id, ':after' => $afterId]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[submission-notes] fetch since failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * One note → the JSON shape the live-messaging JS renders, for a viewer $role
 * ('admin' | 'agent'). `mine` marks the viewer's own side (admin: note/reply;
 * agent: guest_reply). The "📧 Emailed to guest:" bookkeeping prefix is stripped
 * for the agent's eyes only. Pure.
 */
function submission_thread_payload(array $n, string $role): array {
    $kind = (string)($n['kind'] ?? 'note');
    $mine = $role === 'agent' ? ($kind === 'guest_reply') : ($kind !== 'guest_reply');
    $body = (string)($n['body'] ?? '');
    if ($role === 'agent') $body = preg_replace('/^📧 Emailed to guest:\s*/u', '', $body);
    $author = trim((string)($n['frozen_author'] ?? ''))
           ?: (trim((string)($n['author_name'] ?? ''))
           ?: (trim((string)($n['author_email'] ?? '')) ?: ($kind === 'guest_reply' ? 'Guest' : 'Staff')));
    if ($role === 'agent') {
        $label = $mine ? 'You' : 'Reservations';
    } else {
        $label = $kind === 'guest_reply' ? $author : ($kind === 'reply' ? ($author . ' → guest') : $author);
    }
    $ts = strtotime((string)($n['created_at'] ?? 'now')) ?: time();
    return [
        'id'         => (int)($n['id'] ?? 0),
        'kind'       => $kind,
        'mine'       => $mine,
        'author'     => $label,
        'body'       => $body,
        'time_label' => date('j M, H:i', $ts),
    ];
}

/**
 * Add a thread entry. $kind is note|reply|guest_reply (defaults to note);
 * $authorName is frozen at write time. Returns the new id or 0 on failure /
 * pre-migration / empty body. The kind/author_name columns are written only
 * once add_submission_notes_kind.sql has been applied.
 */
function add_submission_note(int $submission_id, ?int $admin_id, string $body,
                             string $kind = 'note', string $authorName = ''): int {
    $body = trim($body);
    if ($submission_id <= 0 || $body === '' || !submission_notes_supported()) return 0;
    $kind = in_array($kind, ['note', 'reply', 'guest_reply'], true) ? $kind : 'note';
    try {
        if (submission_notes_kind_supported()) {
            db_query(
                "INSERT INTO submission_notes (submission_id, admin_id, body, kind, author_name)
                 VALUES (:sid, :aid, :body, :kind, :aname)",
                [':sid' => $submission_id, ':aid' => $admin_id ?: null, ':body' => $body,
                 ':kind' => $kind, ':aname' => $authorName]
            );
        } else {
            db_query(
                "INSERT INTO submission_notes (submission_id, admin_id, body)
                 VALUES (:sid, :aid, :body)",
                [':sid' => $submission_id, ':aid' => $admin_id ?: null, ':body' => $body]
            );
        }
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[submission-notes] add failed: ' . $e->getMessage());
        return 0;
    }
}

/* ── Unread guest-reply markers (Item 4) ──────────────────────────────────────
 * A reliable "new reply since staff last looked" signal for reservations, backed
 * by two nullable timestamps on submissions (add_submission_reply_flags.sql).
 * Every read is guarded so a pre-migration deploy shows no badges and never 500s.
 */

/** True once add_submission_reply_flags.sql has been applied (memoised). */
function submission_reply_flags_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'submissions' AND column_name = 'last_guest_reply_at' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}

/** Stamp a fresh customer reply (inbound email or trade-portal reply). Best-effort. */
function submission_mark_guest_reply(int $submission_id): void {
    if ($submission_id <= 0 || !submission_reply_flags_supported()) return;
    try {
        db_query('UPDATE submissions SET last_guest_reply_at = now() WHERE id = :id', [':id' => $submission_id]);
    } catch (Throwable $e) {
        error_log('[submission-notes] mark guest reply failed: ' . $e->getMessage());
    }
}

/** Mark a submission's replies as seen (staff opened the thread). Best-effort. */
function submission_mark_reply_seen(int $submission_id): void {
    if ($submission_id <= 0 || !submission_reply_flags_supported()) return;
    try {
        db_query('UPDATE submissions SET reply_seen_at = now() WHERE id = :id', [':id' => $submission_id]);
    } catch (Throwable $e) {
        error_log('[submission-notes] mark reply seen failed: ' . $e->getMessage());
    }
}

/**
 * Of the given submission ids, which have an UNREAD customer reply — a
 * last_guest_reply_at that no reply_seen_at covers. Returns a set
 * [submission_id => true]. Empty pre-migration / on error.
 */
function submission_unread_reply_ids(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$ids || !submission_reply_flags_supported()) return [];
    try {
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_query(
            "SELECT id FROM submissions
              WHERE id IN ($ph)
                AND last_guest_reply_at IS NOT NULL
                AND (reply_seen_at IS NULL OR reply_seen_at < last_guest_reply_at)",
            $ids
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[(int)$r['id']] = true;
        return $out;
    } catch (Throwable $e) {
        error_log('[submission-notes] unread ids failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Count of submissions with an unread customer reply, within the acting account's
 * venue scope (for the nav badge). Mirrors admin/submissions.php scoping: an
 * enquiry reaches a property through room_id → rooms.venue_id; property-less
 * contact/agency rows stay visible to every scoped account. 0 pre-migration /
 * on error / outside an admin context.
 */
function submission_unread_reply_count(): int {
    if (!submission_reply_flags_supported()) return 0;
    $where = "last_guest_reply_at IS NOT NULL
              AND (reply_seen_at IS NULL OR reply_seen_at < last_guest_reply_at)";
    if (function_exists('venue_scope_sql')) {
        $sVenue = venue_scope_sql('r.venue_id');
        if ($sVenue === '1=0') return 0; // scoped nowhere
        if ($sVenue !== '') {
            $where .= " AND (s.room_id IS NULL OR EXISTS (SELECT 1 FROM rooms r WHERE r.id = s.room_id AND {$sVenue}))";
        }
    }
    try {
        return (int) db_query("SELECT COUNT(*) FROM submissions s WHERE {$where}")->fetchColumn();
    } catch (Throwable $e) {
        error_log('[submission-notes] unread count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Note counts for a set of submission ids → [submission_id => count].
 * Missing ids simply don't appear. [] pre-migration / on error.
 */
function submission_note_counts(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$ids || !submission_notes_supported()) return [];
    try {
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_query(
            "SELECT submission_id, COUNT(*) AS cnt
             FROM submission_notes WHERE submission_id IN ($ph)
             GROUP BY submission_id",
            $ids
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[(int)$r['submission_id']] = (int)$r['cnt'];
        return $out;
    } catch (Throwable $e) {
        error_log('[submission-notes] counts failed: ' . $e->getMessage());
        return [];
    }
}
