<?php
declare(strict_types=1);
// Guest extras (includes/guest-extras.php): what a property offers its guests to
// add to their stay. Pure rules always; a save/read round-trip in a rolled-back
// transaction when a database with add_venue_extras.sql is reachable.
// Run: php tests/guest_extras_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/guest-extras.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$cat = [
    ['key' => 'tour:1', 'kind' => 'tour', 'ref_id' => 1, 'slug' => 'kite', 'name' => 'Kite lesson', 'category' => 'excursion', 'group' => 'Excursions',
     'duration' => '2 h', 'desc' => '', 'price' => 120.0, 'per_person' => true, 'max_pax' => 4, 'image' => '', 'legacy_featured' => false],
    ['key' => 'tour:2', 'kind' => 'tour', 'ref_id' => 2, 'slug' => 'massage', 'name' => 'Deep tissue massage', 'category' => 'wellness', 'group' => 'Wellness',
     'duration' => '60 min', 'desc' => '', 'price' => null, 'per_person' => false, 'max_pax' => 0, 'image' => '', 'legacy_featured' => true],
    ['key' => 'transfer:7', 'kind' => 'transfer', 'ref_id' => 7, 'slug' => '', 'name' => 'Malindi Airport → Zuri', 'category' => 'transfer', 'group' => 'Transfers',
     'duration' => '', 'desc' => '', 'price' => 45.0, 'per_person' => false, 'max_pax' => 0, 'image' => '', 'legacy_featured' => true, 'legacy_hidden' => false],
    ['key' => 'transfer:8', 'kind' => 'transfer', 'ref_id' => 8, 'slug' => '', 'name' => 'Town run', 'category' => 'transfer', 'group' => 'Transfers',
     'duration' => '', 'desc' => '', 'price' => 10.0, 'per_person' => false, 'max_pax' => 0, 'image' => '', 'legacy_featured' => false, 'legacy_hidden' => true],
];

// ── Not curated: what guests saw before, booking add-ons featured ──
$r = array_column(guest_extras_resolve($cat, []), null, 'key');
check('not curated: activities shown', $r['tour:1']['shown'] && $r['tour:2']['shown']);
check('not curated: a transfer never offered while booking stays hidden', !$r['transfer:8']['shown'] && $r['transfer:7']['shown']);
check('not curated: the old booking add-ons are featured', $r['tour:2']['featured'] && $r['transfer:7']['featured'] && !$r['tour:1']['featured']);
check('not curated: offered any time', $r['tour:1']['when'] === 'any');

// ── Curated: the owner's list is the truth ──
$set = ['transfer:7' => ['shown' => true, 'featured' => true, 'when' => 'before', 'sort' => 0],
        'tour:1'     => ['shown' => true, 'featured' => false, 'when' => 'during', 'sort' => 1],
        'tour:2'     => ['shown' => false, 'featured' => true, 'when' => 'any', 'sort' => 2]];
$res = guest_extras_resolve($cat, $set);
check('curated: owner order', array_column($res, 'key') === ['transfer:7', 'tour:1', 'tour:2', 'transfer:8']);
$rc = array_column($res, null, 'key');
check('curated: an extra added after the last save is hidden', !$rc['transfer:8']['shown']);
check('curated: a hidden extra is never featured', !$rc['tour:2']['featured']);

// ── Phase: before arrival vs during the stay ──
$hold = ['check_in' => '2030-11-12', 'check_out' => '2030-11-15'];
check('phase before arrival', guest_extras_phase($hold, '2030-11-01') === 'before');
check('phase on arrival day = during', guest_extras_phase($hold, '2030-11-12') === 'during');
check('before arrival: transfer (before) yes, kite (during) no', array_column(guest_extras_for_phase($res, 'before'), 'key') === ['transfer:7']);
check('during the stay: kite (during) yes, transfer (before) no', array_column(guest_extras_for_phase($res, 'during'), 'key') === ['tour:1']);

// ── What the guest already asked for ──
$addons = [
    ['kind' => 'tour', 'tour_id' => 1, 'details' => '', 'status' => 'confirmed'],
    ['kind' => 'tour', 'tour_id' => 1, 'details' => '', 'status' => 'requested'],          // older one (list is newest first)
    ['kind' => 'transfer', 'tour_id' => null, 'details' => 'Malindi Airport → Zuri — KQ 612', 'status' => 'requested'],
    ['kind' => 'tour', 'tour_id' => 2, 'details' => '', 'status' => 'cancelled'],
];
$req = guest_extras_requested($res, $addons);
check('requested: newest request wins', ($req['tour:1'] ?? '') === 'confirmed');
check('requested: a transfer matches on its option label', ($req['transfer:7'] ?? '') === 'requested');
check('requested: a cancelled request does not count', !isset($req['tour:2']));

