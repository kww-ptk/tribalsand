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
        check('db: void where the frozen scan IS the current photo keeps the file', storage_read_private($old) === 'OLD');

        // Void where the frozen scan differs from the current photo: the frozen file belongs to no record any more.
        $mk = function (string $body): string {
            $t = tempnam(sys_get_temp_dir(), 'wa'); file_put_contents($t, $body);
            $k = 'tests/frozen-' . bin2hex(random_bytes(4)) . '.jpg';
            storage_put_private($t, $k, 'image/jpeg');
            return $k;
        };
        $frozen = $mk('FROZEN'); $current = $mk('CURRENT');
        try {
            $gid2 = (int)db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, passport_number, passport_file_key, waiver_signed_at, waiver_signed_name, waiver_signature, waiver_scan_snapshot)
                                   VALUES (:h, FALSE, 'ZZ Retaken', 'Y1', :cur, now(), 'ZZ Retaken', 'data:image/png;base64,x', :fr) RETURNING id",
                                  [':h' => $hid, ':cur' => $current, ':fr' => $frozen])->fetchColumn();
            check('db: both files exist before the void', storage_read_private($frozen) === 'FROZEN' && storage_read_private($current) === 'CURRENT');
            checkin_void_signature_if_identity_changed($hid, $gid2, ['passport_number' => 'Y2'], 'admin');
            check('db: void with a retaken photo deletes the frozen file', storage_read_private($frozen) === null);
            check('db: ...and keeps the current photo', storage_read_private($current) === 'CURRENT');
            $row = db_query('SELECT passport_file_key, waiver_scan_snapshot FROM checkin_guests WHERE id = :g', [':g' => $gid2])->fetch();
            check('db: ...the row keeps its current photo and loses the snapshot', $row['passport_file_key'] === $current && $row['waiver_scan_snapshot'] === null);
        } finally {
            storage_delete_private($frozen); storage_delete_private($current);
        }
        $db->rollBack();
        storage_delete_private($old);
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

// ── Task 2: the record ──
require_once __DIR__ . '/../includes/waiver-record.php';
$hold = ['id' => 175, 'access_code' => 'AB12', 'venue_name' => 'Maya Kobe', 'room_name' => 'Ocean Suite', 'check_in' => '2026-12-20', 'check_out' => '2026-12-27'];
$g = ['id' => 9, 'is_lead' => true, 'waiver_name_snapshot' => 'Anne Smith', 'waiver_passport_snapshot' => 'X1234567',
      'waiver_nationality_snapshot' => 'United Kingdom', 'waiver_passport_expiry_snapshot' => '2031-05-01', 'waiver_id_type_snapshot' => 'passport',
      'waiver_terms_snapshot' => 'TERMS', 'waiver_version' => 'v1', 'waiver_signature' => 'data:image/png;base64,AAAA',
      'waiver_signed_name' => 'Anne Smith', 'waiver_signed_at' => '2026-10-15 10:00:00+03', 'waiver_scan_snapshot' => 'k.jpg'];
$m = waiver_record_model_guest($hold, $g, 'TSR-175-9-ab');
check('record: full number, no dots', $m['doc_number'] === 'X1234567');
check('record: expiry kept', $m['expiry'] === '2031-05-01');
$html = waiver_record_html($m, ['scan' => 'none']);
check('guest link: full number on the page', str_contains($html, 'X1234567') && !str_contains($html, '•'));
check('guest link: expiry on the page', str_contains($html, '1 May 2031'));
check('guest link: no scan image', !str_contains($html, 'Document scan'));
$html = waiver_record_html($m, ['scan' => 'url', 'scan_url' => '/admin/checkin-file.php?hold=175&guest=9&signed=1']);
check('owner view: scan image shown', str_contains($html, 'Document scan') && str_contains($html, 'checkin-file.php?hold=175&amp;guest=9&amp;signed=1'));
$html = waiver_record_html($m, ['scan' => 'embed', 'scan_bytes' => "\xFF\xD8\xFFJPEG", 'scan_file' => 'x.jpg']);
check('zip: image embedded as data URI', str_contains($html, 'src="data:image/jpeg;base64,'));
$mPdf = waiver_record_model_guest($hold, ['waiver_scan_snapshot' => 'k.pdf'] + $g, 'R');
$html = waiver_record_html($mPdf, ['scan' => 'embed', 'scan_bytes' => '%PDF-1.4', 'scan_file' => 'x.pdf']);
check('zip: a PDF scan points at the file beside the record', str_contains($html, 'PDF scan') && str_contains($html, 'x.pdf'));
$legacy = waiver_record_model_guest($hold, ['waiver_scan_snapshot' => null, 'passport_file_key' => 'now.jpg'] + $g, 'R');
check('legacy record falls back to the current scan, labelled', $legacy['scan_key'] === 'now.jpg'
    && str_contains(waiver_record_html($legacy, ['scan' => 'url', 'scan_url' => '/x']), 'Scan on file now'));
