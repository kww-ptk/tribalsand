<?php
declare(strict_types=1);
/**
 * Accounting P1 — legal companies, their money accounts, gapless document
 * numbering, and which company owns each property / POS outlet / stock location.
 * Spec: docs/superpowers/specs/2026-09-27-accounting-layer-design.md (§3, §12).
 * Migration: db/migrations/add_companies.sql. Test: php tests/companies_logic.php.
 *
 * Rules that are load-bearing:
 *  - ONE resolver per source: company_for_venue(), company_for_outlet(),
 *    company_for_location(). Each returns null when unresolved; later phases fail
 *    closed on null once a company's accounting is live.
 *  - An outlet's company is its own company_id, else its property's company. An
 *    outlet's stock location follows the OUTLET (never a copy on inv_locations), so
 *    the two can never drift. inv_locations.company_id is only for shared places
 *    that are not outlets (Main stock, a venue-less person).
 *  - acct_next_number() must run inside the issuing transaction: it locks the
 *    sequence row, so a rolled-back issue rolls the number back — no gaps.
 *  - Every read is pre-migration-safe: the *_supported() probes are catalog
 *    lookups, never a failing SELECT (they will run inside POS transactions).
 */

require_once __DIR__ . '/db.php';

/** A refusal the caller shows to the owner (bad KRA PIN, duplicate code…). */
class CompanyRefusal extends RuntimeException {}

const ACCT_DOC_TYPES = [
    'invoice'     => 'Tax invoice',
    'credit_note' => 'Credit note',
    'proforma'    => 'Pro-forma',
    'ic_invoice'  => 'Inter-company invoice',
];
const ACCT_DOC_PREFIX_TAG = ['invoice' => 'INV', 'credit_note' => 'CN', 'proforma' => 'PF', 'ic_invoice' => 'IC'];
const ACCT_NUMBER_PAD     = 6;
const ACCT_NUMBER_MAX     = 99999999;

const COMPANY_ACCOUNT_KINDS = [
    'bank'          => 'Bank account',
    'mpesa_till'    => 'M-Pesa till (Buy Goods)',
    'mpesa_paybill' => 'M-Pesa paybill',
    'cash'          => 'Cash',
    'card_merchant' => 'Card merchant',
];
const COMPANY_INVOICE_TIMING = [
    'checkout' => 'One invoice at check-out',
    'confirm'  => 'Stay invoiced at confirmation, extras at check-out',
];
const COMPANY_ROOM_CHARGE_MODES = [
    'on_behalf' => 'Collected on behalf — the property bills it as a pass-through',
    'reinvoice' => 'Re-invoiced — this company invoices the property',
];
const COMPANY_HOME_CURRENCY = 'KES';   // owner decision 2026-09-27: KES for every company

// ── Pre-migration guards ────────────────────────────────────────────────────

/** True once add_companies.sql has run. */
function companies_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $ok = (bool) db_query("SELECT to_regclass('public.companies') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Whether a table has a column (information_schema — safe inside a transaction). */
function company_column_exists(string $table, string $column): bool {
    static $memo = [];
    $k = $table . '.' . $column;
    if (isset($memo[$k])) return $memo[$k];
    try {
        return $memo[$k] = (bool) db_query(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c",
            [':t' => $table, ':c' => $column])->fetchColumn();
    } catch (Throwable $e) { return $memo[$k] = false; }
}

/** pos_outlets.company_id exists (the POS may be installed after this migration). */
function companies_outlets_supported(): bool   { return companies_supported() && company_column_exists('pos_outlets', 'company_id'); }
/** inv_locations.company_id exists. */
function companies_locations_supported(): bool { return companies_supported() && company_column_exists('inv_locations', 'company_id'); }

// ── Pure rules (no DB) ──────────────────────────────────────────────────────

/** Uppercase, spaces/dashes stripped: " p051 234 567-x " → "P051234567X". */
function company_normalize_pin(string $pin): string {
    return strtoupper((string) preg_replace('/[\s\-]+/', '', $pin));
}

/**
 * A KRA PIN is 11 characters: A (individual) or P (company/non-individual), nine
 * digits, and a check letter — e.g. P051234567X. Null when fine, else the reason.
 */
function company_pin_problem(string $pin): ?string {
    $pin = company_normalize_pin($pin);
    if ($pin === '') return null;
    if (!preg_match('/^[AP]\d{9}[A-Z]$/', $pin)) {
        return 'A KRA PIN is 11 characters: A or P, nine digits, then a letter (e.g. P051234567X).';
    }
    return null;
}

/** Short code: 2–6 letters/digits, uppercase. "" when nothing usable. */
function company_normalize_code(string $code): string {
    return substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)), 0, 6);
}

