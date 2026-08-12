<?php
declare(strict_types=1);
// DB-backed checks for portal v2 helpers. Run: php tests/portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/auth.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$acts = fetch_portal_activities();
check('activities is a list', is_array($acts));
check('activity rows have slug/name/category/hero keys',
      $acts === [] || (array_key_exists('slug',$acts[0]) && array_key_exists('name',$acts[0])
                       && array_key_exists('category',$acts[0]) && array_key_exists('hero',$acts[0])));

$cats = fetch_tour_categories();
check('categories is a list', is_array($cats));
check('categories have key + label',
      $cats === [] || (isset($cats[0]['key'], $cats[0]['label'])));

// addon_label() — pure, DB-free. Guards the "tour details duplicate the name" case.
check('addon_label: details==name shows name once',
      addon_label(['tour_name'=>'Tsavo East','details'=>'Tsavo East']) === 'Tsavo East');
check('addon_label: distinct details are joined',
      addon_label(['tour_name'=>'Tsavo East','details'=>'2 adults']) === 'Tsavo East — 2 adults');
check('addon_label: null tour_name falls back to details',
      addon_label(['tour_name'=>null,'details'=>'Extra towels']) === 'Extra towels');
check('addon_label: empty details with a name shows the name',
      addon_label(['tour_name'=>'Quad Safari','details'=>'']) === 'Quad Safari');
check('addon_label: both empty is an empty string',
      addon_label(['tour_name'=>null,'details'=>'']) === '');

// ── guest board ──────────────────────────────────────────────
$vid = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
db_query("INSERT INTO guest_board_posts (venue_id, category, title, body) VALUES (NULL, 'update', 'ZZ Global Test', '')");
$gGlobal = (int)db()->lastInsertId();
$gScoped = 0;
if ($vid) {
    db_query("INSERT INTO guest_board_posts (venue_id, category, title, body) VALUES (:v, 'promotion', 'ZZ Scoped Test', '')", [':v'=>$vid]);
    $gScoped = (int)db()->lastInsertId();
}

$boardNull = fetch_guest_board(null);
check('board(null) is a list', is_array($boardNull));
check('board(null) includes the global post',
      in_array('ZZ Global Test', array_column($boardNull, 'title'), true));
check('board(null) excludes venue-scoped posts',
      !in_array('ZZ Scoped Test', array_column($boardNull, 'title'), true));

if ($vid) {
    $boardVenue = fetch_guest_board($vid);
    check('board(venue) includes global + scoped',
          in_array('ZZ Global Test', array_column($boardVenue, 'title'), true) &&
          in_array('ZZ Scoped Test', array_column($boardVenue, 'title'), true));
    $otherVid = (int)(db()->query("SELECT id FROM venues WHERE id <> {$vid} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($otherVid) {
        $boardOther = fetch_guest_board($otherVid);
        check('board(other venue) excludes the scoped post',
              !in_array('ZZ Scoped Test', array_column($boardOther, 'title'), true));
    }
}

check('board rows expose category/title/body/image_filename',
      $boardNull === [] || (array_key_exists('category',$boardNull[0]) && array_key_exists('title',$boardNull[0])
                            && array_key_exists('body',$boardNull[0]) && array_key_exists('image_filename',$boardNull[0])));

db_query("DELETE FROM guest_board_posts WHERE id IN (:a, :b)", [':a'=>$gGlobal, ':b'=>$gScoped ?: -1]);

// ── concierge status labels + laundry options ────────────────
check('status label: requested', addon_status_label('requested') === 'Requested');
check('status label: confirmed → In progress', addon_status_label('confirmed') === 'In progress');
check('status label: completed → Done', addon_status_label('completed') === 'Done');
check('status label: declined', addon_status_label('declined') === 'Declined');
check('status label: cancelled', addon_status_label('cancelled') === 'Cancelled');
check('status label: unknown falls back', addon_status_label('weird') === 'Weird');
check('laundry options non-empty', count(fetch_service_options('laundry', false)) >= 2);

// laundry addon persists with scheduled_for
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    db_query("INSERT INTO booking_addons (hold_id,kind,details,scheduled_for) VALUES (:h,'laundry','Wash & fold — 3 shirts','2029-05-01 09:00')", [':h'=>$hid]);
    $la = db_query("SELECT kind, scheduled_for FROM booking_addons WHERE hold_id=:h AND kind='laundry' ORDER BY id DESC LIMIT 1", [':h'=>$hid])->fetch();
    check('laundry addon stored with schedule', $la && $la['kind']==='laundry' && !empty($la['scheduled_for']));
    db_query("DELETE FROM booking_addons WHERE hold_id=:h AND kind='laundry' AND details='Wash & fold — 3 shirts'", [':h'=>$hid]);
}

