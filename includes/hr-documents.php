<?php
declare(strict_types=1);
/**
 * Employee documents — contracts, IDs, certificates attached to an hr_staff row
 * (migration add_hr_staff_documents.sql). Many per person.
 *
 * Files are PRIVATE, exactly like passport scans: stored with
 * storage_put_private() under hr/<staff id>/… and read back only through
 * admin/employee-file.php, which re-checks manager access and venue scope. The
 * DB holds the storage key, never a public URL.
 *
 * NO AUTH HERE — callers (admin/employee.php, admin/employee-file.php) have
 * already run require_manager() and the hr_staff_in_venue_scope() check.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';

const HR_DOC_MAX_BYTES = 15 * 1024 * 1024;   // 15 MB per file

/** True once add_hr_staff_documents.sql has been applied (memoised). */
function hr_staff_documents_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.hr_staff_documents')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Accepted file types: sniffed MIME => stored extension. */
function hr_doc_allowed_types(): array {
    return [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
    ];
}

/**
 * Decide whether an upload is allowed, from its SNIFFED MIME (finfo, never the
 * browser's claim) plus the original name. PURE + testable.
 * Returns ['ext' => …, 'mime' => …] or null when the type is refused.
 *
 * A .docx is a zip archive, and some libmagic builds report it as
 * application/zip — accepted ONLY when the name also ends in .docx, so a random
 * zip is still refused.
 */
function hr_doc_resolve_type(string $sniffedMime, string $originalName): ?array {
    $allowed = hr_doc_allowed_types();
    if (isset($allowed[$sniffedMime])) return ['ext' => $allowed[$sniffedMime], 'mime' => $sniffedMime];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'docx' && in_array($sniffedMime, ['application/zip', 'application/octet-stream'], true)) {
        return ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }
    return null;
}

/**
 * A display/download-safe version of the uploaded file name. PURE + testable.
 * Strips any path, control chars and quotes (it ends up in a
 * Content-Disposition header), collapses whitespace, caps the length and
 * forces the extension we actually stored.
 */
function hr_doc_safe_filename(string $name, string $ext): string {
    $name = basename(str_replace('\\', '/', $name));
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = (string)preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|;]+/u', '', $base);
    $base = trim((string)preg_replace('/\s+/u', ' ', $base), " .-_");
    if ($base === '') $base = 'document';
    if (mb_strlen($base) > 120) $base = mb_substr($base, 0, 120);
    return $base . '.' . $ext;
}

/** Human file size ("820 KB", "2.4 MB"). PURE. */
function hr_doc_format_size(int $bytes): string {
    if ($bytes < 1024)            return $bytes . ' B';
    if ($bytes < 1024 * 1024)     return round($bytes / 1024) . ' KB';
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
}

/** True when a document should open in the browser (PDF/images) rather than download. PURE. */
function hr_doc_is_inline(string $contentType): bool {
    return $contentType === 'application/pdf' || str_starts_with($contentType, 'image/');
}

