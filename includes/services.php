<?php
declare(strict_types=1);
/** Service pricing catalog helpers (laundry & transfer). Depends on includes/db.php. */

/** Active (or all) options for a service, ordered for display. [] on bad service / missing table. */
function fetch_service_options(string $service, bool $activeOnly = true): array {
    if (!in_array($service, ['laundry','transfer'], true)) return [];
    $sql = "SELECT * FROM service_options WHERE service = :s"
         . ($activeOnly ? " AND is_active = TRUE" : "")
         . " ORDER BY sort_order ASC, id ASC";
    try { return db_query($sql, [':s' => $service])->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** One option by id (any service / active state), or false. */
function fetch_service_option(int $id): array|false {
    try { $r = db_query("SELECT * FROM service_options WHERE id = :id", [':id' => $id])->fetch(); }
    catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** Format a money amount with the site currency: "KES 500", "USD 12.50". */
function format_price(float|int $amount, ?string $currency = null): string {
    $currency = $currency ?? setting('site_currency', 'USD');
    $amount   = (float) $amount;
    $formatted = ($amount == floor($amount)) ? number_format($amount, 0) : number_format($amount, 2);
    return trim($currency . ' ' . $formatted);
}

/** True if booking_addons has the price_amount snapshot column (memoised). False pre-migration. */
function addon_price_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'booking_addons' AND column_name = 'price_amount' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}
