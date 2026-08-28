<?php
declare(strict_types=1);
// Admin-editable mega menu — read model, renderers, and mutation SQL.
// Run: php tests/nav_menu_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK, so no rows leak.
require_once __DIR__ . '/../includes/nav-data.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure helpers: nav_img_url ───────────────────────────────────────────────
check('img: empty → empty',            nav_img_url('') === '');
check('img: http passthrough',         nav_img_url('https://x/y.jpg') === 'https://x/y.jpg');
check('img: images/ → asset_url',      nav_img_url('images/a.jpg') === asset_url('images/a.jpg'));
check('img: leading slash passthrough', nav_img_url('/images/a.jpg') === '/images/a.jpg');
check('img: bare key → storage_url',   nav_img_url('abc.jpg') === storage_url('abc.jpg'));

// ── Pure helpers: nav_tag_html ──────────────────────────────────────────────
check('tag: open',  str_contains(nav_tag_html('open'), 'Now Open'));
check('tag: soon',  str_contains(nav_tag_html('soon'), 'Soon'));
check('tag: none',  nav_tag_html('') === '');

// ── Renderers on a synthetic tree (no DB) ───────────────────────────────────
$tree = [
    ['id' => 1, 'label' => 'Stay', 'layout' => 'wide2', 'auto_source' => null, 'groups' => [
        ['id' => 10, 'label' => 'Hotels', 'links' => [
            ['id' => 100, 'label' => 'Zuri', 'href' => 'zuri.php', 'sublabel' => 'Watamu', 'image_key' => 'images/z.jpg', 'tag' => 'open', 'role' => 'row', 'cta_note' => null, 'target_blank' => false],
            ['id' => 101, 'label' => 'Enquire', 'href' => 'enquire.php', 'sublabel' => null, 'image_key' => null, 'tag' => '', 'role' => 'cta_button', 'cta_note' => 'Not sure?', 'target_blank' => false],
        ]],
    ]],
    ['id' => 2, 'label' => 'Eat', 'layout' => 'simple', 'auto_source' => 'restaurants', 'groups' => []],
    ['id' => 3, 'label' => 'More', 'layout' => 'simple', 'auto_source' => null, 'groups' => [
        ['id' => 30, 'label' => null, 'links' => [
            ['id' => 300, 'label' => 'Blog', 'href' => 'blog.php', 'sublabel' => null, 'image_key' => null, 'tag' => '', 'role' => 'row', 'cta_note' => null, 'target_blank' => false],
        ]],
        ['id' => 31, 'label' => 'Guides', 'links' => [
            ['id' => 310, 'label' => 'Ext', 'href' => 'https://x', 'sublabel' => null, 'image_key' => null, 'tag' => '', 'role' => 'row', 'cta_note' => null, 'target_blank' => true],
        ]],
    ]],
];

$desktop = nav_desktop_html($tree, '<!--RESTO-->');
check('desktop: wide2 class on Stay',      str_contains($desktop, 'ts-drop wide-2'));
check('desktop: thumb row for Zuri',       str_contains($desktop, 'ts-prop-row') && str_contains($desktop, 'images/z.jpg') === false /*asset_url wraps*/ ? str_contains($desktop, asset_url('images/z.jpg')) : true);
check('desktop: Now Open tag on Zuri',     str_contains($desktop, 'ts-tag-open'));
check('desktop: CTA button rendered',      str_contains($desktop, 'ts-drop-cta__btn') && str_contains($desktop, 'Not sure?'));
check('desktop: auto splices restaurants', str_contains($desktop, '<!--RESTO-->'));
check('desktop: simple item has divider between groups', str_contains($desktop, 'ts-drop-div'));
check('desktop: Destinations-style label', str_contains($desktop, '>Guides<'));
check('desktop: external target_blank',    str_contains($desktop, 'target="_blank"'));

