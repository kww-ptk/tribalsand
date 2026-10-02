<?php
/**
 * Menu dish photos — menu_items.image_key (migration add_menu_item_images.sql).
 *
 * LOCAL ONLY: the photo is not part of the Zuri sync payload (sync_map_menu_item()
 * sends an explicit field list), so nothing here emits a sync event. A key is a
 * storage key (storage_url()) or a full https URL, resolved by menu_item_image_url().
 *
 * "Import photos from Zuri's website" (admin/menu-edit.php) reads the restaurant's
 * public menu page, whose dish photos carry alt="<Dish> at Zuri Restaurant", matches
 * them to our dishes by name and COPIES each photo into our own storage, so the menu
 * never depends on another site staying up. Only dishes without a photo are filled.
 * Test: php tests/menu_images_logic.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';

const MENU_ZURI_SITE      = 'https://zuriwatamu.com';
const MENU_ZURI_MENU_PAGE = 'https://zuriwatamu.com/menu/';

/** True once add_menu_item_images.sql ran (a catalog lookup, safe inside a transaction). */
function menu_images_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query("SELECT 1 FROM information_schema.columns
                                WHERE table_name = 'menu_items' AND column_name = 'image_key' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** The dish photo's URL, or '' when it has none. */
function menu_item_image_url(array $it): string {
    $key = trim((string)($it['image_key'] ?? ''));
    return $key === '' ? '' : storage_url($key);   // storage_url() passes https through and repoints legacy r2.dev keys
}

/** A dish name reduced for matching: lower case, accents dropped, punctuation → spaces — PURE. */
function menu_name_key(string $name): string {
    $s = mb_strtolower(trim($name));
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'á' => 'a', 'ä' => 'a',
                    'ç' => 'c', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
                    'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u', 'ñ' => 'n', '&' => ' and ', '’' => "'"]);
    $s = preg_replace("/[^a-z0-9]+/", ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/**
 * The dish photos on Zuri's menu page: [name key => absolute photo URL] — PURE.
 * Zuri serves thumbnails through thumb.php?f=<asset path>&s=192; the full photo is
 * the asset path itself, so that is what is returned. First photo per dish wins.
 */
function menu_zuri_dish_images(string $html): array {
    $out = [];
    if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) return $out;
    foreach ($tags[0] as $tag) {
        if (!preg_match('/\balt\s*=\s*"([^"]*?)\s+at Zuri Restaurant"/i', $tag, $a)) continue;
        if (!preg_match('/\bsrc\s*=\s*"([^"]+)"/i', $tag, $s)) continue;
        $src = html_entity_decode($s[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[?&]f=([^&]+)/', $src, $f)) $src = '/' . ltrim(rawurldecode($f[1]), '/');   // thumb.php → the full asset
        if (!preg_match('~^/assets/img/dishes/[A-Za-z0-9._/-]+\.(?:webp|jpe?g|png)$~i', $src)) continue;   // dish photos only, nothing odd
        if (str_contains($src, '..')) continue;
        $key = menu_name_key(html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($key !== '' && !isset($out[$key])) $out[$key] = MENU_ZURI_SITE . $src;
    }
    return $out;
}

/**
 * Which of our dishes get which photo: [item id => photo URL] — PURE. Exact name
 * match after menu_name_key(); dishes that already have a photo are skipped.
 */
function menu_match_dish_images(array $items, array $photos): array {
    $plan = [];
    foreach ($items as $it) {
        if (trim((string)($it['image_key'] ?? '')) !== '') continue;
        $k = menu_name_key((string)($it['name'] ?? ''));
        if ($k !== '' && isset($photos[$k])) $plan[(int)$it['id']] = $photos[$k];
    }
    return $plan;
}

/**
 * Store an image file as a dish photo: any JPG/PNG/WebP, scaled to at most 1000px
 * wide, saved as JPEG through storage_put() (S3 on production). Returns the key.
 * @throws RuntimeException with a message fit for the admin
 */
function menu_store_image(string $path, string $nameHint): string {
    $info = @getimagesize($path);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Use a JPG, PNG or WebP image.');
    $src = match ($mime) { 'image/png' => @imagecreatefrompng($path), 'image/webp' => @imagecreatefromwebp($path), default => @imagecreatefromjpeg($path) };
    if (!$src) throw new RuntimeException('Could not read that image.');
    $w = imagesx($src); $h = imagesy($src);
    $nw = min($w, 1000); $nh = (int) round($h * $nw / max(1, $w));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // transparent PNG/WebP → white, not black
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $filename = seo_filename($nameHint, 'menu-dish');
    $tmp = sys_get_temp_dir() . '/' . $filename;
    imagejpeg($dst, $tmp, 84); imagedestroy($dst);
    $stored = storage_put($tmp, $filename, 'image/jpeg', 'menus');
    @unlink($tmp);
    if ($stored === false) throw new RuntimeException('Could not save the image (storage error).');
    return (string)$stored;
}

/** An uploaded dish photo ($_FILES entry) → storage key; '' when no file was chosen. */
function menu_store_upload(array $file, string $nameHint): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed — try again.');
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) throw new RuntimeException('Image too large (max 8 MB).');
    return menu_store_image((string)$file['tmp_name'], $nameHint);
}

/** GET a URL (Zuri's public site). Returns the body or null. Overridable in tests. */
if (!function_exists('menu_http_get')) {
    function menu_http_get(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                                CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'TribalSand-menu-import/1.0']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    }
}

