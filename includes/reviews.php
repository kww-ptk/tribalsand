<?php
declare(strict_types=1);
/**
 * Guest reviews — DB-driven, owner-editable (Admin → Reviews). A review belongs to
 * one property or to the whole group (venue_id NULL); show_on_home picks it for the
 * home page. Every read is pre-migration-safe and pages keep their built-in reviews
 * as the fallback, so an empty table never blanks a section.
 * Migration: add_reviews.sql. Seed: db/seeds/seed_reviews.php. Test: tests/reviews_logic.php.
 */

require_once __DIR__ . '/db.php';

const REVIEW_QUOTE_MAX = 600;

/** True once add_reviews.sql has run. */
function reviews_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $ok = (bool) db_query("SELECT to_regclass('public.reviews') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** "★★★★☆" for a 1–5 rating. Pure. */
function review_stars(int $rating): string {
    $r = max(1, min(5, $rating));
    return str_repeat('★', $r) . str_repeat('☆', 5 - $r);
}

/** Average rating to one decimal ("4.8"), or null for none. Pure. */
function reviews_average(array $rows): ?string {
    if (!$rows) return null;
    $sum = array_sum(array_map(fn($r) => (int)$r['rating'], $rows));
    return number_format($sum / count($rows), 1);
}

/** A review quote with its surrounding quote marks removed (pages add their own). Pure. */
function review_plain_quote(string $q): string {
    return trim(trim($q), "\"“”'‘’ \t\n");
}

/** Validate a review form. Returns [clean, errors]. Pure. */
function review_clean(array $in): array {
    $err = [];
    $d = [
        'venue_id'     => (int)($in['venue_id'] ?? 0) ?: null,
        'author'       => trim((string)($in['author'] ?? '')),
        'detail'       => trim((string)($in['detail'] ?? '')),
        'rating'       => (int)($in['rating'] ?? 5),
        'quote'        => review_plain_quote((string)($in['quote'] ?? '')),
        'is_published' => !empty($in['is_published']),
        'show_on_home' => !empty($in['show_on_home']),
    ];
    if ($d['author'] === '' || mb_strlen($d['author']) > 120) $err['author'] = 'Who wrote it? (up to 120 characters)';
    if (mb_strlen($d['detail']) > 120) $err['detail'] = 'The detail line is up to 120 characters.';
    if ($d['rating'] < 1 || $d['rating'] > 5) $err['rating'] = 'Pick a rating from 1 to 5 stars.';
    if ($d['quote'] === '' || mb_strlen($d['quote']) > REVIEW_QUOTE_MAX) $err['quote'] = 'The review text is required (up to ' . REVIEW_QUOTE_MAX . ' characters).';
    return [$d, $err];
}

/** Published reviews of one property (by slug), in order. [] pre-migration / none. */
function reviews_for_venue(string $slug, int $limit = 8): array {
    if (!reviews_supported()) return [];
    try {
        return db_query('SELECT r.* FROM reviews r JOIN venues v ON v.id = r.venue_id WHERE v.slug = :s AND r.is_published
                          ORDER BY r.sort_order, r.id LIMIT ' . max(1, $limit), [':s' => $slug])->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Published reviews picked for the home page — whole-group ones first, then each property's in its own order. */
function reviews_for_home(int $limit = 3): array {
    if (!reviews_supported()) return [];
    try {
        return db_query('SELECT r.* FROM reviews r WHERE r.is_published AND r.show_on_home ORDER BY r.venue_id NULLS FIRST, r.sort_order, r.id LIMIT ' . max(1, $limit))->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Every review for the admin list, with the property name. */
function reviews_all(): array {
    if (!reviews_supported()) return [];
    return db_query('SELECT r.*, v.name AS venue_name FROM reviews r LEFT JOIN venues v ON v.id = r.venue_id
                      ORDER BY v.sort_order NULLS FIRST, v.name NULLS FIRST, r.sort_order, r.id')->fetchAll();
}

/** Create ($id null) or update a review from clean data. Returns the id. */
function review_save(?int $id, array $d): int {
    $args = [':v' => $d['venue_id'], ':a' => $d['author'], ':d' => $d['detail'], ':r' => $d['rating'], ':q' => $d['quote'],
             ':p' => $d['is_published'] ? 'TRUE' : 'FALSE', ':h' => $d['show_on_home'] ? 'TRUE' : 'FALSE'];
    if ($id === null) {
        $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM reviews WHERE venue_id IS NOT DISTINCT FROM :v', [':v' => $d['venue_id']])->fetchColumn();
        db_query('INSERT INTO reviews (venue_id, author, detail, rating, quote, is_published, show_on_home, sort_order)
                  VALUES (:v, :a, :d, :r, :q, :p, :h, :o)', $args + [':o' => $max + 1]);
        return (int) db()->lastInsertId();
    }
    db_query('UPDATE reviews SET venue_id = :v, author = :a, detail = :d, rating = :r, quote = :q, is_published = :p, show_on_home = :h WHERE id = :id',
        $args + [':id' => $id]);
    return $id;
}

/** Rewrite the order of reviews from a posted id list (only rows of that list move). */
function reviews_reorder(array $ids): void {
    foreach (array_values(array_filter(array_map('intval', $ids))) as $i => $id) {
        db_query('UPDATE reviews SET sort_order = :o WHERE id = :id', [':o' => $i, ':id' => $id]);
    }
}