// ── messages ─────────────────────────────────────────────────
$mhid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($mhid) {
    db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,NULL,'guest','Hello team',TRUE,FALSE)", [':h'=>$mhid]);
    db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,NULL,'admin','Hi there',FALSE,TRUE)", [':h'=>$mhid]);
    $threads = fetch_message_threads($mhid);
    check('threads include the general thread', $threads[0]['addon_id'] === null);
    $gen = $threads[0];
    check('general thread unread_guest = 1', $gen['unread_guest'] === 1);
    check('general thread last message is the admin reply', $gen['last_body'] === 'Hi there');
    $msgs = fetch_thread_messages($mhid, null);
    check('thread has 2 messages in order', count($msgs) >= 2 && $msgs[0]['body'] === 'Hello team');
    check('count_unread_guest >= 1 before read', count_unread_guest($mhid) >= 1);
    mark_thread_read_by_guest($mhid, null);
    check('unread_guest cleared after read', count_unread_guest($mhid) === 0);
    check('count_unread_admin >= 1 (guest msg unread by admin)', count_unread_admin() >= 1);

    // ── live polling: fetch_thread_messages_since + payload shape ──
    $allNow = fetch_thread_messages($mhid, null);
    $firstId = (int)$allNow[0]['id'];
    $sinceFirst = fetch_thread_messages_since($mhid, null, $firstId);
    check('since(firstId) excludes the first message', !in_array('Hello team', array_column($sinceFirst, 'body'), true));
    check('since(firstId) includes the later message', in_array('Hi there', array_column($sinceFirst, 'body'), true));
    $maxId = (int)$allNow[count($allNow)-1]['id'];
    check('since(maxId) returns nothing new', fetch_thread_messages_since($mhid, null, $maxId) === []);
    check('since(0) returns the whole thread', count(fetch_thread_messages_since($mhid, null, 0)) === count($allNow));
    $pl = message_payload($allNow[0]);
    check('message_payload exposes id/sender/body/time_label',
          isset($pl['id'], $pl['sender'], $pl['body'], $pl['time_label']) && $pl['sender'] === 'guest' && $pl['body'] === 'Hello team');
    check('message_time_label formats a timestamp', message_time_label('2026-05-01 14:30:00') === '1 May, 14:30');

    db_query("DELETE FROM booking_messages WHERE hold_id=:h", [':h'=>$mhid]);
}

// ── require_frontdesk audience (ops & security get no messaging) ──
// require_frontdesk() itself redirects (can't run headless), so we assert the
// underlying predicate it gates on: is_staff() && (ops || security).
$deny = fn(?string $job, bool $staff): bool => $staff && (job_is_ops($job) || $job === 'security');
check('frontdesk gate: housekeeping denied', $deny('housekeeping', true) === true);
check('frontdesk gate: maintenance denied',  $deny('maintenance', true) === true);
check('frontdesk gate: gardening denied',    $deny('gardening', true) === true);
check('frontdesk gate: driver denied',       $deny('driver', true) === true);
check('frontdesk gate: security denied',     $deny('security', true) === true);
check('frontdesk gate: frontdesk staff allowed', $deny('frontdesk', true) === false);
check('frontdesk gate: null-job staff allowed',  $deny(null, true) === false);
check('frontdesk gate: owner/manager allowed (not staff)', $deny(null, false) === false);

