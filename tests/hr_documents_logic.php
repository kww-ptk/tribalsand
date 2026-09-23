<?php
declare(strict_types=1);
// Employee documents. Run: php tests/hr_documents_logic.php
// Pure type/name rules always; store → list → read → delete round-trip inside a
// rolled-back transaction when the DB + add_hr_staff_documents migration exist.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr.php';
require_once __DIR__ . '/../includes/hr-documents.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Type rules (sniffed MIME decides, never the browser) ─────────
check('pdf accepted',              (hr_doc_resolve_type('application/pdf', 'x.pdf')['ext'] ?? '') === 'pdf');
check('jpeg accepted as jpg',      (hr_doc_resolve_type('image/jpeg', 'x.jpeg')['ext'] ?? '') === 'jpg');
check('docx by real mime',         (hr_doc_resolve_type('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'c.docx')['ext'] ?? '') === 'docx');
check('docx sniffed as zip + .docx name → docx', (hr_doc_resolve_type('application/zip', 'Contract.DOCX')['ext'] ?? '') === 'docx');
check('plain zip refused',         hr_doc_resolve_type('application/zip', 'files.zip') === null);
check('php refused even named .pdf', hr_doc_resolve_type('text/x-php', 'evil.pdf') === null);
check('html refused',              hr_doc_resolve_type('text/html', 'page.html') === null);
check('svg refused (script risk)', hr_doc_resolve_type('image/svg+xml', 'a.svg') === null);

// ── Filename sanitising (it lands in a Content-Disposition header) ─
check('keeps a normal name',       hr_doc_safe_filename('Henry contract 2026.pdf', 'pdf') === 'Henry contract 2026.pdf');
check('strips a path',             hr_doc_safe_filename('C:\\Users\\x\\scan.pdf', 'pdf') === 'scan.pdf');
check('strips quotes + ; (header injection)', hr_doc_safe_filename('a"b;c.pdf', 'pdf') === 'abc.pdf');
check('forces the stored extension', hr_doc_safe_filename('photo.png.exe', 'jpg') === 'photo.png.jpg');
check('empty name → document',     hr_doc_safe_filename('', 'pdf') === 'document.pdf');
check('long name capped',          mb_strlen(hr_doc_safe_filename(str_repeat('a', 300) . '.pdf', 'pdf')) === 124);

// ── Display helpers ──────────────────────────────────────────────
check('size bytes',  hr_doc_format_size(500) === '500 B');
check('size KB',     hr_doc_format_size(2048) === '2 KB');
check('size MB',     hr_doc_format_size(2621440) === '2.5 MB');
check('pdf inline',  hr_doc_is_inline('application/pdf'));
check('image inline', hr_doc_is_inline('image/png'));
check('docx downloads', !hr_doc_is_inline('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

// ── DB round-trip (rolled back) ──────────────────────────────────
$dbOk = false;
try { db(); $dbOk = hr_staff_supported() && hr_staff_documents_supported(); } catch (Throwable $e) {}
if (!$dbOk) {
    echo "SKIP  DB or add_hr_staff_documents migration unavailable — pure checks only\n";
} else {
    $keys = [];
    db()->beginTransaction();
    try {
        db_query("INSERT INTO hr_staff (full_name, position) VALUES ('ZZ Doc Test', 'Housekeeper')");
        $sid = (int) db()->lastInsertId();

        $mkTmp = function (string $bytes): string { $t = tempnam(sys_get_temp_dir(), 'hrdoc'); file_put_contents($t, $bytes); return $t; };
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

        $r = hr_doc_store($sid, ['name' => 'Contract 2026.pdf', 'tmp_name' => $mkTmp($pdf), 'error' => UPLOAD_ERR_OK, 'size' => strlen($pdf)], 'Employment contract', null);
        check('DB: pdf stored', $r['ok'] === true);
        $bad = hr_doc_store($sid, ['name' => 'x.pdf', 'tmp_name' => $mkTmp('<?php echo 1;'), 'error' => UPLOAD_ERR_OK, 'size' => 13], '', null);
        check('DB: fake pdf (php) refused', $bad['ok'] === false);
        $none = hr_doc_store($sid, ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], '', null);
        check('DB: missing file refused', $none['ok'] === false);

        $list = fetch_hr_staff_documents($sid);
        check('DB: one document listed', count($list) === 1);
        $d = $list[0] ?? [];
        $keys[] = (string)($d['file_key'] ?? '');
        check('DB: label saved',        ($d['label'] ?? '') === 'Employment contract');
        check('DB: filename saved',     ($d['filename'] ?? '') === 'Contract 2026.pdf');
        check('DB: private key under hr/<id>/', str_starts_with((string)($d['file_key'] ?? ''), "hr/{$sid}/"));
        check('DB: key is not a URL',   !str_starts_with((string)($d['file_key'] ?? ''), 'http'));
        $bytes = hr_doc_read_bytes((string)($d['file_key'] ?? ''));
        check('DB: bytes read back identical', $bytes['ok'] && $bytes['data'] === $pdf);

        check('DB: delete refuses another person\'s id', hr_doc_delete((int)$d['id'], $sid + 999999) === false);
        check('DB: delete own document',  hr_doc_delete((int)$d['id'], $sid) === true);
        check('DB: list empty after delete', fetch_hr_staff_documents($sid) === []);
        $gone = hr_doc_read_bytes((string)$d['file_key']);
        check('DB: private file removed from storage', $gone['ok'] === false);
    } finally {
        db()->rollBack();
        foreach ($keys as $k) { if ($k !== '') { try { storage_delete_private($k); } catch (Throwable $e) {} } }
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
