<?php
declare(strict_types=1);

/**
 * Submission workflow statuses (admin pipeline / lead tracking).
 *
 * Stored as slugs in submissions.status (see db/migrations/add_submission_status.sql).
 * Labels live here so they can be re-worded without a migration. The slug list
 * MUST stay in sync with the CHECK constraint in that migration.
 *
 * Every read of the column must be guarded by submission_status_supported() so a
 * pre-migration deploy never 500s — mirrors submission_notes_supported().
 *
 * Depends on includes/db.php (db_query).
 */

/** True once the submissions.status column exists (memoised). False pre-migration. */
function submission_status_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'submissions' AND column_name = 'status' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}

/** slug => human label, in pipeline order */
function submission_statuses(): array {
    return [
        'received'          => 'Received',
        'answered'          => 'Answered',
        'option_sent'       => 'Option Sent',
        'waiting'           => 'Waiting',
        'to_follow_up'      => 'To Follow Up',
        'booked'            => 'Booked',
        'not_interested'    => 'Not Interested',
        'dates_unavailable' => 'Dates Not Available',
    ];
}

/** Default status applied to new/legacy rows. */
function submission_status_default(): string {
    return 'received';
}

/** True if $slug is a recognised status. */
function submission_status_valid(?string $slug): bool {
    return is_string($slug) && array_key_exists($slug, submission_statuses());
}

/** Display label for a slug (falls back to the default label). */
function submission_status_label(?string $slug): string {
    $map = submission_statuses();
    return $map[$slug] ?? $map[submission_status_default()];
}

/** Admin badge CSS class (badge--blue / --green / --orange / --red / --grey). */
function submission_status_badge(?string $slug): string {
    return match ($slug) {
        'answered', 'option_sent'             => 'badge--blue',
        'booked'                              => 'badge--green',
        'waiting', 'to_follow_up'             => 'badge--orange',
        'not_interested', 'dates_unavailable' => 'badge--red',
        default                               => 'badge--grey', // received
    };
}