// ── itinerary ────────────────────────────────────────────────
$ihold = db()->query("SELECT id, check_in, check_out, guest_name FROM holds ORDER BY id DESC LIMIT 1")->fetch();
if ($ihold) {
    $hid = (int)$ihold['id'];
    $d2  = (new DateTime((string)$ihold['check_in']))->modify('+1 day')->format('Y-m-d');
    db_query("INSERT INTO itinerary_items (hold_id, day, at_time, category, title, detail) VALUES (:h, :d, '10:00', 'dining', 'ZZ Dinner', 'Table for 2')", [':h'=>$hid, ':d'=>$d2]);
    db_query("INSERT INTO booking_addons (hold_id, kind, details, status, scheduled_for) VALUES (:h, 'tour', 'ZZ Safari', 'confirmed', :sf)", [':h'=>$hid, ':sf'=>$d2.' 06:00']);
    $far = (new DateTime((string)$ihold['check_out']))->modify('+100 day')->format('Y-m-d');
    db_query("INSERT INTO itinerary_items (hold_id, day, category, title) VALUES (:h, :d, 'note', 'ZZ FarAway')", [':h'=>$hid, ':d'=>$far]);

    $itin = fetch_itinerary(['id'=>$hid,'check_in'=>$ihold['check_in'],'check_out'=>$ihold['check_out'],'room_name'=>'Test Room']);
    $days = (new DateTime((string)$ihold['check_in']))->diff(new DateTime((string)$ihold['check_out']))->days + 1;
    check('itinerary spans the stay (inclusive)', count($itin) === $days);
    check('day 1 has the check-in anchor', $itin[0]['items'][0]['category'] === 'checkin');
    check('last day has the check-out anchor', in_array('checkout', array_column($itin[count($itin)-1]['items'],'category'), true));
    $day2 = null; foreach ($itin as $D) { if ($D['date'] === $d2) { $day2 = $D; break; } }
    check('day 2 found', $day2 !== null);
    $titles2 = array_column($day2['items'], 'title');
    check('day 2 has the confirmed tour + admin dinner', in_array('ZZ Safari', $titles2, true) && in_array('ZZ Dinner', $titles2, true));
    check('day 2 items in time order (06:00 tour before 10:00 dinner)',
          array_search('ZZ Safari',$titles2) < array_search('ZZ Dinner',$titles2));
    $allTitles = [];
    foreach ($itin as $D) foreach ($D['items'] as $I) $allTitles[] = $I['title'];
    check('out-of-range admin item excluded', !in_array('ZZ FarAway', $allTitles, true));

    db_query("DELETE FROM itinerary_items WHERE hold_id=:h AND title LIKE 'ZZ %'", [':h'=>$hid]);
    db_query("DELETE FROM booking_addons WHERE hold_id=:h AND details='ZZ Safari'", [':h'=>$hid]);
}

// ── per-property stay info + maps link ───────────────────────
check('maps_link: prefers stored maps_url',
      venue_maps_link(['maps_url'=>'https://maps.app.goo.gl/xyz','address'=>'Ignored']) === 'https://maps.app.goo.gl/xyz');
