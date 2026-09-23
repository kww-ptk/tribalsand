<?php
declare(strict_types=1);
/**
 * Customers — ZURI owns these (Zuri → TS per §1). We RECEIVE and store them; we
 * do not originate customer records, so there is no admin create/edit here.
 *
 * Data model (migration: add_restaurant_sync_models.sql): customers, with the
 * standard sync_* columns (sync_source defaults to 'zuri'). The applier (Stage 3+)
 * writes them via upsert_customer_from_sync(); the backfill matcher (§7) pairs on
 * normalised phone, then email.
 *
 * All reads are pre-migration-safe via customers_supported().
 */
require_once __DIR__ . '/db.php';

function customers_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.customers')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Normalise a phone for matching: keep digits only, drop a leading country code's +/00. */
function customer_normalize_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    return (string) $digits;
}

function fetch_customer(int $id): ?array {
    if (!customers_supported() || $id <= 0) return null;
    $row = db_query('SELECT * FROM customers WHERE id = :id', [':id' => $id])->fetch();
    return $row ?: null;
}

function fetch_customer_by_sync_uuid(string $uuid): ?array {
    if (!customers_supported() || $uuid === '') return null;
    $row = db_query('SELECT * FROM customers WHERE sync_uuid = :u', [':u' => $uuid])->fetch();
    return $row ?: null;
}

/**
 * The phone match key from the shared contract (backfill_natural_keys.customer:
 * "phone (last 9 digits)"): the last 9 digits, so "0700 111 222" and
 * "+254 700 111 222" — the same Kenyan number — match. Shorter numbers are kept
 * whole. Pure.
 */
function customer_phone_key(string $phone): string {
    $d = customer_normalize_phone($phone);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

/**
 * Backfill match (§7): phone on its last 9 digits first, then exact email.
 * Returns the local row or null. Used to pair an incoming Zuri customer with one
 * we already have before minting a new sync_uuid.
 */
function match_customer(string $phone, string $email): ?array {
    if (!customers_supported()) return null;
    $key = customer_phone_key($phone);
    if ($key !== '') {
        $row = db_query(
            "SELECT * FROM customers
              WHERE is_deleted = FALSE AND right(regexp_replace(coalesce(phone,''), '\\D', '', 'g'), 9) = :p
              ORDER BY id LIMIT 1",
            [':p' => $key]
        )->fetch();
        if ($row) return $row;
    }
    $email = strtolower(trim($email));
    if ($email !== '') {
        $row = db_query(
            "SELECT * FROM customers WHERE is_deleted = FALSE AND lower(coalesce(email,'')) = :e ORDER BY id LIMIT 1",
            [':e' => $email]
        )->fetch();
        if ($row) return $row;
    }
    return null;
}

/**
 * Upsert a customer we received from the peer, keyed on sync_uuid. Used by the
 * applier. Sets sync_source = 'zuri' (they own it). Returns the row.
 */
function upsert_customer_from_sync(string $syncUuid, array $d, int $version): array {
    $stmt = db_query(
        "INSERT INTO customers (sync_uuid, name, phone, email, notes, sync_version, sync_source, sync_last_at, sync_updated_at)
         VALUES (:u, :n, :p, :e, :notes, :ver, 'zuri', now(), now())
         ON CONFLICT (sync_uuid) DO UPDATE
            SET name = EXCLUDED.name, phone = EXCLUDED.phone, email = EXCLUDED.email,
                notes = EXCLUDED.notes, sync_version = EXCLUDED.sync_version,
                is_deleted = FALSE, updated_at = now(), sync_last_at = now(), sync_updated_at = now()
         RETURNING *",
        [
            ':u'     => $syncUuid,
            ':n'     => trim((string) ($d['name'] ?? '')) ?: null,
            ':p'     => trim((string) ($d['phone'] ?? '')) ?: null,
            ':e'     => trim((string) ($d['email'] ?? '')) ?: null,
            ':notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            ':ver'   => max(1, $version),
        ]
    );
    return $stmt->fetch() ?: [];
}
