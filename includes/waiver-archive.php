<?php
/**
 * Signed waivers archive (owner): guest check-in + visitor waivers by month,
 * property and kind, and the monthly ZIP. Reads only — the records themselves
 * come from includes/waiver-record.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/checkin.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/visitor-store.php';
require_once __DIR__ . '/waiver-record.php';

function waiver_month_bounds(string $ym): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12) return null;
    $from = sprintf('%04d-%02d-01', (int)$mm[1], (int)$mm[2]);
    return [$from, date('Y-m-d', strtotime($from . ' +1 month'))];
}

function waiver_archive_filter_rows(array $rows, ?int $venueId, string $q): array {
    $q = mb_strtolower(trim($q));
    return array_values(array_filter($rows, function ($r) use ($venueId, $q) {
        if ($venueId !== null && (int)($r['venue_id'] ?? 0) !== $venueId) return false;
        if ($q === '') return true;
        $hay = mb_strtolower(($r['name'] ?? '') . ' ' . ($r['reference'] ?? '') . ' ' . ($r['doc_number'] ?? ''));
        return str_contains($hay, $q);
    }));
}

function waiver_archive_rows(string $ym, ?int $venueId, string $kind, string $q = ''): array {
    $b = waiver_month_bounds($ym);
    if (!$b || !checkin_signature_supported()) return [];
    $out = [];
    if ($kind !== 'visitors') {
        $snap = checkin_identity_snapshot_supported();
        $scan = waiver_scan_snapshot_supported() ? 'g.waiver_scan_snapshot' : 'NULL';
        $idt  = checkin_id_type_supported() ? 'g.waiver_id_type_snapshot' : 'NULL';
        $wref = checkin_reference_supported() ? 'g.waiver_reference' : 'NULL';
        $rows = db_query("SELECT g.id AS guest_id, g.hold_id, g.waiver_signed_at, $wref AS waiver_reference, g.waiver_version, g.passport_file_key,
                                 $scan AS waiver_scan_snapshot, $idt AS waiver_id_type_snapshot,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_name_snapshot,''), g.passport_name)" : 'g.passport_name') . " AS name,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_passport_snapshot,''), g.passport_number)" : 'g.passport_number') . " AS doc_number,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_nationality_snapshot,''), g.nationality)" : 'g.nationality') . " AS nationality,
                                 " . ($snap ? "COALESCE(NULLIF(g.waiver_passport_expiry_snapshot::text,''), g.passport_expiry::text)" : 'g.passport_expiry::text') . " AS expiry,
                                 r.venue_id, v.name AS venue_name, r.name AS room_name
                            FROM checkin_guests g
                            JOIN holds h  ON h.id = g.hold_id
                            JOIN units u  ON u.id = h.unit_id
                            JOIN rooms r  ON r.id = " . hold_room_id_sql('h', 'u') . "
                            LEFT JOIN venues v ON v.id = r.venue_id
                           WHERE g.waiver_signed_at >= :f AND g.waiver_signed_at < :t", [':f' => $b[0], ':t' => $b[1]])->fetchAll();
        foreach ($rows as $r) {
            $s = checkin_guest_scan_key($r);
            $out[] = ['kind' => 'guest', 'signed_at' => (string)$r['waiver_signed_at'], 'name' => (string)$r['name'],
                'venue_id' => $r['venue_id'] !== null ? (int)$r['venue_id'] : null, 'venue_name' => (string)($r['venue_name'] ?? ''),
                'where' => 'TS-' . (int)$r['hold_id'] . ($r['room_name'] ? ' · ' . $r['room_name'] : ''),
                'doc_label' => checkin_id_type_label(trim((string)$r['waiver_id_type_snapshot']) ?: 'passport'),
                'doc_number' => (string)$r['doc_number'], 'nationality' => (string)$r['nationality'], 'expiry' => substr((string)$r['expiry'], 0, 10),
                'reference' => (string)$r['waiver_reference'], 'version' => (string)$r['waiver_version'],
                'scan_key' => $s['key'], 'scan_frozen' => $s['frozen'],
                'hold_id' => (int)$r['hold_id'], 'guest_id' => (int)$r['guest_id'], 'visitor_id' => 0, 'removed_at' => ''];
        }
    }
    if ($kind !== 'guests' && visitor_reg_supported()) {
        $ps = visitor_photo_snapshot_supported() ? 'rv.waiver_photo_snapshot' : 'NULL';
        $rows = db_query("SELECT rv.*, $ps AS waiver_photo_snapshot, fv.venue_id, fv.place_name, ve.name AS venue_name
                            FROM registered_visitors rv
                            LEFT JOIN LATERAL (SELECT p.venue_id, p.name AS place_name FROM visitor_visits vv JOIN visitor_places p ON p.id = vv.place_id
                                                WHERE vv.visitor_id = rv.id AND vv.visit_date >= rv.waiver_signed_at::date
                                                ORDER BY vv.visit_date, vv.id LIMIT 1) fv ON TRUE
                            LEFT JOIN venues ve ON ve.id = fv.venue_id
                           WHERE rv.waiver_signed_at >= :f AND rv.waiver_signed_at < :t AND rv.parent_id IS NULL",
                         [':f' => $b[0], ':t' => $b[1]])->fetchAll();
        foreach ($rows as $r) {
            $s = visitor_scan_key($r);
            $out[] = ['kind' => 'visitor', 'signed_at' => (string)$r['waiver_signed_at'], 'name' => (string)$r['waiver_name_snapshot'],
                'venue_id' => $r['venue_id'] !== null ? (int)$r['venue_id'] : null, 'venue_name' => (string)($r['venue_name'] ?? ''),
                'where' => $r['place_name'] ? (string)$r['place_name'] : 'No visit yet',
                'doc_label' => checkin_id_type_label((string)($r['waiver_id_type_snapshot'] ?? 'passport')),
                'doc_number' => (string)$r['waiver_id_number_snapshot'], 'nationality' => (string)$r['waiver_nationality_snapshot'],
                'expiry' => substr((string)$r['waiver_id_expiry_snapshot'], 0, 10),
                'reference' => (string)$r['waiver_reference'], 'version' => (string)$r['waiver_version'],
                'scan_key' => $s['key'], 'scan_frozen' => $s['frozen'],
                'hold_id' => 0, 'guest_id' => 0, 'visitor_id' => (int)$r['id'], 'removed_at' => (string)($r['removed_at'] ?? '')];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['signed_at'], $a['signed_at']));
    return waiver_archive_filter_rows($out, $venueId, $q);
}

// ── Download a month as a ZIP ───────────────────────────────────────────────

/** Letters, digits, space, dash and dot only; accents folded; at most 80 characters. */
function waiver_safe_name(string $s): string {
    // Fold accents ourselves: iconv //TRANSLIT differs by libc (macOS turns é into "'e").
    $s = strtr($s, [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
        'Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','ß'=>'ss',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ð'=>'d','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y',
        'Ā'=>'A','ā'=>'a','Ă'=>'A','ă'=>'a','Ą'=>'A','ą'=>'a','Ć'=>'C','ć'=>'c','Č'=>'C','č'=>'c','Ď'=>'D','ď'=>'d','Đ'=>'D','đ'=>'d','Ē'=>'E','ē'=>'e',
        'Ė'=>'E','ė'=>'e','Ę'=>'E','ę'=>'e','Ě'=>'E','ě'=>'e','Ğ'=>'G','ğ'=>'g','Ī'=>'I','ī'=>'i','İ'=>'I','ı'=>'i','Ł'=>'L','ł'=>'l','Ń'=>'N','ń'=>'n',
        'Ň'=>'N','ň'=>'n','Ő'=>'O','ő'=>'o','Œ'=>'OE','œ'=>'oe','Ř'=>'R','ř'=>'r','Ś'=>'S','ś'=>'s','Ş'=>'S','ş'=>'s','Š'=>'S','š'=>'s','Ť'=>'T','ť'=>'t',
        'Ū'=>'U','ū'=>'u','Ů'=>'U','ů'=>'u','Ű'=>'U','ű'=>'u','Ź'=>'Z','ź'=>'z','Ż'=>'Z','ż'=>'z','Ž'=>'Z','ž'=>'z',
    ]);
    $s = preg_replace('/[^A-Za-z0-9 .\-]+/', ' ', $s);
    // Edge dots/spaces go too: a property called ".." must never become a ".." folder.
    return trim(mb_substr(trim(preg_replace('/\s+/', ' ', (string)$s), " ."), 0, 80), " .");
}

