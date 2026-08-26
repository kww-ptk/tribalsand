<?php
/**
 * Shared resolver for a venue's gallery images.
 *
 * Both includes/property-gallery.php (top hero gallery) and
 * includes/property-photo-grid.php (bottom "Photo Gallery" section) call this,
 * so they render the same ordered list — which is what lets the bottom grid's
 * tile indices address the right image in the hero's pgOpenLb lightbox.
 *
 * Only the DB-derived result is memoized. $fallback is applied per call, because
 * the hero passes one and the bottom grid deliberately does not.
 */
require_once __DIR__ . '/db.php';

/**
 * @param string $slug     venues.slug
 * @param array  $fallback Static images used ONLY when the DB returns nothing.
 *                         Accepts 'path/to.jpg' or ['src' => …, 'alt' => …].
 * @param string $badge    Badge text, used only alongside $fallback.
 * @return array{badge: string, images: array<int, array{url: string, alt: string}>}
 */
function pg_gallery(string $slug, array $fallback = [], string $badge = ''): array {
    static $cache = [];

    if (!isset($cache[$slug])) {
        $venue  = false;
        $rows   = [];
        try {
            $venue = $slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $slug])->fetch() : false;
            $rows  = $venue ? fetch_venue_images((int) $venue['id']) : [];
        } catch (Throwable $e) {
            $venue = false;
            $rows  = [];
        }

        $images = [];
        foreach ($rows as $r) {
            $images[] = [
                'url' => storage_url($r['filename']),
                'alt' => ($r['alt_text'] ?: ($venue['name'] ?? '')),
            ];
        }

        $cache[$slug] = [
            'badge'  => $rows ? trim(($venue['name'] ?? '') . (!empty($venue['location']) ? ' · ' . $venue['location'] : '')) : '',
            'images' => $images,
        ];
    }

    if ($cache[$slug]['images']) return $cache[$slug];
    if (!$fallback)              return $cache[$slug];

    // DB gave us nothing — fall back to the page's static list for this call only.
    $images = [];
    foreach ($fallback as $fb) {
        if (is_string($fb)) {
            $images[] = ['url' => $fb, 'alt' => ($badge ?: 'Property photo')];
        } elseif (is_array($fb)) {
            $images[] = [
                'url' => $fb['src'] ?? ($fb['url'] ?? ''),
                'alt' => $fb['alt'] ?? ($badge ?: 'Property photo'),
            ];
        }
    }
    $images = array_values(array_filter($images, fn($g) => $g['url'] !== ''));

    return ['badge' => $badge, 'images' => $images];
}
