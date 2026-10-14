<?php
declare(strict_types=1);
// Support page guide library: who sees a guide, audience cleaning, titles from
// file names, PDF checks, page counts, share-link signatures (pure, always); with
// a reachable DB + migration, import → new version → list/visibility → remove →
// restore → edit. support_import() commits its own transaction, so the test
// deletes the guide row and its stored PDFs again at the end.
// Run: php tests/support_docs_logic.php
require_once __DIR__ . '/../includes/support-docs.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Who reads as what ──
check('viewer: owner / manager / reception / hr', support_viewer_key_for('owner', null) === 'owner' && support_viewer_key_for('manager', null) === 'manager'
    && support_viewer_key_for('reception', null) === 'reception' && support_viewer_key_for('hr', null) === 'hr');
check('viewer: till jobs → till', support_viewer_key_for('staff', 'shop') === 'till' && support_viewer_key_for('staff', 'spa') === 'till' && support_viewer_key_for('staff', 'kite') === 'till');
check('viewer: other staff → staff', support_viewer_key_for('staff', 'security') === 'staff' && support_viewer_key_for('staff', null) === 'staff' && support_viewer_key_for('staff', 'housekeeping') === 'staff');
check('visible: owner sees everything', support_visible_to([], 'owner') && support_visible_to(['till'], 'owner'));
check('visible: only the roles ticked', support_visible_to(['reception', 'manager'], 'reception') && !support_visible_to(['reception'], 'till'));
check('visible: an owner-only guide hides from managers', !support_visible_to([], 'manager'));

// ── Audience ──
check('audience: unknown keys dropped, order kept, no doubles', support_audience_clean(['till', 'owner', 'bogus', 'manager', 'till']) === ['manager', 'till']);
check('audience label', support_audience_label(['reception', 'manager']) === 'Managers, Reception' && support_audience_label([]) === 'Owner only');
check('shareable: guests or agents', support_is_shareable(['guests']) && support_is_shareable(['manager', 'agents']) && !support_is_shareable(['manager']));
check('pg array literal', support_pg_array_literal(['till', 'manager']) === '{manager,till}' && support_pg_array('{manager,till}') === ['manager', 'till'] && support_pg_array('{}') === []);

// ── File names ──
check('title: Tribal-Sand prefix and dashes', support_title_from_filename('Tribal-Sand-Reception-Guide.pdf') === 'Reception Guide');
check('title: version marks dropped, acronym kept', support_title_from_filename('HR_Guide (v2).pdf') === 'HR Guide');
check('title: lower-case words capitalised', support_title_from_filename('till guide final.pdf') === 'Till Guide');
check('title: empty → Guide', support_title_from_filename('.pdf') === 'Guide');
check('safe filename: path, quotes, control chars', support_safe_filename('../x/"Bad";name.PDF') === 'Badname.pdf' && support_safe_filename('') === 'guide.pdf');

// ── PDF checks ──
check('pdf: real PDF', support_is_pdf('application/pdf', '%PDF-1.7'));
check('pdf: renamed file refused', !support_is_pdf('text/html', '<html>') && !support_is_pdf('application/pdf', 'MZ'));
$fake = "%PDF-1.4\n1 0 obj << /Type /Pages /Kids [2 0 R 3 0 R] >>\n2 0 obj << /Type /Page >>\n3 0 obj << /Type/Page >>\n";
check('pages: counts /Type /Page, not /Pages', support_pdf_page_count($fake) === 2);
check('pages: unknown → null', support_pdf_page_count('%PDF-1.7 compressed') === null);
check('size label', support_format_size(800) === '1 KB' && support_format_size(2516582) === '2.4 MB');

// ── Share link ──
$sig = support_share_sig(7, 'sec');
check('share: 16 hex', (bool)preg_match('/^[0-9a-f]{16}$/', $sig));
check('share: verifies', support_share_ok(7, $sig, 'sec') && support_share_ok(7, strtoupper($sig), 'sec'));
check('share: other guide / secret / no secret refused', !support_share_ok(8, $sig, 'sec') && !support_share_ok(7, $sig, 'x') && !support_share_ok(7, $sig, ''));

// ── DB round-trip (rolled back) ──
$pdo = null;
try { $pdo = db(); $pdo->query('SELECT 1'); } catch (Throwable $e) { $pdo = null; }
if (!$pdo) { echo "SKIP  DB checks (no database)\n"; }
elseif (!support_docs_supported()) { echo "SKIP  DB checks (run add_support_docs.sql)\n"; }
else {
    $tmp = function (string $body): array {
        $p = tempnam(sys_get_temp_dir(), 'sdt'); file_put_contents($p, $body);
        return ['name' => 'Tribal-Sand-Test-Guide.pdf', 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($body)];
    };
    $keys = []; $id = 0;
    try {
        $r1 = support_import($tmp($fake), null, ['title' => 'ZZ Test Guide', 'subtitle' => 'Testing', 'audience' => ['reception', 'bogus']], 'first', null);
        check('db: import a new guide', $r1['ok'] && $r1['version'] === 1);
        $id = (int)($r1['doc_id'] ?? 0);
        $r2 = support_import($tmp($fake), $id, [], 'second', null);
        check('db: new version = 2', $r2['ok'] && $r2['version'] === 2 && $r2['doc_id'] === $id);
        $bad = support_import(['name' => 'x.pdf', 'tmp_name' => (function () { $p = tempnam(sys_get_temp_dir(), 'sdt'); file_put_contents($p, '<html>hi</html>'); return $p; })(), 'error' => 0, 'size' => 15], $id, [], '', null);
        check('db: a non-PDF is refused', !$bad['ok']);
        $doc = support_doc_get($id);
        check('db: audience saved clean', $doc && $doc['audience'] === ['reception']);
        $vs = support_doc_versions($id);
        check('db: two versions, newest first, pages read', count($vs) === 2 && (int)$vs[0]['version_no'] === 2 && (int)$vs[0]['pages'] === 2);
        foreach ($vs as $v) $keys[] = (string)$v['file_key'];
        check('db: reception sees it, till does not', (bool)array_filter(support_docs_list('reception'), fn($d) => (int)$d['id'] === $id)
            && !array_filter(support_docs_list('till'), fn($d) => (int)$d['id'] === $id));
        $bytes = support_read_bytes($keys[0]);
        check('db: stored file reads back', $bytes['ok'] && str_starts_with($bytes['data'], '%PDF-'));
        support_doc_set_active($id, false);
        check('db: removed guide leaves the list', !array_filter(support_docs_list('owner'), fn($d) => (int)$d['id'] === $id)
            && (bool)array_filter(support_docs_list('owner', true), fn($d) => (int)$d['id'] === $id));
        support_doc_set_active($id, true);
        check('db: restored', (bool)array_filter(support_docs_list('owner'), fn($d) => (int)$d['id'] === $id));
        check('db: edit keeps a title', !support_doc_update($id, ['title' => '']) && support_doc_update($id, ['title' => 'ZZ Renamed', 'audience' => ['guests']]));
        check('db: shared guide has a link', support_share_url(support_doc_get($id)) !== '' || (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '') === '');
    } catch (Throwable $e) {
        check('db checks ran without error: ' . $e->getMessage(), false);
    } finally {
        if ($id) db_query('DELETE FROM support_docs WHERE id = :id', [':id' => $id]);
        foreach ($keys as $k) { try { storage_delete_private($k); } catch (Throwable $e) {} }
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