/** `YYYY-MM-DD <Name> <reference>`, safe for a file name. */
function waiver_file_base(array $row): string {
    $date = date('Y-m-d', strtotime((string)$row['signed_at']));
    $ref  = waiver_safe_name((string)$row['reference']);
    $name = waiver_safe_name((string)$row['name']);
    // ≤ 100 characters overall; the NAME gives way so the date and the reference always survive.
    $room = 100 - strlen($date) - strlen($ref) - 2;
    $name = rtrim(substr($name, 0, max(0, $room)), ' .');
    return preg_replace('/\s+/', ' ', trim($date . ' ' . $name . ' ' . $ref));
}

/** A spreadsheet cell safe to open in Excel: a value that starts like a formula is quoted with a leading apostrophe. */
function waiver_csv_cell(string $v): string {
    return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
}

/** The folder a record goes in: the property, or Visitors/<property>. */
function waiver_zip_folder(array $row): string {
    $p = waiver_safe_name((string)($row['venue_name'] ?? ''));
    if (($row['kind'] ?? '') === 'visitor') return 'Visitors/' . ($p !== '' ? $p : 'No visit yet');
    return $p !== '' ? $p : 'No property';
}

/** The record model for an archive row (from the DB by id; tests pass `_model`). */
function waiver_archive_model(array $row): ?array {
    if (array_key_exists('_model', $row)) return $row['_model'];
    if ($row['kind'] === 'guest') {
        $hold = fetch_hold_for_guest((int)$row['hold_id']);
        $g = db_query('SELECT * FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $row['guest_id'], ':h' => $row['hold_id']])->fetch();
        return ($hold && $g) ? waiver_record_model_guest($hold, $g, (string)$row['reference']) : null;
    }
    $v = visitor_find((int)$row['visitor_id'], true);
    return $v ? waiver_record_model_visitor($v) : null;
}

/**
 * Build the ZIP at $path: one HTML record per signature (the scan embedded) plus
 * the original scan file beside it, and index.csv. $read reads a private file
 * (tests inject a stub). A record that can no longer be built is skipped and counted.
 */
function waiver_zip_build(array $rows, string $path, ?callable $read = null): array {
    $read = $read ?? 'storage_read_private';
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the ZIP file.');
    // ZipArchive::addFromString keeps every buffer in memory until close(), so each record's
    // HTML and scan are spooled to a private temp dir and added by path (addFile reads them
    // at close). Only one record's bytes are in PHP memory at a time.
    $spool = sys_get_temp_dir() . '/waivers-spool-' . bin2hex(random_bytes(8));
    if (!@mkdir($spool, 0700)) { $z->close(); throw new RuntimeException('Could not create a temporary folder.'); }
    $used = []; $missing = []; $count = 0; $skipped = 0; $seq = 0;
    try {
        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, ['Signed', 'Name', 'Type', 'Property', 'Booking / place', 'Document', 'Document number', 'Nationality', 'Expiry', 'Reference', 'Waiver version', 'Scan file'], ',', '"', '');
        foreach ($rows as $row) {
            $m = waiver_archive_model($row);
            if (!$m) { $skipped++; continue; }
            $dir = waiver_zip_folder($row); $base = waiver_file_base($row);
            $n = 1; $try = $base;
            while (isset($used[$dir . '/' . $try])) $try = $base . ' (' . (++$n) . ')';
            $used[$dir . '/' . $try] = true; $base = $try;
            $key = (string)($m['scan_key'] ?? '');
            $bytes = $key !== '' ? $read($key) : null;
            $scanFile = '';
            if ($key !== '' && $bytes === null) { $missing[] = $key; $scanFile = 'missing'; }
            if ($bytes !== null) {
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][waiver_scan_mime($bytes)] ?? (pathinfo($key, PATHINFO_EXTENSION) ?: 'bin');
                $scanFile = $base . '.' . $ext;
                $tmp = $spool . '/' . (++$seq);
                if (file_put_contents($tmp, $bytes) !== strlen($bytes)) throw new RuntimeException('Could not write a temporary file (disk full?).');
                if (!$z->addFile($tmp, $dir . '/' . $scanFile)) throw new RuntimeException('Could not add a file to the ZIP.');
            }
            $html = waiver_record_html($m, ['scan' => 'embed', 'scan_bytes' => $bytes ?? '', 'scan_file' => $scanFile, 'print_button' => true]);
            $tmp = $spool . '/' . (++$seq);
            if (file_put_contents($tmp, $html) !== strlen($html)) throw new RuntimeException('Could not write a temporary file (disk full?).');
            if (!$z->addFile($tmp, $dir . '/' . $base . '.html')) throw new RuntimeException('Could not add a file to the ZIP.');
            unset($bytes, $html);
            fputcsv($csv, array_map('waiver_csv_cell', array_map('strval', [date('Y-m-d H:i', strtotime((string)$row['signed_at'])), $row['name'], $row['kind'] === 'guest' ? 'Guest' : 'Visitor',
                $row['venue_name'], $row['where'], $row['doc_label'], $row['doc_number'], $row['nationality'], $row['expiry'],
                $row['reference'], $row['version'], $scanFile])), ',', '"', '');
            $count++;
        }
        rewind($csv);
        $z->addFromString('index.csv', "\xEF\xBB\xBF" . stream_get_contents($csv));   // small; BOM: Excel reads UTF-8
        if (!$z->close()) throw new RuntimeException('Could not write the ZIP file.');
    } finally {
        foreach ((array)glob($spool . '/*') as $f) @unlink($f);
        @rmdir($spool);
    }
    return ['records' => $count, 'missing' => $missing, 'skipped' => $skipped];
}