/** A starting code from a name: "Zuri Watamu Ltd" → "ZWL", "Zuri" → "ZUR". */
function company_code_suggest(string $name): string {
    $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $name) ?: [], fn($w) => $w !== ''));
    if (!$words) return 'CO';
    if (count($words) === 1) return company_normalize_code(substr($words[0], 0, 3)) ?: 'CO';
    $code = '';
    foreach (array_slice($words, 0, 4) as $w) $code .= $w[0];
    $code = company_normalize_code($code);
    return strlen($code) >= 2 ? $code : 'CO';
}

/** The default prefix for a document type: ("ZUR", invoice) → "ZUR-INV-". */
function acct_default_prefix(string $code, string $docType): string {
    return company_normalize_code($code) . '-' . (ACCT_DOC_PREFIX_TAG[$docType] ?? 'DOC') . '-';
}

/** Null when a prefix is usable: 1–20 of A–Z 0–9 - / . */
function acct_prefix_problem(string $prefix): ?string {
    if ($prefix === '' || strlen($prefix) > 20 || !preg_match('#^[A-Z0-9\-/.]+$#', $prefix)) {
        return 'A prefix is 1–20 characters: capital letters, digits, "-", "/" or ".".';
    }
    return null;
}

/** "ZUR-INV-" + 123 → "ZUR-INV-000123". Numbers past the pad simply grow. */
function acct_format_number(string $prefix, int $n): string {
    return $prefix . str_pad((string) max(1, $n), ACCT_NUMBER_PAD, '0', STR_PAD_LEFT);
}

/**
 * Validate a company form. Returns [clean data, errors[]]. Pure: uniqueness of
 * code / PIN is the database's job (unique indexes → CompanyRefusal).
 */
function company_clean(array $in): array {
    $errors = [];
    $d = [
        'name'             => trim((string)($in['name'] ?? '')),
        'code'             => company_normalize_code((string)($in['code'] ?? '')),
        'legal_name'       => trim((string)($in['legal_name'] ?? '')),
        'kra_pin'          => company_normalize_pin((string)($in['kra_pin'] ?? '')),
        'vat_registered'   => !empty($in['vat_registered']),
        'etims_enabled'    => !empty($in['etims_enabled']),
        'invoice_timing'   => (string)($in['invoice_timing'] ?? 'checkout'),
        'room_charge_mode' => (string)($in['room_charge_mode'] ?? 'on_behalf'),
        'address'          => trim((string)($in['address'] ?? '')),
        'email'            => trim((string)($in['email'] ?? '')),
        'phone'            => trim((string)($in['phone'] ?? '')),
        'is_active'        => !array_key_exists('is_active', $in) || !empty($in['is_active']),
    ];
    if ($d['name'] === '' || mb_strlen($d['name']) > 120) $errors['name'] = 'Give the company a name (up to 120 characters).';
    if ($d['code'] === '') $d['code'] = company_code_suggest($d['name']);
    if (strlen($d['code']) < 2) $errors['code'] = 'The short code is 2–6 letters or digits (e.g. ZUR).';
    if (mb_strlen($d['legal_name']) > 200) $errors['legal_name'] = 'The registered name is up to 200 characters.';
    if (($p = company_pin_problem($d['kra_pin'])) !== null) $errors['kra_pin'] = $p;
    elseif ($d['kra_pin'] === '' && ($d['vat_registered'] || $d['etims_enabled'])) {
        $errors['kra_pin'] = 'A VAT-registered or eTIMS company needs its KRA PIN.';
    }
    if (!isset(COMPANY_INVOICE_TIMING[$d['invoice_timing']]))      $errors['invoice_timing'] = 'Pick when invoices are issued.';
    if (!isset(COMPANY_ROOM_CHARGE_MODES[$d['room_charge_mode']])) $errors['room_charge_mode'] = 'Pick how room charges are handled.';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'That email address does not look right.';
    if (mb_strlen($d['email']) > 160) $errors['email'] = 'The email is up to 160 characters.';
    if (mb_strlen($d['phone']) > 40)  $errors['phone'] = 'The phone number is up to 40 characters.';
    if (mb_strlen($d['address']) > 1000) $errors['address'] = 'The address is up to 1,000 characters.';
    return [$d, $errors];
}

