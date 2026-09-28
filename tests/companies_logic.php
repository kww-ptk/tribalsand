<?php
declare(strict_types=1);
// Accounting P1 — companies, accounts, numbering, ownership. Run: php tests/companies_logic.php
// Pure rules always run. The DB block runs inside ONE transaction that is rolled
// back, and SKIPs when no database is reachable or add_companies.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/companies.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function refused(callable $fn): bool {
    try { $fn(); return false; } catch (CompanyRefusal $e) { return true; }
}

// ── KRA PIN ─────────────────────────────────────────────────────────────────
check('pin: normalised (spaces, dashes, lowercase)', company_normalize_pin(' p051 234 567-x ') === 'P051234567X');
check('pin: company PIN is valid', company_pin_problem('P051234567X') === null);
check('pin: individual PIN is valid', company_pin_problem('A012345678B') === null);
check('pin: blank is allowed (not set yet)', company_pin_problem('') === null);
check('pin: wrong first letter refused', company_pin_problem('B051234567X') !== null);
check('pin: too few digits refused', company_pin_problem('P05123456X') !== null);
check('pin: no check letter refused', company_pin_problem('P0512345678') !== null);

// ── Codes, prefixes, number format ──────────────────────────────────────────
check('code: normalised to A–Z0–9, max 6', company_normalize_code('zu-r watamu') === 'ZURWAT');
check('code: suggested from a one-word name', company_code_suggest('Zuri') === 'ZUR');
check('code: suggested from initials', company_code_suggest('Tribal Sand Services Ltd') === 'TSSL');
check('code: empty name falls back', company_code_suggest('') === 'CO');
check('prefix: default per type', acct_default_prefix('zur', 'invoice') === 'ZUR-INV-' && acct_default_prefix('ZUR', 'credit_note') === 'ZUR-CN-'
    && acct_default_prefix('ZUR', 'proforma') === 'ZUR-PF-' && acct_default_prefix('ZUR', 'ic_invoice') === 'ZUR-IC-');
check('prefix: valid prefix accepted', acct_prefix_problem('ZUR/INV-2026.') === null);
check('prefix: lowercase / spaces refused', acct_prefix_problem('zur inv') !== null);
check('prefix: empty refused', acct_prefix_problem('') !== null);
check('prefix: over 20 chars refused', acct_prefix_problem(str_repeat('A', 21)) !== null);
check('number: zero-padded to 6', acct_format_number('ZUR-INV-', 123) === 'ZUR-INV-000123');
check('number: grows past the pad', acct_format_number('X-', 1234567) === 'X-1234567');

// ── Company form ────────────────────────────────────────────────────────────
[$d, $err] = company_clean(['name' => 'Zuri Ltd', 'kra_pin' => 'p051234567x', 'vat_registered' => '1', 'invoice_timing' => 'checkout', 'room_charge_mode' => 'on_behalf']);
check('form: valid company passes, PIN normalised, code suggested', !$err && $d['kra_pin'] === 'P051234567X' && $d['code'] === 'ZL' && $d['vat_registered'] === true);
[, $err] = company_clean(['name' => '']);
check('form: a name is required', isset($err['name']));
[, $err] = company_clean(['name' => 'X Co', 'vat_registered' => '1']);
check('form: VAT-registered needs a KRA PIN', isset($err['kra_pin']));
[, $err] = company_clean(['name' => 'X Co', 'etims_enabled' => '1']);
check('form: eTIMS needs a KRA PIN', isset($err['kra_pin']));
[, $err] = company_clean(['name' => 'X Co', 'kra_pin' => '12345']);
check('form: malformed PIN refused', isset($err['kra_pin']));
[, $err] = company_clean(['name' => 'X Co', 'invoice_timing' => 'whenever']);
check('form: unknown invoice timing refused', isset($err['invoice_timing']));
[, $err] = company_clean(['name' => 'X Co', 'room_charge_mode' => 'free']);
check('form: unknown room-charge mode refused', isset($err['room_charge_mode']));
[, $err] = company_clean(['name' => 'X Co', 'email' => 'not-an-email']);
check('form: bad email refused', isset($err['email']));
[$d, $err] = company_clean(['name' => 'X Co', 'code' => 'x']);
check('form: one-character code refused', isset($err['code']));

