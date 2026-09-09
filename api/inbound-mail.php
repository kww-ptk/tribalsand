<?php
/**
 * Inbound guest-reply webhook — AWS SES → SNS → here.
 *
 * Flow: a guest replies to one of our enquiry replies → SES receives it on
 * INBOUND_MAIL_ADDRESS (reply@mail.tribalsand.com) → an SES receipt rule publishes
 * the message to an SNS topic → SNS HTTP-POSTs the notification to this endpoint.
 * We authenticate the SNS signature, match the reply to its submission via the
 * verified [TSR-<id>] tag in the subject, and log it into the submission thread as
 * a "guest_reply" (blue bubble) — no more manual copy-paste from the mailbox.
 *
 * Security posture (public, unauthenticated endpoint):
 *   - Every request must carry a valid SNS message signature (sns_verify_signature).
 *   - Optionally pinned to one topic via INBOUND_MAIL_SNS_TOPIC_ARN.
 *   - The subject tag's HMAC is re-verified (verify_submission_ref) so a forged
 *     tag can't inject a reply into an arbitrary thread.
 *   - Duplicate SNS deliveries are de-duped on the SES messageId.
 *
 * Always returns 200 to SNS once the signature checks out (even when we can't
 * match the reply) so SNS doesn't retry a message we've already accepted; genuine
 * auth failures return 403. Set-up steps live in docs/inbound-mail-setup.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';          // verify_submission_ref()
require_once __DIR__ . '/../includes/submission-notes.php';  // add_submission_note()
require_once __DIR__ . '/../includes/submission-status.php'; // status pipeline
require_once __DIR__ . '/../includes/inbound-mail.php';      // SNS + MIME helpers

header('Content-Type: application/json');

function inbound_out(int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$msg = json_decode($raw, true);
if (!is_array($msg)) {
    inbound_out(400, ['ok' => false, 'error' => 'Malformed body.']);
}

// 1. Authenticate: the message must be a genuine, untampered SNS delivery.
if (!sns_verify_signature($msg)) {
    error_log('[inbound-mail] SNS signature verification failed');
    inbound_out(403, ['ok' => false, 'error' => 'Bad signature.']);
}

// 2. Optionally pin to our own topic, so the endpoint can't be re-subscribed
//    to an attacker's topic (they'd still need a valid AWS signature, but this
//    closes the gap entirely when the ARN is configured).
$env      = parse_env();
$wantTopic = trim((string)($env['INBOUND_MAIL_SNS_TOPIC_ARN'] ?? ''));
$gotTopic  = (string)($msg['TopicArn'] ?? '');
if ($wantTopic !== '' && !hash_equals($wantTopic, $gotTopic)) {
    error_log('[inbound-mail] topic ARN mismatch: ' . $gotTopic);
    inbound_out(403, ['ok' => false, 'error' => 'Wrong topic.']);
}

$type = (string)($msg['Type'] ?? '');

// 3. Subscription handshake — SNS sends this once when the HTTPS subscription is
//    created; fetching the SubscribeURL activates it.
if ($type === 'SubscriptionConfirmation') {
    $ok = sns_confirm_subscription((string)($msg['SubscribeURL'] ?? ''));
    error_log('[inbound-mail] subscription confirmation ' . ($ok ? 'OK' : 'FAILED'));
    inbound_out($ok ? 200 : 502, ['ok' => $ok, 'action' => 'subscription_confirmation']);
}

if ($type === 'UnsubscribeConfirmation') {
    inbound_out(200, ['ok' => true, 'action' => 'unsubscribe_noted']);
}

if ($type !== 'Notification') {
    inbound_out(400, ['ok' => false, 'error' => 'Unsupported type.']);
}

// 4. Unwrap the SES notification carried inside the SNS envelope's Message field.
$ses = json_decode((string)($msg['Message'] ?? ''), true);
if (!is_array($ses) || ($ses['notificationType'] ?? '') !== 'Received') {
    // Not an SES "email received" event — accept so SNS stops retrying, ignore.
    inbound_out(200, ['ok' => true, 'action' => 'ignored_non_receipt']);
}

$mail       = is_array($ses['mail'] ?? null) ? $ses['mail'] : [];
$common     = is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];
$messageId  = (string)($mail['messageId'] ?? '');
$subject    = (string)($common['subject'] ?? '');
$fromHeader = '';
if (!empty($common['from']) && is_array($common['from'])) {
    $fromHeader = (string)($common['from'][0] ?? '');
}
if ($fromHeader === '') $fromHeader = (string)($mail['source'] ?? '');
$fromAddr = inbound_address_only($fromHeader);

// 5. De-dupe: SNS delivers at least once.
if ($messageId !== '' && inbound_mail_already_seen($messageId)) {
    inbound_out(200, ['ok' => true, 'action' => 'duplicate_ignored']);
}

// 6. Match the reply to its submission via the verified [TSR-<id>] subject tag.
$submissionId = verify_submission_ref($subject);
if ($submissionId === false) {
    // No valid tag — the guest may have started a fresh subject, or it's spam.
    // Record it (unmatched) for visibility and accept.
    inbound_mail_record($messageId, null, $fromHeader, $subject);
    error_log('[inbound-mail] no valid TSR tag in subject: ' . $subject);
    inbound_out(200, ['ok' => true, 'action' => 'unmatched']);
}

// Confirm the submission still exists.
$sub = db_query('SELECT id, guest_name, guest_email FROM submissions WHERE id = :id',
                [':id' => $submissionId])->fetch();
if (!$sub) {
    inbound_mail_record($messageId, null, $fromHeader, $subject);
    inbound_out(200, ['ok' => true, 'action' => 'submission_gone']);
}

// 7. Extract the reply text from the raw MIME the SES action delivered.
$content = (string)($ses['content'] ?? '');
$body    = '';
if ($content !== '') {
    $body = inbound_extract_text(inbound_normalise_content($content));
}
if ($body === '') {
    // Body wasn't captured (e.g. SNS action size cap, or an S3-only action).
    // Still surface the reply so staff know to look, rather than silently drop it.
    $body = '(Guest replied by email, but the message body could not be captured automatically. '
          . 'Check the ' . ($fromAddr ?: 'guest') . ' email for the full text.)';
    error_log('[inbound-mail] empty body for submission #' . $submissionId . ' msg ' . $messageId);
}

// 8. Thread it. Author label is the guest, admin_id NULL (it's inbound).
$authorLabel = trim((string)($sub['guest_name'] ?? ''));
if ($fromAddr !== '') {
    $authorLabel = $authorLabel !== '' ? ($authorLabel . ' <' . $fromAddr . '>') : $fromAddr;
}
if ($authorLabel === '') $authorLabel = 'Guest';

$noteId = add_submission_note((int)$sub['id'], null, $body, 'guest_reply', $authorLabel);

// 9. Nudge the pipeline: a fresh guest reply is something to follow up on —
//    but don't stomp a terminal outcome.
if ($noteId && submission_status_supported()) {
    try {
        db_query(
            "UPDATE submissions SET status = 'to_follow_up'
             WHERE id = :id AND status NOT IN ('booked','not_interested','dates_unavailable')",
            [':id' => $sub['id']]
        );
    } catch (Throwable $e) {
        error_log('[inbound-mail] status nudge failed: ' . $e->getMessage());
    }
}

inbound_mail_record($messageId, (int)$sub['id'], $fromHeader, $subject);

inbound_out(200, [
    'ok'            => (bool)$noteId,
    'action'        => 'threaded',
    'submission_id' => (int)$sub['id'],
]);
