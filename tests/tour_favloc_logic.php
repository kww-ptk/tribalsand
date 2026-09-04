<?php
declare(strict_types=1);
// Tour favourite/location fields + lead idempotency guard.
// Run: D:\php84\php.exe tests/tour_favloc_logic.php
// All DB writes run inside a rolled-back transaction — nothing persists.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure: location normaliser ──
check('normalize keeps valid watamu', tour_normalize_location('watamu') === 'watamu');
check('normalize uppercases → lower', tour_normalize_location('KILIFI') === 'kilifi');
check('normalize trims',              tour_normalize_location('  vipingo ') === 'vipingo');
check('normalize junk → all',         tour_normalize_location('mombasa') === 'all');
check('normalize empty → all',        tour_normalize_location('') === 'all');
check('normalize null → all',         tour_normalize_location(null) === 'all');

check('tours_favloc_supported() true after migration', tours_favloc_supported() === true);

db()->beginTransaction();
try {
    // ── Fav/location columns round-trip ──
    db_query("INSERT INTO tours (slug,name,category,is_published,is_guest_favourite,location)
              VALUES ('zz-fav','ZZ Fav','marine',TRUE,TRUE,'watamu')");
    $row = db_query("SELECT is_guest_favourite, location FROM tours WHERE slug='zz-fav'")->fetch();
    check('favourite tour stored is_guest_favourite', $row && ($row['is_guest_favourite'] === true || $row['is_guest_favourite'] === 't'));
    check('favourite tour stored location',           $row && $row['location'] === 'watamu');

    // location CHECK constraint rejects a bad value
    $rejected = false;
    try { db_query("INSERT INTO tours (slug,name,category,location) VALUES ('zz-bad','ZZ Bad','marine','nairobi')"); }
    catch (Throwable $e) { $rejected = true; }
    check('location CHECK constraint rejects invalid value', $rejected);
    if ($rejected) db()->rollBack();  // a failed statement aborts the tx; restart for the rest
    if (!db()->inTransaction()) db()->beginTransaction();

    // ── Lead idempotency guard ──
    db_query("INSERT INTO submissions (type,guest_name,guest_email,check_in,check_out,ip_address,created_at)
              VALUES ('availability','Dup Test','dup@example.com','2026-10-01','2026-10-03','1.2.3.4', NOW())");
    $dupId = (int)db()->lastInsertId();

    check('finds identical lead in window',
        find_recent_duplicate_submission('availability','dup@example.com','2026-10-01','2026-10-03') === $dupId);
    check('case-insensitive email match',
        find_recent_duplicate_submission('availability','DUP@EXAMPLE.COM','2026-10-01','2026-10-03') === $dupId);
    check('different dates → no match',
        find_recent_duplicate_submission('availability','dup@example.com','2026-11-01','2026-11-03') === 0);
    check('different type → no match',
        find_recent_duplicate_submission('enquiry','dup@example.com','2026-10-01','2026-10-03') === 0);
    check('empty email → no match (fails open to insert)',
        find_recent_duplicate_submission('availability','','2026-10-01','2026-10-03') === 0);

    // A lead older than the window is not a duplicate.
    db_query("UPDATE submissions SET created_at = NOW() - INTERVAL '5 minutes' WHERE id = :id", [':id'=>$dupId]);
    check('lead older than 30s window → no match',
        find_recent_duplicate_submission('availability','dup@example.com','2026-10-01','2026-10-03', 30) === 0);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
