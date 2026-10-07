<?php
declare(strict_types=1);
/**
 * Site search — "find anything" on the public website.
 *
 * ONE index (site_search_index()) of everything a guest can open: the main
 * pages and sections, every published property and room, activities, the
 * restaurant menus and the journal. Read live from the DB (properties, rooms,
 * activities, menus) and from code (pages, articles), so a new room or tour is
 * searchable the moment it is published. Every DB part is fail-soft: a missing
 * table or a DB hiccup drops that part, never the search.
 *
 * Ranking (site_search_rank()) is pure and tested: every word typed must match
 * the start of a word in the title, the subtitle or the keywords; title hits
 * score highest. Used by api/site-search.php (the header overlay + homepage
 * bar, js/site-search.js) and site-search.php (the full results page, no JS).
 *
 * This is NOT /search — that page checks live availability for dates.
 * Test: php tests/site_search_logic.php
 */

require_once __DIR__ . '/db.php';

const SITE_SEARCH_MAX_Q      = 80;
const SITE_SEARCH_CACHE_TTL  = 300;   // seconds the built index is kept in APCu
const SITE_SEARCH_TYPES      = [      // display order of the groups
    'page'       => 'Pages',
    'stay'       => 'Places to stay',
    'room'       => 'Rooms & villas',
    'dining'     => 'Restaurants & menus',
    'experience' => 'Experiences',
    'journal'    => 'Journal',
];

/** Lower-case, accents folded, everything but letters/digits → one space. */
function site_search_normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $map = ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','&'=>' and '];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
    return trim($s);
}

/** The words of a query (normalised, de-duplicated, ignoring tiny filler words). */
function site_search_terms(string $q): array {
    $stop = ['the', 'a', 'an', 'of', 'in', 'at', 'to', 'and', 'for', 'on', 'with', 'is'];
    $out = [];
    foreach (explode(' ', site_search_normalize($q)) as $w) {
        if ($w === '' || in_array($w, $stop, true) || in_array($w, $out, true)) continue;
        $out[] = $w;
    }
    // A query made only of filler words still searches for them.
    if (!$out) $out = array_values(array_filter(explode(' ', site_search_normalize($q))));
    return $out;
}

/** Does $term start a word in the normalised text $hay (" word word ")? */
function site_search_word_hit(string $hay, string $term): bool {
    return $term !== '' && strpos(' ' . $hay, ' ' . $term) !== false;
}

/**
 * Rank index items for a query. Pure.
 * Item: ['title','url','type','sub'?,'keywords'?]. Returns the matching items,
 * best first, each with a 'score', at most $limit.
 */
function site_search_rank(array $items, string $q, int $limit = 30): array {
    $terms = site_search_terms($q);
    if (!$terms) return [];
    $phrase = implode(' ', $terms);
    $order  = array_flip(array_keys(SITE_SEARCH_TYPES));
    $out = [];
    foreach ($items as $i => $it) {
        $title = site_search_normalize((string)($it['title'] ?? ''));
        $sub   = site_search_normalize((string)($it['sub'] ?? ''));
        $kw    = site_search_normalize((string)($it['keywords'] ?? ''));
        $score = 0;
        foreach ($terms as $t) {
            if (site_search_word_hit($title, $t))     $score += 20;
            elseif (site_search_word_hit($sub, $t))   $score += 8;
            elseif (site_search_word_hit($kw, $t))    $score += 5;
            else continue 2;                          // every word must match somewhere
        }
        if ($title === $phrase)                         $score += 100;
        elseif (strpos($title, $phrase) === 0)          $score += 50;
        elseif (site_search_word_hit($title, $phrase))  $score += 25;
        $score += (int)($it['boost'] ?? 0);
        $it['score'] = $score;
        $it['_i'] = $i;
        $out[] = $it;
    }
    usort($out, function ($a, $b) use ($order) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        $ta = $order[$a['type']] ?? 99; $tb = $order[$b['type']] ?? 99;
        if ($ta !== $tb) return $ta <=> $tb;
        return $a['_i'] <=> $b['_i'];
    });
    $out = array_slice($out, 0, max(1, $limit));
    foreach ($out as &$o) unset($o['_i'], $o['keywords'], $o['boost']);
    return $out;
}