// ── Account form ────────────────────────────────────────────────────────────
[$a, $e] = company_account_clean(['label' => 'Equity KES', 'kind' => 'bank', 'currency' => 'kes', 'bank_name' => 'Equity', 'account_number' => '0123 4567 8901', 'swift_code' => 'eqblkena']);
check('account: bank passes, SWIFT uppercased', $e === null && $a['currency'] === 'KES' && $a['swift_code'] === 'EQBLKENA');
[, $e] = company_account_clean(['label' => 'Bank', 'kind' => 'bank', 'currency' => 'KES', 'account_number' => '12345678']);
check('account: bank needs the bank name', $e !== null);
[, $e] = company_account_clean(['label' => 'Bank', 'kind' => 'bank', 'currency' => 'KES', 'bank_name' => 'KCB', 'account_number' => '12']);
check('account: short bank number refused', $e !== null);
[, $e] = company_account_clean(['label' => 'Bank', 'kind' => 'bank', 'currency' => 'KES', 'bank_name' => 'KCB', 'account_number' => '1234567', 'swift_code' => 'ABC']);
check('account: bad SWIFT refused', $e !== null);
[$a, $e] = company_account_clean(['label' => 'Till', 'kind' => 'mpesa_till', 'currency' => 'KES', 'account_number' => '123 456', 'bank_name' => 'ignored']);
check('account: M-Pesa till digits only, bank fields cleared', $e === null && $a['account_number'] === '123456' && $a['bank_name'] === '');
[, $e] = company_account_clean(['label' => 'Till', 'kind' => 'mpesa_paybill', 'currency' => 'USD', 'account_number' => '123456']);
check('account: M-Pesa must be KES', $e !== null);
[, $e] = company_account_clean(['label' => 'Till', 'kind' => 'mpesa_till', 'currency' => 'KES', 'account_number' => '12']);
check('account: M-Pesa number 5–7 digits', $e !== null);
[$a, $e] = company_account_clean(['label' => 'Float', 'kind' => 'cash', 'currency' => 'USD', 'account_number' => '999']);
check('account: cash keeps no number', $e === null && $a['account_number'] === '');
[, $e] = company_account_clean(['label' => 'X', 'kind' => 'crypto', 'currency' => 'KES']);
check('account: unknown kind refused', $e !== null);
[, $e] = company_account_clean(['label' => 'X', 'kind' => 'cash', 'currency' => 'XYZ']);
check('account: unknown currency refused', $e !== null);
[$a, ] = company_account_clean(['label' => 'Old', 'kind' => 'cash', 'currency' => 'KES', 'is_default' => '1', 'is_active' => '']);
check('account: a closed account is never the default', $a['is_default'] === false && $a['is_active'] === false);

// ── Ownership resolution (pure) ─────────────────────────────────────────────
check('outlet: own company wins', company_outlet_owner(5, 2) === 5);
check('outlet: falls back to its property', company_outlet_owner(null, 2) === 2);
check('outlet: unresolved = null', company_outlet_owner(null, null) === null);
check('location: outlet shelf follows the outlet, not the location copy',
    company_location_owner(['kind' => 'outlet', 'pos_outlet_id' => 4, 'venue_id' => null, 'company_id' => 9], null, 5) === 5);
check('location: outlet shelf with no outlet company is unresolved',
    company_location_owner(['kind' => 'outlet', 'pos_outlet_id' => 4, 'venue_id' => null, 'company_id' => 9], null, null) === null);
check('location: property follows its venue', company_location_owner(['kind' => 'property', 'venue_id' => 1, 'company_id' => 9], 2, null) === 2);
check('location: area follows its venue', company_location_owner(['kind' => 'area', 'venue_id' => 1], 2, null) === 2);
check('location: person with a home venue follows it', company_location_owner(['kind' => 'person', 'venue_id' => 3, 'company_id' => 9], 4, null) === 4);
check('location: Main stock uses its own company', company_location_owner(['kind' => 'store', 'venue_id' => null, 'company_id' => 7], null, null) === 7);
check('location: unowned Main stock is unresolved', company_location_owner(['kind' => 'store', 'venue_id' => null, 'company_id' => null], null, null) === null);

