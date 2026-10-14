<?php
declare(strict_types=1);
/**
 * Support page — the guide library (design B "Reader", Oct 2026).
 *
 * The owner imports PDF guides; everyone reads or downloads the guides meant for
 * their role. A guide is a row (title, subtitle, summary, who it is for) with one
 * or more VERSIONS (the PDF files); the newest version is the one shown. A new
 * feature never needs code here: import a new guide, or a new version.
 *
 * Who sees a guide: the owner always; otherwise the account's audience key
 * (support_viewer_key(): manager · reception · hr · till · staff) must be in the
 * guide's audience. "guests" / "agents" are for sharing outside the admin: such a
 * guide gets a signed share link (/guide?d=…&t=…).
 *
 * Files are private (storage_put_private(), key support/<doc>/<random>.pdf) and
 * read only through admin/support-file.php or guide.php. Pure helpers first
 * (tests/support_docs_logic.php), then the I/O. Migration: add_support_docs.sql.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';

const SUPPORT_DOC_MAX_BYTES = 15 * 1024 * 1024;   // PHP's upload limit in the image is 16 MB

/** Who a guide can be for: key => label. The owner is not listed — the owner sees every guide. */
const SUPPORT_AUDIENCES = [
    'manager'   => 'Managers',
    'reception' => 'Reception',
    'hr'        => 'HR',
    'staff'     => 'Staff',
    'till'      => 'Till staff',
    'guests'    => 'Guests',
    'agents'    => 'Travel agents',
];

/** The audiences that are outside the admin — a guide for them gets a share link. */
const SUPPORT_SHARE_AUDIENCES = ['guests', 'agents'];

/** True once add_support_docs.sql has run. */
function support_docs_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.support_doc_versions') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

// ── Pure ────────────────────────────────────────────────────────────

/** Which audience key a signed-in account reads as. 'owner' sees everything. Pure. */
function support_viewer_key_for(string $role, ?string $job): string {
    if (in_array($role, ['owner', 'manager', 'reception', 'hr'], true)) return $role;
    return in_array($job, ['shop', 'spa', 'kite'], true) ? 'till' : 'staff';
}

/** May this viewer read a guide with this audience? Pure. */
function support_visible_to(array $audience, string $viewer): bool {
    return $viewer === 'owner' || in_array($viewer, $audience, true);
}

/** Keep only known audience keys, once each, in the SUPPORT_AUDIENCES order. Pure. */
function support_audience_clean(array $in): array {
    return array_values(array_filter(array_keys(SUPPORT_AUDIENCES), fn($k) => in_array($k, $in, true)));
}

/** Is the guide shared outside the admin (guests / travel agents)? Pure. */
function support_is_shareable(array $audience): bool {
    return (bool) array_intersect($audience, SUPPORT_SHARE_AUDIENCES);
}

/** "Managers, Reception" — the "For" line. Pure. */
function support_audience_label(array $audience): string {
    $l = array_map(fn($k) => SUPPORT_AUDIENCES[$k], support_audience_clean($audience));
    return $l ? implode(', ', $l) : 'Owner only';
}

/**
 * A title guessed from the file name, for the import form. Pure.
 * "Tribal-Sand-Reception-Guide.pdf" → "Reception Guide"; "HR_Guide (v2).pdf" → "HR Guide".
 */
function support_title_from_filename(string $name): string {
    $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
    $base = (string)preg_replace('/\((?:v|version)?\s*\d+\)|\bv\d+\b|\bfinal\b/i', ' ', $base);
    $base = (string)preg_replace('/^tribal[\s_-]*sand[\s_-]*/i', '', $base);
    $base = trim((string)preg_replace('/[\s_-]+/', ' ', $base));
    if ($base === '') return 'Guide';
    // Keep acronyms (HR, POS); capitalise plain words.
    return implode(' ', array_map(fn($w) => preg_match('/^[A-Z0-9]{2,}$/', $w) ? $w : mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), explode(' ', $base)));
}

