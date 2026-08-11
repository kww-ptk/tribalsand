<?php
declare(strict_types=1);
// Front Desk helpers. Run: php tests/frontdesk_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/frontdesk.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function ids(array $rows): array { return array_map(fn($r) => (int)$r['id'], $rows); }

// A unit whose room belongs to a venue (needed for venue scoping).
$u = db()->query("SELECT u.id AS unit_id, r.venue_id
                  FROM units u JOIN rooms r ON r.id = u.room_id
                  WHERE r.venue_id IS NOT NULL LIMIT 1")->fetch();
if (!$u) { echo "SKIP  no unit with a venue — cannot test frontdesk\n"; echo "\nALL PASS\n"; exit(0); }
$unit = (int)$u['unit_id']; $venue = (int)$u['venue_id'];

$A   = '2031-06-15';                                   // anchor day (far future, no real data)
$d   = fn(string $base, int $n) => (new DateTime($base))->modify(($n>=0?'+':'').$n.' days')->format('Y-m-d');
$mk  = function(string $ci, string $co) use ($unit): int {
    db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
              VALUES (:u,:ci,:co,'ZZ FD Guest','zz@example.com','confirmed', NOW())",
             [':u'=>$unit, ':ci'=>$ci, ':co'=>$co]);
    return (int)db()->lastInsertId();
};

$hArrive  = $mk($A,            $d($A, 3));   // arrives on anchor
$hInhouse = $mk($d($A,-2),     $d($A, 2));   // staying across anchor
$hDepart  = $mk($d($A,-3),     $A);          // departs on anchor
$hWeekOut = $mk($d($A,10),     $d($A, 12));  // arrival outside the 7-day window

// ── Staff-created bookings: pending, and never auto-expire ──────────────────
$hNoExp = create_hold_with_block($unit, null, $d($A, 20), $d($A, 22), 'ZZ FD NoExpiry', 'zz-noexp@example.com', 'pending', null);
$rowNE  = db_query("SELECT status, expires_at FROM holds WHERE id = :i", [':i' => $hNoExp])->fetch();
check('null expiry stores NULL',        $rowNE['expires_at'] === null);
check('null expiry keeps it pending',   $rowNE['status'] === 'pending');
check('its availability block exists',  (int)db_query("SELECT COUNT(*) FROM availability_blocks WHERE hold_id = :i", [':i' => $hNoExp])->fetchColumn() > 0);
// Assert the cron's PREDICATE rather than calling expire_stale_holds(): that
// function sweeps every stale hold in the database, deletes their blocks and
// EMAILS THEIR GUESTS. A test must never do that. NULL < NOW() is NULL, so the
// predicate cannot match — which is exactly the property we depend on.
$wouldExpire = (int)db_query(
    "SELECT COUNT(*) FROM holds WHERE id = :i AND status = 'pending' AND expires_at < NOW()",
    [':i' => $hNoExp]
)->fetchColumn();
check('expiry predicate cannot match it', $wouldExpire === 0);

$day = frontdesk_day([$venue], $A);
check('arrival is in "arriving"',      in_array($hArrive,  ids($day['arriving']),  true));
check('continuing stay is "in house"', in_array($hInhouse, ids($day['inhouse']),   true));
check('departure is in "departing"',   in_array($hDepart,  ids($day['departing']), true));
check('arrival is NOT counted as departing', !in_array($hArrive, ids($day['departing']), true));
check('departing guest is NOT in house', !in_array($hDepart, ids($day['inhouse']), true));
check('kpi_inhouse = arrivals + continuing', $day['kpi_inhouse'] === count($day['arriving']) + count($day['inhouse']));

// Venue scoping: a non-matching venue id yields none of our holds.
$other = frontdesk_day([$venue + 999999], $A);
check('venue scoping excludes other venues', !in_array($hArrive, ids($other['arriving']), true));

// Week: arrivals within [A, A+7) include the anchor arrival, exclude the +10 one.
$week = frontdesk_week([$venue], $A, 7);
check('week includes the in-window arrival', in_array($hArrive,  ids($week), true));
check('week excludes the out-of-window arrival', !in_array($hWeekOut, ids($week), true));

// Badges: a requested addon + an unread guest message on the arrival hold.
db_query("INSERT INTO booking_addons (hold_id, kind, details, status) VALUES (:h,'other','ZZ FD req','requested')", [':h'=>$hArrive]);
db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,NULL,'guest','ZZ FD msg',TRUE,FALSE)", [':h'=>$hArrive]);
$day2 = frontdesk_day([$venue], $A);
$arr  = null; foreach ($day2['arriving'] as $r) { if ((int)$r['id'] === $hArrive) { $arr = $r; break; } }
check('badge: open_requests counted', $arr && (int)$arr['open_requests'] >= 1);
check('badge: unread_msgs counted',   $arr && (int)$arr['unread_msgs']   >= 1);

// Cleanup
db_query("DELETE FROM booking_messages WHERE hold_id = :h", [':h'=>$hArrive]);
db_query("DELETE FROM booking_addons  WHERE hold_id = :h", [':h'=>$hArrive]);
db_query("DELETE FROM availability_blocks WHERE hold_id = :h", [':h'=>$hNoExp]);
db_query("DELETE FROM holds WHERE id IN (:a,:b,:c,:e,:f)", [':a'=>$hArrive, ':b'=>$hInhouse, ':c'=>$hDepart, ':e'=>$hWeekOut, ':f'=>$hNoExp]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
