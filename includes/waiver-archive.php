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
        $rows = db_query("SELECT g.id AS guest_id, g.hold_id, g.waiver_signed_at, g.waiver_reference, g.waiver_version, g.passport_file_key,
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
