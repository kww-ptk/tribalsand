<?php
declare(strict_types=1);
/**
 * Travel-agency partner helpers — the "Our Partners" logo ticker on
 * /for-agents.php, curated in Admin → Partners.
 *
 * All reads are pre-migration-safe via partners_supported(): pages that call
 * these before add_agency_partners.sql has run get empty results, so the ticker
 * simply hides instead of fataling.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';

/** True if the agency_partners table exists (memoised). False pre-migration. */
function partners_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.agency_partners')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Resolve a stored logo key to a URL (http / images/ / storage key), '' if none. */
function partner_logo_url(?string $key): string {
    $key = trim((string) $key);
    if ($key === '') return '';
    if (str_starts_with($key, 'http')) return $key;
    if (str_starts_with($key, '/'))     return $key;
    if (str_starts_with($key, 'images/')) return asset_url($key);
    return storage_url($key);
}

/** Normalise a partner website URL to an absolute, safe href (or '' if unusable). */
function partner_website_href(?string $url): string {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/** Published partners for the public ticker, in sort order. [] pre-migration. */
function fetch_published_partners(): array {
    if (!partners_supported()) return [];
    try {
        return db_query(
            "SELECT * FROM agency_partners
              WHERE is_published = TRUE AND COALESCE(logo_key,'') <> ''
              ORDER BY sort_order, name"
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** All partners for the admin list (published or not). [] pre-migration. */
function fetch_all_partners(): array {
    if (!partners_supported()) return [];
    try {
        return db_query("SELECT * FROM agency_partners ORDER BY sort_order, name")->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** One partner row by id, or null. */
function fetch_partner(int $id): ?array {
    if (!partners_supported() || $id <= 0) return null;
    $r = db_query("SELECT * FROM agency_partners WHERE id = :id", [':id' => $id])->fetch();
    return $r ?: null;
}

/**
 * True when this row came in through the public "Become Our Partner" form and
 * nobody has approved it yet — the admin list flags those for review.
 */
function partner_is_pending(array $p): bool {
    return !empty($p['submission_id']) && empty($p['is_published']);
}

/**
 * Resize + store an uploaded agency logo, preserving PNG/WebP alpha so a
 * transparent logo stays transparent on the cream ticker background.
 *
 * Shared by the owner's admin form and the PUBLIC registration form, so both go
 * through the same checks. The image is fully DECODED AND RE-ENCODED by GD,
 * which is what makes accepting an upload from an anonymous visitor safe: only
 * real pixels survive, so a polyglot file with script bytes appended cannot be
 * stored. Returns the storage key, '' when no file was sent (keep existing), or
 * false on a rejection with $err set.
 */
function partner_store_logo(array $file, ?string &$err): string|false {
    $err = null;
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) return '';                                     // no file = keep existing
    // php.ini's upload_max_filesize defaults to 2M, below our own 4MB cap, so
    // this is the limit a real upload usually trips — say so rather than "failed".
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) { $err = 'Logo too large — please send one under 2MB.'; return false; }
    if ($code !== UPLOAD_ERR_OK)                 { $err = 'Upload failed.'; return false; }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024)  { $err = 'Logo too large — please send one under 2MB.'; return false; }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_readable($tmp)) { $err = 'Upload failed.'; return false; }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime    = (string) @mime_content_type($tmp);
    if (!isset($allowed[$mime])) { $err = 'Use a JPG, PNG or WebP image.'; return false; }

    // Refuse absurd pixel dimensions before decoding — a small file can still
    // decompress into gigabytes of bitmap ("decompression bomb").
    $size = @getimagesize($tmp);
    if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] > 8000 || $size[1] > 8000) {
        $err = 'That image is not a usable logo.'; return false;
    }

    $src = match ($mime) {
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        default      => @imagecreatefromjpeg($tmp),
    };
    if (!$src) { $err = 'Could not read that image.'; return false; }

    $w = imagesx($src); $h = imagesy($src);
    $maxW = 600;
    if ($w > $maxW) {
        $nh  = (int) round($h * $maxW / $w);
        $dst = imagecreatetruecolor($maxW, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $nh, $w, $h);
        imagedestroy($src); $src = $dst;
    }

    $ext      = ($mime === 'image/jpeg') ? 'jpg' : 'png';   // normalise webp→png to keep alpha
    $filename = seo_filename((string)($file['name'] ?? 'logo'), 'partner', $ext);
    $out      = sys_get_temp_dir() . '/' . $filename;
    if ($ext === 'jpg') { imagejpeg($src, $out, 90); }
    else { imagealphablending($src, false); imagesavealpha($src, true); imagepng($src, $out); }
    imagedestroy($src);

    $stored = storage_put($out, $filename, $ext === 'jpg' ? 'image/jpeg' : 'image/png', 'partners');
    @unlink($out);
    if ($stored === false) { $err = 'Storage error — check the image bucket is configured.'; return false; }
    return $stored;
}

/**
 * Record a self-registered agency from the public "Become Our Partner" form.
 *
 * Always UNPUBLISHED: an anonymous visitor can hand us a logo and a link, but it
 * only reaches the public ticker (and gives them a backlink) once the owner
 * approves it in Admin → Partners. Best-effort — a failure here must never lose
 * the enquiry itself, so the caller ignores the return value.
 *
 * Re-registering under a name we already hold refreshes that row rather than
 * stacking duplicates, but never un-publishes a partner already approved.
 */
function partner_register_pending(string $name, string $website, string $logoKey, string $email, int $submissionId): int {
    $name = trim($name);
    if (!partners_supported() || $name === '') return 0;
    try {
        $existing = db_query('SELECT id, is_published FROM agency_partners WHERE lower(name) = lower(:n) LIMIT 1',
            [':n' => $name])->fetch();

        if ($existing) {
            $sets = ['website_url = COALESCE(NULLIF(:w, \'\'), website_url)',
                     'contact_email = COALESCE(NULLIF(:e, \'\'), contact_email)',
                     'submission_id = :s'];
            if ($logoKey !== '') $sets[] = 'logo_key = :l';
            $params = [':w' => $website, ':e' => $email, ':s' => $submissionId, ':id' => (int)$existing['id']];
            if ($logoKey !== '') $params[':l'] = $logoKey;
            db_query('UPDATE agency_partners SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
            return (int)$existing['id'];
        }

        $ord = (int) db_query('SELECT COALESCE(MAX(sort_order),0)+1 FROM agency_partners')->fetchColumn();
        $stmt = db()->prepare(
            'INSERT INTO agency_partners (name, website_url, logo_key, contact_email, submission_id, sort_order, is_published)
             VALUES (:n, :w, :l, :e, :s, :o, FALSE) RETURNING id'
        );
        $stmt->execute([':n' => $name, ':w' => $website, ':l' => $logoKey,
                        ':e' => $email, ':s' => $submissionId, ':o' => $ord]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[partners] register: ' . $e->getMessage());
        return 0;
    }
}
