<?php
declare(strict_types=1);
// Booking widget embed (booking-embed.php + js/booking-embed.js + Admin → Booking widgets):
// which widget a property gets, the embedding site's host, lead tracking and the code.
// Run: php tests/booking_embed_logic.php   (pure — no DB needed)
require_once __DIR__ . '/../includes/booking-embed.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Which widget: the same one the property page uses ──────────────────────
$multi = [['slug' => 'mk-a', 'is_entire_place' => false], ['slug' => 'mk-b', 'is_entire_place' => false], ['slug' => 'mk-buyout', 'is_entire_place' => true]];
$amani = [['slug' => 'my-amani-premium-sea-view-single', 'is_entire_place' => false], ['slug' => 'my-amani-twin-sea-view-twin', 'is_entire_place' => false], ['slug' => 'my-amani-full-rental', 'is_entire_place' => false]];
check('Maya Kobe → the availability-first property widget', booking_embed_plan('maya-kobe', $multi) === ['kind' => 'property']);
check('My Amani → the full rental, like its page (not its 10 room listings)', booking_embed_plan('my-amani', $amani) === ['kind' => 'room', 'room_slug' => 'my-amani-full-rental']);
check('Maya Ilai → its configurator, whatever its rooms', booking_embed_plan('maya_ilai', $multi) === ['kind' => 'maya_ilai'] && booking_embed_plan('maya-ilai', [])['kind'] === 'maya_ilai');
check('a listed room that is unpublished falls back to the rule', booking_embed_plan('sandbox', [['slug' => 'sandbox-new', 'is_entire_place' => false]]) === ['kind' => 'room', 'room_slug' => 'sandbox-new']);
check('unlisted, two+ rooms → property widget', booking_embed_plan('new-place', $multi) === ['kind' => 'property']);
check('unlisted, one room + a buyout → the buyout room', booking_embed_plan('x', [['slug' => 'one', 'is_entire_place' => false], ['slug' => 'all', 'is_entire_place' => true]]) === ['kind' => 'room', 'room_slug' => 'all']);
check('unlisted, a single room (pg "f" = not whole) → that room', booking_embed_plan('x', [['slug' => 'solo', 'is_entire_place' => 'f']]) === ['kind' => 'room', 'room_slug' => 'solo']);
check('no published room → nothing to book', booking_embed_plan('empty', []) === null && booking_embed_plan('zuri', []) === null);
// The map must name the widget each property page really includes.
foreach (['maya-kobe' => 'maya-kobe.php', 'zuri' => 'zuri.php', 'my-amani' => 'my-amani.php', 'enkare-bofa' => 'enkare-bofa.php', 'sandbox' => 'sandbox.php'] as $slug => $page) {
    $src = (string)@file_get_contents(__DIR__ . '/../' . $page);
    $w = BOOKING_EMBED_PAGE_WIDGETS[$slug];
    $ok = $w['kind'] === 'property'
        ? str_contains($src, "\$pa_venue_slug = '{$slug}'")
        : str_contains($src, "\$booking_slug = '{$w['room_slug']}'");
    check("map matches {$page}", $ok);
}

// ── Host of the embedding website ──────────────────────────────────────────
check('a plain host is kept, lower-cased', booking_embed_host('MayaKobe.com') === 'mayakobe.com');
check('a URL gives its host', booking_embed_host('https://www.zuri.co.ke/stay?x=1') === 'www.zuri.co.ke');
check('a port is dropped', booking_embed_host('localhost.test:8080') === 'localhost.test');
check('junk is refused', booking_embed_host('<script>') === '' && booking_embed_host('a b.com') === '' && booking_embed_host('nodot') === '');
check('an over-long host is refused', booking_embed_host(str_repeat('a', 100) . '.com') === '');
check('from wins over the Referer', booking_embed_from('mayakobe.com', 'https://other.com/x', 'tribalsand.com') === 'mayakobe.com');
check('Referer host when no from', booking_embed_from('', 'https://myamani.com/book', 'tribalsand.com') === 'myamani.com');
check('our own site is not an embedding site', booking_embed_from('tribalsand.com', '', 'tribalsand.com') === '' && booking_embed_from('', 'https://tribalsand.com/x', 'tribalsand.com') === '');

// ── Tracking: the property site becomes the lead source ────────────────────
$t = booking_embed_tracking(['utm_source' => '', 'referrer' => ''], 'mayakobe.com');
check('no source → the property site, medium booking-widget', $t['utm_source'] === 'mayakobe.com' && $t['utm_medium'] === 'booking-widget' && $t['referrer'] === 'https://mayakobe.com/');
check('a real UTM source is never overwritten', booking_embed_tracking(['utm_source' => 'google'], 'mayakobe.com')['utm_source'] === 'google');
check('no / bad embed_from → tracking unchanged', booking_embed_tracking(['a' => 1], null) === ['a' => 1] && booking_embed_tracking(['a' => 1], ['x']) === ['a' => 1] && booking_embed_tracking([], 'bad host') === []);

// ── The code ────────────────────────────────────────────────────────────────
$c = booking_embed_code('maya-kobe', 'https://tribalsand.com/');
check('code = a placeholder div + the loader from our site', $c === "<div data-tribalsand-booking=\"maya-kobe\"></div>\n<script src=\"https://tribalsand.com/js/booking-embed.js\" async></script>");
$f = booking_embed_iframe_code('zuri', 'https://tribalsand.com', 'Book "Zuri"');
check('frame code points at the embed page and escapes its title', str_contains($f, 'src="https://tribalsand.com/booking-embed?venue=zuri"') && str_contains($f, 'title="Book &quot;Zuri&quot;"'));

// ── The loader and the page agree on the message ────────────────────────────
$js = file_get_contents(__DIR__ . '/../js/booking-embed.js');
$page = file_get_contents(__DIR__ . '/../booking-embed.php');
check('loader checks the message origin and source frame', str_contains($js, 'e.origin !== origin') && str_contains($js, 'contentWindow !== e.source'));
check('page posts tsEmbed height + overlay with the loader\'s id', str_contains($page, 'tsEmbed: 1, id: id, height:') && str_contains($js, '#tsid=') && str_contains($page, 'tsid='));
check('page is noindex and has no site footer (chat, cookie banner)', str_contains($page, '$noindex          = true') && !str_contains($page, 'footer.php'));

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
