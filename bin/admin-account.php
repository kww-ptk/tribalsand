#!/usr/bin/env php
<?php
/**
 * Inspect / disable an admin account by email — for accounts the Team page can't
 * manage (owner accounts are hidden there so the owner can't be self-deleted).
 *
 * READ-ONLY by default (just reports). To change anything, pass a mode flag:
 *   php bin/admin-account.php neha.patel@tribalsand.com               # report only
 *   php bin/admin-account.php neha.patel@tribalsand.com --deactivate  # block login (reversible)
 *   php bin/admin-account.php neha.patel@tribalsand.com --delete      # remove permanently
 *
 * SAFETY: it refuses to deactivate or delete the LAST active owner (so you can
 * never lock yourself out). Deactivate is preferred — it blocks the login, is
 * reversible, and preserves audit history. Delete is a hard removal (guarded, and
 * will fail rather than cascade if other rows reference the account).
 *
 * Run on PROD via an ECS run-task command override, like the other bin scripts.
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';

// First non-flag arg is the email.
$email = '';
$mode  = 'report';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--deactivate') $mode = 'deactivate';
    elseif ($a === '--delete') $mode = 'delete';
    elseif ($a[0] !== '-' && $email === '') $email = strtolower(trim($a));
}
if ($email === '') { fwrite(STDERR, "Usage: php bin/admin-account.php <email> [--deactivate|--delete]\n"); exit(1); }

echo "Admin account tool  (" . date('Y-m-d H:i:s') . ")  mode=" . strtoupper($mode) . "\n";
echo str_repeat('=', 80) . "\n";

$acct = db_query(
    'SELECT id, name, email, role, is_active,
            (password_hash IS NOT NULL AND password_hash <> \'\') AS has_password,
            (access_code IS NOT NULL AND access_code <> \'\')     AS has_code,
            last_login_at
       FROM admin_users WHERE lower(email) = :e',
    [':e' => $email]
)->fetch();

if (!$acct) {
    echo "No admin account has the email {$email}.\n";
    echo "(Nothing to remove. If this was a guest/booking login or an external\n";
    echo " service account, it is not in admin_users.)\n";
    exit(0);
}

$isOwner = ($acct['role'] === 'owner');
$activeOwnersElse = (int) db_query(
    "SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND is_active = TRUE AND id <> :id",
    [':id' => (int)$acct['id']]
)->fetchColumn();

echo "Found:\n";
echo "  id           : {$acct['id']}\n";
echo "  name         : " . ($acct['name'] ?: '(none)') . "\n";
echo "  email        : {$acct['email']}\n";
echo "  role         : {$acct['role']}\n";
echo "  active       : " . ($acct['is_active'] ? 'yes' : 'no') . "\n";
echo "  has password : " . ($acct['has_password'] ? 'yes' : 'no') . "\n";
echo "  has access-code : " . ($acct['has_code'] ? 'yes' : 'no') . "\n";
echo "  last login   : " . ($acct['last_login_at'] ?: 'never') . "\n";
if ($isOwner) echo "  other ACTIVE owners: {$activeOwnersElse}\n";
echo "\n";

if ($mode === 'report') {
    echo "Report only — nothing changed. Re-run with --deactivate (recommended) or --delete.\n";
    exit(0);
}

// Last-owner guard.
if ($isOwner && $activeOwnersElse === 0) {
    fwrite(STDERR, "REFUSED: this is the only active owner. Removing it would lock everyone out.\n"
        . "Create/activate another owner first, then retry.\n");
    exit(2);
}

try {
    if ($mode === 'deactivate') {
        if (!$acct['is_active']) { echo "Already inactive — no change.\n"; exit(0); }
        db_query('UPDATE admin_users SET is_active = FALSE WHERE id = :id', [':id' => (int)$acct['id']]);
        echo "DEACTIVATED. {$email} can no longer sign in. (Reversible: set is_active = TRUE to restore.)\n";
    } else { // delete
        db_query('DELETE FROM admin_users WHERE id = :id', [':id' => (int)$acct['id']]);
        echo "DELETED. {$email} has been permanently removed from admin_users.\n";
    }
    try { audit_log($mode === 'delete' ? 'admin_delete' : 'admin_deactivate', 'admin_user', (int)$acct['id'], $email); } catch (\Throwable $e) {}
} catch (\Throwable $ex) {
    // A hard delete can fail if other rows reference the account — deactivate instead.
    fwrite(STDERR, "FAILED: " . $ex->getMessage() . "\n"
        . ($mode === 'delete' ? "The account is referenced elsewhere — use --deactivate instead (it blocks login without removing the row).\n" : ""));
    exit(1);
}
