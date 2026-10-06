#!/usr/bin/env php
<?php
/**
 * "Add to your stay" reminders — emails each confirmed guest the property's
 * featured extras N days before arrival (N per property: Admin → Properties →
 * Guest extras → Reminder email; 0 = off). Each booking is claimed before it is
 * emailed (guest_extras_claim_reminder()), so it is reminded at most once even
 * with several app containers running the scheduler.
 *
 * Run daily via the in-container scheduler (docker/scheduler.sh, Job 9):
 *   php bin/extras-reminder.php [--dry-run]
 * Quiet no-op until add_venue_extras.sql has run.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/guest-extras.php';

$dry = in_array('--dry-run', $argv, true);
$ts  = '[' . date('Y-m-d H:i:s') . ']';

if (!guest_extras_supported()) {
    echo "{$ts} Guest extras not enabled (add_venue_extras.sql not run) — no reminders.\n";
    exit(0);
}

$due = guest_extras_reminders_due(date('Y-m-d'));
$sent = 0; $skipped = 0;
foreach ($due as $holdId) {
    $hold = fetch_hold_for_guest($holdId);
    if (!$hold) continue;
    if ($dry) { echo "{$ts} would remind hold #{$holdId} ({$hold['guest_email']}, arriving {$hold['check_in']})\n"; continue; }
    if (!guest_extras_claim_reminder($holdId)) continue;   // another container got it
    // Never email a channel relay or our own address (same rule as "Email the guest").
    $d = email_hold_confirm_default($hold);
    if (!$d['default']) { $skipped++; continue; }
    $r = send_extras_reminder($hold, ['trigger' => 'reminder']);
    ($r['status'] ?? '') === 'sent' ? $sent++ : $skipped++;
}
echo "{$ts} Extras reminders: " . count($due) . " due, {$sent} sent, {$skipped} not sent." . ($dry ? ' (dry run)' : '') . "\n";