/**
 * GET many URLs at once (4 in flight — gentle on their server) — one by one, 80 photos took minutes, longer
 * than the load balancer lets a request run. Returns [url => body|null].
 * Overridable in tests.
 */
if (!function_exists('menu_http_get_many')) {
    function menu_http_get_many(array $urls, int $parallel = 4): array {
        $out = array_fill_keys($urls, null);
        $queue = array_values(array_unique($urls));
        $mh = curl_multi_init();
        $live = [];
        $add = function () use (&$queue, &$live, $mh): void {
            $url = array_shift($queue);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                                    CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'TribalSand-menu-import/1.0']);
            curl_multi_add_handle($mh, $ch);
            $live[(int)$ch] = [$ch, $url];
        };
        while ($queue && count($live) < $parallel) $add();
        do {
            curl_multi_exec($mh, $running);
            while ($done = curl_multi_info_read($mh)) {
                $ch = $done['handle'];
                [, $url] = $live[(int)$ch];
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $body = curl_multi_getcontent($ch);
                $out[$url] = ($done['result'] === CURLE_OK && $code >= 200 && $code < 300) ? (string)$body : null;
                curl_multi_remove_handle($mh, $ch); curl_close($ch);
                unset($live[(int)$ch]);
                if ($queue) $add();
            }
            if ($running || $live) curl_multi_select($mh, 0.5);
        } while ($running || $live);
        curl_multi_close($mh);
        return $out;
    }
}

/**
 * Fill the photo of every dish in $menuId that has none from Zuri's website.
 * @return array{matched:int, saved:int, failed:int, unmatched:int, found:int}
 * @throws RuntimeException when Zuri's menu page can't be read
 */
function menu_import_zuri_images(int $menuId): array {
    $html = menu_http_get(MENU_ZURI_MENU_PAGE);
    if ($html === null) throw new RuntimeException('Couldn’t reach Zuri’s website — try again in a minute.');
    $photos = menu_zuri_dish_images($html);
    if (!$photos) throw new RuntimeException('No dish photos were found on Zuri’s menu page.');

    $items = db_query("SELECT i.id, i.name, i.image_key FROM menu_items i JOIN menu_categories c ON c.id = i.category_id
                        WHERE c.menu_id = :m" . menu_live_sql('i') . menu_live_sql('c'), [':m' => $menuId])->fetchAll();
    $plan = menu_match_dish_images($items, $photos);
    $names = array_column($items, 'name', 'id');
    $saved = 0; $failed = 0;
    $bodies = $plan ? menu_http_get_many(array_values($plan)) : [];
    foreach ($plan as $itemId => $url) {
        $bytes = $bodies[$url] ?? null;
        if ($bytes === null) { $failed++; continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'dish');
        file_put_contents($tmp, $bytes);
        try {
            $key = menu_store_image($tmp, (string)($names[$itemId] ?? 'dish'));
            db_query('UPDATE menu_items SET image_key = :k WHERE id = :i AND (image_key IS NULL OR image_key = \'\')', [':k' => $key, ':i' => $itemId]);
            $saved++;
        } catch (Throwable $e) {
            $failed++;
            error_log('[menu-images] ' . $url . ': ' . $e->getMessage());
        } finally { @unlink($tmp); }
    }
    $withoutPhoto = count(array_filter($items, fn($i) => trim((string)($i['image_key'] ?? '')) === ''));
    return ['found' => count($photos), 'matched' => count($plan), 'saved' => $saved, 'failed' => $failed,
            'unmatched' => $withoutPhoto - count($plan)];
}