/** Group ranked results by type in SITE_SEARCH_TYPES order (empty groups dropped). Pure. */
function site_search_group(array $results): array {
    $groups = [];
    foreach (SITE_SEARCH_TYPES as $type => $label) {
        $rows = array_values(array_filter($results, fn($r) => ($r['type'] ?? '') === $type));
        if ($rows) $groups[] = ['type' => $type, 'label' => $label, 'items' => $rows];
    }
    return $groups;
}

/** The main pages and sections, with the words guests use for them. */
function site_search_pages(): array {
    $p = fn($title, $url, $sub, $kw, $type = 'page', $boost = 0) =>
        ['title' => $title, 'url' => $url, 'sub' => $sub, 'keywords' => $kw, 'type' => $type, 'boost' => $boost];
    return [
        $p('Home', '/', 'Luxury beachfront hotels & villas in Kenya', 'start main homepage tribal sand'),
        $p('All properties', '/properties', 'Every hotel and villa on the coast', 'accommodation accommodations stay stays hotels villas rooms where to stay lodging', 'page', 6),
        $p('Check availability', '/search', 'Live availability and prices for your dates', 'book booking dates price prices rates availability free rooms reserve', 'page', 6),
        $p('Send an enquiry', '/enquire', 'Ask us anything about your stay', 'enquiry inquiry question ask request quote', 'page'),
        $p('Plan your trip', '/trip-builder', 'Build a trip — stays, activities and transfers', 'trip builder itinerary plan holiday vacation package'),
        $p('Contact us', '/contact', 'Phone, email, WhatsApp and directions', 'contact phone call email whatsapp address directions location map reach help support'),
        $p('Ask our concierge', '/concierge', 'Chat with our AI concierge', 'ai chat concierge assistant help questions'),
        $p('Restaurants', '/zuri-restaurant', 'Dining across Tribal Sand', 'restaurant restaurants food eat dining dinner lunch breakfast bar', 'dining'),
        $p('Reserve a table', '/reserve', 'Book a table at one of our restaurants', 'reservation reserve table booking restaurant dinner lunch', 'dining', 4),
        $p('Tribal Table', '/tribal-table', 'Restaurant & bar · Kilifi', 'restaurant bar food kilifi tribal dunes dinner drinks', 'dining'),
        $p('Somewhere Café', '/somewhere-cafe', 'Beachfront café · Kilifi (coming soon)', 'cafe coffee breakfast brunch kilifi beach', 'dining'),
        $p('Zuri Restaurant', '/zuri-restaurant', 'Restaurant · Watamu', 'restaurant watamu seafood dinner zuri', 'dining'),
        $p('À la carte dining', '/a-la-carte-dining', 'Private chefs and à la carte menus', 'chef private chef meals menu food catering', 'dining'),
        $p('Activities & experiences', '/activities', 'Ocean, culture, safari and adventure', 'activities experiences things to do tours excursions safari snorkelling diving fishing kitesurf dhow', 'experience', 6),
        $p('Excursions', '/excursions', 'Day trips along the Kenya coast', 'excursions day trips tours safari', 'experience'),
        $p('Retreats', '/retreats', 'Yoga, wellness and group retreats', 'retreat retreats yoga wellness group corporate', 'experience'),
        $p('Active holidays', '/kenya-active-holidays', 'Kitesurfing, diving, golf and more', 'active sport sports adventure kitesurf diving golf', 'experience'),
        $p('Honeymoons', '/kenya-honeymoon', 'Romantic stays on the Kenya coast', 'honeymoon romantic couples anniversary', 'experience'),
        $p('Kenya itinerary', '/kenya-itinerary', 'Sample itineraries for the coast and safari', 'itinerary safari plan days route', 'experience'),
        $p('Tribal Gym', '/tribal-gym', 'Gym and fitness · Tribal Dunes', 'gym fitness workout training sport', 'experience'),
        $p('Events & weddings', '/events', 'Weddings, celebrations and private hire', 'events event wedding weddings party celebration private hire venue conference', 'page', 4),
        $p('Events gallery', '/events-gallery', 'Photos from weddings and events', 'events photos pictures wedding gallery', 'page'),
        $p('Wedding venues on the Kenya coast', '/finding-the-best-luxury-wedding-venue-kenya-coast', 'Guide to beach weddings', 'wedding weddings venue beach ceremony', 'journal'),
        $p('Tribal Dunes', '/tribal-dunes', 'Beachfront village · Kilifi', 'tribal dunes kilifi village compound maya ilai off duty', 'stay', 2),
        $p('Off Duty', '/off-duty', 'Coworking hotel · Tribal Dunes, Kilifi', 'coworking cowork work remote digital nomad office wifi', 'stay'),
        $p('Kilifi', '/kilifi', 'Area guide', 'kilifi area guide creek town beach bofa', 'page', 2),
        $p('Watamu', '/watamu', 'Area guide', 'watamu area guide marine park beach', 'page', 2),
        $p('Kenya coast travel guide', '/kenya-coast-guide', 'Getting here, transfers and weather', 'travel guide airport transfer transfers flights weather getting here mombasa malindi', 'page'),
        $p('Interactive site map', '/interactive-site-map', 'Find your way around the coast', 'map site map location where', 'page'),
        $p('Gallery', '/gallery', 'Photos of every property', 'photos pictures images gallery', 'page'),
        $p('Journal', '/blog', 'Stories, guides and travel tips', 'blog journal articles stories news tips guides', 'journal', 4),
        $p('Our story', '/tribalsandstory', 'About Tribal Sand', 'about us story team who we are history', 'page'),
        $p('Sustainability', '/sustainability', 'Solar, water, beach clean-ups and conservation', 'sustainability eco green solar environment conservation beach clean', 'page'),
        $p('For travel agents', '/for-agents', 'Trade rates and the agent portal', 'agent agents trade travel agency partner b2b', 'page'),
        $p('Terms & conditions', '/tc', 'Bookings, payments, cancellations and refunds', 'terms conditions policy policies cancellation cancel refund deposit payment rules', 'page'),
        $p('Privacy policy', '/privacy_policy', 'How we use your data', 'privacy data cookies gdpr policy', 'page'),
        $p('Licences', '/licences', 'Our licences and registrations', 'licence license registration legal', 'page'),
    ];
}

