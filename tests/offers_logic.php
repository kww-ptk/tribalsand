<?php
declare(strict_types=1);
// Offers & Specials helpers — categories, image resolver, publish/date-window filter.
// Run: php tests/offers_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real offer rows are ever left behind. Requires add_offers.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/offers.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ──────────────────────────────────────────────────────
$cats = offer_categories();
check('categories include stay/dining/experience/special',
      isset($cats['stay'], $cats['dining'], $cats['experience'], $cats['special']));
check('category label: known key',    offer_category_label('dining') === 'Dining');
check('category label: unknown key',  offer_category_label('mystery') === 'Mystery');
check('img: empty → ""',              offer_img_url('') === '');
check('img: http passes through',     offer_img_url('https://x/y.jpg') === 'https://x/y.jpg');
check('img: leading slash passes',    offer_img_url('/images/a.jpg') === '/images/a.jpg');
check('img: images/ → asset_url',     str_ends_with(offer_img_url('images/a.jpg'), '/images/a.jpg'));

if (!offers_supported()) {
    echo "\nSKIP  DB assertions (add_offers.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

$today = date('Y-m-d');
db()->beginTransaction();
try {
    // A published, in-window offer shows; hidden / expired / future ones don't.
    db_query("INSERT INTO offers (title, category, is_published, sort_order, valid_from, valid_to)
              VALUES ('T-live','special',TRUE,1,NULL,NULL)");
    db_query("INSERT INTO offers (title, category, is_published, sort_order, valid_to)
              VALUES ('T-hidden','special',FALSE,2,NULL)");
    db_query("INSERT INTO offers (title, category, is_published, sort_order, valid_to)
              VALUES ('T-expired','special',TRUE,3, :y)", [':y' => date('Y-m-d', strtotime($today . ' -1 day'))]);
    db_query("INSERT INTO offers (title, category, is_published, sort_order, valid_from)
              VALUES ('T-future','special',TRUE,4, :y)", [':y' => date('Y-m-d', strtotime($today . ' +2 day'))]);

    $pub    = fetch_published_offers();
    $titles = array_column($pub, 'title');
    check('published: includes live',          in_array('T-live', $titles, true));
    check('published: excludes hidden',        !in_array('T-hidden', $titles, true));
    check('published: excludes expired',       !in_array('T-expired', $titles, true));
    check('published: excludes not-yet-valid', !in_array('T-future', $titles, true));

    $all = fetch_all_offers();
    $allTitles = array_column($all, 'title');
    check('fetch_all: includes hidden',        in_array('T-hidden', $allTitles, true));
    check('fetch_all: includes expired',       in_array('T-expired', $allTitles, true));

    $id  = (int) db_query("SELECT id FROM offers WHERE title='T-live'")->fetchColumn();
    $one = fetch_offer($id);
    check('fetch_offer returns the row',       is_array($one) && $one['title'] === 'T-live');
    check('fetch_offer(0) → false',            fetch_offer(0) === false);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
