<?php
declare(strict_types=1);
/**
 * Media library — the image picker's read model.
 *
 * The picker does NOT read the `media` table alone. media_library_items()
 * UNIONs it with the image keys already stored by every existing gallery
 * (venue_images / room_images / tour_images / property_images). That means:
 *
 *   - Photos you already uploaded through a venue or room gallery appear in the
 *     picker immediately, with no backfill step.
 *   - Anything uploaded through those galleries tomorrow appears too, with no
 *     sync job that could drift out of date.
 *
 * SECURITY: private check-in scans (passport photos, deposit card images) live
 * under 'checkin/' and are served only through admin/checkin-file.php. They are
 * excluded here in the query itself — not by convention — and must never be
 * listed by a picker. See MEDIA_PRIVATE_PREFIXES.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';

/** Key prefixes that must never appear in the picker. */
const MEDIA_PRIVATE_PREFIXES = ['checkin/'];

/** True once add_media.sql has been applied. */
function media_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query("SELECT to_regclass('public.media') IS NOT NULL AS ok")->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True when a stored key is private and must be hidden from the library. */
function media_is_private(string $key): bool {
    $k = ltrim(trim($key), '/');
    foreach (MEDIA_PRIVATE_PREFIXES as $p) {
        if (str_starts_with($k, $p)) return true;
    }
    return false;
}

/** Browser-usable URL for a stored key (mirrors page_image()). */
function media_url(string $key): string {
    $k = trim($key);
    if ($k === '') return '';
    if (str_starts_with($k, 'http') || str_starts_with($k, '/')) return $k;
    if (str_starts_with($k, 'images/')) return asset_url($k);
    return storage_url($k);
}

/** Does a table exist? Used so the union degrades on partly-migrated databases. */
function _media_table_exists(string $table): bool {
    static $seen = [];
    if (isset($seen[$table])) return $seen[$table];
    try {
        $seen[$table] = (bool) db_query(
            "SELECT to_regclass(:t) IS NOT NULL AS ok", [':t' => 'public.' . $table]
        )->fetchColumn();
    } catch (Throwable $e) { $seen[$table] = false; }
    return $seen[$table];
}

/**
 * Every image an owner may pick, newest first.
 *
 * @return list<array{key:string,url:string,source:string,alt:string}>
 */
function media_library_items(string $search = '', int $limit = 500): array {
    $items = [];   // keyed by storage key so the same photo can't appear twice

    // 1) Uploads made through the media library itself.
    if (media_supported()) {
        try {
            $rows = db_query(
                'SELECT storage_key, alt_text FROM media ORDER BY created_at DESC, id DESC LIMIT :l',
                [':l' => $limit]
            )->fetchAll();
            foreach ($rows as $r) {
                $k = (string)$r['storage_key'];
                if ($k === '' || media_is_private($k)) continue;
                $items[$k] = ['key'=>$k, 'url'=>media_url($k), 'source'=>'Library',
                              'alt'=>(string)($r['alt_text'] ?? '')];
            }
        } catch (Throwable $e) { /* fall through to the galleries */ }
    }

    // 2) Everything already attached to a gallery. Read-only — never written back.
    $galleries = [
        'venue_images'    => ['filename', 'Venues'],
        'room_images'     => ['filename', 'Rooms'],
        'tour_images'     => ['filename', 'Tours'],
        'property_images' => ['filename', 'Properties'],
    ];
    foreach ($galleries as $table => [$col, $label]) {
        if (!_media_table_exists($table)) continue;
        try {
            $rows = db_query("SELECT DISTINCT {$col} AS k FROM {$table}
                              WHERE {$col} IS NOT NULL AND {$col} <> '' LIMIT :l",
                             [':l' => $limit])->fetchAll();
        } catch (Throwable $e) { continue; }
        foreach ($rows as $r) {
            $k = (string)$r['k'];
            if ($k === '' || media_is_private($k) || isset($items[$k])) continue;
            $items[$k] = ['key'=>$k, 'url'=>media_url($k), 'source'=>$label, 'alt'=>''];
        }
    }

    $out = array_values($items);
    if (trim($search) !== '') {
        $q = mb_strtolower(trim($search));
        $out = array_values(array_filter($out,
            fn($i) => str_contains(mb_strtolower($i['key']), $q)));
    }
    return $out;
}

/** Gallery tables the picker may append to → their owner FK column. */
const MEDIA_GALLERY_TABLES = [
    'venue_images'    => 'venue_id',
    'room_images'     => 'room_id',
    'tour_images'     => 'tour_id',
    'property_images' => 'property_id',
];

/**
 * Append an existing library image key as a new gallery row, mirroring each
 * page's own upload INSERT (filename, alt_text, is_hero, sort_order). The
 * table/FK pair is whitelisted so a caller can't target an arbitrary table.
 * Becomes the hero only when the gallery is currently empty. Returns true when
 * a row was inserted; false when the key is blank/private, already in this
 * gallery, or on error.
 */
function media_attach_to_gallery(string $table, string $fkCol, int $ownerId, string $key, string $alt = ''): bool {
    $key = trim($key);
    if ($key === '' || $ownerId <= 0 || media_is_private($key)) return false;
    if ((MEDIA_GALLERY_TABLES[$table] ?? null) !== $fkCol)       return false;   // whitelist guard
    try {
        // Don't add the same file twice to one gallery.
        if (db_query("SELECT 1 FROM {$table} WHERE {$fkCol} = :o AND filename = :f LIMIT 1",
                     [':o' => $ownerId, ':f' => $key])->fetchColumn()) {
            return false;
        }
        $count = (int) db_query("SELECT COUNT(*) FROM {$table} WHERE {$fkCol} = :o", [':o' => $ownerId])->fetchColumn();
        $max   = (int) db_query("SELECT COALESCE(MAX(sort_order),0) FROM {$table} WHERE {$fkCol} = :o", [':o' => $ownerId])->fetchColumn();
        db_query(
            "INSERT INTO {$table} ({$fkCol}, filename, alt_text, is_hero, sort_order)
             VALUES (:o, :f, :alt, :hero, :ord)",
            [':o' => $ownerId, ':f' => $key, ':alt' => $alt,
             ':hero' => $count === 0 ? 'TRUE' : 'FALSE', ':ord' => $max + 1]
        );
        return true;
    } catch (Throwable $e) {
        error_log('[media] attach to ' . $table . ' failed: ' . $e->getMessage());
        return false;
    }
}

/** Record a library upload. Returns the storage key, or '' on failure. */
function media_record(string $storageKey, string $originalName = '', ?int $adminId = null,
                      ?int $bytes = null, ?int $w = null, ?int $h = null): string {
    $storageKey = trim($storageKey);
    if ($storageKey === '' || !media_supported() || media_is_private($storageKey)) return '';
    try {
        db_query(
            'INSERT INTO media (storage_key, original_name, bytes, width, height, uploaded_by)
             VALUES (:k, :n, :b, :w, :h, :u)
             ON CONFLICT (storage_key) DO NOTHING',
            [':k'=>$storageKey, ':n'=>$originalName ?: null, ':b'=>$bytes,
             ':w'=>$w, ':h'=>$h, ':u'=>$adminId]
        );
    } catch (Throwable $e) {
        error_log('[media] record failed: ' . $e->getMessage());
        return '';
    }
    return $storageKey;
}
