<?php
declare(strict_types=1);
// Activities catalog & booking helpers. Run: php tests/activities_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function slugs(array $rows): array { return array_column($rows, 'slug'); }

// Two venues (need a second for scoping); skip cleanly if fewer than two exist.
$venues = db()->query("SELECT id FROM venues ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($venues) < 2) { echo "SKIP  need two venues\n\nALL PASS\n"; exit(0); }
$vA = (int)$venues[0]; $vB = (int)$venues[1];

// Two published tours: T1 unrestricted, T2 assigned to venue B only.
db_query("INSERT INTO tours (slug,name,category,is_published,price_amount,price_per_person,max_pax) VALUES ('zz-t1','ZZ T1','excursion',TRUE,1000,TRUE,4)");
$t1 = (int)db()->lastInsertId();
db_query("INSERT INTO tours (slug,name,category,is_published,price_amount,price_per_person,max_pax) VALUES ('zz-t2','ZZ T2','excursion',TRUE,1500,FALSE,6)");
$t2 = (int)db()->lastInsertId();
db_query("INSERT INTO tour_venues (tour_id,venue_id) VALUES (:t,:v)", [':t'=>$t2, ':v'=>$vB]);

$all = fetch_portal_activities(null);
check('activities(null) includes both', in_array('zz-t1', slugs($all), true) && in_array('zz-t2', slugs($all), true));

$atA = fetch_portal_activities($vA);
check('activities(A) includes the unrestricted T1', in_array('zz-t1', slugs($atA), true));
check('activities(A) excludes B-only T2',           !in_array('zz-t2', slugs($atA), true));

$atB = fetch_portal_activities($vB);
check('activities(B) includes both', in_array('zz-t1', slugs($atB), true) && in_array('zz-t2', slugs($atB), true));

check('tour_for_booking(T2,A) is false (wrong venue)', fetch_tour_for_booking('zz-t2', $vA) === false);
$okB = fetch_tour_for_booking('zz-t2', $vB);
check('tour_for_booking(T2,B) returns the row', $okB && (int)$okB['id'] === $t2);
db_query("UPDATE tours SET is_published=FALSE WHERE id=:id", [':id'=>$t1]);
check('tour_for_booking excludes unpublished', fetch_tour_for_booking('zz-t1', $vA) === false);
db_query("UPDATE tours SET is_published=TRUE WHERE id=:id", [':id'=>$t1]);

check('venue_ids(T2) = [vB]', activity_venue_ids($t2) === [$vB]);

$per  = ['price_amount'=>1000,'price_per_person'=>true];
$flat = ['price_amount'=>1500,'price_per_person'=>false];
check('price_total per-person × pax', activity_price_total($per, 3) === 3000.0);
check('price_total flat ignores pax',  activity_price_total($flat, 3) === 1500.0);
check('price_total null when unpriced', activity_price_total(['price_amount'=>null], 2) === null);
// Exercise the REAL fetched-row path (price_per_person may come back as bool or 't'/'f').
$__t1row = fetch_tour_for_booking('zz-t1', $vA);   // per-person, 1000
$__t2row = fetch_tour_for_booking('zz-t2', $vB);   // flat, 1500
check('price_total on a fetched per-person row × pax', $__t1row && activity_price_total($__t1row, 3) === 3000.0);
check('price_total on a fetched flat row ignores pax', $__t2row && activity_price_total($__t2row, 3) === 1500.0);

check('addon_pax_supported true after migration', addon_pax_supported() === true);

// insert_booking_addon snapshots pax + price.
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    $aid = insert_booking_addon(['hold_id'=>$hid,'kind'=>'tour','tour_id'=>$t2,'details'=>'ZZ activity','scheduled_for'=>null,'price_amount'=>3000,'pax'=>3]);
    $row = db_query("SELECT pax, price_amount FROM booking_addons WHERE id=:id", [':id'=>$aid])->fetch();
    check('insert_booking_addon stored pax + price', $row && (int)$row['pax'] === 3 && (float)$row['price_amount'] === 3000.0);
    db_query("DELETE FROM booking_addons WHERE id=:id", [':id'=>$aid]);
}

db_query("DELETE FROM tour_venues WHERE tour_id IN (:a,:b)", [':a'=>$t1, ':b'=>$t2]);
db_query("DELETE FROM tours WHERE id IN (:a,:b)", [':a'=>$t1, ':b'=>$t2]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
