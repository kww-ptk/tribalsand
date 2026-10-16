<?php
declare(strict_types=1);
// A co-guest who has finished check-in gets the portal — and its actions —
// whether or not staff turned on "Share reservation" (that switch now only shows
// the shared Bill). Pure rule always; the token round-trip in a rolled-back
// transaction when a database is reachable.
// Run: php tests/coguest_portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$hold = ['id' => 1, 'require_checkin' => true, 'share_reservation' => false];
$open = ['passport_name' => 'Maria Rossi', 'passport_number' => 'YA1234567', 'nationality' => 'Italy', 'passport_expiry' => '2031-01-01', 'passport_file_key' => 'checkin/1/9/x.jpg',
         'waiver_signed_at' => '2026-10-14 10:00', 'waiver_signed_name' => 'Maria Rossi', 'waiver_signature' => 'data:image/png;base64,x'];
check('finished check-in: portal open, sharing off', coguest_portal_open($hold, $open));
check('not signed yet: still the check-in page', !coguest_portal_open($hold, ['waiver_signed_at' => null] + $open));
check('no passport yet: still the check-in page', !coguest_portal_open($hold, ['passport_number' => ''] + $open));
check('no guest row: closed', !coguest_portal_open($hold, null));
check('booking without check-in: open', coguest_portal_open(['require_checkin' => false] + $hold, ['passport_name' => 'X']));

try {
    $db = db();
    $hid = (int)db_query("SELECT id FROM holds ORDER BY id LIMIT 1")->fetchColumn();
    if (!$hid || !checkin_supported()) { echo "SKIP  DB checks (no hold / check-in tables)\n"; }
    else {
        $db->beginTransaction();
        db_query('UPDATE holds SET require_checkin = TRUE' . (share_reservation_supported() ? ', share_reservation = FALSE' : '') . ' WHERE id = :h', [':h' => $hid]);
        $gid = (int)db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name) VALUES (:h, FALSE, 'ZZ Coguest') RETURNING id", [':h' => $hid])->fetchColumn();
        $url = make_guest_pass_url($hid, $gid);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $tok = (string)($q['g'] ?? '');
        check('db: a token was made', $tok !== '');
        check('db: unfinished co-guest cannot act in the portal', resolve_portal_actor($tok) === false);
        $sigCols = checkin_signature_supported() ? ", waiver_signed_at = now(), waiver_signed_name = 'ZZ Coguest', waiver_signature = 'data:image/png;base64,x'" : '';
        db_query("UPDATE checkin_guests SET passport_number = 'YA1234567', nationality = 'Italy', passport_expiry = '2031-01-01', passport_file_key = 'checkin/x/y.jpg'{$sigCols} WHERE id = :g", [':g' => $gid]);
        $a = resolve_portal_actor($tok);
        check('db: finished co-guest acts in the portal with sharing off', is_array($a) && $a['guest_id'] === $gid && $a['is_lead'] === false);
        $db->rollBack();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