/** Validate a money-account form. Returns [clean data, error|null]. Pure. */
function company_account_clean(array $in): array {
    $kind = (string)($in['kind'] ?? '');
    $d = [
        'label'          => trim((string)($in['label'] ?? '')),
        'kind'           => $kind,
        'currency'       => strtoupper(trim((string)($in['currency'] ?? COMPANY_HOME_CURRENCY))),
        'bank_name'      => trim((string)($in['bank_name'] ?? '')),
        'branch'         => trim((string)($in['branch'] ?? '')),
        'account_number' => trim((string) preg_replace('/\s+/', ' ', (string)($in['account_number'] ?? ''))),
        'swift_code'     => strtoupper(trim((string)($in['swift_code'] ?? ''))),
        'is_default'     => !empty($in['is_default']),
        'is_active'      => !array_key_exists('is_active', $in) || !empty($in['is_active']),
    ];
    if (!isset(COMPANY_ACCOUNT_KINDS[$kind])) return [$d, 'Pick what kind of account it is.'];
    if ($d['label'] === '' || mb_strlen($d['label']) > 120) return [$d, 'Give the account a name (up to 120 characters), e.g. "Equity KES current".'];
    if (!isset(TS_CURRENCIES[$d['currency']])) return [$d, 'Pick the account currency from the list.'];
    if ($kind !== 'bank') { $d['bank_name'] = ''; $d['branch'] = ''; $d['swift_code'] = ''; }
    switch ($kind) {
        case 'bank':
            if ($d['bank_name'] === '' || mb_strlen($d['bank_name']) > 120) return [$d, 'A bank account needs the bank name.'];
            if (mb_strlen($d['branch']) > 120) return [$d, 'The branch is up to 120 characters.'];
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{3,33}$/', $d['account_number'])) return [$d, 'A bank account number is 4–34 letters or digits.'];
            if ($d['swift_code'] !== '' && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $d['swift_code'])) return [$d, 'A SWIFT code is 8 or 11 letters/digits.'];
            break;
        case 'mpesa_till':
        case 'mpesa_paybill':
            $d['account_number'] = preg_replace('/\D/', '', $d['account_number']);
            if (!preg_match('/^\d{5,7}$/', $d['account_number'])) return [$d, 'An M-Pesa till or paybill number is 5–7 digits.'];
            if ($d['currency'] !== 'KES') return [$d, 'M-Pesa accounts are in KES.'];
            break;
        case 'card_merchant':
            if (mb_strlen($d['account_number']) > 40) return [$d, 'The merchant id is up to 40 characters.'];
            break;
        case 'cash':
            $d['account_number'] = '';
            break;
    }
    if (!$d['is_active']) $d['is_default'] = false;   // a closed account can't be where money lands by default
    return [$d, null];
}

/** An outlet's company: its own, else its property's. Pure. */
function company_outlet_owner(?int $outletCompany, ?int $venueCompany): ?int {
    return $outletCompany ?: ($venueCompany ?: null);
}

/**
 * A stock location's company. Pure — the caller passes the resolved company of
 * the location's venue and (for an outlet shelf) of its outlet.
 *   outlet shelf           → the outlet's company (never a copy on the location)
 *   property / area / person with a venue → the venue's company
 *   shared (Main stock, venue-less person) → the location's own company_id
 */
function company_location_owner(array $loc, ?int $venueCompany, ?int $outletCompany): ?int {
    if (($loc['kind'] ?? '') === 'outlet' || !empty($loc['pos_outlet_id'])) return $outletCompany ?: null;
    if (!empty($loc['venue_id'])) return $venueCompany ?: null;
    return !empty($loc['company_id']) ? (int)$loc['company_id'] : null;
}

// ── Reads ───────────────────────────────────────────────────────────────────

/** Every company, with what it owns, ordered by name. */
function company_fetch_all(bool $activeOnly = false): array {
    if (!companies_supported()) return [];
    $outletCount = companies_outlets_supported()
        ? '(SELECT COUNT(*) FROM pos_outlets o WHERE o.company_id = c.id)' : '0';
    $locCount = companies_locations_supported()
        ? "(SELECT COUNT(*) FROM inv_locations l WHERE l.company_id = c.id AND l.venue_id IS NULL AND l.kind <> 'outlet' AND l.pos_outlet_id IS NULL)" : '0';
    return db_query(
        "SELECT c.*,
                (SELECT COUNT(*) FROM venues v WHERE v.company_id = c.id) AS venue_count,
                {$outletCount} AS outlet_count,
                {$locCount} AS location_count,
                (SELECT COUNT(*) FROM company_accounts a WHERE a.company_id = c.id AND a.is_active) AS account_count
           FROM companies c" . ($activeOnly ? ' WHERE c.is_active' : '') . "
          ORDER BY c.is_active DESC, LOWER(c.name)"
    )->fetchAll();
}

