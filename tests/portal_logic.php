<?php
declare(strict_types=1);
// DB-backed checks for portal v2 helpers. Run: php tests/portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

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
check('laundry options non-empty', is_array(LAUNDRY_OPTIONS) && count(LAUNDRY_OPTIONS) >= 2);

// laundry addon persists with scheduled_for
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    db_query("INSERT INTO booking_addons (hold_id,kind,details,scheduled_for) VALUES (:h,'laundry','Wash & fold — 3 shirts','2029-05-01 09:00')", [':h'=>$hid]);
    $la = db_query("SELECT kind, scheduled_for FROM booking_addons WHERE hold_id=:h AND kind='laundry' ORDER BY id DESC LIMIT 1", [':h'=>$hid])->fetch();
    check('laundry addon stored with schedule', $la && $la['kind']==='laundry' && !empty($la['scheduled_for']));
    db_query("DELETE FROM booking_addons WHERE hold_id=:h AND kind='laundry' AND details='Wash & fold — 3 shirts'", [':h'=>$hid]);
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
