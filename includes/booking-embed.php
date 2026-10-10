<?php
declare(strict_types=1);
/**
 * Booking widget for the properties' own websites (embed code).
 *
 * Each property also has a small website on its own domain. Those sites show
 * THIS site's booking widget in an iframe (`/booking-embed?venue=<slug>`), so a
 * guest there uses the very same widget, the same availability and the same
 * submit endpoints as on tribalsand.com — one calendar, so nothing can be
 * double-booked from a second form.
 *
 *  - Page:   booking-embed.php     (no site header/footer/chat; noindex)
 *  - Loader: js/booking-embed.js   (makes the iframe, follows its height, and
 *                                   lets a booking pop-up cover the whole window)
 *  - Admin:  admin/booking-widgets.php (owner — copy the code per property)
 *
 * Which widget a property gets mirrors its own page on tribalsand.com
 * (BOOKING_EMBED_PAGE_WIDGETS / booking_embed_plan(), pure). Leads sent from an embed carry the property
 * site's domain as their source (booking_embed_tracking()), because an iframe on
 * another domain usually gets no session cookie for the first-touch tracking.
 *
 * Test: php tests/booking_embed_logic.php
 */
require_once __DIR__ . '/db.php';

/** Only allow documented theme values, never arbitrary CSS from a URL. */
function booking_embed_theme(array $options): string
{
    $rules = [];
    foreach (['primary' => ['--bk-teal', '--teal', '--ts-teal'],
              'header' => ['--bk-teal-d', '--teal-d', '--ts-teal-d'],
              'accent' => ['--bk-sand', '--sand', '--ts-sand'],
              'background' => ['--embed-background']] as $key => $tokens) {
        $value = $options[$key] ?? '';
        if (!is_string($value) || !preg_match('/^#[a-f0-9]{6}$/i', $value)) continue;
        foreach ($tokens as $token) $rules[] = $token . ':' . $value;
    }
    if (isset($options['radius']) && is_scalar($options['radius']) && preg_match('/^\d{1,2}$/', (string)$options['radius'])) {
        $rules[] = '--embed-radius:' . min(32, (int)$options['radius']) . 'px';
    }
    if (($options['font'] ?? '') === 'sans') $rules[] = '--embed-heading-font:Arial,sans-serif';
    return implode(';', $rules);
}

/**
 * The widget each property PAGE shows (maya-kobe.php, zuri.php, my-amani.php …).
 * Keep in step with those pages: the embed must offer exactly what the page offers.
 * A property not listed here falls back to booking_embed_plan()'s rule.
 */
const BOOKING_EMBED_PAGE_WIDGETS = [
    'maya-kobe'   => ['kind' => 'property'],
    'zuri'        => ['kind' => 'property'],
    'my-amani'    => ['kind' => 'room', 'room_slug' => 'my-amani-full-rental'],
    'enkare-bofa' => ['kind' => 'room', 'room_slug' => 'enkare-bofa'],
    'sandbox'     => ['kind' => 'room', 'room_slug' => 'sandbox'],
    'maya_ilai'   => ['kind' => 'maya_ilai'],   // its own configurator (includes/maya-ilai-booking.php)
    'maya-ilai'   => ['kind' => 'maya_ilai'],
];

/**
 * Which widget a property's embed shows — the same one its page uses. Pure.
 *   property  → availability-first widget (pick dates + party, then a room / the whole place)
 *   room      → the single-room widget on one room (whole-property villas)
 *   maya_ilai → the configurator pop-up
 * A listed room that isn't published any more falls through to the rule:
 * two or more bookable (non-whole-property) rooms → property, else the
 * whole-property room (or the only room) → room.
 *
 * @param list<array{slug:string,is_entire_place?:mixed}> $rooms the venue's PUBLISHED rooms, in page order
 * @return array{kind:string,room_slug?:string}|null null = nothing bookable
 */
