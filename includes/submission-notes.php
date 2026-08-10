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

/** Notes for one submission, oldest-first, with author name. [] pre-migration / on error. */
function fetch_submission_notes(int $submission_id): array {
    if ($submission_id <= 0 || !submission_notes_supported()) return [];
    try {
        return db_query(
            "SELECT n.id, n.body, n.created_at, n.admin_id,
                    a.name AS author_name, a.email AS author_email
             FROM submission_notes n
             LEFT JOIN admin_users a ON a.id = n.admin_id
             WHERE n.submission_id = :sid
             ORDER BY n.created_at ASC, n.id ASC",
            [':sid' => $submission_id]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Add a note. Returns the new id or 0 on failure / pre-migration / empty body. */
function add_submission_note(int $submission_id, ?int $admin_id, string $body): int {
    $body = trim($body);
    if ($submission_id <= 0 || $body === '' || !submission_notes_supported()) return 0;
    try {
        db_query(
            "INSERT INTO submission_notes (submission_id, admin_id, body)
             VALUES (:sid, :aid, :body)",
            [':sid' => $submission_id, ':aid' => $admin_id ?: null, ':body' => $body]
        );
        return (int) db()->lastInsertId();
    } catch (Throwable $e) { return 0; }
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
    } catch (Throwable $e) { return []; }
}
