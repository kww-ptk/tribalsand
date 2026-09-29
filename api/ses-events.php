<?php
/**
 * SES delivery events — AWS SES configuration set → SNS → here.
 *
 * Every email we send through SES carries X-SES-CONFIGURATION-SET (when
 * SES_CONFIGURATION_SET is set). SES publishes Delivery / Bounce / Complaint /
 * Reject events for it to an SNS topic, which POSTs them here. We match the
 * event to its email_log row by the SES Message-ID (captured from SES's
 * "250 Ok <id>" reply in send_smtp()) and update the row's status, so the Email
 * log shows Delivered / Bounced / Marked as spam.
 *
 * Security (public endpoint), same as api/inbound-mail.php: the SNS signature is
 * verified (sns_verify_signature()), optionally pinned to one topic with
 * SES_EVENTS_SNS_TOPIC_ARN, and the subscription is auto-confirmed. Returns 200
 * for any authentic message (even one we can't match) so SNS stops retrying;
 * 403 only for a bad signature / wrong topic. Setup: docs/email-events-setup.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inbound-mail.php';   // sns_verify_signature(), sns_confirm_subscription()
require_once __DIR__ . '/../includes/mail-log.php';       // email_log_apply_ses_event()

header('Content-Type: application/json');

function ses_events_out(int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$msg = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($msg)) ses_events_out(400, ['ok' => false, 'error' => 'Malformed body.']);

if (!sns_verify_signature($msg)) {
    error_log('[ses-events] SNS signature verification failed');
    ses_events_out(403, ['ok' => false, 'error' => 'Bad signature.']);
}

$env       = parse_env();
$wantTopic = trim((string)($env['SES_EVENTS_SNS_TOPIC_ARN'] ?? ''));
if ($wantTopic !== '' && !hash_equals($wantTopic, (string)($msg['TopicArn'] ?? ''))) {
    error_log('[ses-events] topic ARN mismatch: ' . (string)($msg['TopicArn'] ?? ''));
    ses_events_out(403, ['ok' => false, 'error' => 'Wrong topic.']);
}

$type = (string)($msg['Type'] ?? '');
if ($type === 'SubscriptionConfirmation') {
    $ok = sns_confirm_subscription((string)($msg['SubscribeURL'] ?? ''));
    error_log('[ses-events] subscription confirmation ' . ($ok ? 'OK' : 'FAILED'));
    ses_events_out($ok ? 200 : 502, ['ok' => $ok, 'action' => 'subscription_confirmation']);
}
if ($type === 'UnsubscribeConfirmation') ses_events_out(200, ['ok' => true, 'action' => 'unsubscribe_noted']);
if ($type !== 'Notification') ses_events_out(400, ['ok' => false, 'error' => 'Unsupported type.']);

$event = json_decode((string)($msg['Message'] ?? ''), true);
if (!is_array($event) || empty($event['mail']['messageId'])) {
    ses_events_out(200, ['ok' => true, 'action' => 'ignored']);
}

try {
    $n = email_log_apply_ses_event($event);
} catch (Throwable $e) {
    // A DB hiccup: let SNS retry this one.
    error_log('[ses-events] ' . $e->getMessage());
    ses_events_out(500, ['ok' => false, 'error' => 'Could not record the event.']);
}
ses_events_out(200, ['ok' => true, 'action' => $n ? 'updated' : 'unmatched', 'rows' => $n]);