/** The properties as the header menu lists them — used only when the DB can't be read. */
function site_search_fallback_stays(): array {
    $s = fn($name, $url, $sub, $kw) => ['title' => $name, 'url' => $url, 'sub' => $sub, 'type' => 'stay', 'boost' => 10,
                                       'keywords' => 'hotel villa stay accommodation rooms ' . $kw];
    return [
        $s('Zuri', '/zuri', 'Watamu · Boutique hotel', 'watamu boutique hotel suites'),
        $s('Maya Kobe', '/maya-kobe', 'Kilifi · Boutique hotel', 'kilifi bofa beach boutique hotel suites cottages'),
        $s('My Amani', '/my-amani', 'Vipingo · Private villa', 'vipingo kilifi private villa romantic'),
        $s('Enkare Bofa', '/enkare-bofa', 'Kilifi · Private villa', 'kilifi bofa private villa group'),
        $s('Sandbox', '/sandbox', 'Kilifi · Private villa', 'kilifi private villa'),
        $s('Maya Ilai', '/maya_ilai', 'Tribal Dunes, Kilifi · Eco compound', 'kilifi tribal dunes eco villas studios'),
    ];
}

/** True when a page file exists at the web root for this slug. */
function site_search_page_exists(string $slug): bool {
    return $slug !== '' && preg_match('/^[a-z0-9_-]+$/i', $slug) && is_file(dirname(__DIR__) . '/' . $slug . '.php');
}