// ── Featured (home + emails) skips what was asked for ──
$list = guest_extras_for_phase(guest_extras_resolve($cat, []), 'during');
foreach ($list as $i => $x) $list[$i]['status'] = $x['key'] === 'tour:2' ? 'requested' : '';
check('featured: skips extras already asked for', array_column(guest_extras_featured($list), 'key') === ['transfer:7']);
foreach ($list as $i => $x) $list[$i]['featured'] = false;
check('featured: none featured → the first ones not asked for', array_column(guest_extras_featured($list, 2), 'key') === ['tour:1', 'transfer:7']);

// ── Admin form cleaning ──
[$rows, $notice] = guest_extras_clean_post($cat, [
    'order' => 'tour:2,bogus:9,transfer:7',
    'shown' => ['tour:2' => '1', 'transfer:7' => '1', 'tour:1' => '1', 'transfer:8' => '1'],
    'featured' => ['tour:2' => '1', 'transfer:7' => '1', 'tour:1' => '1', 'transfer:8' => '1'],
    'when' => ['tour:2' => 'during', 'transfer:7' => 'nonsense'],
]);
check('clean: unknown keys dropped, unlisted ones appended in catalogue order',
    array_map(fn($r) => $r['kind'] . ':' . $r['ref_id'], $rows) === ['tour:2', 'transfer:7', 'tour:1', 'transfer:8']);
check('clean: at most ' . GUEST_EXTRAS_MAX_FEATURED . ' featured, the first in order', array_column($rows, 'featured') === [true, true, true, false] && $notice !== '');
check('clean: an unknown "when" becomes any', $rows[1]['when'] === 'any' && $rows[0]['when'] === 'during');
[$rows2] = guest_extras_clean_post($cat, ['featured' => ['tour:1' => '1']]);
check('clean: featured without shown is not featured', !array_filter($rows2, fn($r) => $r['featured']));

// ── Labels ──
check('price label: unpriced = On request (no currency lookup needed)', guest_extra_price_label($cat[1]) === 'On request');
check('status words for the guest', guest_extra_status_view('requested') === ['Waiting', 'pend'] && guest_extra_status_view('declined')[0] === 'Not available'
    && guest_extra_status_view('') === ['', '']);
check('time of day maps to a clock time', GUEST_EXTRAS_PARTS_OF_DAY['afternoon'][1] === '14:00');
$p = guest_extra_payload($rc['tour:1'] + ['status' => '', 'price_label' => 'USD 120 pp']);
check('payload for the sheet', $p['k'] === 'tour:1' && $p['slug'] === 'kite' && $p['max'] === 4 && $p['pp'] === true);

// ── DB round-trip (rolled back) ──
try {
    $db = db();
    if (!guest_extras_supported()) { echo "SKIP  DB round-trip (add_venue_extras.sql not applied)\n"; }
    else {
        $db->beginTransaction();
        check('db: price label per person / per car', guest_extra_price_label($cat[0]) === format_price(120.0) . ' pp'
            && guest_extra_price_label($cat[2]) === format_price(45.0) . ' per car');
        $vid = (int)$db->query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
        $live = guest_extras_catalogue($vid);
        if (!$vid || !$live) { echo "SKIP  DB round-trip (no venue/catalogue)\n"; }
        else {
            [$rows] = guest_extras_clean_post($live, ['order' => $live[0]['key'], 'shown' => [$live[0]['key'] => '1'], 'featured' => [$live[0]['key'] => '1'], 'when' => [$live[0]['key'] => 'before']]);
            guest_extras_save($vid, $rows, false, 5);
            $s = guest_extras_settings($vid);
            check('db: saved settings read back', ($s[$live[0]['key']]['when'] ?? '') === 'before' && $s[$live[0]['key']]['featured'] === true);
            check('db: venue options saved', guest_extras_venue_options($vid) === ['in_email' => false, 'reminder_days' => 5]);
            check('db: only the first extra shown', count(array_filter(guest_extras_resolve($live, $s), fn($x) => $x['shown'])) === 1);
        }
        $db->rollBack();
    }
} catch (Throwable $e) {
    echo "SKIP  DB round-trip (no database: " . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
