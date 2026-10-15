<?php
declare(strict_types=1);
// Signed waivers archive: frozen scans, full-detail records, month list, ZIP.
// Pure checks always; DB checks in ONE rolled-back transaction.
// Run: php tests/waiver_archive_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/visitor-store.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; } else { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Task 1: which scan a record shows ──
check('scan: frozen snapshot wins', checkin_guest_scan_key(['waiver_scan_snapshot' => 'a.jpg', 'passport_file_key' => 'b.jpg']) === ['key' => 'a.jpg', 'frozen' => true]);
check('scan: legacy falls back to the current file', checkin_guest_scan_key(['passport_file_key' => 'b.jpg']) === ['key' => 'b.jpg', 'frozen' => false]);
check('scan: nothing on file', checkin_guest_scan_key([]) === ['key' => '', 'frozen' => false]);
check('visitor scan: frozen wins', visitor_scan_key(['waiver_photo_snapshot' => 'v1.jpg', 'id_photo_key' => 'v2.jpg']) === ['key' => 'v1.jpg', 'frozen' => true]);

// storage_read_private round-trip (local private dir)
$tmp = tempnam(sys_get_temp_dir(), 'wa'); file_put_contents($tmp, 'SCAN');
$k = 'tests/waiver-' . bin2hex(random_bytes(4)) . '.jpg';
storage_put_private($tmp, $k, 'image/jpeg');
check('storage_read_private returns the bytes', storage_read_private($k) === 'SCAN');
storage_delete_private($k);
check('storage_read_private: missing file = null', storage_read_private($k) === null);

try {
    $db = db();
    if (!waiver_scan_snapshot_supported()) { echo "SKIP  DB checks (add_waiver_scan_snapshot.sql not applied)\n"; }
    else {
        // Probe the older "is this migration applied?" guards BEFORE the transaction: some of them
        // run a failing SELECT, which would abort the transaction on a DB that lacks the column.
        foreach (['checkin_signature_supported', 'checkin_identity_snapshot_supported', 'checkin_id_type_supported',
                  'checkin_reference_supported', 'checkin_signing_audit_supported'] as $probe) {
            if (function_exists($probe)) $probe();
        }
        $db->beginTransaction();
        $hid = (int)db_query('SELECT id FROM holds ORDER BY id LIMIT 1')->fetchColumn();
        $tmp = tempnam(sys_get_temp_dir(), 'wa'); file_put_contents($tmp, 'OLD');
        $old = 'tests/frozen-' . bin2hex(random_bytes(4)) . '.jpg';
        storage_put_private($tmp, $old, 'image/jpeg');
        $gid = (int)db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, passport_number, passport_file_key, waiver_signed_at, waiver_signed_name, waiver_signature, waiver_scan_snapshot)
                              VALUES (:h, FALSE, 'ZZ Frozen', 'X1', :k, now(), 'ZZ Frozen', 'data:image/png;base64,x', :k) RETURNING id",
                             [':h' => $hid, ':k' => $old])->fetchColumn();
        checkin_scan_delete_unless_frozen($hid, $gid, $old);
        check('db: retake after signing keeps the frozen file', storage_read_private($old) === 'OLD');
        $keys = checkin_guest_file_keys($hid, $gid);
        check('db: guest file keys include the frozen scan once', $keys === [$old]);
        checkin_void_signature_if_identity_changed($hid, $gid, ['passport_number' => 'X2'], 'admin');
        $snap = db_query('SELECT waiver_scan_snapshot FROM checkin_guests WHERE id = :g', [':g' => $gid])->fetchColumn();
        check('db: voiding the signature clears the frozen scan', $snap === null);
        $db->rollBack();
        storage_delete_private($old);
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