$noScan = waiver_record_model_guest($hold, ['waiver_scan_snapshot' => null, 'passport_file_key' => null] + $g, 'R');
check('no scan at all says so', str_contains(waiver_record_html($noScan, ['scan' => 'url', 'scan_url' => '']), 'No scan on file'));
$v = ['id' => 4, 'waiver_reference' => 'VR-4', 'waiver_name_snapshot' => 'Wanjiru Kamau', 'waiver_id_type_snapshot' => 'kenya_id',
      'waiver_id_number_snapshot' => '12345678', 'waiver_nationality_snapshot' => 'Kenya', 'waiver_id_expiry_snapshot' => null,
      'waiver_terms_snapshot' => 'T', 'waiver_version' => 'v', 'waiver_signature' => 'data:image/png;base64,A', 'waiver_signed_name' => 'W K',
      'waiver_signed_at' => '2026-10-15 09:00:00+03', 'removed_at' => '2026-10-20 12:00:00+03', 'waiver_photo_snapshot' => null, 'id_photo_key' => null];
$vm = waiver_record_model_visitor($v);
check('visitor: Kenyan ID full number, expiry dash', $vm['doc_label'] === 'Kenyan ID' && $vm['doc_number'] === '12345678' && $vm['expiry'] === '');
check('visitor removed: photo removed line', str_contains(waiver_record_html($vm, ['scan' => 'url', 'scan_url' => '']), 'ID photo removed on 20 Oct 2026'));
check('record escapes names', !str_contains(waiver_record_html(waiver_record_model_guest($hold, ['waiver_name_snapshot' => '<b>x</b>'] + $g, 'R')), '<b>x</b>'));
check('blank scan_url never renders an empty img', !str_contains(waiver_record_html($m, ['scan' => 'url', 'scan_url' => '']), 'src=""'));