// ── DB block ────────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!companies_supported()) {
    echo "\nSKIP  DB block (add_companies.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx = substr(bin2hex(random_bytes(3)), 0, 4);
    [$d] = company_clean(['name' => "Test Zuri {$sfx}", 'code' => "Z{$sfx}", 'kra_pin' => 'P999999999Z', 'vat_registered' => '1']);
    $zuri = company_save(null, $d);
    check('db: company created', $zuri > 0 && company_fetch($zuri)['home_currency'] === 'KES');
    $seq = company_sequences($zuri);
    check('db: four sequences created with default prefixes', count($seq) === 4 && $seq['invoice']['prefix'] === acct_default_prefix($d['code'], 'invoice')
        && array_keys($seq) === array_keys(ACCT_DOC_TYPES));

    [$d2] = company_clean(['name' => "Test Services {$sfx}", 'code' => "S{$sfx}"]);
    $svc = company_save(null, $d2);
    check('db: duplicate code refused', refused(fn() => company_save(null, company_clean(['name' => 'Dup', 'code' => "Z{$sfx}"])[0])));
    check('db: duplicate KRA PIN refused', refused(fn() => company_save($svc, company_clean(['name' => "Test Services {$sfx}", 'code' => "S{$sfx}", 'kra_pin' => 'P999999999Z'])[0])));
    check('db: a refused save leaves the transaction usable', (int) db_query('SELECT 1')->fetchColumn() === 1);

    // Numbering: gapless, and a rolled-back issue returns its number.
    company_sequence_save($zuri, 'invoice', 'ZT-INV-', 120);
    $n1 = acct_next_number($zuri, 'invoice');
    check('db: first number uses the configured start', $n1 === 'ZT-INV-000120');
    db()->exec('SAVEPOINT issue');
    $n2 = acct_next_number($zuri, 'invoice');
    db()->exec('ROLLBACK TO SAVEPOINT issue');
    $n3 = acct_next_number($zuri, 'invoice');
    check('db: a rolled-back issue gives its number back (no gap)', $n2 === 'ZT-INV-000121' && $n3 === 'ZT-INV-000121');
    check('db: other document types keep their own series', acct_next_number($zuri, 'credit_note') === acct_default_prefix($d['code'], 'credit_note') . '000001');
    check('db: series locked once a number is taken', refused(fn() => company_sequence_save($zuri, 'invoice', 'NEW-', 1)));
    check('db: an unused series can still be changed', !refused(fn() => company_sequence_save($zuri, 'proforma', 'ZT-PF-', 5)));
    check('db: bad prefix refused', refused(fn() => company_sequence_save($zuri, 'proforma', 'bad prefix', 1)));

    // Changing the code re-prefixes only untouched default series.
    $d['code'] = "Q{$sfx}";
    company_save($zuri, $d);
    $seq = company_sequences($zuri);
    check('db: code change keeps a used / customised series', $seq['invoice']['prefix'] === 'ZT-INV-' && $seq['proforma']['prefix'] === 'ZT-PF-');
    check('db: code change re-prefixes an untouched default series', $seq['ic_invoice']['prefix'] === acct_default_prefix("Q{$sfx}", 'ic_invoice'));

    // Accounts: one default per currency.
    [$a1] = company_account_clean(['label' => 'Equity KES', 'kind' => 'bank', 'currency' => 'KES', 'bank_name' => 'Equity', 'account_number' => '1234567890']);
    $acc1 = company_account_save($zuri, null, $a1);
    $rows = company_accounts($zuri);
    check('db: first account of a currency becomes its default', (bool)$rows[0]['is_default'] === true);
    [$a2] = company_account_clean(['label' => 'Till', 'kind' => 'mpesa_till', 'currency' => 'KES', 'account_number' => '543210', 'is_default' => '1']);
    $acc2 = company_account_save($zuri, null, $a2);
    $def = db_query("SELECT id FROM company_accounts WHERE company_id = :c AND currency = 'KES' AND is_default", [':c' => $zuri])->fetchAll(PDO::FETCH_COLUMN);
    check('db: marking a new default moves it (still exactly one)', $def === [$acc2]);
    [$a3] = company_account_clean(['label' => 'USD float', 'kind' => 'cash', 'currency' => 'USD']);
    company_account_save($zuri, null, $a3);
    $defCount = (int) db_query('SELECT COUNT(*) FROM company_accounts WHERE company_id = :c AND is_default', [':c' => $zuri])->fetchColumn();
    check('db: each currency has its own default', $defCount === 2);
    check('db: deleting the default hands it to the next active account', company_account_delete($zuri, $acc2) === 'deleted'
        && db_query("SELECT id FROM company_accounts WHERE company_id = :c AND currency = 'KES' AND is_default", [':c' => $zuri])->fetchColumn() == $acc1);
    check('db: another company cannot touch this account', refused(fn() => company_account_delete($svc, $acc1)));

    // Ownership + resolvers.
    $venues = db_query('SELECT id FROM venues ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if (count($venues) === 2) {
        [$v1, $v2] = array_map('intval', $venues);
        company_assign($zuri, 'venues', [$v1, $v2]);
        check('db: assigned properties resolve to the company', company_for_venue($v1) === $zuri && company_for_venue($v2) === $zuri);
        company_assign($svc, 'venues', [$v2]);
        check('db: ticking a property elsewhere moves it', company_for_venue($v2) === $svc && company_for_venue($v1) === $zuri);
        company_assign($zuri, 'venues', []);
        check('db: unticking releases it', company_for_venue($v1) === null && company_for_venue($v2) === $svc);
        company_set_venue($v1, $zuri);
        check('db: the property page picker sets it', company_for_venue($v1) === $zuri);

        if (companies_outlets_supported()) {
            db_query("INSERT INTO pos_outlets (name, slug, venue_id, currency) VALUES ('T Bar', :s, :v, 'KES')", [':s' => 't-bar-' . $sfx, ':v' => $v1]);
            $out = (int) db()->lastInsertId();
            check('db: an outlet follows its property by default', company_for_outlet($out) === $zuri);
            company_set_outlet($out, $svc);
            check('db: an outlet can belong to another company', company_for_outlet($out) === $svc);
            if (companies_locations_supported()) {
                db_query("INSERT INTO inv_locations (kind, name, venue_id, pos_outlet_id, company_id) VALUES ('outlet', 'T Bar', :v, :o, :c)",
                    [':v' => $v1, ':o' => $out, ':c' => $zuri]);
                $shelf = (int) db()->lastInsertId();
                check('db: an outlet shelf resolves through its outlet (ignores a stale location copy)', company_for_location($shelf) === $svc);
                [$added] = company_assign($zuri, 'locations', [$shelf]);
                check('db: an outlet shelf cannot be given a company directly', $added === 0);
            }
            company_set_outlet($out, null);
            check('db: clearing the outlet falls back to its property', company_for_outlet($out) === $zuri);
            $gaps = company_ownership_gaps();
            check('db: an outlet covered by its property is not a gap', !in_array($out, array_map(fn($r) => (int)$r['id'], $gaps['outlets']), true));
        }

        if (companies_locations_supported()) {
            db_query("INSERT INTO inv_locations (kind, name) VALUES ('store', 'Main stock') ON CONFLICT (kind) WHERE kind = 'store' DO NOTHING");
            $store = (int) db_query("SELECT id FROM inv_locations WHERE kind = 'store'")->fetchColumn();
            db_query('UPDATE inv_locations SET company_id = NULL WHERE id = :l', [':l' => $store]);   // start from unowned (rolled back)
            check('db: unowned Main stock is unresolved and listed as a gap', company_for_location($store) === null
                && in_array($store, array_map(fn($r) => (int)$r['id'], company_ownership_gaps()['locations']), true));
            company_assign($svc, 'locations', [$store]);
            check('db: Main stock owned by the services company', company_for_location($store) === $svc);
            db_query("INSERT INTO inv_locations (kind, name, venue_id) VALUES ('property', 'T prop', :v) ON CONFLICT DO NOTHING", [':v' => $v1]);
            $prop = (int) db_query("SELECT id FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $v1])->fetchColumn();
            check('db: a property location follows its venue', company_for_location($prop) === $zuri);
        }

        check('db: a company owning things is switched off, not deleted', company_delete_or_deactivate($zuri) === 'deactivated'
            && company_fetch($zuri) && !(bool) company_fetch($zuri)['is_active']);
        check('db: a switched-off company still shows in its own picker', in_array($zuri, array_map(fn($r) => (int)$r['id'], company_options($zuri)), true)
            && !in_array($zuri, array_map(fn($r) => (int)$r['id'], company_options()), true));
    } else {
        echo "SKIP  ownership checks (need 2 venues)\n";
    }

    [$d3] = company_clean(['name' => "Empty {$sfx}", 'code' => "E{$sfx}"]);
    $empty = company_save(null, $d3);
    check('db: an empty, unused company is deleted', company_delete_or_deactivate($empty) === 'deleted' && !company_fetch($empty));
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

// After the rollback: no transaction is open, and the guard refuses before any write.
check('db: acct_next_number refuses outside a transaction', (function (): bool {
    try { acct_next_number(1, 'invoice'); return false; }
    catch (LogicException $e) { return true; }
})());
check('db: the test left no companies behind', (int) db_query("SELECT COUNT(*) FROM companies WHERE name LIKE 'Test %' OR name LIKE 'Empty %'")->fetchColumn() === 0);

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