function company_fetch(int $id): array|false {
    if (!companies_supported() || $id <= 0) return false;
    return db_query('SELECT * FROM companies WHERE id = :id', [':id' => $id])->fetch();
}

/** Company options for a picker: active ones, plus $keepId even when switched off. */
function company_options(?int $keepId = null): array {
    if (!companies_supported()) return [];
    return db_query('SELECT id, name, code, is_active FROM companies WHERE is_active OR id = :k ORDER BY LOWER(name)',
        [':k' => (int)($keepId ?? 0)])->fetchAll();
}

/** A logo key → a URL the browser can load ('' when none). */
function company_logo_url(?string $key): string {
    $key = (string)$key;
    if ($key === '') return '';
    if (preg_match('#^https?://#', $key)) return $key;
    return storage_url($key);
}

// ── Resolvers (one per source) ──────────────────────────────────────────────

/** The company that owns a property, or null. */
function company_for_venue(?int $venueId): ?int {
    if (!companies_supported() || !$venueId) return null;
    $c = db_query('SELECT company_id FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn();
    return $c ? (int)$c : null;
}

/** The company that owns a POS outlet: its own, else its property's. Null when unresolved. */
function company_for_outlet(?int $outletId): ?int {
    if (!companies_outlets_supported() || !$outletId) return null;
    $r = db_query('SELECT o.company_id AS oc, v.company_id AS vc FROM pos_outlets o LEFT JOIN venues v ON v.id = o.venue_id WHERE o.id = :o',
        [':o' => $outletId])->fetch();
    if (!$r) return null;
    return company_outlet_owner($r['oc'] ? (int)$r['oc'] : null, $r['vc'] ? (int)$r['vc'] : null);
}

/** The company that owns a stock location. Null when unresolved. */
function company_for_location(?int $locationId): ?int {
    if (!companies_supported() || !$locationId || !to_regclass_exists('inv_locations')) return null;
    $cols = 'id, kind, venue_id, pos_outlet_id' . (companies_locations_supported() ? ', company_id' : ', NULL AS company_id');
    $loc = db_query("SELECT {$cols} FROM inv_locations WHERE id = :l", [':l' => $locationId])->fetch();
    if (!$loc) return null;
    $outletCo = !empty($loc['pos_outlet_id']) ? company_for_outlet((int)$loc['pos_outlet_id']) : null;
    $venueCo  = !empty($loc['venue_id']) ? company_for_venue((int)$loc['venue_id']) : null;
    return company_location_owner($loc, $venueCo, $outletCo);
}

/** to_regclass as a bool (catalog lookup; never throws inside a transaction). */
function to_regclass_exists(string $table): bool {
    static $memo = [];
    if (isset($memo[$table])) return $memo[$table];
    try { return $memo[$table] = (bool) db_query('SELECT to_regclass(:t) IS NOT NULL', [':t' => 'public.' . $table])->fetchColumn(); }
    catch (Throwable $e) { return $memo[$table] = false; }
}

/**
 * What still has no company — the owner's rollout checklist. Outlets that inherit
 * their property's company count as assigned.
 */
function company_ownership_gaps(): array {
    $gaps = ['venues' => [], 'outlets' => [], 'locations' => []];
    if (!companies_supported()) return $gaps;
    $gaps['venues'] = db_query('SELECT id, name FROM venues WHERE company_id IS NULL ORDER BY sort_order, name')->fetchAll();
    if (companies_outlets_supported()) {
        $gaps['outlets'] = db_query(
            'SELECT o.id, o.name FROM pos_outlets o LEFT JOIN venues v ON v.id = o.venue_id
              WHERE o.is_active AND o.company_id IS NULL AND v.company_id IS NULL ORDER BY o.sort_order, o.name')->fetchAll();
    }
    if (companies_locations_supported()) {
        $gaps['locations'] = company_shared_locations(true);
    }
    return $gaps;
}

/** Shared stock places a company can own directly (not an outlet, no venue). */
function company_shared_locations(bool $unownedOnly = false): array {
    if (!companies_locations_supported()) return [];
    return db_query(
        "SELECT id, name, kind, company_id FROM inv_locations
          WHERE is_active AND venue_id IS NULL AND kind <> 'outlet' AND pos_outlet_id IS NULL"
        . ($unownedOnly ? ' AND company_id IS NULL' : '') . "
          ORDER BY CASE kind WHEN 'store' THEN 0 ELSE 1 END, name")->fetchAll();
}

// ── Writes: companies ───────────────────────────────────────────────────────

/** Run $fn in a transaction (or a savepoint inside one). */
function company_tx(callable $fn): mixed {
    static $depth = 0;
    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    $sp = 'co_sp_' . (++$depth);
    $pdo->exec("SAVEPOINT {$sp}");
    try { $r = $fn(); $pdo->exec("RELEASE SAVEPOINT {$sp}"); $depth--; return $r; }
    catch (Throwable $e) {
        try { $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}"); $pdo->exec("RELEASE SAVEPOINT {$sp}"); } catch (Throwable $ignored) {}
        $depth--;
        throw $e;
    }
}

/** Turn a unique-index violation into a message the owner can act on. */
function company_unique_refusal(PDOException $e): never {
    $m = $e->getMessage();
    if (str_contains($m, 'uq_companies_code'))    throw new CompanyRefusal('Another company already uses that short code.');
    if (str_contains($m, 'uq_companies_kra_pin')) throw new CompanyRefusal('Another company already has that KRA PIN.');
    if (str_contains($m, 'uq_company_accounts_default')) throw new CompanyRefusal('That currency already has a default account — try again.');
    throw $e;
}

/**
 * Create ($id null) or update a company from clean data (company_clean()).
 * A new company gets its four number sequences. Changing the code re-prefixes
 * only sequences that still carry the old default and have taken no number.
 */
function company_save(?int $id, array $d): int {
    if (!companies_supported()) throw new CompanyRefusal('Run the add_companies migration first.');
    try {
        return company_tx(function () use ($id, $d): int {
            $args = [':n' => $d['name'], ':c' => $d['code'], ':ln' => $d['legal_name'], ':p' => $d['kra_pin'],
                     ':vr' => $d['vat_registered'] ? 'TRUE' : 'FALSE', ':et' => $d['etims_enabled'] ? 'TRUE' : 'FALSE',
                     ':it' => $d['invoice_timing'], ':rc' => $d['room_charge_mode'], ':ad' => $d['address'],
                     ':em' => $d['email'], ':ph' => $d['phone'], ':a' => $d['is_active'] ? 'TRUE' : 'FALSE'];
            if ($id === null) {
                db_query("INSERT INTO companies (name, code, legal_name, kra_pin, vat_registered, etims_enabled, home_currency,
                                                 invoice_timing, room_charge_mode, address, email, phone, is_active)
                          VALUES (:n, :c, :ln, :p, :vr, :et, '" . COMPANY_HOME_CURRENCY . "', :it, :rc, :ad, :em, :ph, :a)", $args);
                $id = (int) db()->lastInsertId();
                company_sequences($id);   // creates the four rows with default prefixes
                return $id;
            }
            $old = db_query('SELECT code FROM companies WHERE id = :id FOR UPDATE', [':id' => $id])->fetchColumn();
            if ($old === false) throw new CompanyRefusal('That company no longer exists.');
            db_query('UPDATE companies SET name = :n, code = :c, legal_name = :ln, kra_pin = :p, vat_registered = :vr,
                             etims_enabled = :et, invoice_timing = :it, room_charge_mode = :rc, address = :ad, email = :em,
                             phone = :ph, is_active = :a, updated_at = now() WHERE id = :id', $args + [':id' => $id]);
            if ((string)$old !== $d['code']) {
                foreach (ACCT_DOC_TYPES as $type => $_) {
                    db_query('UPDATE company_doc_sequences SET prefix = :np
                               WHERE company_id = :id AND doc_type = :t AND prefix = :op AND last_issued_at IS NULL',
                        [':np' => acct_default_prefix($d['code'], $type), ':id' => $id, ':t' => $type,
                         ':op' => acct_default_prefix((string)$old, $type)]);
                }
            }
            return $id;
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') company_unique_refusal($e);
        throw $e;
    }
}

/** Set (or clear) a company's logo storage key. */
function company_set_logo(int $id, ?string $key): void {
    db_query('UPDATE companies SET logo_key = :k, updated_at = now() WHERE id = :id', [':k' => $key, ':id' => $id]);
}

/**
 * How much a company holds on to: owned properties/outlets/locations and whether
 * it has ever taken a document number. Used to refuse a hard delete.
 */
function company_links(int $id): array {
    $l = ['venues' => (int) db_query('SELECT COUNT(*) FROM venues WHERE company_id = :c', [':c' => $id])->fetchColumn(),
          'outlets' => 0, 'locations' => 0,
          'numbers' => (bool) db_query('SELECT 1 FROM company_doc_sequences WHERE company_id = :c AND last_issued_at IS NOT NULL LIMIT 1', [':c' => $id])->fetchColumn()];
    if (companies_outlets_supported())   $l['outlets']   = (int) db_query('SELECT COUNT(*) FROM pos_outlets WHERE company_id = :c', [':c' => $id])->fetchColumn();
    if (companies_locations_supported()) $l['locations'] = (int) db_query('SELECT COUNT(*) FROM inv_locations WHERE company_id = :c', [':c' => $id])->fetchColumn();
    return $l;
}

/**
 * Delete a company that owns nothing and never numbered a document; otherwise
 * switch it off. Returns 'deleted' | 'deactivated'.
 */
function company_delete_or_deactivate(int $id): string {
    return company_tx(function () use ($id): string {
        if (!db_query('SELECT 1 FROM companies WHERE id = :id FOR UPDATE', [':id' => $id])->fetchColumn()) {
            throw new CompanyRefusal('That company no longer exists.');
        }
        $l = company_links($id);
        if ($l['venues'] || $l['outlets'] || $l['locations'] || $l['numbers']) {
            db_query('UPDATE companies SET is_active = FALSE, updated_at = now() WHERE id = :id', [':id' => $id]);
            return 'deactivated';
        }
        db_query('DELETE FROM companies WHERE id = :id', [':id' => $id]);   // accounts + sequences cascade
        return 'deleted';
    });
}

// ── Writes: ownership ───────────────────────────────────────────────────────

/** Point one property at a company (null = none). */
function company_set_venue(int $venueId, ?int $companyId): void {
    if (!companies_supported()) return;
    if ($companyId !== null && !company_fetch($companyId)) throw new CompanyRefusal('Pick a company from the list.');
    db_query('UPDATE venues SET company_id = :c WHERE id = :v', [':c' => $companyId, ':v' => $venueId]);
}

/** Point one POS outlet at a company (null = follow its property). */
function company_set_outlet(int $outletId, ?int $companyId): void {
    if (!companies_outlets_supported()) return;
    if ($companyId !== null && !company_fetch($companyId)) throw new CompanyRefusal('Pick a company from the list.');
    db_query('UPDATE pos_outlets SET company_id = :c WHERE id = :o', [':c' => $companyId, ':o' => $outletId]);
}

/**
 * Make $companyId own exactly the ticked rows of one kind: ticked rows move to it
 * (from whichever company had them), rows it owned that are no longer ticked are
 * released. $kind: venues | outlets | locations. Returns [added, released].
 */
function company_assign(int $companyId, string $kind, array $ids): array {
    $table = ['venues' => 'venues', 'outlets' => 'pos_outlets', 'locations' => 'inv_locations'][$kind] ?? null;
    if ($table === null) throw new InvalidArgumentException("Unknown ownership kind {$kind}");
    if ($kind === 'outlets'   && !companies_outlets_supported())   return [0, 0];
    if ($kind === 'locations' && !companies_locations_supported()) return [0, 0];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    // Locations: only shared, non-outlet places may carry a company of their own.
    $eligible = $kind === 'locations' ? " AND venue_id IS NULL AND kind <> 'outlet' AND pos_outlet_id IS NULL" : '';
    return company_tx(function () use ($companyId, $table, $ids, $eligible): array {
        if (!db_query('SELECT 1 FROM companies WHERE id = :id FOR UPDATE', [':id' => $companyId])->fetchColumn()) {
            throw new CompanyRefusal('That company no longer exists.');
        }
        $released = 0; $added = 0;
        $keep = $ids ? implode(',', $ids) : '0';
        $released = db_query("UPDATE {$table} SET company_id = NULL WHERE company_id = :c AND id NOT IN ({$keep})", [':c' => $companyId])->rowCount();
        if ($ids) {
            $added = db_query("UPDATE {$table} SET company_id = :c WHERE id IN ({$keep}) AND company_id IS DISTINCT FROM :c2{$eligible}",
                [':c' => $companyId, ':c2' => $companyId])->rowCount();
        }
        return [$added, $released];
    });
}

// ── Writes: money accounts ──────────────────────────────────────────────────

function company_accounts(int $companyId, bool $activeOnly = false): array {
    if (!companies_supported()) return [];
    return db_query('SELECT * FROM company_accounts WHERE company_id = :c' . ($activeOnly ? ' AND is_active' : '') . '
                      ORDER BY is_active DESC, currency, is_default DESC, sort_order, id', [':c' => $companyId])->fetchAll();
}

/**
 * Create ($accountId null) or update an account from clean data. Setting a
 * default clears the previous default of that currency; the first active
 * account of a currency becomes its default automatically.
 */
function company_account_save(int $companyId, ?int $accountId, array $d): int {
    try {
        return company_tx(function () use ($companyId, $accountId, $d): int {
            if (!db_query('SELECT 1 FROM companies WHERE id = :id FOR UPDATE', [':id' => $companyId])->fetchColumn()) {
                throw new CompanyRefusal('That company no longer exists.');
            }
            if ($d['is_default']) {
                db_query('UPDATE company_accounts SET is_default = FALSE WHERE company_id = :c AND currency = :cur AND is_default AND id <> :id',
                    [':c' => $companyId, ':cur' => $d['currency'], ':id' => (int)($accountId ?? 0)]);
            }
            $args = [':l' => $d['label'], ':k' => $d['kind'], ':cur' => $d['currency'], ':b' => $d['bank_name'], ':br' => $d['branch'],
                     ':n' => $d['account_number'], ':sw' => $d['swift_code'], ':def' => $d['is_default'] ? 'TRUE' : 'FALSE',
                     ':a' => $d['is_active'] ? 'TRUE' : 'FALSE', ':c' => $companyId];
            if ($accountId === null) {
                $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM company_accounts WHERE company_id = :c', [':c' => $companyId])->fetchColumn();
                db_query('INSERT INTO company_accounts (company_id, label, kind, currency, bank_name, branch, account_number, swift_code, is_default, is_active, sort_order)
                          VALUES (:c, :l, :k, :cur, :b, :br, :n, :sw, :def, :a, :o)', $args + [':o' => $max + 1]);
                $accountId = (int) db()->lastInsertId();
            } else {
                $n = db_query('UPDATE company_accounts SET label = :l, kind = :k, currency = :cur, bank_name = :b, branch = :br,
                                      account_number = :n, swift_code = :sw, is_default = :def, is_active = :a
                                WHERE id = :id AND company_id = :c', $args + [':id' => $accountId])->rowCount();
                if ($n === 0) throw new CompanyRefusal('That account no longer exists.');
            }
            company_account_ensure_defaults($companyId);
            return $accountId;
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') company_unique_refusal($e);
        throw $e;
    }
}

/** Every currency with an active account has exactly one default (the oldest active one when none is marked). */
function company_account_ensure_defaults(int $companyId): void {
    db_query('UPDATE company_accounts a SET is_default = TRUE
               WHERE a.id IN (SELECT DISTINCT ON (currency) id FROM company_accounts
                               WHERE company_id = :c AND is_active ORDER BY currency, sort_order, id)
                 AND NOT EXISTS (SELECT 1 FROM company_accounts d WHERE d.company_id = :c2 AND d.currency = a.currency AND d.is_default)',
        [':c' => $companyId, ':c2' => $companyId]);
}

/**
 * Remove an account. Refused once payments (Phase 2) reference it — then it is
 * switched off instead. Returns 'deleted' | 'deactivated'.
 */
function company_account_delete(int $companyId, int $accountId): string {
    return company_tx(function () use ($companyId, $accountId): string {
        $row = db_query('SELECT id FROM company_accounts WHERE id = :id AND company_id = :c FOR UPDATE', [':id' => $accountId, ':c' => $companyId])->fetch();
        if (!$row) throw new CompanyRefusal('That account no longer exists.');
        $used = to_regclass_exists('acct_payments')
            && db_query('SELECT 1 FROM acct_payments WHERE account_id = :id LIMIT 1', [':id' => $accountId])->fetchColumn();
        if ($used) {
            db_query('UPDATE company_accounts SET is_active = FALSE, is_default = FALSE WHERE id = :id', [':id' => $accountId]);
            $out = 'deactivated';
        } else {
            db_query('DELETE FROM company_accounts WHERE id = :id', [':id' => $accountId]);
            $out = 'deleted';
        }
        company_account_ensure_defaults($companyId);
        return $out;
    });
}

// ── Numbering ───────────────────────────────────────────────────────────────

/** A company's four sequences (created with default prefixes when missing), keyed by doc type. */
function company_sequences(int $companyId): array {
    $code = (string) db_query('SELECT code FROM companies WHERE id = :c', [':c' => $companyId])->fetchColumn();
    if ($code === '') return [];
    foreach (ACCT_DOC_TYPES as $type => $_) {
        db_query('INSERT INTO company_doc_sequences (company_id, doc_type, prefix) VALUES (:c, :t, :p) ON CONFLICT DO NOTHING',
            [':c' => $companyId, ':t' => $type, ':p' => acct_default_prefix($code, $type)]);
    }
    $out = [];
    foreach (db_query('SELECT * FROM company_doc_sequences WHERE company_id = :c', [':c' => $companyId])->fetchAll() as $r) {
        $out[$r['doc_type']] = $r;
    }
    return array_replace(array_intersect_key(ACCT_DOC_TYPES, $out), $out);   // keep ACCT_DOC_TYPES order
}

/**
 * Change a sequence's prefix and next number. Refused once a number has been
 * taken — from then on the series is fixed (no gaps, no re-use).
 */
function company_sequence_save(int $companyId, string $type, string $prefix, int $nextNo): void {
    if (!isset(ACCT_DOC_TYPES[$type])) throw new CompanyRefusal('Unknown document type.');
    $prefix = strtoupper(trim($prefix));
    if (($p = acct_prefix_problem($prefix)) !== null) throw new CompanyRefusal($p);
    if ($nextNo < 1 || $nextNo > ACCT_NUMBER_MAX) throw new CompanyRefusal('The next number is between 1 and ' . number_format(ACCT_NUMBER_MAX) . '.');
    company_tx(function () use ($companyId, $type, $prefix, $nextNo): void {
        company_sequences($companyId);
        $row = db_query('SELECT last_issued_at FROM company_doc_sequences WHERE company_id = :c AND doc_type = :t FOR UPDATE',
            [':c' => $companyId, ':t' => $type])->fetch();
        if (!$row) throw new CompanyRefusal('That company no longer exists.');
        if ($row['last_issued_at'] !== null) throw new CompanyRefusal(ACCT_DOC_TYPES[$type] . ' numbers are already in use, so the series is locked.');
        db_query('UPDATE company_doc_sequences SET prefix = :p, next_no = :n WHERE company_id = :c AND doc_type = :t',
            [':p' => $prefix, ':n' => $nextNo, ':c' => $companyId, ':t' => $type]);
    });
}

/**
 * Take the next document number for a company — e.g. "ZUR-INV-000123".
 * MUST be called inside the transaction that issues the document: the row lock is
 * held until it commits, and a rollback returns the number, so the series never
 * has a gap or a duplicate. Refuses outside a transaction for exactly that reason.
 */
function acct_next_number(int $companyId, string $type): string {
    if (!isset(ACCT_DOC_TYPES[$type])) throw new CompanyRefusal('Unknown document type.');
    if (!db()->inTransaction()) throw new LogicException('acct_next_number() must run inside the issuing transaction.');
    $row = db_query('SELECT prefix, next_no FROM company_doc_sequences WHERE company_id = :c AND doc_type = :t FOR UPDATE',
        [':c' => $companyId, ':t' => $type])->fetch();
    if (!$row) {
        company_sequences($companyId);
        $row = db_query('SELECT prefix, next_no FROM company_doc_sequences WHERE company_id = :c AND doc_type = :t FOR UPDATE',
            [':c' => $companyId, ':t' => $type])->fetch();
        if (!$row) throw new CompanyRefusal('That company does not exist.');
    }
    $n = (int)$row['next_no'];
    if ($n >= ACCT_NUMBER_MAX) throw new CompanyRefusal('This number series is full.');
    db_query('UPDATE company_doc_sequences SET next_no = next_no + 1, last_issued_at = now() WHERE company_id = :c AND doc_type = :t',
        [':c' => $companyId, ':t' => $type]);
    return acct_format_number((string)$row['prefix'], $n);
}