/** Everything searchable, from code + the live DB. Cached in APCu when present. */
function site_search_index(bool $fresh = false): array {
    $key = 'ts_site_search_index_v1';
    if (!$fresh && function_exists('apcu_fetch')) {
        $hit = apcu_fetch($key, $ok);
        if ($ok && is_array($hit)) return $hit;
    }
    $items = site_search_pages();
    $venueUrl = [];
    // After one failed DB read the rest are skipped: each would wait out the
    // connection timeout again, and the static pages can still be searched.
    $dbDown = false;

    // Properties.
    if (!$dbDown) try {
        foreach (db_query("SELECT id, slug, name, location FROM venues WHERE is_published = TRUE ORDER BY sort_order, name")->fetchAll() as $v) {
            $url = site_search_page_exists((string)$v['slug']) ? '/' . $v['slug'] : '/properties';
            $venueUrl[(int)$v['id']] = $url;
            $items[] = [
                'title' => (string)$v['name'], 'url' => $url, 'type' => 'stay', 'boost' => 10,
                'sub' => (string)($v['location'] ?? ''),
                'keywords' => 'hotel villa stay accommodation rooms ' . ($v['location'] ?? '') . ' ' . str_replace(['-', '_'], ' ', (string)$v['slug']),
            ];
        }
    } catch (Throwable $e) { $dbDown = true; }
    if (!$venueUrl) $items = array_merge($items, site_search_fallback_stays());

    // Rooms (each opens its own page when it has one, else its property).
    if (!$dbDown) try {
        $rows = db_query("SELECT r.slug, r.name, r.short_desc, r.capacity, v.id AS venue_id, v.name AS venue_name, v.location
                            FROM rooms r LEFT JOIN venues v ON v.id = r.venue_id
                           WHERE r.is_published = TRUE AND (v.id IS NULL OR v.is_published = TRUE)
                           ORDER BY v.sort_order NULLS LAST, r.sort_order, r.name")->fetchAll();
        foreach ($rows as $r) {
            $url = site_search_page_exists((string)$r['slug']) ? '/' . $r['slug']
                 : ($venueUrl[(int)($r['venue_id'] ?? 0)] ?? '/properties');
            $sub = trim(($r['venue_name'] ?? '') . (($r['capacity'] ?? 0) ? ' · sleeps ' . (int)$r['capacity'] : ''), ' ·');
            $items[] = [
                'title' => (string)$r['name'], 'url' => $url, 'type' => 'room', 'sub' => $sub,
                'keywords' => 'room suite villa bedroom ' . ($r['location'] ?? '') . ' ' . mb_substr(strip_tags((string)($r['short_desc'] ?? '')), 0, 240),
            ];
        }
    } catch (Throwable $e) { $dbDown = true; }

    // Activities.
    if (!$dbDown) try {
        foreach (db_query("SELECT slug, name, category, tag_label, duration, short_desc FROM tours WHERE is_published = TRUE ORDER BY sort_order, name")->fetchAll() as $t) {
            $sub = trim(implode(' · ', array_filter([(string)($t['tag_label'] ?? ''), (string)($t['duration'] ?? '')])));
            $items[] = [
                'title' => (string)$t['name'], 'url' => '/activities#tour-' . rawurlencode((string)$t['slug']),
                'type' => 'experience', 'sub' => $sub !== '' ? $sub : 'Activity',
                'keywords' => 'activity experience tour excursion ' . ($t['category'] ?? '') . ' ' . mb_substr(strip_tags((string)($t['short_desc'] ?? '')), 0, 240),
            ];
        }
    } catch (Throwable $e) { $dbDown = true; }

    // Restaurant menus.
    if (!$dbDown) try {
        if (is_file(__DIR__ . '/menu.php')) {
            require_once __DIR__ . '/menu.php';
            foreach (fetch_published_menus() as $m) {
                $items[] = [
                    'title' => (string)$m['title'], 'url' => '/menu?m=' . rawurlencode((string)$m['slug']),
                    'type' => 'dining', 'sub' => (string)($m['subtitle'] ?? '') ?: 'Menu',
                    'keywords' => 'menu food drinks prices restaurant breakfast lunch dinner',
                ];
            }
        }
    } catch (Throwable $e) { /* fail soft */ }

    // Journal articles.
    try {
        if (is_file(__DIR__ . '/articles.php')) {
            require_once __DIR__ . '/articles.php';
            foreach (ts_articles() as $slug => $a) {
                $items[] = [
                    'title' => (string)($a['title'] ?? $slug), 'url' => '/' . $slug, 'type' => 'journal',
                    'sub' => (string)($a['category'] ?? 'Journal'),
                    'keywords' => (string)($a['excerpt'] ?? '') . ' ' . (string)($a['desc'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) { /* fail soft */ }

    // Same URL + title listed twice (e.g. a page and a DB row) → keep the first.
    $seen = []; $out = [];
    foreach ($items as $it) {
        $k = strtolower($it['url'] . '|' . $it['title']);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $it;
    }
    if (function_exists('apcu_store')) @apcu_store($key, $out, SITE_SEARCH_CACHE_TTL);
    return $out;
}

/** Clean a raw query: trimmed, single-spaced, capped. */
function site_search_clean_query(string $q): string {
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    return mb_substr($q, 0, SITE_SEARCH_MAX_Q);
}

/** Search the live index. */
function site_search(string $q, int $limit = 30): array {
    $q = site_search_clean_query($q);
    if (mb_strlen($q) < 2) return [];
    return site_search_rank(site_search_index(), $q, $limit);
}
