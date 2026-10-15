<?php
declare(strict_types=1);
// Passport OR Kenyan National ID at check-in (checkin_guests.id_type,
// add_checkin_id_type.sql). Pure rules always; a column round-trip in a
// rolled-back transaction when the migration is applied.
// Run: php tests/checkin_id_type_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Types ──
check('type: kenya_id kept', checkin_id_type('kenya_id') === 'kenya_id');
check('type: unknown / empty / null = passport', checkin_id_type('driving_licence') === 'passport' && checkin_id_type('') === 'passport' && checkin_id_type(null) === 'passport');
check('labels', checkin_id_type_label('passport') === 'Passport' && checkin_id_type_label('kenya_id') === 'Kenyan ID'
    && checkin_id_type_label('nonsense') === 'Passport');

// ── Complete = name + number + (photo, or what the document carries) ──
$id = ['passport_name' => 'Wanjiru Kamau', 'passport_number' => '12345678', 'nationality' => 'Kenya', 'id_type' => 'kenya_id'];
check('kenyan id: typed details without the card photo are not complete', !checkin_guest_passport_complete($id));
check('kenyan id: photo + name + number is complete (no expiry needed)', checkin_guest_passport_complete(['passport_file_key' => 'k'] + $id));
check('passport: typed details without a photo are not complete', !checkin_guest_passport_complete(['id_type' => 'passport', 'passport_expiry' => '2031-01-01'] + $id));

// ── Reading the photo ──
$r = checkin_passport_from_ai(['document_type' => 'kenya_id', 'given_names' => 'WANJIRU', 'surname' => 'KAMAU',
    'document_number' => '12 345 678', 'date_of_expiry' => ''], '2026-10-14');
check('kenyan id read: type, name, digits-only number, nationality Kenya',
    ($r['fields']['id_type'] ?? '') === 'kenya_id' && ($r['fields']['passport_name'] ?? '') === 'Wanjiru Kamau'
    && ($r['fields']['passport_number'] ?? '') === '12345678' && ($r['fields']['nationality'] ?? '') === 'Kenya');
check('kenyan id read: no expiry is fine (no warning, no field)', !isset($r['fields']['passport_expiry']) && $r['warnings'] === []);
$r = checkin_passport_from_ai(['document_type' => 'kenya_id', 'given_names' => 'Otieno', 'surname' => 'Ochieng',
    'document_number' => '123456789', 'date_of_expiry' => '2034-03-01'], '2026-10-14');
check('maisha card: 9 digits + expiry kept', ($r['fields']['passport_number'] ?? '') === '123456789' && ($r['fields']['passport_expiry'] ?? '') === '2034-03-01');
$r = checkin_passport_from_ai(['document_type' => 'kenya_id', 'given_names' => 'A', 'surname' => 'B', 'document_number' => 'A1234567'], '2026-10-14');
check('kenyan id read: a number with letters is dropped (the guest types it)', !isset($r['fields']['passport_number']) && ($r['fields']['id_type'] ?? '') === 'kenya_id');
$r = checkin_passport_from_ai(['document_type' => 'kenya_id', 'document_number' => '1234567890'], '2026-10-14');
check('kenyan id read: 10 digits is not an ID number', !isset($r['fields']['passport_number']));
$r = checkin_passport_from_ai(['document_type' => 'kenya_id', 'document_number' => '12345678', 'date_of_expiry' => '2020-01-01'], '2026-10-14');
check('kenyan id read: an expired card is flagged', in_array('This ID card has expired.', $r['warnings'], true));
$r = checkin_passport_from_ai(['document_type' => 'other', 'given_names' => 'X'], '2026-10-14');
check('another document: nothing filled, a clear message', $r['fields'] === [] && $r['warnings'] === ['Please use a passport or a Kenyan National ID card.']);
$r = checkin_passport_from_ai(['document_type' => 'passport', 'given_names' => 'ANNE', 'surname' => 'SMITH', 'passport_number' => 'X1234567',
    'nationality' => 'United Kingdom', 'date_of_expiry' => '2031-05-01'], '2026-10-14');
check('passport read still works and says passport', ($r['fields']['id_type'] ?? '') === 'passport' && ($r['fields']['passport_number'] ?? '') === 'X1234567');
$r = checkin_passport_from_ai(['is_passport' => false], '2026-10-14');
check('an old-style reply that is not a passport is still refused', $r['fields'] === [] && $r['warnings'] !== []);
check('prompt asks for the document type', str_contains(checkin_passport_read_prompt(), 'kenya_id'));

// ── Identity fields that void a signature include the type (once it is stored) ──
check('material fields: id_type only when the column exists',
    in_array('id_type', checkin_identity_material_fields(), true) === checkin_id_type_supported());

// ── DB round-trip (rolled back) ──
try {
    $db = db();
    if (!checkin_id_type_supported()) { echo "SKIP  DB checks (add_checkin_id_type.sql not applied)\n"; }
    else {
        $db->beginTransaction();
        $hid = (int)db_query('SELECT id FROM holds ORDER BY id LIMIT 1')->fetchColumn();
        $gid = (int)db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name) VALUES (:h, FALSE, 'ZZ Id Test') RETURNING id", [':h' => $hid])->fetchColumn();
        check('db: a new guest is a passport holder by default', db_query('SELECT id_type FROM checkin_guests WHERE id = :g', [':g' => $gid])->fetchColumn() === 'passport');
        db_query("UPDATE checkin_guests SET id_type = 'kenya_id' WHERE id = :g", [':g' => $gid]);
        check('db: kenya_id stored', db_query('SELECT id_type FROM checkin_guests WHERE id = :g', [':g' => $gid])->fetchColumn() === 'kenya_id');
        $bad = false;
        try { db_query("SAVEPOINT t1"); db_query("UPDATE checkin_guests SET id_type = 'licence' WHERE id = :g", [':g' => $gid]); }
        catch (Throwable $e) { $bad = true; db_query("ROLLBACK TO SAVEPOINT t1"); }
        check('db: an unknown type is refused', $bad);
        $db->rollBack();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
