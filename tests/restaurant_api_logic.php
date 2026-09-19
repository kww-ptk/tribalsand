<?php
declare(strict_types=1);
/**
 * Restaurant integration API + travel-agency partners.
 * Run: php tests/restaurant_api_logic.php
 *
 * Pure logic always runs. DB assertions run inside ONE transaction that is
 * ROLLED BACK at the end, so no real menus, reservations or partners are left
 * behind. They SKIP when the relevant migration isn't applied.
 */

// parse_env() memoises on first call (db.php connects), so seed the key first.
$_ENV['RESTAURANT_API_KEY'] = 'test-key-abc123';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant-api.php';
require_once __DIR__ . '/../includes/menu.php';
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/partners.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Key resolution + presentation ───────────────────────────────────────────
check('key comes from RESTAURANT_API_KEY', restaurant_api_key() === 'test-key-abc123');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-key-abc123';
check('key read from Bearer header', restaurant_api_presented_key() === 'test-key-abc123');
$_SERVER['HTTP_AUTHORIZATION'] = 'bearer  spaced-key  ';
check('Bearer match is case-insensitive + trimmed', restaurant_api_presented_key() === 'spaced-key');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_GET['key'] = 'from-query';
check('key falls back to ?key=', restaurant_api_presented_key() === 'from-query');
unset($_GET['key']);
check('no key presented reads as empty', restaurant_api_presented_key() === '');

// A configured key must NOT close the read feed. restaurant_api_authenticate()
// exits on refusal, so simply returning is the assertion: with a key set and
// none presented, a read is still served. (Setting RESTAURANT_API_KEY is
// mandatory for bookings, and it used to 401 the partner's menu fetch.)
restaurant_api_authenticate(false);
check('a configured key never closes the read feed', true);

// ── Request body parsing (JSON body or form post) ───────────────────────────
$_POST = ['venue' => 'zuri', 'party_size' => '4'];
check('form body is used when present', restaurant_api_input()['venue'] === 'zuri');
$_POST = [];

// ── Shared reservation validator ────────────────────────────────────────────
$venue  = ['id' => 1, 'slug' => 'zuri', 'is_published' => true];
$good   = [
    'reservation_date' => date('Y-m-d', strtotime('+3 days')),
    'reservation_time' => '19:30',
    'party_size'       => 4,
    'guest_name'       => 'Amina Yusuf',
    'guest_phone'      => '+254712345678',
    'guest_email'      => 'amina@example.com',
];
check('a good request validates clean', reservation_validate($good, $venue) === []);
check('unpublished venue is refused',
    array_key_exists('venue_id', reservation_validate($good, ['id' => 1, 'is_published' => false])));
check('missing venue is refused', array_key_exists('venue_id', reservation_validate($good, false)));
check('a time outside the slot list is refused',
    array_key_exists('reservation_time', reservation_validate(['reservation_time' => '03:15'] + $good, $venue)));
check('a past date is refused',
    array_key_exists('reservation_date', reservation_validate(['reservation_date' => '2020-01-01'] + $good, $venue)));
check('party size 0 is refused',
    array_key_exists('party_size', reservation_validate(['party_size' => 0] + $good, $venue)));
check('party size over the cap is refused',
    array_key_exists('party_size', reservation_validate(['party_size' => reservation_max_party() + 1] + $good, $venue)));
check('party size at the cap is allowed',
    !array_key_exists('party_size', reservation_validate(['party_size' => reservation_max_party()] + $good, $venue)));
check('a nameless request is refused',
    array_key_exists('guest_name', reservation_validate(['guest_name' => '  '] + $good, $venue)));
check('a phoneless request is refused',
    array_key_exists('guest_phone', reservation_validate(['guest_phone' => ''] + $good, $venue)));
check('a malformed email is refused',
    array_key_exists('guest_email', reservation_validate(['guest_email' => 'not-an-email'] + $good, $venue)));
check('a blank email is fine (it is optional)',
    !array_key_exists('guest_email', reservation_validate(['guest_email' => ''] + $good, $venue)));
check('every offered slot passes its own validator',
    !array_key_exists('reservation_time',
        reservation_validate(['reservation_time' => array_key_first(reservation_slots())] + $good, $venue)));

// ── Partner URL helpers ─────────────────────────────────────────────────────
check('a bare domain becomes https',   partner_website_href('agency.com') === 'https://agency.com');
check('http is left alone',            partner_website_href('http://agency.com') === 'http://agency.com');
check('an empty website is empty',     partner_website_href('  ') === '');
check('a javascript: url is rejected', partner_website_href('javascript:alert(1)') === '');
check('an absolute logo URL passes through', partner_logo_url('https://cdn/x.png') === 'https://cdn/x.png');
check('a root-relative logo passes through', partner_logo_url('/img/x.png') === '/img/x.png');
check('no logo resolves to empty',           partner_logo_url(null) === '');