/** A download-safe file name ending in .pdf. Pure. */
function support_safe_filename(string $name): string {
    $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
    $base = (string)preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|;]+/u', '', $base);
    $base = trim((string)preg_replace('/\s+/u', ' ', $base), " .-_");
    if ($base === '') $base = 'guide';
    return mb_substr($base, 0, 120) . '.pdf';
}

/** Is this a real PDF? (sniffed MIME + the %PDF- header — the name alone proves nothing) Pure. */
function support_is_pdf(string $sniffedMime, string $head): bool {
    return $sniffedMime === 'application/pdf' && str_starts_with($head, '%PDF-');
}

/** Rough page count from the PDF bytes (null when it can't tell — compressed object streams). Pure. */
function support_pdf_page_count(string $bytes): ?int {
    $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes);
    return $n > 0 ? $n : null;
}

/** "2.4 MB". Pure. */
function support_format_size(int $bytes): string {
    if ($bytes < 1024 * 1024) return max(1, (int)round($bytes / 1024)) . ' KB';
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
}

/** The share-link signature for a guide. '' when BOOKING_TOKEN_SECRET is unset. Pure given the secret. */
function support_share_sig(int $docId, ?string $secret = null): string {
    $secret ??= (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '');
    return $secret === '' || $docId <= 0 ? '' : substr(hash_hmac('sha256', "support:{$docId}", $secret), 0, 16);
}

function support_share_ok(int $docId, string $sig, ?string $secret = null): bool {
    $want = support_share_sig($docId, $secret);
    return $want !== '' && hash_equals($want, strtolower(trim($sig)));
}

// ── I/O ─────────────────────────────────────────────────────────────

/** The signed-in account's audience key. */
function support_viewer_key(): string {
    if (is_owner()) return 'owner';
    $a = current_admin() ?: [];
    return support_viewer_key_for((string)($a['role'] ?? ''), $a['job_type'] ?? null);
}

/** Decode a Postgres text[] ("{manager,hr}") into a PHP list. */
function support_pg_array(?string $v): array {
    $v = trim((string)$v, '{}');
    return $v === '' ? [] : array_map(fn($x) => trim($x, '"'), explode(',', $v));
}
function support_pg_array_literal(array $a): string {
    return '{' . implode(',', support_audience_clean($a)) . '}';
}

/**
 * Guides with their current version, ordered for the list. $viewer filters by
 * audience; $removed = also the removed ones (owner's Removed list).
 */
function support_docs_list(string $viewer, bool $removed = false): array {
    if (!support_docs_supported()) return [];
    try {
        $rows = db_query(
            "SELECT d.*, v.id AS version_id, v.version_no, v.filename, v.size_bytes, v.pages, v.uploaded_at AS version_at, v.note,
                    (SELECT COUNT(*) FROM support_doc_versions x WHERE x.doc_id = d.id) AS version_count
               FROM support_docs d
               LEFT JOIN LATERAL (SELECT * FROM support_doc_versions s WHERE s.doc_id = d.id ORDER BY s.version_no DESC LIMIT 1) v ON TRUE
              WHERE d.is_active = :a
              ORDER BY d.sort_order, d.title, d.id",
            [':a' => $removed ? 'false' : 'true']
        )->fetchAll();
    } catch (Throwable $e) { error_log('[support] list failed: ' . $e->getMessage()); return []; }
    $out = [];
    foreach ($rows as $r) {
        $r['audience'] = support_pg_array($r['audience'] ?? '');
        if (!$r['version_id'] || !support_visible_to($r['audience'], $viewer)) continue;
        $out[] = $r;
    }
    return $out;
}

/** One guide (any state) with audience decoded, or null. */
function support_doc_get(int $id): ?array {
    if ($id <= 0 || !support_docs_supported()) return null;
    $r = db_query('SELECT * FROM support_docs WHERE id = :id', [':id' => $id])->fetch();
    if (!$r) return null;
    $r['audience'] = support_pg_array($r['audience'] ?? '');
    return $r;
}

