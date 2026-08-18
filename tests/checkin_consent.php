<?php
declare(strict_types=1);
// Signed-consent integrity — snapshot freeze, void-on-edit, tamper-evident audit.
// Run: php tests/checkin_consent.php
// Everything runs inside ONE transaction that is ROLLED BACK at the end, so the
// real (append-only) audit trail and bookings are never touched. Requires the
// add_checkin_reference.sql + add_checkin_record_integrity.sql migrations.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure hash logic (no DB) ────────────────────────────────────────────────
$rowA = ['reference'=>'TSR-1','hold_id'=>'1','guest_id'=>'2','step'=>'signed','waiver_version'=>'abc',
         'personal_link'=>'x','ip'=>'1.2.3.4','user_agent'=>'UA','method'=>'own_link','detail'=>'d'];
$rowB = $rowA; $rowB['detail'] = 'd2';
check('canonical is deterministic',       checkin_audit_canonical($rowA) === checkin_audit_canonical($rowA));
check('canonical changes with payload',   checkin_audit_canonical($rowA) !== checkin_audit_canonical($rowB));

if (!checkin_signature_supported() || !checkin_signing_audit_supported()) {
    echo "\nSKIP  DB assertions (check-in migrations not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
if (!checkin_identity_snapshot_supported() || !checkin_audit_hash_supported()) {
    echo "\nSKIP  DB assertions (add_checkin_record_integrity.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

$unitId = (int) db_query('SELECT id FROM units ORDER BY id LIMIT 1')->fetchColumn();
if (!$unitId) { echo "\nSKIP  no units seeded\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    // Throwaway booking that requires check-in, far-future so nothing else touches it.
    db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at, require_checkin, guest_count)
              VALUES (:u, DATE '2099-01-10', DATE '2099-01-12', 'Test Guest', 't@example.com', 'confirmed', now() + interval '1 day', TRUE, 1)",
             [':u'=>$unitId]);
    $holdId = (int) db()->lastInsertId();

    db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, passport_number, nationality, passport_expiry)
              VALUES (:h, TRUE, 'Valentina Bartoncelli', 'TA424TH', 'Italian', DATE '2030-08-12')", [':h'=>$holdId]);
    $gid = (int) db()->lastInsertId();

    // ── Sign: set waiver fields + freeze the identity snapshot (as checkin-save does) ──
    $terms = checkin_waiver_text(); $ver = waiver_version($terms);
    db_query("UPDATE checkin_guests SET
                waiver_signed_name='Valentina Bartoncelli', waiver_signed_at=now(), waiver_signed_ip='9.9.9.9',
                waiver_version=:v, waiver_signature='data:image/png;base64,AAAA', waiver_terms_snapshot=:t,
                waiver_signed_method='own_link',
                waiver_name_snapshot=passport_name, waiver_passport_snapshot=passport_number,
                waiver_nationality_snapshot=nationality, waiver_passport_expiry_snapshot=to_char(passport_expiry,'YYYY-MM-DD')
              WHERE id=:g", [':v'=>$ver, ':t'=>$terms, ':g'=>$gid]);
    checkin_log_signing_step('signed', ['reference'=>'TSR-TEST','hold_id'=>$holdId,'guest_id'=>$gid,'waiver_version'=>$ver,'method'=>'own_link','detail'=>'Signed by Valentina']);
    db_query("UPDATE holds SET checkin_completed_at=now() WHERE id=:h", [':h'=>$holdId]);

    $g = db_query('SELECT * FROM checkin_guests WHERE id=:g', [':g'=>$gid])->fetch();
    check('guest reads as signed',                 checkin_guest_waiver_signed($g));
    check('identity snapshot captured at signing', trim((string)$g['waiver_passport_snapshot']) === 'TA424TH');

    // ── Edit with NO change → signature must survive ──
    $voided = checkin_void_signature_if_identity_changed($holdId, $gid, [
        'passport_name'=>'Valentina Bartoncelli','passport_number'=>'TA424TH','nationality'=>'Italian','passport_expiry'=>'2030-08-12'], 'admin');
    check('unchanged edit does NOT void',          $voided === false);
    check('still signed after no-op edit',         checkin_guest_waiver_signed(db_query('SELECT * FROM checkin_guests WHERE id=:g',[':g'=>$gid])->fetch()));

    // ── Change passport number → signature voided, snapshot cleared, booking reopened ──
    // Callers run the helper BEFORE writing the new value (it compares stored vs new),
    // so pass the NEW value while the row still holds the old one.
    $voided = checkin_void_signature_if_identity_changed($holdId, $gid, ['passport_number'=>'NEW999'], 'admin');
    db_query("UPDATE checkin_guests SET passport_number='NEW999' WHERE id=:g", [':g'=>$gid]); // then the identity write
    check('changed identity DOES void',            $voided === true);

    $g2 = db_query('SELECT * FROM checkin_guests WHERE id=:g', [':g'=>$gid])->fetch();
    check('signature cleared after void',          !checkin_guest_waiver_signed($g2));
    check('snapshot cleared on void',              trim((string)($g2['waiver_passport_snapshot'] ?? '')) === '');
    $completed = db_query('SELECT checkin_completed_at FROM holds WHERE id=:h',[':h'=>$holdId])->fetchColumn();
    check('booking completion reopened on void',   $completed === null);

    $voidRows = (int) db_query("SELECT COUNT(*) FROM checkin_signing_audit WHERE hold_id=:h AND step='signature_voided'",[':h'=>$holdId])->fetchColumn();
    check('signature_voided step logged',          $voidRows === 1);

    // ── Tamper-evidence: chain verifies, then breaks when a row is edited ──
    $v = checkin_audit_verify();
    check('audit chain verifies intact',           $v['ok'] === true);

    $lastId = (int) db_query("SELECT id FROM checkin_signing_audit WHERE hold_id=:h ORDER BY id DESC LIMIT 1",[':h'=>$holdId])->fetchColumn();
    db_query("UPDATE checkin_signing_audit SET detail='TAMPERED' WHERE id=:i",[':i'=>$lastId]); // simulate tampering
    $v2 = checkin_audit_verify();
    check('audit chain detects tampering',         $v2['ok'] === false && $v2['bad_id'] === $lastId);

} finally {
    db()->rollBack(); // discard everything — real data untouched
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