// ── Task 3: the list ──
require_once __DIR__ . '/../includes/waiver-archive.php';
check('month bounds', waiver_month_bounds('2026-10') === ['2026-10-01', '2026-11-01'] && waiver_month_bounds('2026-12') === ['2026-12-01', '2027-01-01']);
check('month bounds: junk refused', waiver_month_bounds('2026-13') === null && waiver_month_bounds('x') === null);
$rows = [['name' => 'Anne Smith', 'venue_id' => 3, 'reference' => 'R1', 'doc_number' => 'X1'], ['name' => 'Bob Jones', 'venue_id' => 4, 'reference' => 'R2', 'doc_number' => 'Y2']];
check('filter: property', count(waiver_archive_filter_rows($rows, 3, '')) === 1);
check('filter: name search, any case', array_column(waiver_archive_filter_rows($rows, null, 'jones'), 'name') === ['Bob Jones']);
check('filter: search by reference or number', count(waiver_archive_filter_rows($rows, null, 'y2')) === 1 && count(waiver_archive_filter_rows($rows, null, 'r1')) === 1);
try {
    $db = db();
    if (!checkin_signature_supported()) { echo "SKIP  list DB checks\n"; }
    else {
        $db->beginTransaction();
        $hid = (int)db_query('SELECT id FROM holds ORDER BY id LIMIT 1')->fetchColumn();
        // 31 Oct 23:30 Nairobi = October; 1 Nov 00:10 Nairobi = November
        db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, waiver_signed_at, waiver_signed_name, waiver_signature, waiver_name_snapshot)
                  VALUES (:h, FALSE, 'ZZ Oct', '2099-10-31 23:30:00+03', 'ZZ Oct', 'data:image/png;base64,x', 'ZZ Oct'),
                         (:h, FALSE, 'ZZ Nov', '2099-11-01 00:10:00+03', 'ZZ Nov', 'data:image/png;base64,x', 'ZZ Nov')", [':h' => $hid]);
        $oct = array_column(waiver_archive_rows('2099-10', null, 'guests', 'ZZ'), 'name');
        check('Nairobi month edge: 23:30 on the 31st is October', $oct === ['ZZ Oct']);
        check('Nairobi month edge: 00:10 on the 1st is November', array_column(waiver_archive_rows('2099-11', null, 'guests', 'ZZ'), 'name') === ['ZZ Nov']);
        $r = waiver_archive_rows('2099-10', null, 'guests', 'ZZ Oct')[0] ?? [];
        check('row: guest carries its booking and property', str_starts_with((string)($r['where'] ?? ''), 'TS-' . $hid) && array_key_exists('venue_name', $r));
        $db->rollBack();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  list DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

// ── Task 4: the ZIP ──
check('file base is safe', waiver_file_base(['signed_at' => '2026-10-15 10:00:00+03', 'name' => 'José / "Q" Ñandú', 'reference' => 'TSR-1-2-ab']) === '2026-10-15 Jose Q Nandu TSR-1-2-ab');
check('folders', waiver_zip_folder(['kind' => 'guest', 'venue_name' => 'Maya Kobe']) === 'Maya Kobe'
    && waiver_zip_folder(['kind' => 'visitor', 'venue_name' => '']) === 'Visitors/No visit yet');
if (class_exists('ZipArchive')) {
    $rowsZ = [
        ['kind' => 'guest', 'signed_at' => '2026-10-15 10:00:00+03', 'name' => 'Anne Smith', 'venue_name' => 'Maya Kobe', 'reference' => 'R1',
         'where' => 'TS-175', 'doc_label' => 'Passport', 'doc_number' => 'X1', 'nationality' => 'UK', 'expiry' => '2031-05-01', 'version' => 'v',
         'scan_key' => 'a.jpg', 'scan_frozen' => true, 'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => 0, 'removed_at' => '',
         '_model' => ['scan_key' => 'a.jpg'] + $m],
        ['kind' => 'guest', 'signed_at' => '2026-10-15 10:00:00+03', 'name' => 'Anne Smith', 'venue_name' => 'Maya Kobe', 'reference' => 'R1',
         'where' => 'TS-176', 'doc_label' => 'Passport', 'doc_number' => 'X2', 'nationality' => 'UK', 'expiry' => '', 'version' => 'v',
         'scan_key' => 'gone.jpg', 'scan_frozen' => true, 'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => 0, 'removed_at' => '',
         '_model' => ['scan_key' => 'gone.jpg'] + $m],
        ['kind' => 'guest', 'signed_at' => '2026-10-15 10:00:00+03', 'name' => 'Ghost', 'venue_name' => 'Maya Kobe', 'reference' => 'R9',
         'where' => '', 'doc_label' => '', 'doc_number' => '', 'nationality' => '', 'expiry' => '', 'version' => 'v',
         'scan_key' => '', 'scan_frozen' => false, 'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => 0, 'removed_at' => '',
         '_model' => null],
    ];
    $zipPath = tempnam(sys_get_temp_dir(), 'wz');
    $res = waiver_zip_build($rowsZ, $zipPath, fn($k) => $k === 'a.jpg' ? "\xFF\xD8\xFFJPEG" : null);
    $z = new ZipArchive(); $z->open($zipPath);
    $names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    check('zip: record + scan in the property folder', in_array('Maya Kobe/2026-10-15 Anne Smith R1.html', $names, true) && in_array('Maya Kobe/2026-10-15 Anne Smith R1.jpg', $names, true));
    check('file base is safe + unique (same name twice)', in_array('Maya Kobe/2026-10-15 Anne Smith R1 (2).html', $names, true));
    check('missing scan file: record still there, listed as missing', in_array('Maya Kobe/2026-10-15 Anne Smith R1 (2).html', $names, true) && $res['missing'] === ['gone.jpg']);
    check('record gone: skipped and counted', $res['records'] === 2 && $res['skipped'] === 1);
    $csv = (string)$z->getFromName('index.csv');
    check('index.csv: header + 2 rows', substr_count(trim($csv), "\n") === 2 && str_contains($csv, 'Document number') && str_contains($csv, 'missing'));
    check('zip: embedded image in the record', str_contains((string)$z->getFromName('Maya Kobe/2026-10-15 Anne Smith R1.html'), 'data:image/jpeg;base64,'));
    $z->close(); @unlink($zipPath);
} else { echo "SKIP  ZIP checks (no ZipArchive)\n"; }

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