/** Every version of a guide, newest first. */
function support_doc_versions(int $docId): array {
    if (!support_docs_supported()) return [];
    return db_query(
        "SELECT v.*, a.name AS uploader_name FROM support_doc_versions v
           LEFT JOIN admin_users a ON a.id = v.uploaded_by
          WHERE v.doc_id = :d ORDER BY v.version_no DESC", [':d' => $docId])->fetchAll();
}

/** One version row + its guide, or null. */
function support_version_get(int $versionId): ?array {
    if ($versionId <= 0 || !support_docs_supported()) return null;
    $v = db_query('SELECT * FROM support_doc_versions WHERE id = :id', [':id' => $versionId])->fetch();
    if (!$v) return null;
    $doc = support_doc_get((int)$v['doc_id']);
    return $doc ? $v + ['doc' => $doc] : null;
}

/** Newest version of a guide, or null. */
function support_current_version(int $docId): ?array {
    if (!support_docs_supported()) return null;
    $v = db_query('SELECT * FROM support_doc_versions WHERE doc_id = :d ORDER BY version_no DESC LIMIT 1', [':d' => $docId])->fetch();
    return $v ?: null;
}

/**
 * Check an uploaded file ($_FILES entry). Returns ['ok'=>true,'size','pages','name'] or ['ok'=>false,'error'].
 */
function support_check_upload(array $file): array {
    $orig = (string)($file['name'] ?? '');
    $err  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['ok' => false, 'error' => 'That PDF is too large (max 15 MB).'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file($file['tmp_name'])) return ['ok' => false, 'error' => 'Choose a PDF to import.'];
    $size = (int)($file['size'] ?? filesize($file['tmp_name']));
    if ($size <= 0) return ['ok' => false, 'error' => 'That file is empty.'];
    if ($size > SUPPORT_DOC_MAX_BYTES) return ['ok' => false, 'error' => 'That PDF is too large (max 15 MB).'];
    $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $bytes = (string)file_get_contents($file['tmp_name']);
    if (!support_is_pdf($mime, substr($bytes, 0, 8))) return ['ok' => false, 'error' => 'Only PDF files can be imported.'];
    return ['ok' => true, 'size' => $size, 'pages' => support_pdf_page_count($bytes), 'name' => support_safe_filename($orig)];
}

/**
 * Import a guide: a NEW guide ($docId null, $meta = title/subtitle/summary/audience)
 * or a NEW VERSION of $docId. One transaction; the guide row is locked so two
 * uploads can't take the same version number. Returns ['ok'=>true,'doc_id','version'] or ['ok'=>false,'error'].
 */
function support_import(array $file, ?int $docId, array $meta, string $note, ?int $adminId): array {
    if (!support_docs_supported()) return ['ok' => false, 'error' => 'Run the add_support_docs migration first.'];
    $chk = support_check_upload($file);
    if (!$chk['ok']) return $chk;
    $title = trim((string)($meta['title'] ?? ''));
    if ($docId === null && $title === '') return ['ok' => false, 'error' => 'Give the guide a title.'];

    $pdo = db();
    $key = '';
    try {
        $pdo->beginTransaction();
        if ($docId === null) {
            $docId = (int) db_query(
                "INSERT INTO support_docs (title, subtitle, summary, audience, sort_order, created_by)
                 VALUES (:t, :s, :m, :a, COALESCE((SELECT MAX(sort_order) + 1 FROM support_docs), 0), :by) RETURNING id",
                [':t' => mb_substr($title, 0, 120), ':s' => mb_substr(trim((string)($meta['subtitle'] ?? '')), 0, 120) ?: null,
                 ':m' => mb_substr(trim((string)($meta['summary'] ?? '')), 0, 300) ?: null,
                 ':a' => support_pg_array_literal((array)($meta['audience'] ?? [])), ':by' => $adminId ?: null]
            )->fetchColumn();
        } else {
            $ok = db_query('SELECT id FROM support_docs WHERE id = :d FOR UPDATE', [':d' => $docId])->fetchColumn();
            if (!$ok) { $pdo->rollBack(); return ['ok' => false, 'error' => 'That guide no longer exists.']; }
            db_query('UPDATE support_docs SET updated_at = NOW(), is_active = TRUE WHERE id = :d', [':d' => $docId]);
        }
        $next = (int) db_query('SELECT COALESCE(MAX(version_no), 0) + 1 FROM support_doc_versions WHERE doc_id = :d', [':d' => $docId])->fetchColumn();
        $key  = 'support/' . $docId . '/' . bin2hex(random_bytes(12)) . '.pdf';
        if (!storage_put_private($file['tmp_name'], $key, 'application/pdf')) throw new RuntimeException('storage');
        db_query(
            "INSERT INTO support_doc_versions (doc_id, version_no, file_key, filename, size_bytes, pages, note, uploaded_by)
             VALUES (:d, :n, :k, :f, :sz, :p, :note, :by)",
            [':d' => $docId, ':n' => $next, ':k' => $key, ':f' => $chk['name'], ':sz' => $chk['size'], ':p' => $chk['pages'],
             ':note' => mb_substr(trim($note), 0, 300) ?: null, ':by' => $adminId ?: null]
        );
        $pdo->commit();
        return ['ok' => true, 'doc_id' => $docId, 'version' => $next];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($key !== '') { try { storage_delete_private($key); } catch (Throwable $ignored) {} }
        error_log('[support] import failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not import the guide — try again.'];
    }
}

/** Change a guide's title, subtitle, summary and audience. */
function support_doc_update(int $docId, array $meta): bool {
    $title = trim((string)($meta['title'] ?? ''));
    if ($docId <= 0 || $title === '') return false;
    return db_query(
        "UPDATE support_docs SET title = :t, subtitle = :s, summary = :m, audience = :a, updated_at = NOW() WHERE id = :d",
        [':d' => $docId, ':t' => mb_substr($title, 0, 120), ':s' => mb_substr(trim((string)($meta['subtitle'] ?? '')), 0, 120) ?: null,
         ':m' => mb_substr(trim((string)($meta['summary'] ?? '')), 0, 300) ?: null,
         ':a' => support_pg_array_literal((array)($meta['audience'] ?? []))]
    )->rowCount() > 0;
}

/** Remove (hide) or restore a guide. Files and versions are kept. */
function support_doc_set_active(int $docId, bool $active): bool {
    return db_query('UPDATE support_docs SET is_active = :a, updated_at = NOW() WHERE id = :d',
        [':a' => $active ? 'true' : 'false', ':d' => $docId])->rowCount() > 0;
}

/** The public share link for a guide meant for guests / travel agents ('' otherwise). */
function support_share_url(array $doc): string {
    if (!support_is_shareable($doc['audience'] ?? []) || empty($doc['is_active'])) return '';
    $sig = support_share_sig((int)$doc['id']);
    return $sig === '' ? '' : site_url('/guide?d=' . (int)$doc['id'] . '&t=' . $sig);
}

/** Read a stored PDF's bytes: ['ok'=>true,'data'] or ['ok'=>false,'status','error'] (private bucket, else local dir). */
function support_read_bytes(string $key): array {
    $signed = storage_signed_get_url($key);
    if ($signed !== '') {
        $data = @file_get_contents($signed);
        return $data === false ? ['ok' => false, 'status' => 502, 'error' => 'The guide could not be fetched from storage.'] : ['ok' => true, 'data' => $data];
    }
    $path = storage_local_path($key);
    if (!is_file($path)) return ['ok' => false, 'status' => 404, 'error' => 'The guide file is missing from storage — import it again.'];
    $data = file_get_contents($path);
    return $data === false ? ['ok' => false, 'status' => 500, 'error' => 'The guide could not be read.'] : ['ok' => true, 'data' => $data];
}

/** Send a stored PDF to the browser: inline (to view) or as a download. Exits. */
function support_stream(array $version, bool $download): never {
    $r = support_read_bytes((string)$version['file_key']);
    if (!$r['ok']) {
        http_response_code($r['status']);
        header('Content-Type: text/plain; charset=utf-8');
        exit($r['error']);
    }
    $fname = support_safe_filename((string)$version['filename']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
         . '; filename="' . str_replace('"', '', $fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . strlen($r['data']));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo $r['data'];
    exit;
}
