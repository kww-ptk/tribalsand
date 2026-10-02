<?php
/**
 * Menu dish photos — includes/menu-images.php.
 *   php tests/menu_images_logic.php
 * Pure matching/parsing always; the import round-trip (Zuri's site stubbed, real
 * GD + storage) runs in ONE rolled-back transaction when a DB with the migration
 * is reachable, else SKIPs.
 */
declare(strict_types=1);

// Stub Zuri's website BEFORE the include defines the real fetchers.
$GLOBALS['__stub_page']  = '';
$GLOBALS['__stub_bytes'] = '';
function menu_http_get(string $url): ?string { return $GLOBALS['__stub_page'] !== '' ? $GLOBALS['__stub_page'] : null; }
function menu_http_get_many(array $urls, int $parallel = 4): array {
    $out = [];
    foreach ($urls as $u) $out[$u] = str_contains($u, 'broken') ? null : $GLOBALS['__stub_bytes'];
    return $out;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/menu.php';
require_once __DIR__ . '/../includes/menu-images.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$cond) $failures++;
}

// ── Names ────────────────────────────────────────────────────────────────────
check('name key: accents, case and punctuation fold', menu_name_key('Zuri Garden Velouté') === 'zuri garden veloute');
check('name key: & reads as "and"', menu_name_key('Octopus & Potato Salad') === menu_name_key('Octopus and Potato Salad'));
check('name key: spacing and dashes collapse', menu_name_key("  Crêpe – with   Fruit ") === 'crepe with fruit');

// ── Reading Zuri's menu page ────────────────────────────────────────────────
$page = '<img alt="Zuri Garden Velouté at Zuri Restaurant" src="https://zuriwatamu.com/thumb.php?f=assets%2Fimg%2Fdishes%2Fzuri-garden-veloute-b91a2c.webp&amp;s=192">'
      . '<img src="/thumb.php?f=assets%2Fimg%2Fdishes%2Foctopus.jpg&s=192" alt="Octopus &amp; Potato Salad at Zuri Restaurant" loading="lazy">'
      . '<img alt="Zuri Garden Velouté at Zuri Restaurant" src="/assets/img/dishes/second-copy.webp">'
      . '<img alt="Our beach at sunset" src="/assets/img/beach.jpg">'
      . '<img alt="Sneaky at Zuri Restaurant" src="https://evil.example/x.jpg">'
      . '<img alt="Path trick at Zuri Restaurant" src="/thumb.php?f=..%2F..%2Fetc%2Fpasswd&s=1">';
$photos = menu_zuri_dish_images($page);
check('page: the thumbnail resolves to the full dish photo',
    ($photos['zuri garden veloute'] ?? '') === 'https://zuriwatamu.com/assets/img/dishes/zuri-garden-veloute-b91a2c.webp');
check('page: entities in the alt are decoded', isset($photos['octopus and potato salad']));
check('page: the first photo of a dish wins', count(array_filter($photos, fn($u) => str_contains($u, 'second-copy'))) === 0);
check('page: non-dish images are ignored', !isset($photos['our beach at sunset']));
check('page: another host or a path outside /assets/img/dishes is refused', !isset($photos['sneaky']) && !isset($photos['path trick']));
check('page: nothing found on an empty page', menu_zuri_dish_images('') === []);

// ── Matching ────────────────────────────────────────────────────────────────
$items = [['id' => 1, 'name' => 'Zuri Garden Velouté', 'image_key' => null],
          ['id' => 2, 'name' => 'Octopus & Potato Salad', 'image_key' => 'menus/mine.jpg'],
          ['id' => 3, 'name' => 'Rustic White Bean Soup', 'image_key' => '']];
$plan = menu_match_dish_images($items, $photos);
check('match: same name gets the photo', isset($plan[1]));
check('match: a dish that already has a photo is left alone', !isset($plan[2]));
check('match: no dish of that name — nothing is guessed', !isset($plan[3]));
check('url: a storage key resolves, an https URL passes through, none is empty',
    menu_item_image_url(['image_key' => 'menus/a.jpg']) === '/assets/img/menus/a.jpg'
    && menu_item_image_url(['image_key' => 'https://cdn.example/a.jpg']) === 'https://cdn.example/a.jpg'
    && menu_item_image_url(['image_key' => null]) === '');

// ── Import round-trip (stubbed site, real GD + storage, rolled back) ─────────
try { db(); $dbOk = true; } catch (Throwable $e) { $dbOk = false; }
if (!$dbOk || !menus_supported() || !menu_images_supported()) {
    echo "SKIP  no DB or add_menu_item_images not applied — import round-trip\n";
} else {
    $im = imagecreatetruecolor(40, 30); imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40));
    ob_start(); imagejpeg($im); $GLOBALS['__stub_bytes'] = (string)ob_get_clean(); imagedestroy($im);
    $saved = [];
    db()->beginTransaction();
    try {
        db_query("INSERT INTO menus (slug, title) VALUES ('zz-photo-test', 'ZZ')");
        $m = (int)db()->lastInsertId();
        db_query("INSERT INTO menu_categories (menu_id, name) VALUES (:m, 'Soups')", [':m' => $m]);
        $c = (int)db()->lastInsertId();
        $mk = function (string $n, ?string $img = null) use ($c): int {
            db_query('INSERT INTO menu_items (category_id, name, image_key) VALUES (:c, :n, :i)', [':c' => $c, ':n' => $n, ':i' => $img]);
            return (int)db()->lastInsertId();
        };
        $a = $mk('Zuri Garden Velouté'); $b = $mk('Octopus and Potato Salad', 'menus/keep.jpg'); $x = $mk('Not On Their Site');
        $broken = $mk('Broken Dish');
        $GLOBALS['__stub_page'] = $page . '<img alt="Broken Dish at Zuri Restaurant" src="/assets/img/dishes/broken.jpg">';
        $r = menu_import_zuri_images($m);
        $key = (string)db_query('SELECT image_key FROM menu_items WHERE id = :i', [':i' => $a])->fetchColumn();
        if ($key !== '' && !str_starts_with($key, 'http')) $saved[] = __DIR__ . '/../assets/img/' . $key;
        check('import: the matched dish now has a stored photo', $key !== '' && $key !== 'menus/keep.jpg');
        check('import: an existing photo is never replaced',
            db_query('SELECT image_key FROM menu_items WHERE id = :i', [':i' => $b])->fetchColumn() === 'menus/keep.jpg');
        check('import: a failed download is counted, not fatal', $r['failed'] === 1 && $r['saved'] === 1);
        check('import: the unmatched dish is reported', $r['unmatched'] === 1);
        $GLOBALS['__stub_page'] = '';
        $threw = false; try { menu_import_zuri_images($m); } catch (RuntimeException $e) { $threw = true; }
        check('import: an unreachable site is a clear error', $threw);
    } finally {
        db()->rollBack();
        foreach ($saved as $f) if (is_file($f)) @unlink($f);   // the local storage fallback wrote a real file
    }
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
