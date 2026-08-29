<?php
/**
 * Offers & Specials helpers (DB-driven, site-wide, homepage promo strip).
 *
 * Data model (migration: add_offers.sql):
 *   offers — site-wide promotions shown in the homepage strip. v1 has no
 *   per-property scoping (no venue_id). An offer is hidden once valid_to passes.
 *
 * All reads are pre-migration-safe via offers_supported(): pages that call these
 * before the migration has run get empty results instead of a fatal.
 *
 * "Today" for the date-window filter is Nairobi-local (see CLAUDE.md timezone note)
 * — includes/db.php sets Africa/Nairobi for PHP and the DB, so date('Y-m-d') and
 * CURRENT_DATE agree.
 */

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage.php';

/** True if the offers table exists (memoised). False pre-migration. */
function offers_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.offers')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Category key => short label marker shown on each card. */
function offer_categories(): array {
    return [
        'stay'       => 'Stay',
        'dining'     => 'Dining',
        'experience' => 'Experience',
        'special'    => 'Special',
    ];
}

/** Label for a category key (falls back to a capitalised key). */
function offer_category_label(string $cat): string {
    return offer_categories()[$cat] ?? ucfirst($cat ?: 'Offer');
}

/**
 * Resolve an offer's image_key to a URL. Mirrors nav_img_url():
 *   http(s)://… → as-is · images/… → asset_url() · else → storage_url().
 */
function offer_img_url(?string $key): string {
    $key = trim((string) $key);
    if ($key === '') return '';
    if (str_starts_with($key, 'http')) return $key;
    if ($key[0] === '/') return $key;
    if (str_starts_with($key, 'images/')) return asset_url($key);
    return storage_url($key);
}

/**
 * Published, in-window offers for the public strip, ordered by sort_order.
 * An offer shows when: is_published AND (valid_from is null or <= today)
 * AND (valid_to is null or >= today).
 */
function fetch_published_offers(): array {
    if (!offers_supported()) return [];
    $today = date('Y-m-d');
    return db_query(
        "SELECT * FROM offers
          WHERE is_published = TRUE
            AND (valid_from IS NULL OR valid_from <= :t1)
            AND (valid_to   IS NULL OR valid_to   >= :t2)
          ORDER BY sort_order ASC, id ASC",
        [':t1' => $today, ':t2' => $today]
    )->fetchAll();
}

/** All offers (admin list), newest-configured order first by sort then id. */
function fetch_all_offers(): array {
    if (!offers_supported()) return [];
    return db_query(
        "SELECT * FROM offers ORDER BY sort_order ASC, id ASC"
    )->fetchAll();
}

/** Single offer by id, or false. */
function fetch_offer(int $id): array|false {
    if (!offers_supported() || $id <= 0) return false;
    return db_query("SELECT * FROM offers WHERE id = :id", [':id' => $id])->fetch();
}