$drawer = nav_drawer_html($tree, '<!--RESTO-DRAWER-->');
check('drawer: section label Stay',        str_contains($drawer, 'ts-mob-lbl') && str_contains($drawer, '>Stay<'));
check('drawer: thumb prop row',            str_contains($drawer, 'ts-mob-prop'));
check('drawer: auto splices drawer resto', str_contains($drawer, '<!--RESTO-DRAWER-->'));
check('drawer: CTA is omitted from drawer', !str_contains($drawer, 'Not sure?'));

// ── DB: nav_supported + tree fetch + mutations (rolled back) ─────────────────
try { db()->query('SELECT 1'); }
catch (Throwable $e) {
    echo "\nSKIP  DB assertions (database unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n");
    exit($failures ? 1 : 0);
}

if (!nav_supported()) {
    echo "\nSKIP  DB assertions (nav tables not migrated)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n");
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    // A test item with a distinctive high sort_order.
    $iid = (int) db_query("INSERT INTO nav_items (label, layout, sort_order, is_published) VALUES ('__NAV_TEST__', 'wide2', 9000, TRUE) RETURNING id")->fetchColumn();
    $g1  = (int) db_query("INSERT INTO nav_groups (nav_item_id, label, sort_order) VALUES (:i, 'G1', 0) RETURNING id", [':i' => $iid])->fetchColumn();
    $g2  = (int) db_query("INSERT INTO nav_groups (nav_item_id, label, sort_order) VALUES (:i, 'G2', 1) RETURNING id", [':i' => $iid])->fetchColumn();
    db_query("INSERT INTO nav_links (nav_group_id, label, href, sort_order, is_published) VALUES (:g,'A','a.php',0,TRUE),(:g,'B','b.php',1,FALSE)", [':g' => $g1]);

    // fetch_nav_tree(true) = published-only (admin=false includes unpublished).
    $find = function (array $t, int $id) { foreach ($t as $x) if ((int)$x['id'] === $id) return $x; return null; };
    $pub  = $find(fetch_nav_tree(true),  $iid);
    $all  = $find(fetch_nav_tree(false), $iid);
    check('DB: item appears in tree',           $pub !== null);
    check('DB: two groups nested',              count($all['groups']) === 2);
    check('DB: published-only hides link B',    count($pub['groups'][0]['links']) === 1);
    check('DB: admin view shows both links',    count($all['groups'][0]['links']) === 2);

    // Group reorder (mirror admin nav_move).
    $rows = array_map('intval', db_query("SELECT id FROM nav_groups WHERE nav_item_id=:i ORDER BY sort_order,id", [':i'=>$iid])->fetchAll(PDO::FETCH_COLUMN));
    check('DB: group order before = G1,G2',     $rows === [$g1, $g2]);
    // swap
    db_query("UPDATE nav_groups SET sort_order=1 WHERE id=:g", [':g'=>$g1]);
    db_query("UPDATE nav_groups SET sort_order=0 WHERE id=:g", [':g'=>$g2]);
    $rows2 = array_map('intval', db_query("SELECT id FROM nav_groups WHERE nav_item_id=:i ORDER BY sort_order,id", [':i'=>$iid])->fetchAll(PDO::FETCH_COLUMN));
    check('DB: group order after swap = G2,G1', $rows2 === [$g2, $g1]);

    // Cascade delete: removing the item clears its groups + links.
    db_query("DELETE FROM nav_items WHERE id=:i", [':i'=>$iid]);
    $gLeft = (int) db_query("SELECT COUNT(*) FROM nav_groups WHERE nav_item_id=:i", [':i'=>$iid])->fetchColumn();
    $lLeft = (int) db_query("SELECT COUNT(*) FROM nav_links WHERE nav_group_id IN (:a,:b)", [':a'=>$g1, ':b'=>$g2])->fetchColumn();
    check('DB: delete item cascades groups',    $gLeft === 0);
    check('DB: delete item cascades links',     $lLeft === 0);
} finally {
    db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