/** All documents for one person, newest first. [] pre-migration / on error. */
function fetch_hr_staff_documents(int $staffId): array {
    if ($staffId <= 0 || !hr_staff_documents_supported()) return [];
    try {
        return db_query(
            "SELECT d.*, a.name AS uploader_name
             FROM hr_staff_documents d
             LEFT JOIN admin_users a ON a.id = d.uploaded_by
             WHERE d.hr_staff_id = :s
             ORDER BY d.uploaded_at DESC, d.id DESC",
            [':s' => $staffId]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[hr-documents] list failed: ' . $e->getMessage());
        return [];
    }
}

/** One document row, or false. */
function fetch_hr_staff_document(int $docId): array|false {
    if ($docId <= 0 || !hr_staff_documents_supported()) return false;
    return db_query('SELECT * FROM hr_staff_documents WHERE id = :id', [':id' => $docId])->fetch();
}

/**
 * Store ONE uploaded file ($_FILES-style entry) against a person.
 * Returns ['ok' => true, 'id' => int] or ['ok' => false, 'error' => message].
 */
function hr_doc_store(int $staffId, array $file, string $label, ?int $adminId): array {
    if (!hr_staff_documents_supported()) return ['ok' => false, 'error' => 'Run the add_hr_staff_documents migration first.'];
    $orig = (string)($file['name'] ?? '');
    $err  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['ok' => false, 'error' => ($orig ?: 'File') . ' is too large (max 15 MB).'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_file($file['tmp_name'])) return ['ok' => false, 'error' => 'No file received.'];
    $size = (int)($file['size'] ?? filesize($file['tmp_name']));
    if ($size <= 0)               return ['ok' => false, 'error' => ($orig ?: 'File') . ' is empty.'];
    if ($size > HR_DOC_MAX_BYTES) return ['ok' => false, 'error' => ($orig ?: 'File') . ' is too large (max 15 MB).'];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $type = hr_doc_resolve_type($mime, $orig);
    if (!$type) return ['ok' => false, 'error' => ($orig ?: 'File') . ' — only PDF, Word, JPG, PNG or WEBP files are accepted.'];

    $key = 'hr/' . $staffId . '/' . bin2hex(random_bytes(12)) . '.' . $type['ext'];
    if (!storage_put_private($file['tmp_name'], $key, $type['mime'])) return ['ok' => false, 'error' => 'Upload failed — try again.'];

    $label = trim($label);
    try {
        db_query(
            "INSERT INTO hr_staff_documents (hr_staff_id, file_key, filename, label, content_type, size_bytes, uploaded_by)
             VALUES (:s, :k, :f, :l, :ct, :sz, :by)",
            [':s' => $staffId, ':k' => $key, ':f' => hr_doc_safe_filename($orig, $type['ext']),
             ':l' => $label !== '' ? mb_substr($label, 0, 200) : null, ':ct' => $type['mime'],
             ':sz' => $size, ':by' => $adminId ?: null]
        );
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (Throwable $e) {
        // Don't leave an orphaned private file behind a failed insert.
        try { storage_delete_private($key); } catch (Throwable $ignored) {}
        error_log('[hr-documents] insert failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save the document — try again.'];
    }
}

/** Delete one document (row + private file). Scoped to its person so a foreign id can't be removed. */
function hr_doc_delete(int $docId, int $staffId): bool {
    $doc = fetch_hr_staff_document($docId);
    if (!$doc || (int)$doc['hr_staff_id'] !== $staffId) return false;
    db_query('DELETE FROM hr_staff_documents WHERE id = :id AND hr_staff_id = :s', [':id' => $docId, ':s' => $staffId]);
    try { storage_delete_private((string)$doc['file_key']); } catch (Throwable $e) {}
    return true;
}

/**
 * Read a private document's bytes. Returns ['ok'=>true,'data'=>string] or
 * ['ok'=>false,'status'=>int,'error'=>string]. Mirrors admin/checkin-file.php:
 * private bucket via a short-lived signed URL, else the local private dir.
 */
function hr_doc_read_bytes(string $key): array {
    $signed = storage_signed_get_url($key);
    if ($signed !== '') {
        $data = @file_get_contents($signed);
        return $data === false
            ? ['ok' => false, 'status' => 502, 'error' => 'The document is stored remotely but could not be fetched (check the private bucket credentials).']
            : ['ok' => true, 'data' => $data];
    }
    $path = storage_local_path($key);
    if (!is_file($path)) {
        return ['ok' => false, 'status' => 404, 'error' => 'The document file is missing from storage — it was likely lost on a deploy because no private bucket or persistent disk (CHECKIN_STORAGE_DIR) is configured. Please upload it again.'];
    }
    $data = file_get_contents($path);
    return $data === false ? ['ok' => false, 'status' => 500, 'error' => 'The document could not be read.'] : ['ok' => true, 'data' => $data];
}
