<?php
declare(strict_types=1);
// Editable page content + media library.
// Run: php tests/page_content.php
// DB assertions run inside ONE transaction that is ROLLED BACK, so no real
// page_content or media rows are left behind.
require_once __DIR__ . '/../includes/page-content.php';
require_once __DIR__ . '/../includes/media.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Registry integrity ──────────────────────────────────────────────────────
$reg = page_content_registry();
check('registry: home exists',            isset($reg['home']));
$valid = ['text','textarea','html','image'];
$allOk = true; $labelled = true; $defaults = true;
foreach ($reg as $pageKey => $def) {
    if (!isset($def['label'], $def['groups'])) { $allOk = false; break; }
    foreach ($def['groups'] as $slots) {
        foreach ($slots as $k => $s) {
            if (!in_array($s['type'] ?? '', $valid, true)) $allOk = false;
            if (trim((string)($s['label'] ?? '')) === '')  $labelled = false;
            // Every non-image slot needs a default, or an empty DB blanks the page.
            if (($s['type'] ?? '') !== 'image' && !array_key_exists('default', $s)) $defaults = false;
        }
    }
}
check('registry: every slot has a valid type',   $allOk);
check('registry: every slot has a label',        $labelled);
check('registry: text slots all carry defaults', $defaults);

$slots = page_slots('home');
check('slots: home is flattened',        count($slots) > 0);
check('slots: carry their group',        ($slots['hero_title']['group'] ?? '') === 'Hero');
check('slots: unknown page → empty',     page_slots('does-not-exist') === []);

// no duplicate slot keys across groups (a dupe would silently shadow)
$seen = []; $dupes = 0;
foreach ($reg['home']['groups'] as $g) { foreach ($g as $k => $_) { if (isset($seen[$k])) $dupes++; $seen[$k] = 1; } }
check('slots: no duplicate keys on home', $dupes === 0);

// ── Fallback: the page must render its shipped copy with nothing stored ─────
check('value: falls back to the default',
      page_value('home','hero_sub') === $slots['hero_sub']['default']);
check('value: unknown slot → empty string', page_value('home','nope') === '');
check('value: unknown page → empty string', page_value('nope','hero_title') === '');

// ── Escaping ────────────────────────────────────────────────────────────────
check('text: escapes markup',   !str_contains(page_text('home','hero_title'), '<em>'));
check('html: keeps markup on an html slot', str_contains(page_html('home','hero_title'), '<em>'));
// page_html on a NON-html slot must still escape — mislabelling a call site
// must not become an injection point.
check('html: escapes a text-typed slot',
      page_html('home','stat1_num') === e(page_value('home','stat1_num')));

// ── Images ──────────────────────────────────────────────────────────────────
check('image: absolute url passes through',
      page_image_probe('https://x/y.jpg') === 'https://x/y.jpg');
check('image: root-relative passes through', page_image_probe('/images/a.jpg') === '/images/a.jpg');
check('image: images/ goes through asset_url',
      str_ends_with(page_image_probe('images/a.jpg'), '/images/a.jpg'));
check('image: empty → empty',                page_image_probe('') === '');

/** page_image() operates on a stored value; probe the same resolution rules. */
function page_image_probe(string $v): string {
    if ($v === '') return '';
    if (str_starts_with($v, 'http') || str_starts_with($v, '/')) return $v;
    if (str_starts_with($v, 'images/')) return asset_url($v);
    return storage_url($v);
}

// ── Media privacy: check-in scans must never reach a picker ─────────────────
check('media: checkin/ key is private',        media_is_private('checkin/12/passport/a.jpg'));
check('media: leading slash still private',    media_is_private('/checkin/12/deposit/b.jpg'));
check('media: ordinary key is not private',    !media_is_private('rooms/abc.jpg'));
check('media: venue key is not private',       !media_is_private('images/zuri/a.webp'));

$items = media_library_items();
check('media: library returns a list',         is_array($items));
$leaked = array_filter($items, fn($i) => media_is_private($i['key']));
check('media: no private key in the library',  $leaked === []);
$dupeKeys = array_column($items, 'key');
check('media: no duplicate keys',              count($dupeKeys) === count(array_unique($dupeKeys)));
$shaped = array_filter($items, fn($i) => isset($i['key'],$i['url'],$i['source']));
check('media: every item is well-formed',      count($shaped) === count($items));
check('media: search filters by filename',     count(media_library_items('zzz-no-such-file')) === 0);

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!page_content_supported()) {
    echo "\nSKIP  DB assertions (add_page_content.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    page_content_save('home', 'hero_sub', 'Custom sub-heading', null);
    // page_content_values() memoises per request, so read the row directly.
    $row = db_query('SELECT value FROM page_content WHERE page_key=:p AND slot_key=:s',
                    [':p'=>'home', ':s'=>'hero_sub'])->fetchColumn();
    check('DB: save stores the override', $row === 'Custom sub-heading');

    page_content_save('home', 'hero_sub', '', null);
    $gone = db_query('SELECT COUNT(*) FROM page_content WHERE page_key=:p AND slot_key=:s',
                     [':p'=>'home', ':s'=>'hero_sub'])->fetchColumn();
    check('DB: empty value deletes the override (back to default)', (int)$gone === 0);

    page_content_save('home', 'not_a_real_slot', 'x', null);
    $bogus = db_query('SELECT COUNT(*) FROM page_content WHERE slot_key=:s',
                      [':s'=>'not_a_real_slot'])->fetchColumn();
    check('DB: unknown slot keys are refused', (int)$bogus === 0);

    page_content_save('home', 'hero_sub', 'One', null);
    page_content_save('home', 'hero_sub', 'Two', null);
    $n = db_query('SELECT COUNT(*) FROM page_content WHERE page_key=:p AND slot_key=:s',
                  [':p'=>'home', ':s'=>'hero_sub'])->fetchColumn();
    check('DB: re-saving upserts rather than duplicating', (int)$n === 1);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