function booking_embed_plan(string $venueSlug, array $rooms): ?array
{
    $fixed = BOOKING_EMBED_PAGE_WIDGETS[$venueSlug] ?? null;
    if ($fixed && $fixed['kind'] === 'maya_ilai') return $fixed;
    if (!$rooms) return null;
    if ($fixed && ($fixed['kind'] === 'property' || in_array($fixed['room_slug'], array_column($rooms, 'slug'), true))) return $fixed;
    $isEntire = fn($r) => !empty($r['is_entire_place']) && $r['is_entire_place'] !== 'f';
    $entire = array_values(array_filter($rooms, $isEntire));
    if (count($rooms) - count($entire) >= 2) return ['kind' => 'property'];
    return ['kind' => 'room', 'room_slug' => (string)($entire[0]['slug'] ?? $rooms[0]['slug'])];
}

/** A bare, lower-case host name, or '' when $h isn't one. Pure. */
function booking_embed_host(?string $h): string
{
    $h = strtolower(trim((string)$h));
    if (str_contains($h, '://')) $h = (string)parse_url($h, PHP_URL_HOST);
    $h = preg_replace('/:\d+$/', '', $h);
    return (strlen($h) <= 100 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $h)) ? $h : '';
}

/**
 * The website the widget is shown on: the loader's `from`, else the Referer's
 * host. Our own host is not an "other site" and gives ''. Pure.
 */
function booking_embed_from(?string $from, ?string $referer, string $ownHost): string
{
    $h = booking_embed_host($from);
    if ($h === '') $h = booking_embed_host((string)parse_url((string)$referer, PHP_URL_HOST));
    return ($h !== '' && $h !== booking_embed_host($ownHost)) ? $h : '';
}

/**
 * Fold the embedding site into a lead's tracking: when nothing else named a
 * source, the property website becomes utm_source (medium "booking-widget"), so
 * Enquiry trends shows which site the lead came from. Never overwrites a real
 * UTM source. Pure.
 */
function booking_embed_tracking(array $tracking, mixed $embedFrom): array
{
    $host = booking_embed_host(is_string($embedFrom) ? $embedFrom : '');
    if ($host === '' || trim((string)($tracking['utm_source'] ?? '')) !== '') return $tracking;
    $tracking['utm_source'] = $host;
    $tracking['utm_medium'] = 'booking-widget';
    if (trim((string)($tracking['referrer'] ?? '')) === '') $tracking['referrer'] = 'https://' . $host . '/';
    return $tracking;
}

/** The code a property website pastes where the widget should appear. Pure. */
function booking_embed_code(string $venueSlug, string $base): string
{
    $base = rtrim($base, '/');
    $slug = htmlspecialchars($venueSlug, ENT_QUOTES);
    return '<div data-tribalsand-booking="' . $slug . '"></div>' . "\n"
         . '<script src="' . htmlspecialchars($base, ENT_QUOTES) . '/js/booking-embed.js" async></script>';
}

/** The no-JavaScript variant: a plain iframe of a fixed height. Pure. */
function booking_embed_iframe_code(string $venueSlug, string $base, string $title): string
{
    return '<iframe src="' . htmlspecialchars(rtrim($base, '/') . '/booking-embed?venue=' . rawurlencode($venueSlug), ENT_QUOTES) . '"'
         . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '" loading="lazy"'
         . ' style="width:100%;max-width:440px;height:760px;border:0"></iframe>';
}

/**
 * Every published property with the widget its embed shows (admin list).
 * @return list<array{id:int,name:string,slug:string,plan:?array}>
 */
function booking_embed_venues(): array
{
    $venues = db_query('SELECT id, name, slug FROM venues WHERE is_published = TRUE ORDER BY sort_order, name')->fetchAll();
    $out = [];
    foreach ($venues as $v) {
        $out[] = ['id' => (int)$v['id'], 'name' => (string)$v['name'], 'slug' => (string)$v['slug'],
                  'plan' => booking_embed_plan((string)$v['slug'], booking_embed_rooms((int)$v['id']))];
    }
    return $out;
}

/** A venue's published rooms, in page order. */
function booking_embed_rooms(int $venueId): array
{
    return db_query(
        'SELECT slug, is_entire_place FROM rooms WHERE venue_id = :v AND is_published = TRUE ORDER BY sort_order, id',
        [':v' => $venueId]
    )->fetchAll();
}
