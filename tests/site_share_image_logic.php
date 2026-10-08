<?php
declare(strict_types=1);
// Site-wide social share image (Admin → Settings → Social Sharing Image):
// key validation, absolute-URL resolution, and the setting round-trip.
// Run: php tests/site_share_image_logic.php
//
// The DB part runs inside a transaction that is rolled back.
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure: what the setting may hold ─────────────────────────────────────────
check('a picker storage key is accepted',       share_image_key_ok('pages/hero-1a2b3c4d.jpg'));
check('a bundled images/ path is accepted',     share_image_key_ok('images/Maya-Kobe-1-hero.webp'));
check('a folder with a space is accepted',      share_image_key_ok('images/maya ilai/Best1.jpg'));
check('an S3 upload URL is accepted',           share_image_key_ok('https://cdn.example.com/pages/a.jpg'));
check('blank is not a key',                     !share_image_key_ok(''));
check('javascript: is refused',                 !share_image_key_ok('javascript:alert(1)'));
check('a parent-directory path is refused',     !share_image_key_ok('images/../.env'));
check('a newline is refused',                   !share_image_key_ok("pages/a.jpg\nX"));
check('a quote in a URL is refused',            !share_image_key_ok('https://x.com/a".jpg'));
check('an over-long key is refused',            !share_image_key_ok('pages/' . str_repeat('a', 600) . '.jpg'));

// ── Pure: always an ABSOLUTE URL (og:image is fetched by a crawler) ─────────
$u = share_image_url('pages/a.jpg');
check('an uploaded key resolves to an absolute URL', str_starts_with($u, 'http') && str_ends_with($u, '/assets/img/pages/a.jpg'));
check('a full URL passes through',              share_image_url('https://cdn.example.com/a.jpg') === 'https://cdn.example.com/a.jpg');
check('an images/ path goes through asset_url', share_image_url('images/x.jpg') === asset_url('images/x.jpg'));
check('spaces in an images/ folder are encoded', share_image_url('images/maya ilai/Best1.jpg') === asset_url('images/maya%20ilai/Best1.jpg'));
check('blank resolves to nothing',              share_image_url('') === '');

// ── DB: the setting drives site_share_image() ───────────────────────────────
try { db()->beginTransaction(); } catch (Throwable $e) {
    echo "\nSKIP  no database reachable\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
try {
    // site_share_image() memoises per request, so the DB checks read the
    // setting and resolve it the same way it does.
    $resolve = function (): string {
        $k = trim(setting('site_share_image', ''));
        $u = ($k !== '' && share_image_key_ok($k)) ? share_image_url($k) : '';
        return $u !== '' ? $u : share_image_url(SITE_SHARE_IMAGE_DEFAULT);
    };
    set_setting('site_share_image', '');
    check('no setting = the built-in image', $resolve() === share_image_url(SITE_SHARE_IMAGE_DEFAULT));
    check('site_share_image() agrees with the resolver', site_share_image() === $resolve());

    set_setting('site_share_image', 'pages/share-test.jpg');
    check('a saved key is used', str_ends_with($resolve(), '/assets/img/pages/share-test.jpg'));

    set_setting('site_share_image', 'javascript:alert(1)');
    check('a bad stored value falls back to the built-in image', $resolve() === share_image_url(SITE_SHARE_IMAGE_DEFAULT));
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
