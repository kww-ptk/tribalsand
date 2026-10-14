<?php
declare(strict_types=1);
// Deposit card photo → name on card, card type, last 4 digits, expiry
// (booking_checkin.deposit_card_*, add_checkin_deposit_card_details.sql).
// The FULL card number must never survive parsing. Pure rules always; a column
// round-trip in a rolled-back transaction when the migration is applied.
// Run: php tests/checkin_card_read_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$r = checkin_card_from_ai(['is_card' => true, 'name_on_card' => 'JOHN A SMITH', 'brand' => 'VISA', 'last4' => '4242', 'expiry' => '08/28'], '2026-10-14');
check('read: name title-cased', ($r['fields']['card_name'] ?? '') === 'John A Smith');
check('read: brand normalised', ($r['fields']['card_brand'] ?? '') === 'Visa');
check('read: last 4', ($r['fields']['card_last4'] ?? '') === '4242');
check('read: expiry MM/YY → YYYY-MM', ($r['fields']['card_expiry'] ?? '') === '2028-08');
check('read: no warnings for a valid card', $r['warnings'] === []);

$r = checkin_card_from_ai(['is_card' => true, 'last4' => '4111 1111 1111 1111', 'card_number' => '4111111111111111', 'brand' => 'mastercard'], '2026-10-14');
check('a full number is cut to its last 4 — never more', ($r['fields']['card_last4'] ?? '') === '1111');
check('no field anywhere holds more than 4 digits in a row', !preg_match('/\d{5,}/', json_encode($r)));
check('brand: mastercard', ($r['fields']['card_brand'] ?? '') === 'Mastercard');

$r = checkin_card_from_ai(['is_card' => true, 'last4' => '12', 'expiry' => '13/27', 'brand' => 'Shop Card'], '2026-10-14');
check('too few digits: no last 4', !isset($r['fields']['card_last4']));
check('impossible month: no expiry', !isset($r['fields']['card_expiry']));
check('unknown brand: left out', !isset($r['fields']['card_brand']));

$r = checkin_card_from_ai(['is_card' => true, 'last4' => '0005', 'expiry' => '01/24'], '2026-10-14');
check('expired card flagged', in_array('This card has expired.', $r['warnings'], true) && ($r['fields']['card_expiry'] ?? '') === '2024-01');
$r = checkin_card_from_ai(['is_card' => true, 'expiry' => '10/26'], '2026-10-14');
check('a card expiring this month is still valid', $r['warnings'] === []);

$r = checkin_card_from_ai(['is_card' => false], '2026-10-14');
check('not a card: nothing filled, a clear message', $r['fields'] === [] && $r['warnings'] === ["That doesn't look like a payment card."]);

check('prompt never asks for the full number', !preg_match('/full (card )?number/i', str_replace('never the full', '', checkin_card_read_prompt()))
    && str_contains(checkin_card_read_prompt(), 'last4'));

// ── What a guest may type ──
check('typed last 4: exactly four digits', checkin_card_last4_clean('4242') === '4242' && checkin_card_last4_clean('•••• 4242') === '4242'
    && checkin_card_last4_clean('424') === null && checkin_card_last4_clean('4111111111111111') === null);
check('typed expiry: MM/YY, MM/YYYY or YYYY-MM', checkin_card_expiry_clean('08/28') === '2028-08' && checkin_card_expiry_clean('08/2028') === '2028-08'
    && checkin_card_expiry_clean('2028-08') === '2028-08' && checkin_card_expiry_clean('13/28') === null && checkin_card_expiry_clean('') === null);
check('summary for staff', checkin_card_summary(['deposit_card_brand' => 'Visa', 'deposit_card_last4' => '4242', 'deposit_card_expiry' => '2028-08', 'deposit_card_name' => 'John Smith'])
    === 'Visa •••• 4242 · exp 08/2028 · John Smith');
check('summary: empty when nothing read', checkin_card_summary([]) === '');

// ── DB round-trip (rolled back) ──
try {
    $db = db();
    if (!checkin_deposit_card_details_supported()) { echo "SKIP  DB checks (add_checkin_deposit_card_details.sql not applied)\n"; }
    else {
        $db->beginTransaction();
        $hid = (int)db_query('SELECT id FROM holds ORDER BY id LIMIT 1')->fetchColumn();
        db_query("INSERT INTO booking_checkin (hold_id, deposit_card_last4, deposit_card_expiry) VALUES (:h, '4242', '2028-08')
                  ON CONFLICT (hold_id) DO UPDATE SET deposit_card_last4 = '4242', deposit_card_expiry = '2028-08'", [':h' => $hid]);
        check('db: last 4 stored', db_query('SELECT deposit_card_last4 FROM booking_checkin WHERE hold_id = :h', [':h' => $hid])->fetchColumn() === '4242');
        $bad = false;
        try { db_query('SAVEPOINT t1'); db_query("UPDATE booking_checkin SET deposit_card_last4 = '41111' WHERE hold_id = :h", [':h' => $hid]); }
        catch (Throwable $e) { $bad = true; db_query('ROLLBACK TO SAVEPOINT t1'); }
        check('db: more than 4 digits is refused by the database itself', $bad);
        $db->rollBack();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