check('maps_link: builds a search URL from the address',
      venue_maps_link(['maps_url'=>'','address'=>'Zuri Beach, Vipingo'])
        === 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Zuri Beach, Vipingo'));
check('maps_link: empty when nothing to link', venue_maps_link(['maps_url'=>'','address'=>'']) === '');
check('maps_link: rejects a javascript: scheme, falls back to address search',
      venue_maps_link(['maps_url'=>'javascript:alert(1)','address'=>'Zuri Beach'])
        === 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Zuri Beach'));
check('maps_link: rejects a non-http scheme with no address',
      venue_maps_link(['maps_url'=>'javascript:alert(1)','address'=>'']) === '');
check('maps_link: tolerates missing keys', venue_maps_link([]) === '');

$vsNull = fetch_venue_stay(null);
check('venue_stay(null) returns the six blank keys',
      $vsNull['address']==='' && $vsNull['maps_url']==='' && $vsNull['stay_wifi']==='' &&
      $vsNull['stay_checkout']==='' && $vsNull['stay_house_rules']==='' && $vsNull['stay_area_guide']==='');

$vsVid = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($vsVid) {
    db_query("UPDATE venues SET stay_wifi='ZZ Net · pw zz', address='ZZ Addr' WHERE id=:v", [':v'=>$vsVid]);
    $vs = fetch_venue_stay($vsVid);
    check('venue_stay(venue) reads stored wifi', $vs['stay_wifi'] === 'ZZ Net · pw zz');
    check('venue_stay(venue) reads stored address', $vs['address'] === 'ZZ Addr');
    db_query("UPDATE venues SET stay_wifi=NULL, address=NULL WHERE id=:v", [':v'=>$vsVid]);
}

// ── request auto-starts a conversation ───────────────────────
$shid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($shid) {
    db_query("INSERT INTO booking_addons (hold_id, kind, details) VALUES (:h,'other','ZZ seed request')", [':h'=>$shid]);
    $saId = (int)db()->lastInsertId();
    seed_request_message($shid, $saId, 'ZZ seed request');
    $seeded = db_query("SELECT sender, body, read_by_admin, read_by_guest FROM booking_messages WHERE addon_id=:a", [':a'=>$saId])->fetch();
    check('seed_request_message posts a guest opening message',
          $seeded && $seeded['sender']==='guest' && $seeded['body']==='ZZ seed request');
    check('seed_request_message leaves it unread for admin, read for guest',
          $seeded && !$seeded['read_by_admin'] && $seeded['read_by_guest']);
    db_query("DELETE FROM booking_messages WHERE addon_id=:a", [':a'=>$saId]);
    db_query("DELETE FROM booking_addons WHERE id=:a", [':a'=>$saId]);
}

// ── staff role ───────────────────────────────────────────────
$vA = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
$vB = (int)(db()->query("SELECT id FROM venues WHERE id <> $vA ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($vA) {
    $code = 'ZZTESTCODE01';
    db_query("DELETE FROM admin_users WHERE access_code=:c", [':c'=>$code]); // idempotent re-run
    db_query("INSERT INTO admin_users (email, role, name, access_code, is_active) VALUES (NULL,'staff','ZZ Staff',:c,TRUE)", [':c'=>$code]);
    $sid = (int)db()->lastInsertId();
    db_query("INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s,:v)", [':s'=>$sid, ':v'=>$vA]);
    check('gen_staff_code is 12 hex chars', preg_match('/^[0-9A-F]{12}$/', gen_staff_code()) === 1);
    @login_staff($code, '127.0.0.1');
    check('is_staff after staff login', is_staff() === true);
    check('admin_venue_ids = [vA] for staff', admin_venue_ids() === [$vA]);
    $hAid = (int)(db()->query("SELECT h.id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE r.venue_id=$vA LIMIT 1")->fetchColumn() ?: 0);
    if ($hAid) check('staff_can_hold true at assigned venue', staff_can_hold($hAid) === true);
    if ($vB) { $hBid = (int)(db()->query("SELECT h.id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE r.venue_id=$vB LIMIT 1")->fetchColumn() ?: 0);
        if ($hBid) check('staff_can_hold false at other venue', staff_can_hold($hBid) === false); }
    unset($_SESSION['admin_id']);
    check('login_staff fails with wrong code', @login_staff('NOPEZZ', '10.0.0.9') === false);
    unset($_SESSION['admin_id']);
    db_query("DELETE FROM admin_users WHERE access_code=:c", [':c'=>$code]);
    db_query("DELETE FROM login_attempts WHERE email IN (:c,'NOPEZZ')", [':c'=>$code]);
}

// ── request lifecycle: status templates, admin post, header, ordering ──
check('status msg: confirmed non-empty', request_status_message('confirmed') !== '');
check('status msg: declined non-empty',  request_status_message('declined')  !== '');
check('status msg: distinct per status', request_status_message('confirmed') !== request_status_message('completed'));
check('status msg: unknown is empty',    request_status_message('weird')     === '');

$rlHold = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($rlHold) {
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status) VALUES (:h,'other','ZZ RL A','requested')", [':h'=>$rlHold]);
    $rlA = (int)db()->lastInsertId();
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status) VALUES (:h,'other','ZZ RL B','requested')", [':h'=>$rlHold]);
    $rlB = (int)db()->lastInsertId();   // created after A (newer)

    $before = count_unread_guest($rlHold);
    post_admin_message($rlHold, $rlA, 'ZZ admin update');
    check('post_admin_message increments guest unread', count_unread_guest($rlHold) === $before + 1);

    $hdr = fetch_addon_for_thread($rlHold, $rlA);
    check('fetch_addon_for_thread returns the row', $hdr && (int)$hdr['id'] === $rlA);
    check('fetch_addon_for_thread is hold-scoped', fetch_addon_for_thread($rlHold + 999999, $rlA) === false);

    $threads = fetch_message_threads($rlHold);
    check('threads: general pinned first', $threads[0]['addon_id'] === null);
    $ids  = array_map(fn($t) => $t['addon_id'], array_slice($threads, 1));
    $posA = array_search($rlA, $ids, true);
    $posB = array_search($rlB, $ids, true);
    check('threads: messaged request bumps above a newer idle one', $posA !== false && $posB !== false && $posA < $posB);

    db_query("DELETE FROM booking_messages WHERE hold_id=:h AND body='ZZ admin update'", [':h'=>$rlHold]);
    db_query("DELETE FROM booking_addons WHERE id IN (:a,:b)", [':a'=>$rlA, ':b'=>$rlB]);
}

// ── events (What's on bookable) ──
$evVid = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
db_query("INSERT INTO guest_board_posts (venue_id, category, title, body, is_published, price_amount) VALUES (NULL,'event','ZZ Beach BBQ','Friday night',TRUE,2000)");
$evId = (int)db()->lastInsertId();
db_query("INSERT INTO guest_board_posts (venue_id, category, title, is_published) VALUES (NULL,'promotion','ZZ Promo',TRUE)");
$promoId = (int)db()->lastInsertId();

$ev = fetch_board_event($evId, $evVid ?: null);
check('fetch_board_event returns the event', $ev && (int)$ev['id'] === $evId && (float)$ev['price_amount'] === 2000.0);
check('fetch_board_event rejects a non-event post', fetch_board_event($promoId, $evVid ?: null) === false);
db_query("UPDATE guest_board_posts SET is_published=FALSE WHERE id=:id", [':id'=>$evId]);
check('fetch_board_event rejects an unpublished event', fetch_board_event($evId, $evVid ?: null) === false);
db_query("UPDATE guest_board_posts SET is_published=TRUE WHERE id=:id", [':id'=>$evId]);

$evHold = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($evHold) {
    check('guest_joined_event false before joining', guest_joined_event($evHold, $evId) === false);
    db_query("INSERT INTO booking_addons (hold_id,kind,details,status,board_post_id) VALUES (:h,'event','Join: ZZ Beach BBQ','requested',:p)", [':h'=>$evHold, ':p'=>$evId]);
    $evAddon = (int)db()->lastInsertId();
    check('guest_joined_event true after joining', guest_joined_event($evHold, $evId) === true);
    db_query("UPDATE booking_addons SET status='declined' WHERE id=:a", [':a'=>$evAddon]);
    check('guest_joined_event false when the join was declined', guest_joined_event($evHold, $evId) === false);
    db_query("DELETE FROM booking_addons WHERE id=:a", [':a'=>$evAddon]);
}

db_query("DELETE FROM guest_board_posts WHERE id IN (:a,:b)", [':a'=>$evId, ':b'=>$promoId]);

// ── Portal actor resolution (pure) ──────────────────────────────────────────
$paLead  = ['id' => 7, 'passport_name' => 'Jessica Mwangi'];
$paBlank = ['id' => 7, 'passport_name' => ''];
$paCo    = ['id' => 9, 'passport_name' => 'Patrik Otieno'];

$a = portal_actor($paLead, null, false, 'Jessica Booking');
check('actor lead uses passport name', $a['name'] === 'Jessica Mwangi' && $a['first'] === 'Jessica');
check('actor lead is_lead true',       $a['is_lead'] === true && $a['guest_id'] === 7);

$a = portal_actor($paBlank, null, false, 'Jessica Booking');
check('actor unnamed lead uses booking', $a['name'] === 'Jessica Booking' && $a['first'] === 'Jessica');

$a = portal_actor(null, null, false, 'Jessica Booking');
check('actor no lead row still names',  $a['name'] === 'Jessica Booking' && $a['guest_id'] === null);

$a = portal_actor($paLead, $paCo, true, 'Jessica Booking');
check('actor co-guest uses own name',   $a['name'] === 'Patrik Otieno' && $a['first'] === 'Patrik');
check('actor co-guest is_lead false',   $a['is_lead'] === false && $a['guest_id'] === 9);

$a = portal_actor($paLead, ['id' => 9, 'passport_name' => ''], true, 'Jessica Booking');
check('actor unnamed co-guest stays blank', $a['name'] === '' && $a['first'] === '');
check('actor unnamed co-guest not lead',    $a['is_lead'] === false);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