// ── DB-backed ───────────────────────────────────────────────────────────────
if (!menus_supported()) {
    echo "\nSKIP  menu feed DB assertions (add_menus.sql not applied)\n";
} else {
    check('unknown slug has no payload', menu_feed_payload('definitely-not-a-menu') === null);

    $index = menu_feed_index();
    check('the index lists published menus', $index !== []);
    if ($index) {
        $first = $index[0];
        check('index rows carry a feed + public url',
            str_contains((string)$first['feed_url'], 'menu-feed.php?slug=')
            && str_contains((string)$first['public_url'], 'menu.php?m='));

        $payload = menu_feed_payload((string)$first['slug']);
        check('the feed resolves its own index slug', is_array($payload));
        if ($payload) {
            foreach (['slug','title','currency','sections','reservations','public_url'] as $k) {
                check("payload has '{$k}'", array_key_exists($k, $payload));
            }
            check('sections are split food/drinks',
                array_keys($payload['sections']) === ['food', 'drinks']);
            check('reservation slots come from reservation_slots()',
                !$payload['reservations']['enabled']
                || $payload['reservations']['slots'] === reservation_slots());
            check('the feed advertises the write endpoint',
                str_contains((string)$payload['reservations']['api_url'], 'reservation-api.php'));

            // Prices must be the SAME string /menu.php renders — one formatter.
            $anyItem = null;
            foreach ($payload['sections'] as $cats) {
                foreach ($cats as $c) { if ($c['items']) { $anyItem = $c['items'][0]; break 2; } }
            }
            if ($anyItem !== null) {
                check('price_label matches menu_price_label()',
                    $anyItem['price_label'] === menu_price_label($anyItem['price'], $payload['currency']));
                check('item attributes use the badge vocabulary',
                    array_keys($anyItem['attributes'])
                    === array_map(fn($d) => $d[0], array_values(menu_badge_defs())));
            }

            // An unpublished menu must never surface through the feed.
            db()->beginTransaction();
            try {
                db_query('UPDATE menus SET is_published = FALSE WHERE slug = :s', [':s' => $payload['slug']]);
                check('unpublishing hides the menu from the feed',
                    menu_feed_payload($payload['slug']) === null);
            } finally { db()->rollBack(); }
        }
    }
}

if (!reservations_supported()) {
    echo "\nSKIP  reservation DB assertions (add_reservations.sql not applied)\n";
} else {
    $venueRow = db_query('SELECT id FROM venues WHERE is_published = TRUE ORDER BY id LIMIT 1')->fetch();
    if (!$venueRow) {
        echo "\nSKIP  reservation DB assertions (no published venue)\n";
    } else {
        db()->beginTransaction();
        try {
            $res = create_reservation([
                'venue_id'         => (int)$venueRow['id'],
                'reservation_date' => date('Y-m-d', strtotime('+3 days')),
                'reservation_time' => '19:30',
                'party_size'       => 4,
                'guest_name'       => 'TEST Partner Guest',
                'guest_phone'      => '+254700000000',
                'guest_email'      => 'test@example.com',
                'notes'            => 'from the partner API test',
                'source'           => 'zuri-website',
            ]);
            check('the API writer creates a pending row', ($res['status'] ?? '') === 'pending');
            check('it records where the booking came from', ($res['source'] ?? '') === 'zuri-website');
            check('a reference is minted', str_starts_with((string)($res['reference'] ?? ''), 'TSR-'));

            $found = fetch_reservation_by_reference((string)$res['reference']);
            check('lookup by reference finds it', $found && (int)$found['id'] === (int)$res['id']);
            check('lookup joins the venue name', $found && array_key_exists('venue_name', $found));
            check('an unknown reference finds nothing',
                fetch_reservation_by_reference('TSR-0-NOPE!') === null);
        } finally { db()->rollBack(); }
    }
}

if (!partners_supported()) {
    echo "\nSKIP  partner DB assertions (add_agency_partners.sql not applied)\n";
} else {
    db()->beginTransaction();
    try {
        $pid = partner_register_pending('TEST Safari Travel', 'https://safari.example', 'partners/test.png', 'ops@safari.example', 4242);
        check('self-registration inserts a row', $pid > 0);
        $row = fetch_partner($pid);
        check('a self-registered partner is NOT published', $row && empty($row['is_published']));
        check('it is flagged pending for review', $row && partner_is_pending($row));
        check('it keeps the submission it came from', $row && (int)$row['submission_id'] === 4242);
        check('a pending partner stays out of the public ticker',
            !in_array($pid, array_map(fn($p) => (int)$p['id'], fetch_published_partners()), true));

        // Re-registering refreshes the same row instead of stacking duplicates.
        $again = partner_register_pending('test safari travel', 'https://safari2.example', '', 'ops@safari.example', 4343);
        check('re-registering updates the same row', $again === $pid);
        $row2 = fetch_partner($pid);
        check('the newer website wins', $row2 && $row2['website_url'] === 'https://safari2.example');
        check('an omitted logo keeps the old one', $row2 && $row2['logo_key'] === 'partners/test.png');

        // Once approved, it shows — and a resubmission must not un-publish it.
        db_query('UPDATE agency_partners SET is_published = TRUE WHERE id = :id', [':id' => $pid]);
        partner_register_pending('TEST Safari Travel', 'https://safari3.example', '', 'ops@safari.example', 4444);
        $row3 = fetch_partner($pid);
        check('re-registering never un-publishes an approved partner', $row3 && !empty($row3['is_published']));
        check('an approved partner appears in the public ticker',
            in_array($pid, array_map(fn($p) => (int)$p['id'], fetch_published_partners()), true));
    } finally { db()->rollBack(); }
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