/** Stream the month's ZIP and write the audit line. Call before any output. */
function waiver_archive_download(array $rows, string $ym, ?int $venueId, string $kind): void {
    if (!class_exists('ZipArchive')) { http_response_code(501); exit('ZIP support is not installed on the server.'); }
    @set_time_limit(300);
    $path = tempnam(sys_get_temp_dir(), 'waivers');
    try {
        // Building can take a while: let go of the session lock so the owner's other tabs keep working.
        // (audit_log only reads $_SESSION, which stays readable after the write lock is released.)
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $res = waiver_zip_build($rows, $path);
        $venueName = $venueId ? (string)db_query('SELECT name FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn() : '';
        $file = 'waivers-' . $ym . ($venueName !== '' ? '-' . waiver_safe_name($venueName) : '') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        if (readfile($path) === false) throw new RuntimeException('Could not send the ZIP file.');
        // Logged only once the file has gone out.
        audit_log('waivers.download', 'waivers', 0, "month $ym · " . ($venueName ?: 'all properties') . " · $kind · {$res['records']} records"
            . ($res['missing'] ? ' · ' . count($res['missing']) . ' scans missing' : '')
            . ($res['skipped'] ? ' · ' . $res['skipped'] . ' skipped' : ''));
    } finally {
        @unlink($path);   // never leave a ZIP of personal documents behind, even on an exception
    }
}
