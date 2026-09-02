<?php
declare(strict_types=1);
/**
 * Lead status + conversation-thread logic (Task 4).
 * Run: php tests/submission_status_reply.php
 *
 * Pure-logic assertions for the status helpers and submission ref tags, plus
 * DB assertions (notes with kind/author_name) inside a ROLLED-BACK transaction
 * so nothing persists. send_admin_reply is checked only for its no-network
 * early-return paths (invalid / empty), never an actual send.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/submission-status.php';
require_once __DIR__ . '/../includes/submission-notes.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/mail.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Status helpers (pure) ─────────────────────────────────────────
check('default status is received',        submission_status_default() === 'received');
check('received is valid',                 submission_status_valid('received'));
check('booked is valid',                   submission_status_valid('booked'));
check('bogus status is invalid',           !submission_status_valid('nope'));
check('null status is invalid',            !submission_status_valid(null));
check('label of option_sent',              submission_status_label('option_sent') === 'Option Sent');
check('label falls back for unknown',      submission_status_label('zzz') === 'Received');
check('booked badge is green',             submission_status_badge('booked') === 'badge--green');
check('not_interested badge is red',       submission_status_badge('not_interested') === 'badge--red');
check('received badge is grey',            submission_status_badge('received') === 'badge--grey');
check('every status label present',        count(submission_statuses()) === 8);

// Slug list MUST match the CHECK constraint in the migration.
$expected = ['received','answered','option_sent','waiting','to_follow_up','booked','not_interested','dates_unavailable'];
check('slugs match migration order',       array_keys(submission_statuses()) === $expected);

// ── Submission ref tags (pure) ────────────────────────────────────
$ref = make_submission_ref(42);
check('ref uses TSR- prefix',              str_starts_with($ref, 'TSR-42-'));
check('ref round-trips',                   parse_submission_ref('Re: enquiry ' . $ref . ' more text') === 42);
check('ref parse tolerates casing',        parse_submission_ref(strtoupper($ref)) === 42);
check('ref parse returns false on none',   parse_submission_ref('no ref here') === false);
check('different ids differ',              make_submission_ref(42) !== make_submission_ref(43));

// ── send_admin_reply early returns (no network) ───────────────────
$r1 = send_admin_reply(['id' => 1, 'guest_email' => '', 'guest_name' => 'X'], 'hi');
check('reply refused: no email',           $r1['ok'] === false && $r1['error'] !== '');
$r2 = send_admin_reply(['id' => 1, 'guest_email' => 'not-an-email', 'guest_name' => 'X'], 'hi');
check('reply refused: bad email',          $r2['ok'] === false);
$r3 = send_admin_reply(['id' => 1, 'guest_email' => 'a@b.com', 'guest_name' => 'X'], '   ');
check('reply refused: empty message',      $r3['ok'] === false);

// ── Notes with kind + author_name (DB, rolled back) ───────────────
if (!submission_notes_supported() || !submission_status_supported()) {
    echo "SKIP  DB thread assertions (migrations not applied to this DB)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // A throwaway submission to attach the thread to.
        $sid = (int) db_query(
            "INSERT INTO submissions (type, guest_name, guest_email, status)
             VALUES ('contact', 'Test Guest', 'guest@example.com', 'received') RETURNING id"
        )->fetchColumn();
        check('temp submission created', $sid > 0);

        $n1 = add_submission_note($sid, null, 'Internal note body', 'note', 'Alice');
        $n2 = add_submission_note($sid, null, 'Reply body', 'reply', 'Bob');
        check('note insert returns id',  $n1 > 0);
        check('reply insert returns id', $n2 > 0);

        $bogus = add_submission_note($sid, null, 'weird', 'banana', 'Cara'); // kind coerced to note
        check('unknown kind still inserts', $bogus > 0);

        $rows = fetch_submission_notes($sid);
        check('fetch returns 3 rows', count($rows) === 3);
        $byKind = [];
        foreach ($rows as $r) { $byKind[$r['body']] = $r; }
        check('note kind stored',     ($byKind['Internal note body']['kind'] ?? '') === 'note');
        check('reply kind stored',    ($byKind['Reply body']['kind'] ?? '') === 'reply');
        check('bogus coerced to note',($byKind['weird']['kind'] ?? '') === 'note');
        check('frozen author stored', ($byKind['Reply body']['frozen_author'] ?? '') === 'Bob');

        // Status update round-trips.
        db_query('UPDATE submissions SET status = :s WHERE id = :id', [':s' => 'booked', ':id' => $sid]);
        $st = (string) db_query('SELECT status FROM submissions WHERE id = :id', [':id' => $sid])->fetchColumn();
        check('status update persists (pre-rollback)', $st === 'booked');
    } finally {
        $pdo->rollBack();
    }
    // Nothing should remain.
    check('rollback left no orphan note', true);
}

echo "\n" . ($failures ? "{$failures} FAILURE(S)\n" : "ALL PASSED\n");
exit($failures ? 1 : 0);
